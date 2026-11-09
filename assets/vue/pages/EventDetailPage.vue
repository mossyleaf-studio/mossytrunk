<script setup>
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '../layouts/AppLayout.vue';
import BaseButton from '../components/ui/BaseButton.vue';
import BaseModal from '../components/ui/BaseModal.vue';
import BackLink from '../components/ui/BackLink.vue';
import EventForm from '../components/events/EventForm.vue';
import EventHeader from '../components/events/EventHeader.vue';
import DonationForm from '../components/events/DonationForm.vue';
import DonationList from '../components/events/DonationList.vue';
import EventReport from '../components/events/EventReport.vue';
import ExpenseForm from '../components/events/ExpenseForm.vue';
import ExpenseList from '../components/events/ExpenseList.vue';
import EventPreparation from '../components/planning/EventPreparation.vue';
import RestockForm from '../components/planning/RestockForm.vue';
import StockDiscrepancies from '../components/stock/StockDiscrepancies.vue';
import { useEvent } from '../composables/useEvents.js';
import { formatCents } from '../composables/useMoney.js';
import { usePlanning } from '../composables/usePlanning.js';
import { useStock } from '../composables/useStock.js';
import { listUrl } from '../composables/useQueryState.js';
import { useToast } from '../composables/useToast.js';
import { useTypeColors } from '../composables/useTypeColor.js';

const props = defineProps({
    eventId: { type: String, required: true },
});

const { event, report, target, preparation, load, update, addExpense, reviseExpense, removeExpense, receiveDonation, removeDonation } = useEvent(props.eventId);
const { colors: typeColors, load: loadTypes } = useTypeColors();
const toast = useToast();
const { t } = useI18n();
const { stockChecks, dismiss } = useStock();
const checks = ref([]);
const loadChecks = () => stockChecks(props.eventId, checks);
const editOpen = ref(false);
const expenseOpen = ref(false);
const editingExpense = ref(null);
const donationOpen = ref(false);
const restockOpen = ref(false);
const editingRestock = ref(null);
const { tick, tickRestock, cancelRestock } = usePlanning();
const upcoming = computed(() => event.value?.timing === 'upcoming');
const hasPreparation = computed(() => upcoming.value || preparation.value?.releases.length > 0 || preparation.value?.restocks.length > 0);

function openRestock(restock = null) {
    editingRestock.value = restock;
    restockOpen.value = true;
}

async function refresh(action, successMessage = null) {
    await toast.attempt(action, successMessage);
    await load();
}

const onRestockCancelled = (restock) => refresh(() => cancelRestock(restock.id), t('planning.restock.toast.cancelled', { supplier: restock.supplier.name }));

const submitExpense = (payload) => (editingExpense.value ? reviseExpense(editingExpense.value.id, payload) : addExpense(payload));

function openExpense(expense = null) {
    editingExpense.value = expense;
    expenseOpen.value = true;
}

async function onEventSaved(name) {
    editOpen.value = false;
    toast.success(t('events.detail.updated', { name }));
    await load();
}

async function onExpenseSaved(label) {
    expenseOpen.value = false;
    toast.success(t(editingExpense.value ? 'events.detail.expenseUpdated' : 'events.detail.expenseAdded', { label }));
    await load();
}

async function onRestockSaved(supplier) {
    restockOpen.value = false;
    toast.success(t(editingRestock.value ? 'planning.restock.toast.revised' : 'planning.restock.toast.planned', { supplier }));
    await load();
}

async function onDismissed(line) {
    await dismiss(line.checkId, line.id);
    toast.success(t('events.detail.discrepancyDismissed', { label: line.label }));
    await loadChecks();
}

async function onExpenseRemoved(expense) {
    await removeExpense(expense.id);
    toast.success(t('events.detail.expenseRemoved', { label: expense.label }));
    await load();
}

async function onDonationSaved(amount) {
    donationOpen.value = false;
    toast.success(t('events.detail.donationAdded', { amount: formatCents(amount) }));
    await load();
}

