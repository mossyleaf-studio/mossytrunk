<script setup>
import { computed, onMounted, ref } from 'vue';
import { Trash2 } from '@lucide/vue';
import { ToggleGroupItem } from 'reka-ui';
import { useI18n } from 'vue-i18n';
import AppLayout from '../layouts/AppLayout.vue';
import BaseButton from '../components/ui/BaseButton.vue';
import BaseModal from '../components/ui/BaseModal.vue';
import ConfirmButton from '../components/ui/ConfirmButton.vue';
import EmptyState from '../components/ui/EmptyState.vue';
import CollectionForm from '../components/designs/CollectionForm.vue';
import DesignForm from '../components/designs/DesignForm.vue';
import DesignIndex from '../components/designs/DesignIndex.vue';
import DesignRows from '../components/designs/DesignRows.vue';
import BaseSwitch from '../components/ui/BaseSwitch.vue';
import ChoiceGroup from '../components/ui/ChoiceGroup.vue';
import GabaritManager from '../components/designs/GabaritManager.vue';
import WorkbenchCard from '../components/designs/WorkbenchCard.vue';
import ReleaseForm from '../components/planning/ReleaseForm.vue';
import ReleaseTimeline from '../components/planning/ReleaseTimeline.vue';
import { DESIGN_STATUSES, useDesignFilters } from '../composables/useDesignFilters.js';
import { useDesignBoard, useGabarits } from '../composables/useDesigns.js';
import { releaseOf, usePlanning } from '../composables/usePlanning.js';
import { useProductTypes } from '../composables/useProductTypes.js';
import { visit } from '../composables/useNavigation.js';
import { useToast } from '../composables/useToast.js';

const { board, load, createDesign, saveCollection, removeCollection, workOnCollection, validateCollection } = useDesignBoard();
const { gabarits, load: loadGabarits, save: saveGabarit, remove: removeGabarit } = useGabarits();
const { load: loadTypes } = useProductTypes();
const { planning, load: loadPlanning, cancel: cancelRelease, tick: tickRelease } = usePlanning();
const toast = useToast();
const { t } = useI18n();

const designOpen = ref(false);
const designCollectionId = ref('');
const collectionOpen = ref(false);
const editingCollection = ref(null);
const gabaritsOpen = ref(false);
const releaseOpen = ref(false);

const filters = useDesignFilters(board);
const collections = computed(() => board.value?.collections ?? []);
const { selected, allDesigns, onBench } = filters;
const collectionRelease = computed(() => (selected.value?.collection ? releaseOf(planning.value, 'collection', selected.value.collection.id) : null));
const shelfName = (shelf) => shelf.collection?.name ?? t('designs.noCollection');
const emptyShelf = computed(() => {
    if (filters.searching.value) return t('designs.shelf.nothingFound');
    if (selected.value && selected.value.designs.length === 0) return t(selected.value.collection ? 'designs.page.collectionEmpty' : 'designs.page.standaloneEmpty');
    return t(`designs.shelf.none.${filters.status.value}`);
});

function newDesign(collectionId = '') {
    designCollectionId.value = collectionId;
    designOpen.value = true;
}

function openCollection(collection = null) {
    editingCollection.value = collection;
    collectionOpen.value = true;
}

function onDesignSaved({ name, id }) {
    designOpen.value = false;
    toast.success(t('designs.page.started', { name }));
    visit(`/designs/${id}`);
}

async function onCollectionSaved(name) {
    collectionOpen.value = false;
    toast.success(t(editingCollection.value ? 'designs.page.collectionUpdated' : 'designs.page.collectionCreated', { name }));
    await load();
}

async function onRemoveCollection(collection) {
    await removeCollection(collection.id);
    toast.success(t('designs.page.collectionRemoved', { name: collection.name }));
    await load();
}

async function onBenchToggled(collection, current) {
    await workOnCollection(collection.id, current);
    await load();
}

async function onValidateCollection(collection) {
    const validated = await toast.attempt(async () => {
        const { productsCreated } = await validateCollection(collection.id);
        toast.success(t('designs.page.collectionValidated', { name: collection.name, count: productsCreated }, productsCreated));
    });
    if (validated) await load();
}

async function onGabaritRemoved(name) {
    toast.success(t('designs.page.gabaritRemoved', { name }));
    await loadGabarits();
}

async function onGabaritSaved(name) {
    toast.success(t('designs.page.gabaritSaved', { name }));
    await Promise.all([loadGabarits(), loadTypes()]);
}

async function onReleaseSaved(collection) {
    const planned = !collectionRelease.value;
    releaseOpen.value = false;
    toast.success(t(planned ? 'planning.toast.planned' : 'planning.toast.replanned', { name: collection.name }));
    await loadPlanning();
}

async function onReleaseCancelled(collection, release) {
    if (await toast.attempt(() => cancelRelease(release.id), t('planning.toast.cancelled', { name: collection.name }))) await loadPlanning();
}

