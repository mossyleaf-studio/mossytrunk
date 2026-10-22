<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import MoneyAmount from '../ui/MoneyAmount.vue';
import ResultReceipt from '../reporting/ResultReceipt.vue';
import OrderRecap from './OrderRecap.vue';
import SalesTarget from './SalesTarget.vue';
import { formatPercent } from '../../composables/useMoney.js';
import { resultLines } from '../../composables/useResultLines.js';

const props = defineProps({
    report: { type: Object, required: true },
    eventId: { type: String, required: true },
    upcoming: { type: Boolean, default: false },
    target: { type: Object, default: null },
    typeColors: { type: Map, required: true },
});

const { t } = useI18n();

const lines = computed(() => resultLines(props.report.total, {
    label: (key) => t(`events.report.${key}`),
    urssafHint: formatPercent(props.report.urssaf.rate / 100, 2),
    turnoverOpen: props.report.orders.count > 0,
}));
</script>

<template>
    <section v-if="upcoming && report.orders.count === 0" class="event-report event-report--upcoming" :aria-label="t('events.report.committedExpenses')">
        <h2 class="event-report__title">{{ t('events.report.committedExpenses') }}</h2>
        <p class="event-report__committed"><MoneyAmount :cents="report.total.expenses" /></p>
        <p class="event-report__summary">{{ t('events.report.noSales') }}</p>
        <SalesTarget v-if="target && target.expenses > 0" :target="target" />
        <div class="event-report__aside"><slot name="aside" /></div>
    </section>
    <ResultReceipt
        v-else
        class="event-report"
        :title="t('events.report.result')"
        :turnover="report.total.turnover"
        :lines="lines"
        :result="report.total.result"
        result-test="event-result"
    >
        <template #summary>
            <p class="event-report__summary">
                <template v-if="report.orders.count > 0">{{ t('events.report.orderCount', report.orders.count) }}</template>
                <template v-else>{{ t('events.report.noOrders') }}</template>
            </p>
            <div class="event-report__aside"><slot name="aside" /></div>
        </template>

        <template #detail-turnover>
            <dl class="event-report__figures">
                <div class="event-report__line"><dt>{{ t('events.report.grossSales') }}</dt><dd><MoneyAmount :cents="report.orders.grossSales" /></dd></div>
                <div class="event-report__line"><dt>{{ t('events.report.discountsGiven') }}</dt><dd><MoneyAmount :cents="report.orders.discounts ? -report.orders.discounts : 0" /></dd></div>
            </dl>
            <OrderRecap :groups="report.orders.groups" :type-colors="typeColors" />
            <p class="event-report__more"><a :href="`/orders?event=${eventId}`">{{ t('events.report.seeOrders') }}</a></p>
        </template>
    </ResultReceipt>
</template>

<style scoped>
.event-report__summary { margin: 0; color: var(--color-muted); }
.event-report__aside { margin-top: var(--space-6); }
.event-report__aside:empty { display: none; }

.event-report--upcoming {
    padding: var(--space-6);
    background: var(--color-surface);
    border: 0.0625rem solid var(--color-border);
    border-radius: var(--radius);
}

.event-report__title { margin: 0; font-size: 1.2rem; color: var(--color-muted); }
.event-report__committed { margin: var(--space-1) 0; font-family: var(--font-display); font-size: 2.5rem; line-height: 1.1; }

.event-report__figures { display: flex; flex-direction: column; gap: var(--space-1); margin: 0 0 var(--space-2); padding: 0; }
.event-report__line { display: flex; justify-content: space-between; gap: var(--space-3); font-size: var(--font-size-md); }
.event-report__line dd { margin: 0; font-variant-numeric: tabular-nums; }

.event-report__more { margin: var(--space-2) 0 0; font-size: var(--font-size-md); }
</style>