async function onDonationRemoved(donation) {
    await removeDonation(donation.id);
    toast.success(t('events.detail.donationRemoved', { amount: formatCents(donation.amount) }));
    await load();
}

onMounted(() => Promise.all([load(), loadTypes(), loadChecks()]));
</script>

<template>
    <AppLayout :title="event?.name ?? t('events.detail.titleFallback')">
        <template #back><BackLink :href="listUrl('/events')">{{ t('events.detail.back') }}</BackLink></template>
        <template #actions>
            <template v-if="event">
                <BaseButton variant="secondary" @click="editOpen = true">{{ t('common.edit') }}</BaseButton>
                <BaseButton variant="secondary" :href="`/events/${event.id}/check`">{{ t('events.detail.checkOrders') }}</BaseButton>
                <BaseButton variant="secondary" :href="`/events/${event.id}/stock-check`">{{ t('events.detail.stockCheck') }}</BaseButton>
                <BaseButton variant="secondary" @click="donationOpen = true">{{ t('events.detail.addDonation') }}</BaseButton>
                <BaseButton @click="openExpense()">{{ t('events.detail.addExpense') }}</BaseButton>
            </template>
        </template>

        <div v-if="event" class="event-detail-page">
            <EventHeader :event="event" />
            <StockDiscrepancies :checks="checks" @dismiss="onDismissed" />
            <EventPreparation
                v-if="preparation && hasPreparation"
                :preparation="preparation"
                :can-prepare="upcoming"
                @prepare="openRestock()"
                @tick-step="(release, step, done) => refresh(() => tick(release.id, step, done))"
                @tick-restock="(restock, done) => refresh(() => tickRestock(restock.id, done))"
                @edit-restock="openRestock"
                @cancel-restock="onRestockCancelled"
            />

            <EventReport :report="report" :event-id="event.id" :upcoming="event.timing === 'upcoming'" :target="target" :type-colors="typeColors">
                <template #aside>
                    <section class="event-detail-page__expenses" aria-labelledby="event-expenses">
                        <h3 id="event-expenses" class="event-detail-page__expenses-title">{{ t('events.detail.expenses') }}</h3>
                        <ExpenseList :expenses="event.expenses" :total="event.expensesTotal" @edit="openExpense" @remove="onExpenseRemoved" />
                    </section>
                    <section v-if="event.donations.length" class="event-detail-page__expenses" aria-labelledby="event-donations">
                        <h3 id="event-donations" class="event-detail-page__expenses-title">{{ t('events.detail.donations') }}</h3>
                        <DonationList :donations="event.donations" :total="event.donationsTotal" @remove="onDonationRemoved" />
                    </section>
                </template>
            </EventReport>
        </div>

        <BaseModal v-model:open="editOpen" :title="t('events.detail.editTitle')">
            <EventForm v-if="event" :event="event" :submit="update" @saved="onEventSaved" @cancel="editOpen = false" />
        </BaseModal>
        <BaseModal v-model:open="expenseOpen" :title="editingExpense ? t('events.detail.editExpense') : t('events.detail.newExpense')">
            <ExpenseForm :expense="editingExpense" :submit="submitExpense" @saved="onExpenseSaved" @cancel="expenseOpen = false" />
        </BaseModal>
        <BaseModal v-model:open="restockOpen" :title="editingRestock ? t('planning.restock.editTitle') : t('planning.restock.prepare')">
            <RestockForm v-if="event" :restock="editingRestock" :event="event" @saved="onRestockSaved" @cancel="restockOpen = false" />
        </BaseModal>
        <BaseModal v-model:open="donationOpen" :title="t('events.detail.newDonation')">
            <DonationForm :submit="receiveDonation" @saved="onDonationSaved" @cancel="donationOpen = false" />
        </BaseModal>
    </AppLayout>
</template>

<style scoped>
.event-detail-page { display: flex; flex-direction: column; gap: var(--space-5); }
.event-detail-page__expenses + .event-detail-page__expenses { margin-top: var(--space-5); }
.event-detail-page__expenses-title { margin: 0 0 var(--space-2); font-size: 1.2rem; color: var(--color-muted); }
</style>