async function onStepTicked(release, step, done) {
    await toast.attempt(() => tickRelease(release.id, step, done));
    await loadPlanning();
}

onMounted(() => Promise.all([load(), loadGabarits(), loadTypes(), loadPlanning()]));
</script>

<template>
    <AppLayout :title="t('designs.page.title')">
        <template #actions>
            <BaseButton variant="secondary" @click="gabaritsOpen = true">{{ t('designs.page.gabarits') }}</BaseButton>
            <BaseButton variant="secondary" @click="openCollection()">{{ t('designs.page.newCollection') }}</BaseButton>
            <BaseButton @click="newDesign()">{{ t('designs.page.newDesign') }}</BaseButton>
        </template>

        <div v-if="board" class="designs-page">
            <section class="designs-page__bench" aria-labelledby="bench-title">
                <h2 id="bench-title" class="designs-page__bench-title">
                    {{ t('designs.onBench') }}
                    <span v-if="onBench.length" class="designs-page__bench-count">{{ onBench.length }}</span>
                </h2>
                <div v-if="onBench.length" class="designs-page__strip">
                    <WorkbenchCard v-for="design in onBench" :key="design.id" :design="design" class="designs-page__card" />
                </div>
                <EmptyState v-else>{{ t('designs.page.benchEmpty') }}</EmptyState>
            </section>

            <div class="designs-page__atelier">
                <aside class="designs-page__index">
                    <DesignIndex
                        :scope="filters.activeScope.value"
                        @update:scope="filters.scope.value = $event"
                        v-model:search="filters.search.value"
                        :shelves="filters.index.value"
                        :total="allDesigns.length"
                        :searching="filters.searching.value"
                    />
                </aside>

                <section class="designs-page__shelf" aria-labelledby="shelf-title">
                    <header class="designs-page__shelf-header">
                        <div class="designs-page__shelf-heading">
                            <h2 id="shelf-title" class="designs-page__shelf-title">{{ selected ? shelfName(selected) : t('designs.index.all') }}</h2>
                            <p v-if="selected?.collection?.description" class="designs-page__description">{{ selected.collection.description }}</p>
                        </div>
                        <label v-if="selected?.collection" class="designs-page__bench-switch">
                            <BaseSwitch :model-value="selected.collection.current" @update:model-value="onBenchToggled(selected.collection, $event)" />
                            {{ t('designs.onBench') }}
                        </label>
                    </header>

                    <ChoiceGroup v-model="filters.status.value" class="designs-page__statuses" :aria-label="t('designs.filters.status')">
                        <ToggleGroupItem v-for="value in Object.values(DESIGN_STATUSES)" :key="value" :value="value" class="designs-page__status">
                            {{ t(`designs.filters.statuses.${value}`) }}
                            <span class="designs-page__status-count">{{ filters.counts.value[value] }}</span>
                        </ToggleGroupItem>
                    </ChoiceGroup>

                    <DesignRows v-if="selected" :designs="selected.shown" :empty="emptyShelf" />
                    <template v-else>
                        <div v-for="group in filters.groups.value" :key="group.key" class="designs-page__group">
                            <h3 class="designs-page__group-title">
                                <button type="button" class="designs-page__group-link" @click="filters.scope.value = group.key">{{ shelfName(group) }}</button>
                            </h3>
                            <DesignRows :designs="group.shown" />
                        </div>
                        <EmptyState v-if="filters.groups.value.length === 0">{{ emptyShelf }}</EmptyState>
                    </template>

                    <ReleaseTimeline
                        v-if="collectionRelease"
                        :release="collectionRelease"
                        editable
                        @tick="(step, done) => onStepTicked(collectionRelease, step, done)"
                        @edit="releaseOpen = true"
                        @cancel="onReleaseCancelled(selected.collection, collectionRelease)"
                    />

                    <footer v-if="selected" class="actions-row">
                        <template v-if="selected.collection">
                            <ConfirmButton
                                :icon="Trash2"
                                :label="t('designs.page.removeCollection', { name: selected.collection.name })"
                                :message="t('designs.page.removeCollectionMessage', { count: selected.designs.length }, selected.designs.length)"
                                @confirm="onRemoveCollection(selected.collection)"
                            />
                            <BaseButton variant="ghost" @click="openCollection(selected.collection)">{{ t('common.edit') }}</BaseButton>
                            <BaseButton v-if="!collectionRelease" variant="secondary" @click="releaseOpen = true">{{ t('planning.form.planTitle') }}</BaseButton>
                        </template>
                        <BaseButton variant="secondary" @click="newDesign(selected.collection?.id ?? '')">{{ t('designs.page.addDesign') }}</BaseButton>
                        <ConfirmButton
                            v-if="selected.collection?.readyToValidate"
                            variant="primary"
                            :label="t('designs.page.validateCollection')"
                            :confirm-label="t('designs.page.validate')"
                            :message="t('designs.page.validateCollectionMessage', { name: selected.collection.name, count: selected.open }, selected.open)"
                            @confirm="onValidateCollection(selected.collection)"
                        />
                    </footer>
                </section>
            </div>
        </div>

        <BaseModal v-model:open="designOpen" :title="t('designs.page.newDesign')">
            <DesignForm :collections="collections" :gabarits="gabarits" :collection-id="designCollectionId" :submit="createDesign" @saved="onDesignSaved" @cancel="designOpen = false" />
        </BaseModal>
        <BaseModal v-model:open="collectionOpen" :title="editingCollection ? t('designs.page.editCollection') : t('designs.page.newCollection')">
            <CollectionForm :collection="editingCollection" :submit="(payload) => saveCollection(editingCollection?.id, payload)" @saved="onCollectionSaved" @cancel="collectionOpen = false" />
        </BaseModal>
        <BaseModal v-model:open="releaseOpen" :title="collectionRelease ? t('planning.form.editTitle') : t('planning.form.planTitle')">
            <ReleaseForm
                v-if="selected?.collection"
                :owner="{ kind: 'collection', id: selected.collection.id }"
                :release="collectionRelease"
                @saved="onReleaseSaved(selected.collection)"
                @cancel="releaseOpen = false"
            />
        </BaseModal>
        <BaseModal v-model:open="gabaritsOpen" :title="t('designs.page.gabarits')">
            <GabaritManager :gabarits="gabarits" :save="saveGabarit" :remove="removeGabarit" @saved="onGabaritSaved" @removed="onGabaritRemoved" />
        </BaseModal>
    </AppLayout>
