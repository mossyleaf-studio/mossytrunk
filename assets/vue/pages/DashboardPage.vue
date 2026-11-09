<script setup>
import { computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { TriangleAlert, PackageMinus } from '@lucide/vue';
import AppLayout from '../layouts/AppLayout.vue';
import BaseButton from '../components/ui/BaseButton.vue';
import BaseCard from '../components/ui/BaseCard.vue';
import BaseSelect from '../components/ui/BaseSelect.vue';
import EmptyState from '../components/ui/EmptyState.vue';
import BestSellers from '../components/dashboard/BestSellers.vue';
import MonthlyResultChart from '../components/dashboard/MonthlyResultChart.vue';
import ResultsTable from '../components/dashboard/ResultsTable.vue';
import PlanningSteps from '../components/planning/PlanningSteps.vue';
import ResultBars from '../components/reporting/ResultBars.vue';
import ResultReceipt from '../components/reporting/ResultReceipt.vue';
import { monthName, useDashboard } from '../composables/useDashboard.js';
import { resultLines } from '../composables/useResultLines.js';
import { useTypeColors } from '../composables/useTypeColor.js';
import { formatDate } from '../composables/useDate.js';
import { usePlanning } from '../composables/usePlanning.js';
import { useToast } from '../composables/useToast.js';

const { t } = useI18n();
const { dashboard, load } = useDashboard();
const { colors: typeColors, load: loadTypes } = useTypeColors();
const { planning, load: loadPlanning, tick } = usePlanning();
const dueSteps = computed(() => (planning.value ? [...planning.value.late, ...planning.value.thisWeek] : []));
const isEmpty = computed(() => dashboard.value !== null && dashboard.value.byYear.length === 0 && dashboard.value.events.length === 0);
const yearOptions = computed(() => (dashboard.value?.years ?? []).map((year) => ({ value: year, label: String(year) })));

const receiptLines = computed(() => resultLines(dashboard.value.total, {
    label: (key) => t(`dashboard.receipt.${key}`),
    urssafHint: t('dashboard.receipt.urssafRate'),
}));

const eventBars = computed(() => dashboard.value.events.map((event) => ({
    id: event.id,
    label: event.name,
    meta: formatDate(event.startDate),
    value: event.result,
    turnover: event.turnover,
    href: `/events/${event.id}`,
})));

async function onTick(step, done) {
    await useToast().attempt(() => tick(step.release.id, step.label, done));
    await loadPlanning();
}

onMounted(() => Promise.all([load(), loadTypes(), loadPlanning()]));
</script>

<template>
    <AppLayout :title="t('dashboard.title')">
        <template #actions>
            <BaseSelect v-if="dashboard && !isEmpty" :model-value="dashboard.year" :options="yearOptions" size="small" :aria-label="t('dashboard.year')" @update:model-value="load" />
        </template>

        <BaseCard v-if="dueSteps.length" class="dashboard-page__planning" :title="t('dashboard.planning.title')">
            <template #actions><a href="/planning">{{ t('dashboard.planning.see') }}</a></template>
            <PlanningSteps :steps="dueSteps" @tick="onTick" />
        </BaseCard>

        <section v-if="isEmpty" class="dashboard-page__welcome" aria-labelledby="dashboard-welcome">
            <h2 id="dashboard-welcome" class="dashboard-page__welcome-title">{{ t('dashboard.welcome.title') }}</h2>
            <p class="dashboard-page__welcome-text">
                {{ t('dashboard.welcome.text') }}
            </p>
            <div class="dashboard-page__welcome-actions">
                <BaseButton href="/events?new">{{ t('dashboard.welcome.createEvent') }}</BaseButton>
            </div>
        </section>

        <div v-else-if="dashboard" class="dashboard-page">
            <p v-if="dashboard.productsWithoutCost > 0" class="dashboard-page__check" role="status">
                <TriangleAlert size="1rem" aria-hidden="true" />
                <span>
                    {{ t('dashboard.checks.withoutCost', dashboard.productsWithoutCost) }}
                    <a href="/products?purchase-price=missing">{{ t('dashboard.checks.seeWithoutCost') }}</a>
                </span>
            </p>
            <p v-if="dashboard.productsLowOnStock + dashboard.productsOutOfStock > 0" class="dashboard-page__check" role="status">
                <PackageMinus size="1rem" aria-hidden="true" />
                <span>
                    <template v-if="dashboard.productsOutOfStock > 0">{{ `${t('dashboard.checks.outOfStock', dashboard.productsOutOfStock)} ` }}</template>
                    <template v-if="dashboard.productsLowOnStock > 0">{{ `${t('dashboard.checks.lowOnStock', dashboard.productsLowOnStock)} ` }}</template>
                    <a href="/products?stock=low">{{ t('dashboard.checks.seeLowOnStock') }}</a>
                </span>
            </p>
            <ResultReceipt :title="t('dashboard.receipt.title', { year: dashboard.year })" :turnover="dashboard.total.turnover" :lines="receiptLines" :result="dashboard.total.result" result-test="year-result">
                <template #summary>
                    <p class="dashboard-page__summary">
                        {{ t('dashboard.summary', { orders: t('dashboard.orderCount', dashboard.total.orderCount), events: t('dashboard.eventCount', dashboard.events.length) }) }}
                    </p>
                </template>
            </ResultReceipt>

            <BaseCard :title="t('dashboard.byMonth')">
                <MonthlyResultChart :months="dashboard.months" />
            </BaseCard>

            <div class="dashboard-page__pair">
                <BaseCard :title="t('dashboard.events.title')">
                    <ResultBars v-if="eventBars.length" :items="eventBars" :label="t('dashboard.events.label')" />
                    <EmptyState v-else>{{ t('dashboard.events.empty', { year: dashboard.year }) }}</EmptyState>
                </BaseCard>
                <BaseCard :title="t('dashboard.bestSellers.title')">
                    <BestSellers v-if="dashboard.products.length" :products="dashboard.products" :types="dashboard.types" :type-colors="typeColors" />
                    <EmptyState v-else>{{ t('dashboard.bestSellers.empty', { year: dashboard.year }) }}</EmptyState>
                </BaseCard>
            </div>

            <BaseCard :title="t('dashboard.monthlyDetail')">
                <ResultsTable
                    :rows="dashboard.months"
                    :label-header="t('dashboard.month')"
                    :label="(row) => monthName(row.month)"
                    :total="dashboard.total"
                    collapsible
                />
                <p class="dashboard-page__note">{{ t('dashboard.expensesNote') }}</p>
            </BaseCard>

            <BaseCard v-if="dashboard.byYear.length > 1" :title="t('dashboard.yearComparison')">
                <ResultsTable :rows="dashboard.byYear" :label-header="t('dashboard.year')" :label="(row) => String(row.year)" />
            </BaseCard>
        </div>
    </AppLayout>
</template>

<style scoped>
.dashboard-page__planning { margin-bottom: var(--space-5); }
.dashboard-page { display: flex; flex-direction: column; gap: var(--space-5); }
.dashboard-page__pair { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(22rem, 100%), 1fr)); gap: var(--space-5); align-items: start; }
.dashboard-page__check {
    display: flex;
    align-items: flex-start;
    gap: var(--space-2);
    margin: 0;
    padding: var(--space-3) var(--space-4);
    border: 0.0625rem solid var(--color-warning);
    border-radius: var(--radius);
    background: var(--color-surface);
    color: var(--color-text);
}

.dashboard-page__check svg { flex-shrink: 0; margin-top: 0.1875rem; color: var(--color-warning); }
.dashboard-page__summary { color: var(--color-muted); }
.dashboard-page__welcome {
    max-width: 36rem;
    padding: var(--space-6);
    background: var(--color-surface);
    border: 0.0625rem solid var(--color-border);
    border-radius: var(--radius);
}

.dashboard-page__welcome-title { margin: 0 0 var(--space-2); }
.dashboard-page__welcome-text { margin: 0 0 var(--space-5); color: var(--color-muted); }
.dashboard-page__welcome-actions { display: flex; flex-wrap: wrap; gap: var(--space-2); }
.dashboard-page__note { margin: var(--space-3) 0 0; color: var(--color-muted); font-size: var(--font-size-md); }
</style>
