<script setup>
import { computed, onMounted, ref } from 'vue';
import { Plus } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '../layouts/AppLayout.vue';
import BaseButton from '../components/ui/BaseButton.vue';
import BaseModal from '../components/ui/BaseModal.vue';
import BaseSwitch from '../components/ui/BaseSwitch.vue';
import ConfirmButton from '../components/ui/ConfirmButton.vue';
import EmptyState from '../components/ui/EmptyState.vue';
import StatusBadge from '../components/ui/StatusBadge.vue';
import BackLink from '../components/ui/BackLink.vue';
import DeclinationCard from '../components/designs/DeclinationCard.vue';
import DesignForm from '../components/designs/DesignForm.vue';
import ReleaseForm from '../components/planning/ReleaseForm.vue';
import ReleaseTimeline from '../components/planning/ReleaseTimeline.vue';
import { useDesign, useDesignBoard, useDesignProgress, useGabarits } from '../composables/useDesigns.js';
import { formatDateTime } from '../composables/useDate.js';
import { ownerUrl, useDesignRelease, usePlanning } from '../composables/usePlanning.js';
import { useProductTypes } from '../composables/useProductTypes.js';
import { visit } from '../composables/useNavigation.js';
import { listUrl } from '../composables/useQueryState.js';
import { useToast } from '../composables/useToast.js';

const props = defineProps({
    designId: { type: String, required: true },
});

const { design, load, update, remove, workOn, decline, adjust, withdraw, tick, validate } = useDesign(props.designId);
const { board, load: loadBoard } = useDesignBoard();
const { gabarits, load: loadGabarits } = useGabarits();
const { load: loadTypes } = useProductTypes();
const { designRelease, load: loadRelease } = useDesignRelease(props.designId);
const { cancel: cancelRelease, tick: tickRelease } = usePlanning();
const toast = useToast();
const { t } = useI18n();
const editOpen = ref(false);
const releaseOpen = ref(false);
const ownRelease = computed(() => (designRelease.value?.inherited ? null : designRelease.value?.release ?? null));

const validated = computed(() => design.value?.status === 'validated');
const available = computed(() => gabarits.value.filter((gabarit) => !design.value?.declinations.some((d) => d.gabarit.id === gabarit.id)));
const { pending, hasProducts, ready } = useDesignProgress(design);
const productNames = computed(() => pending.value.map((d) => d.displayName).join(', '));

async function run(action, success) {
    if (await toast.attempt(action, success)) await load();
}

const onDecline = (gabarit) => run(() => decline(gabarit.id), t('designs.detail.declined', { name: gabarit.name }));
const onTick = (declination, adaptation, done) => run(() => tick(declination.id, adaptation, done));
const onWithdraw = (declination) => run(() => withdraw(declination.id), t('designs.detail.withdrawn', { name: declination.gabarit.name }));
const onBench = (current) => run(() => workOn(current), t(current ? 'designs.detail.backOnBench' : 'designs.detail.setAside'));

async function submitAdjustment(declination, payload) {
    await adjust(declination.id, payload);
    toast.success(t('designs.detail.adjusted', { name: declination.gabarit.name }));
    await Promise.all([load(), loadTypes()]);
}

const onValidate = () => run(async () => {
    const { productsCreated } = await validate();
    toast.success(t('designs.detail.validated', productsCreated));
});

async function onRemove() {
    await remove();
    toast.success(t('designs.detail.removed', { name: design.value.name }));
    visit(listUrl('/designs'));
}

async function onSaved({ name }) {
    editOpen.value = false;
    toast.success(t('designs.detail.updated', { name }));
    await Promise.all([load(), loadRelease()]);
}

async function onReleaseSaved() {
    const planned = !ownRelease.value;
    releaseOpen.value = false;
    toast.success(t(planned ? 'planning.toast.planned' : 'planning.toast.replanned', { name: design.value.name }));
    await loadRelease();
}

async function onReleaseCancelled() {
    if (await toast.attempt(() => cancelRelease(ownRelease.value.id), t('planning.toast.cancelled', { name: design.value.name }))) await loadRelease();
}

async function onStepTicked(step, done) {
    await toast.attempt(() => tickRelease(ownRelease.value.id, step, done));
    await loadRelease();
}

onMounted(() => Promise.all([load(), loadGabarits(), loadBoard(), loadTypes(), loadRelease()]));
</script>

