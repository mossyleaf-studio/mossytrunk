<script setup>
import { computed } from 'vue';
import { TriangleAlert } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import MoneyAmount from '../ui/MoneyAmount.vue';
import { formatCents, formatRatio } from '../../composables/useMoney.js';

const props = defineProps({
    product: { type: Object, required: true },
});
const { t } = useI18n();

const sold = computed(() => t('reports-sheet.receipt.sold', { count: props.product.units }, props.product.units));
const split = computed(() => t('reports-sheet.receipt.split', {
    sold: sold.value,
    full: props.product.fullPriceUnits,
    discounted: t('reports-sheet.receipt.discounted', { count: props.product.discountedUnits }, props.product.discountedUnits),
}));
const fullPriceShare = computed(() => `${(props.product.fullPriceUnits / props.product.units) * 100}%`);
const discountDetail = computed(() => (props.product.discountedUnits === 0
    ? t('reports-sheet.receipt.noDiscount')
    : t('reports-sheet.receipt.discountDetail', {
        average: formatCents(Math.round(props.product.discount / props.product.discountedUnits)),
        share: formatRatio(props.product.discount, props.product.discountedGross, 1),
    })));
const unitMargin = computed(() => props.product.unitMargin ?? null);
</script>

<template>
    <section class="sheet-receipt" aria-labelledby="sheet-receipt-title">
        <h3 id="sheet-receipt-title" class="subheading">{{ t('reports-sheet.receipt.title') }}</h3>
        <p v-if="product.units === 0" class="sheet-receipt__nothing">{{ t('reports-sheet.receipt.nothing') }}</p>
        <template v-else>
            <p class="sheet-receipt__split">{{ split }}</p>
            <span class="sheet-receipt__bar" aria-hidden="true">
                <span v-if="product.fullPriceUnits > 0" class="sheet-receipt__full" :style="{ flexBasis: fullPriceShare }" />
                <span v-if="product.discountedUnits > 0" class="sheet-receipt__discounted" />
            </span>
            <p class="sheet-receipt__definition">{{ t('reports.analysis.how.fullPrice') }} {{ t('reports.analysis.how.discounted') }}</p>

            <dl class="sheet-receipt__lines">
                <div class="sheet-receipt__line">
                    <dt class="sheet-receipt__label"><span class="sheet-receipt__operator" aria-hidden="true" />{{ t('reports-sheet.receipt.gross') }}</dt>
                    <dd class="sheet-receipt__amount"><MoneyAmount :cents="product.gross" /></dd>
                </div>
                <div class="sheet-receipt__line">
                    <dt class="sheet-receipt__label"><span class="sheet-receipt__operator" aria-hidden="true">−</span>{{ t('reports-sheet.receipt.discount') }}</dt>
                    <dd class="sheet-receipt__amount"><MoneyAmount :cents="product.discount" /></dd>
                    <dd class="sheet-receipt__detail">{{ discountDetail }}</dd>
                </div>
                <div class="sheet-receipt__line sheet-receipt__line--total">
                    <dt class="sheet-receipt__label"><span class="sheet-receipt__operator" aria-hidden="true">=</span>{{ t('reports-sheet.receipt.revenue') }}</dt>
                    <dd class="sheet-receipt__amount"><MoneyAmount :cents="product.revenue" /></dd>
                </div>
                <div class="sheet-receipt__line">
                    <dt class="sheet-receipt__label"><span class="sheet-receipt__operator" aria-hidden="true">−</span>{{ t('reports-sheet.receipt.cost') }}</dt>
                    <dd class="sheet-receipt__amount"><MoneyAmount :cents="product.cost" /></dd>
                    <dd v-if="product.unknownCost" class="sheet-receipt__detail sheet-receipt__detail--warning">
                        <TriangleAlert size="0.875rem" aria-hidden="true" />{{ t('reports-sheet.receipt.unknownCost') }}
                    </dd>
                </div>
                <div class="sheet-receipt__line sheet-receipt__line--total">
                    <dt class="sheet-receipt__label"><span class="sheet-receipt__operator" aria-hidden="true">=</span>{{ t('reports-sheet.receipt.margin') }}</dt>
                    <dd class="sheet-receipt__amount"><MoneyAmount :cents="product.margin" /></dd>
                </div>
                <div v-if="unitMargin !== null" class="sheet-receipt__line">
                    <dt class="sheet-receipt__label"><span class="sheet-receipt__operator" aria-hidden="true">÷</span>{{ t('reports-sheet.receipt.unitMargin', { sold }) }}</dt>
                    <dd class="sheet-receipt__amount"><MoneyAmount :cents="unitMargin" /></dd>
                </div>
            </dl>
        </template>
    </section>
</template>

<style scoped>
.sheet-receipt { display: flex; flex-direction: column; gap: var(--space-2); padding-top: var(--space-4); border-top: 0.0625rem solid var(--color-border); }
.sheet-receipt__nothing { margin: 0; color: var(--color-muted); font-size: var(--font-size-md); }
.sheet-receipt__split { margin: 0; color: var(--color-ink); font-size: var(--font-size); font-variant-numeric: tabular-nums; }
.sheet-receipt__definition { margin: 0; color: var(--color-muted); font-size: var(--font-size-sm); line-height: 1.4; }
.sheet-receipt__bar { display: flex; gap: 0.125rem; height: 0.75rem; }
.sheet-receipt__full { flex: none; background: var(--color-accent); border-radius: var(--radius-sm); }

.sheet-receipt__discounted {
    flex: 1 1 auto;
    border-radius: var(--radius-sm);
    background: repeating-linear-gradient(135deg, var(--color-accent) 0 0.125rem, var(--color-accent-soft) 0.125rem 0.3125rem);
}

.sheet-receipt__lines { display: flex; flex-direction: column; margin: var(--space-2) 0 0; }

.sheet-receipt__line {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 0 var(--space-3);
    padding: var(--space-1) 0;
    font-size: var(--font-size-md);
}

.sheet-receipt__label { display: flex; gap: var(--space-2); color: var(--color-ink); }
.sheet-receipt__amount { margin: 0; text-align: right; font-variant-numeric: tabular-nums; }
.sheet-receipt__line--total { border-top: 0.0625rem solid var(--color-border-strong); font-weight: 600; }
.sheet-receipt__operator { flex: none; width: 0.75rem; color: var(--color-muted); text-align: center; }
.sheet-receipt__detail { grid-column: 1 / -1; margin: 0; padding-left: calc(0.75rem + var(--space-2)); color: var(--color-muted); font-size: var(--font-size-sm); }
.sheet-receipt__detail--warning { display: flex; align-items: center; gap: var(--space-1); color: var(--color-warning); }
</style>
