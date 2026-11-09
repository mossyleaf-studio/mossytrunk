<script setup>
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '../layouts/AppLayout.vue';
import BaseButton from '../components/ui/BaseButton.vue';
import BaseCard from '../components/ui/BaseCard.vue';
import BaseModal from '../components/ui/BaseModal.vue';
import AgendaBlock from '../components/planning/AgendaBlock.vue';
import PlanningSummary from '../components/planning/PlanningSummary.vue';
import PlanningTimeline from '../components/planning/PlanningTimeline.vue';
import RestockForm from '../components/planning/RestockForm.vue';
import { agendaOf, usePlanning } from '../composables/usePlanning.js';
import { useToast } from '../composables/useToast.js';

const { planning, load, tickItem, cancelRestock } = usePlanning();
const toast = useToast();
const { t } = useI18n();

const agenda = computed(() => ({ late: agendaOf(planning.value, 'late'), thisWeek: agendaOf(planning.value, 'thisWeek'), later: agendaOf(planning.value, 'later') }));
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

const onTick = (item, done) => refresh(() => tickItem(item, done));
const onRestockCancelled = (restock) => refresh(() => cancelRestock(restock.id), t('planning.restock.toast.cancelled', { supplier: restock.supplier.name }));

onMounted(load);
</script>

<template>
    <AppLayout :title="t('planning.page.title')">
        <template #actions>
            <BaseButton @click="openRestock()">{{ t('planning.restock.prepare') }}</BaseButton>
        </template>

        <div v-if="planning" class="planning-page">
            <PlanningSummary :planning="planning" />

            <BaseCard :title="t('planning.overview.title')">
                <PlanningTimeline :planning="planning" />
            </BaseCard>

            <BaseCard :title="t('planning.agenda.title')">
                <div class="planning-page__agenda">
                    <AgendaBlock
                        v-if="agenda.late.length"
                        id="planning-late"
                        :title="t('planning.page.groups.late')"
                        :items="agenda.late"
                        :today="planning.today"
                        tone="danger"
                        editable
                        @tick="onTick"
                        @edit="openRestock"
                        @cancel="onRestockCancelled"
                    />
                    <AgendaBlock
                        id="planning-this-week"
                        :title="t('planning.page.groups.thisWeek')"
                        :items="agenda.thisWeek"
                        :today="planning.today"
                        :empty="t('planning.page.empty.thisWeek')"
                        tone="warning"
                        editable
                        @tick="onTick"
                        @edit="openRestock"
                        @cancel="onRestockCancelled"
                    />
                    <AgendaBlock
                        v-if="agenda.later.length"
                        id="planning-later"
                        :title="t('planning.page.groups.later')"
                        :items="agenda.later"
                        :today="planning.today"
                        collapsible
                        editable
                        @tick="onTick"
                        @edit="openRestock"
                        @cancel="onRestockCancelled"
                    />
                </div>
            </BaseCard>
        </div>

        <BaseModal v-model:open="restockOpen" :title="editingRestock ? t('planning.restock.editTitle') : t('planning.restock.prepare')">
            <RestockForm :restock="editingRestock" @saved="onRestockSaved" @cancel="restockOpen = false" />
        </BaseModal>
    </AppLayout>
</template>

<style scoped>
.planning-page, .planning-page__agenda { display: flex; flex-direction: column; gap: var(--space-5); }
</style>