</template>

<style scoped>
.designs-page { display: flex; flex-direction: column; gap: var(--space-6); }

.designs-page__bench-title { display: flex; align-items: baseline; gap: var(--space-2); margin: 0 0 var(--space-3); font-size: 1.35rem; }
.designs-page__bench-count { color: var(--color-muted); font-family: var(--font-body); font-size: var(--font-size); font-weight: 400; }

.designs-page__strip {
    display: grid;
    grid-auto-columns: minmax(15rem, 17rem);
    grid-auto-flow: column;
    gap: var(--space-3);
    overflow-x: auto;
    padding-bottom: var(--space-2);
    scroll-snap-type: x proximity;
    overscroll-behavior-x: contain;
}

.designs-page__card { scroll-snap-align: start; }

.designs-page__atelier { display: grid; grid-template-columns: 15rem minmax(0, 1fr); gap: var(--space-5); align-items: start; }
.designs-page__index { position: sticky; top: var(--space-4); max-height: calc(100vh - var(--space-6)); overflow-y: auto; }

.designs-page__shelf {
    display: flex;
    flex-direction: column;
    gap: var(--space-4);
    min-width: 0;
    padding: var(--space-5);
    border: 0.0625rem solid var(--color-border);
    border-radius: var(--radius);
    background: var(--color-surface);
}

.designs-page__shelf-header { display: flex; align-items: flex-start; justify-content: space-between; gap: var(--space-3); }
.designs-page__shelf-heading { min-width: 0; }
.designs-page__shelf-title { margin: 0; font-size: 1.5rem; line-height: 1.2; }
.designs-page__description { max-width: 40rem; margin: var(--space-1) 0 0; color: var(--color-muted); font-size: var(--font-size-md); }
.designs-page__bench-switch { display: inline-flex; align-items: center; gap: var(--space-2); font-size: var(--font-size-md); white-space: nowrap; }

.designs-page__statuses { display: flex; gap: var(--space-4); border-bottom: 0.0625rem solid var(--color-border); }

.designs-page__status {
    display: inline-flex;
    align-items: baseline;
    gap: var(--space-1);
    margin-bottom: -0.0625rem;
    padding: var(--space-2) 0;
    border: none;
    border-bottom: 0.125rem solid transparent;
    background: none;
    color: var(--color-muted);
    font: inherit;
    font-size: var(--font-size-md);
    cursor: pointer;
    transition: color var(--transition), border-color var(--transition);
}

.designs-page__status:hover { color: var(--color-ink); }
.designs-page__status[data-state="on"] { border-bottom-color: var(--color-ink); color: var(--color-ink); font-weight: 600; }
.designs-page__status-count { font-size: var(--font-size-sm); font-variant-numeric: tabular-nums; font-weight: 400; }

.designs-page__group + .designs-page__group { margin-top: var(--space-3); }
.designs-page__group-title { margin: 0 0 var(--space-1); font-size: 1rem; }

.designs-page__group-link {
    padding: 0;
    border: none;
    background: none;
    color: var(--color-ink);
    font: inherit;
    cursor: pointer;
}

.designs-page__group-link:hover { text-decoration: underline; text-underline-offset: 0.1875rem; }
.designs-page__group-link:focus-visible { border-radius: var(--radius); }

@media (max-width: 56rem) {
    .designs-page__atelier { grid-template-columns: 1fr; }
    .designs-page__index { position: static; max-height: none; overflow: visible; }
    .designs-page__shelf { padding: var(--space-4); }
    .designs-page__shelf-header { flex-wrap: wrap; }
}
</style>
