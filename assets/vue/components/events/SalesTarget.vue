<script setup>
import { useI18n } from 'vue-i18n';
import MoneyAmount from '../ui/MoneyAmount.vue';
import { formatCents, formatPercent } from '../../composables/useMoney.js';

defineProps({
    target: { type: Object, required: true },
});

const { t } = useI18n();
</script>

<template>
    <section class="sales-target" aria-labelledby="sales-target-title">
        <h3 id="sales-target-title" class="sales-target__title">{{ t('events.target.title') }}</h3>
        <dl v-if="target.total !== null" class="sales-target__figures">
            <div class="sales-target__figure">
                <dt>{{ t('events.target.perDay', target.days) }}</dt>
                <dd data-test="target-per-day"><MoneyAmount :cents="target.perDay" /></dd>
            </div>
            <div class="sales-target__figure">
                <dt>{{ t('events.target.total') }}</dt>
                <dd data-test="target-total"><MoneyAmount :cents="target.total" /></dd>
            </div>
            <div v-if="target.usualPerDay !== null" class="sales-target__figure sales-target__figure--usual">
                <dt>{{ t('events.target.usualPerDay') }}</dt>
                <dd><MoneyAmount :cents="target.usualPerDay" /></dd>
            </div>
        </dl>
        <p v-else class="sales-target__hint">{{ t('events.target.unreachable') }}</p>
        <p class="sales-target__hint">
            {{ t('events.target.covers', { expenses: formatCents(target.expenses), urssaf: formatPercent(target.urssafRate / 100, 2) }) }}
            <template v-if="target.basedOnEvents > 0">{{ t('events.target.costs', { rate: formatPercent(target.costRate / 100, 1), events: t('events.target.events', target.basedOnEvents) }) }}</template>
            <template v-else>{{ t('events.target.noHistory') }}</template>
        </p>
    </section>
</template>

<style scoped>
.sales-target { margin-top: var(--space-5); padding-top: var(--space-5); border-top: 0.0625rem solid var(--color-border); }
.sales-target__title { margin: 0 0 var(--space-3); font-size: 1.2rem; color: var(--color-muted); }
.sales-target__figures { display: flex; flex-wrap: wrap; gap: var(--space-3) var(--space-6); margin: 0 0 var(--space-3); }
.sales-target__figure dt { font-size: var(--font-size-xs); text-transform: uppercase; letter-spacing: 0.05em; color: var(--color-muted); }
.sales-target__figure dd { margin: var(--space-1) 0 0; font-family: var(--font-display); font-size: 1.75rem; line-height: 1.1; }
.sales-target__figure--usual dd { font-family: inherit; font-size: var(--font-size); color: var(--color-muted); }
.sales-target__hint { margin: 0; font-size: var(--font-size-md); color: var(--color-muted); }
</style>
