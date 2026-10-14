<script setup>
import { useI18n } from 'vue-i18n';
import { X } from '@lucide/vue';
import IconButton from '../ui/IconButton.vue';
import MoneyAmount from '../ui/MoneyAmount.vue';

defineProps({
    subtotal: { type: Number, required: true },
    discounts: { type: Array, required: true },
    shipping: { type: Number, default: 0 },
    total: { type: Number, required: true },
    linkRules: { type: Boolean, default: false },
    withdrawable: { type: Boolean, default: false },
});
const emit = defineEmits(['withdraw']);

const { t } = useI18n();
</script>

<template>
    <dl class="order-totals">
        <div class="order-totals__row">
            <dt>{{ t('orders.totals.subtotal') }}</dt>
            <dd><MoneyAmount :cents="subtotal" /></dd>
        </div>
        <TransitionGroup name="drop">
            <div v-for="discount in discounts" :key="discount.label" class="order-totals__row order-totals__row--discount">
                <dt>
                    <a v-if="linkRules && discount.ruleId" :href="`/discounts?rule=${discount.ruleId}`" class="order-totals__rule">{{ discount.label }}</a>
                    <template v-else>{{ discount.label }}</template>
                </dt>
                <dd class="order-totals__amount">
                    − <MoneyAmount :cents="discount.amount" />
                    <IconButton v-if="withdrawable && discount.manual" :icon="X" :label="t('orders.discounts.withdraw', { label: discount.label })" @click="emit('withdraw', discount)" />
                </dd>
            </div>
        </TransitionGroup>
        <div v-if="shipping > 0" class="order-totals__row">
            <dt>{{ t('orders.totals.shipping') }}</dt>
            <dd><MoneyAmount :cents="shipping" /></dd>
        </div>
        <div class="order-totals__row order-totals__row--total">
            <dt>{{ t('orders.totals.total') }}</dt>
            <dd><MoneyAmount :cents="total" data-test="order-total" /></dd>
        </div>
    </dl>
</template>

<style scoped>
.order-totals { display: flex; flex-direction: column; gap: var(--space-1); margin: 0; }
.order-totals__row { display: flex; justify-content: space-between; gap: var(--space-3); }
.order-totals__row dd { margin: 0; }
.order-totals__amount { display: inline-flex; align-items: center; gap: var(--space-1); }
.order-totals__row--discount { color: var(--color-success); }
.order-totals__rule { color: inherit; text-decoration: underline; text-underline-offset: 0.2em; }
.order-totals__rule:hover { text-decoration-thickness: 0.125rem; }
.order-totals__row--total {
    margin-top: var(--space-1);
    padding-top: var(--space-2);
    border-top: 0.0625rem solid var(--color-border);
    font-size: 1.1rem;
    font-weight: 700;
}
</style>