<template>
    <AppLayout :title="design?.name ?? t('designs.detail.titleFallback')">
        <template #back><BackLink :href="listUrl('/designs')">{{ t('designs.detail.back') }}</BackLink></template>
        <template #actions>
            <template v-if="design">
                <ConfirmButton v-if="!hasProducts" variant="ghost" :label="t('common.delete')" :message="t('designs.detail.deleteMessage', { name: design.name })" @confirm="onRemove" />
                <BaseButton variant="secondary" @click="editOpen = true">{{ t('common.edit') }}</BaseButton>
                <ConfirmButton
                    v-if="ready"
                    variant="primary"
                    :label="hasProducts ? t('designs.detail.createNewProducts') : t('designs.detail.validate')"
                    :confirm-label="t('designs.detail.createProducts')"
                    :message="t('designs.detail.validateMessage', { products: productNames })"
                    @confirm="onValidate"
                />
            </template>
        </template>

        <div v-if="design" class="design-page">
            <div class="design-page__meta">
                <span v-if="design.collection"><i18n-t keypath="designs.detail.collection" scope="global"><template #name><strong>{{ design.collection.name }}</strong></template></i18n-t></span>
                <span v-else>{{ t('designs.noCollection') }}</span>
                <StatusBadge v-if="validated" tone="success">{{ t('designs.validatedOn', { date: formatDateTime(design.validatedAt) }) }}</StatusBadge>
                <label v-if="!validated" class="design-page__bench"><BaseSwitch :model-value="design.current" @update:model-value="onBench" /> {{ t('designs.onBench') }}</label>
            </div>
            <p v-if="design.notes" class="design-page__notes">{{ design.notes }}</p>

            <div v-if="designRelease" class="design-page__release">
                <template v-if="designRelease.inherited">
                    <p class="design-page__inherited">
                        <i18n-t keypath="planning.design.inherited" scope="global"><template #name><a :href="ownerUrl(designRelease.release.owner)">{{ designRelease.release.owner.name }}</a></template></i18n-t>
                    </p>
                    <ReleaseTimeline :release="designRelease.release" readonly />
                </template>
                <ReleaseTimeline v-else-if="ownRelease" :release="ownRelease" editable @tick="onStepTicked" @edit="releaseOpen = true" @cancel="onReleaseCancelled" />
                <div v-if="!ownRelease" class="design-page__release-actions">
                    <BaseButton variant="secondary" @click="releaseOpen = true">{{ designRelease.inherited ? t('planning.design.planApart') : t('planning.form.planTitle') }}</BaseButton>
                </div>
            </div>

            <div class="design-page__decline">
                <span class="design-page__decline-label">{{ t('designs.detail.declineOn') }}</span>
                <BaseButton v-for="gabarit in available" :key="gabarit.id" variant="secondary" @click="onDecline(gabarit)">
                    <Plus size="0.875rem" aria-hidden="true" /> {{ gabarit.name }}
                </BaseButton>
                <span v-if="gabarits.length === 0" class="design-page__hint">{{ t('designs.detail.noGabarits') }}</span>
                <span v-else-if="available.length === 0" class="design-page__hint">{{ t('designs.detail.allDeclined') }}</span>
            </div>

            <EmptyState v-if="design.declinations.length === 0">{{ t('designs.detail.noDeclinations') }}</EmptyState>
            <div v-else class="design-page__declinations">
                <DeclinationCard
                    v-for="declination in design.declinations"
                    :key="declination.id"
                    :declination="declination"
                    :locked="Boolean(declination.productId)"
                    @tick="(adaptation, done) => onTick(declination, adaptation, done)"
                    :submit="(payload) => submitAdjustment(declination, payload)"
                    @withdraw="onWithdraw(declination)"
                />
            </div>
        </div>

        <BaseModal v-model:open="releaseOpen" :title="ownRelease ? t('planning.form.editTitle') : t('planning.form.planTitle')">
            <ReleaseForm :owner="{ kind: 'design', id: designId }" :release="ownRelease" @saved="onReleaseSaved" @cancel="releaseOpen = false" />
        </BaseModal>
        <BaseModal v-model:open="editOpen" :title="t('designs.detail.editTitle')">
            <DesignForm v-if="design" :design="design" :collections="board?.collections ?? []" :submit="update" @saved="onSaved" @cancel="editOpen = false" />
        </BaseModal>
    </AppLayout>
</template>

<style scoped>
.design-page { display: flex; flex-direction: column; gap: var(--space-4); }
.design-page__meta { display: flex; align-items: center; gap: var(--space-4); color: var(--color-muted); }
.design-page__bench { display: inline-flex; align-items: center; gap: var(--space-2); color: var(--color-text); font-size: var(--font-size-md); }
.design-page__notes { max-width: 45rem; margin: 0; white-space: pre-line; }
.design-page__release { display: flex; flex-direction: column; gap: var(--space-2); max-width: 45rem; }
.design-page__inherited { margin: 0; color: var(--color-muted); }
.design-page__release-actions { display: flex; justify-content: flex-start; }
.design-page__decline { display: flex; align-items: center; gap: var(--space-2); flex-wrap: wrap; }
.design-page__decline-label { font-weight: 600; }
.design-page__hint { color: var(--color-muted); font-size: var(--font-size-md); }
.design-page__declinations { display: grid; grid-template-columns: repeat(auto-fill, minmax(20rem, 1fr)); gap: var(--space-4); align-items: start; }
</style>
