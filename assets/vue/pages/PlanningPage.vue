<script setup>
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '../layouts/AppLayout.vue';
import BaseButton from '../components/ui/BaseButton.vue';
import BaseCard from '../components/ui/BaseCard.vue';
import BaseModal from '../components/ui/BaseModal.vue';
import EmptyState from '../components/ui/EmptyState.vue';
import PlanningSteps from '../components/planning/PlanningSteps.vue';
import ReleaseTimeline from '../components/planning/ReleaseTimeline.vue';
import RestockForm from '../components/planning/RestockForm.vue';
import RestockRow from '../components/planning/RestockRow.vue';
import { ownerUrl, usePlanning } from '../composables/usePlanning.js';
import { useToast } from '../composables/useToast.js';

const { planning, load, tick, tickItem, tickRestock, cancelRestock } = usePlanning();
const toast = useToast();
const { t } = useI18n();

const GROUPS = ['late', 'thisWeek', 'later'];
const upcoming = computed(() => planning.value?.releases.filter((release) => !release.past) ?? []);
const restockOpen = ref(false);
const editingRestock = ref(null);

function openRestock(restock = null) {
    editingRestock.value = restock;
    restockOpen.value = true;
}

async function refresh(action, successMessage = null) {
    await toast.attempt(action, successMessage);
    await load();
}

async function onRestockSaved(supplier) {
    restockOpen.value = false;
    toast.success(t(editingRestock.value ? 'planning.restock.toast.revised' : 'planning.restock.toast.planned', { supplier }));
    await load();
}

const onRestockCancelled = (restock) => refresh(() => cancelRestock(restock.id), t('planning.restock.toast.cancelled', { supplier: restock.supplier.name }));

onMounted(load);
</script>

<template>
    <AppLayout :title="t('planning.page.title')">
        <template #actions>
            <BaseButton @click="openRestock()">{{ t('planning.restock.prepare') }}</BaseButton>
        </template>

        <div v-if="planning" class="planning-page">
            <BaseCard v-for="group in GROUPS" :key="group" :title="t(`planning.page.groups.${group}`)">
                <PlanningSteps v-if="planning[group].length" :steps="planning[group]" editable @tick="(item, done) => refresh(() => tickItem(item, done))" @edit="openRestock" @cancel="onRestockCancelled" />
                <EmptyState v-else>{{ t(`planning.page.empty.${group}`) }}</EmptyState>
            </BaseCard>

            <BaseCard :title="t('planning.page.restocks')">
                <ul v-if="planning.restocks.length" class="planning-page__restocks">
                    <RestockRow v-for="restock in planning.restocks" :key="restock.id" :restock="restock" editable @tick="(item, done) => refresh(() => tickRestock(item.id, done))" @edit="openRestock" @cancel="onRestockCancelled" />
                </ul>
                <EmptyState v-else>{{ t('planning.page.empty.restocks') }}</EmptyState>
            </BaseCard>

            <BaseCard :title="t('planning.page.releases')">
                <div v-if="upcoming.length" class="planning-page__releases">
                    <article v-for="release in upcoming" :key="release.id" class="planning-page__release" :aria-labelledby="`release-${release.id}`">
                        <h3 :id="`release-${release.id}`" class="planning-page__owner"><a :href="ownerUrl(release.owner)">{{ release.owner.name }}</a></h3>
                        <ReleaseTimeline :release="release" @tick="(step, done) => refresh(() => tick(release.id, step, done))" />
                    </article>
                </div>
                <EmptyState v-else>{{ t('planning.page.empty.releases') }}</EmptyState>
            </BaseCard>
        </div>

        <BaseModal v-model:open="restockOpen" :title="editingRestock ? t('planning.restock.editTitle') : t('planning.restock.prepare')">
            <RestockForm :restock="editingRestock" @saved="onRestockSaved" @cancel="restockOpen = false" />
        </BaseModal>
    </AppLayout>
</template>

<style scoped>
.planning-page, .planning-page__releases { display: flex; flex-direction: column; gap: var(--space-5); }
.planning-page__restocks { display: flex; flex-direction: column; margin: 0; padding: 0; list-style: none; }
.planning-page__release { display: flex; flex-direction: column; gap: var(--space-2); }
.planning-page__owner { margin: 0; font-size: var(--font-size-md); }
</style>
