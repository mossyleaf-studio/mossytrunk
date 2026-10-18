<script setup>
import { computed } from 'vue';
import { I18nT, useI18n } from 'vue-i18n';
import { CircleCheck } from '@lucide/vue';
import BaseButton from '../ui/BaseButton.vue';
import ConfirmButton from '../ui/ConfirmButton.vue';
import MoneyAmount from '../ui/MoneyAmount.vue';
import Notice from '../ui/Notice.vue';
import StatusBadge from '../ui/StatusBadge.vue';
import { formatDate, formatDateTime, fromToday } from '../../composables/useDate.js';
import { formatPercent } from '../../composables/useMoney.js';
import { PERIOD_STATUSES, periodLabel, periodStatusLabel } from '../../composables/useAccounting.js';

const props = defineProps({
    period: { type: Object, required: true },
    rate: { type: Number, required: true },
    saving: { type: Boolean, default: false },
});
const emit = defineEmits(['declare', 'withdraw']);
const { t } = useI18n();

const status = computed(() => PERIOD_STATUSES[props.period.status]);
const declarable = computed(() => ['due', 'late', 'changed'].includes(props.period.status));
const payments = computed(() => ['card', 'cash', 'mixed', 'none']
    .filter((method) => props.period.payments[method])
    .map((method) => ({ method, label: method === 'none' ? t('accounting.slip.noPayment') : t(`orders.payment.${method}`), turnover: props.period.payments[method] })));
const over = computed(() => !['current', 'upcoming', 'inactive'].includes(props.period.status));
</script>

<template>
    <section class="declaration-slip" :aria-label="t('accounting.slip.label', { period: periodLabel(period) })">
        <header class="declaration-slip__header">
            <div>
                <h2 class="declaration-slip__period">{{ periodLabel(period) }}</h2>
                <p class="declaration-slip__dates">{{ t('accounting.slip.dates', { from: formatDate(period.start), to: formatDate(period.end) }) }}</p>
            </div>
            <StatusBadge :tone="status.tone">{{ periodStatusLabel(period.status) }}</StatusBadge>
        </header>

        <div class="declaration-slip__line">
            <span class="declaration-slip__box">
                <span class="declaration-slip__box-label">{{ t('accounting.slip.box') }}</span>
                <span class="declaration-slip__box-hint">{{ t('accounting.slip.boxHint') }}</span>
            </span>
            <span class="declaration-slip__amount"><MoneyAmount :cents="period.turnover" /></span>
        </div>

        <dl v-if="payments.length" class="declaration-slip__facts" :aria-label="t('accounting.slip.payments')">
            <div v-for="payment in payments" :key="payment.method"><dt>{{ payment.label }}</dt><dd><MoneyAmount :cents="payment.turnover" /></dd></div>
        </dl>

        <dl class="declaration-slip__facts">
            <div><dt>{{ t('accounting.slip.orders') }}</dt><dd>{{ period.orderCount }}</dd></div>
            <div><dt>{{ t('accounting.slip.contribution', { rate: formatPercent(rate / 100, 2) }) }}</dt><dd><MoneyAmount :cents="period.contribution" /></dd></div>
            <div>
                <dt>{{ t('accounting.slip.deadline') }}</dt>
                <dd>{{ formatDate(period.deadline) }} <span class="declaration-slip__relative">({{ fromToday(period.deadline) }})</span></dd>
            </div>
        </dl>

        <Notice v-if="period.status === 'changed'" role="status" class="declaration-slip__notice">
            <I18nT keypath="accounting.slip.changed">
                <template #amount><MoneyAmount :cents="period.declaredTurnover" /></template>
                <template #date>{{ formatDateTime(period.declaredAt) }}</template>
            </I18nT>
        </Notice>
        <p v-else-if="period.status === 'declared'" class="declaration-slip__done">
            <CircleCheck size="1rem" aria-hidden="true" /> {{ t('accounting.slip.declaredOn', { date: formatDateTime(period.declaredAt) }) }}
        </p>
        <p v-else-if="period.status === 'inactive'" class="declaration-slip__hint">{{ t('accounting.slip.inactive') }}</p>
        <p v-else-if="!over" class="declaration-slip__hint">{{ t('accounting.slip.current') }}</p>
        <p v-else-if="period.turnover === 0" class="declaration-slip__hint">{{ t('accounting.slip.noSales') }}</p>

        <footer class="actions-row">
            <ConfirmButton
                v-if="period.status === 'declared' || period.status === 'changed'"
                variant="ghost"
                :label="t('accounting.slip.withdraw')"
                :confirm-label="t('accounting.slip.withdrawConfirm')"
                :message="t('accounting.slip.withdrawMessage')"
                @confirm="emit('withdraw', period)"
            />
            <BaseButton v-if="declarable" :loading="saving" @click="emit('declare', period)">
                {{ t('accounting.slip.declare', period.orderCount) }}
            </BaseButton>
        </footer>
    </section>
</template>

<style scoped>
.declaration-slip {
    display: flex;
    flex-direction: column;
    gap: var(--space-4);
    padding: var(--space-5);
    border: 0.0625rem solid var(--color-border);
    border-left: 0.25rem solid var(--color-accent);
    border-radius: var(--radius);
    background: var(--color-surface);
}

.declaration-slip__header { display: flex; align-items: flex-start; justify-content: space-between; gap: var(--space-3); }
.declaration-slip__period { margin: 0; font-size: 1.5rem; }
.declaration-slip__dates { margin: var(--space-1) 0 0; color: var(--color-muted); font-size: var(--font-size-md); }

.declaration-slip__line {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-4);
    padding: var(--space-4);
    border: 0.0625rem dashed var(--color-border-strong);
    border-radius: var(--radius);
    background: var(--color-bg);
}

.declaration-slip__box { display: flex; flex-direction: column; }
.declaration-slip__box-label { font-weight: 600; }
.declaration-slip__box-hint { color: var(--color-muted); font-size: var(--font-size-md); }
.declaration-slip__amount { font-family: var(--font-display); font-size: 2.5rem; line-height: 1; color: var(--color-ink); font-variant-numeric: tabular-nums; }

.declaration-slip__facts { display: flex; gap: var(--space-6); flex-wrap: wrap; margin: 0; }
.declaration-slip__facts dt { color: var(--color-muted); font-size: var(--font-size-sm); }
.declaration-slip__facts dd { margin: 0; font-weight: 600; font-variant-numeric: tabular-nums; }
.declaration-slip__relative { color: var(--color-muted); font-weight: 400; }

.declaration-slip__notice { font-size: var(--font-size-md); }
.declaration-slip__done { display: flex; align-items: center; gap: var(--space-2); margin: 0; color: var(--color-accent-strong); }
.declaration-slip__hint { margin: 0; color: var(--color-muted); font-size: var(--font-size-md); }

@media (max-width: 40rem) {
    .declaration-slip__line { flex-direction: column; align-items: flex-start; }
    .declaration-slip__amount { font-size: 2rem; }
}
</style>
