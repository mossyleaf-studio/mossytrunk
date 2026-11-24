<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { formatCents } from '../../composables/useMoney.js';
import { POPULAR_QUADRANTS, formatRate } from '../../composables/useProductAnalysis.js';

const props = defineProps({
    product: { type: Object, required: true },
    analysis: { type: Object, required: true },
    type: { type: Object, default: null },
});
const { t } = useI18n();

const PROFITABLE = ['star', 'puzzle'];
const TOO_EARLY = { too_few_orders: 'tooFew', arrived_late: 'late' };

const judged = computed(() => props.product.quadrant !== null);
const quadrantOutcome = (positives, yes, no) => {
    if (!judged.value) return t('reports-sheet.why.unjudged');
    return t(`reports-sheet.why.${positives.includes(props.product.quadrant) ? yes : no}`);
};

const timeOutcome = () => {
    const early = TOO_EARLY[props.product.reason];
    if (early) return t(`reports-sheet.why.time.${early}`);
    return props.product.reason || judged.value ? t('reports-sheet.why.time.enough') : null;
};

const time = () => ({
    key: 'time',
    figure: t('reports-sheet.why.time.figure', { count: props.product.ordersSinceArrival }, props.product.ordersSinceArrival),
    limit: t('reports-sheet.why.time.limit', { enough: props.analysis.rules.enoughOrders, total: props.analysis.orders }),
    outcome: timeOutcome(),
});

const pace = () => ({
    key: 'pace',
    figure: props.product.per100Orders === null ? t('reports-sheet.why.pace.none') : t('reports-sheet.why.pace.figure', { units: props.product.units, exposure: props.product.exposure, pace: formatRate(props.product.per100Orders) }),
    limit: (props.type?.popularFrom ?? null) === null
        ? t('reports-sheet.why.pace.noLimit')
        : t('reports-sheet.why.pace.limit', { limit: formatRate(props.type.popularFrom), percent: props.analysis.rules.popularPercent, type: props.type.name, average: formatRate(props.type.averagePace) }),
    outcome: quadrantOutcome(POPULAR_QUADRANTS, 'pace.popular', 'pace.unpopular'),
});

const margin = () => ({
    key: 'margin',
    figure: props.product.unitMargin === null ? t('reports-sheet.why.margin.none') : t('reports-sheet.why.margin.figure', { margin: formatCents(props.product.unitMargin) }),
    limit: (props.type?.unitMarginFrom ?? null) === null
        ? t('reports-sheet.why.margin.noLimit')
        : t('reports-sheet.why.margin.limit', { limit: formatCents(props.type.unitMarginFrom), percent: props.analysis.rules.profitablePercent, average: formatCents(props.type.averageUnitMargin) }),
    outcome: quadrantOutcome(PROFITABLE, 'margin.profitable', 'margin.unprofitable'),
});

const rank = computed(() => props.analysis.products.findIndex((other) => other.id === props.product.id) + 1);
const abcClass = () => ({
    key: 'class',
    figure: t('reports-sheet.why.class.figure', { class: t(`reports.analysis.classes.${props.product.class}`), rank: rank.value, count: props.analysis.products.length }),
    limit: t('reports-sheet.why.class.limit', { a: props.analysis.rules.classAPercent, b: props.analysis.rules.classBPercent }),
    outcome: t(`reports-sheet.why.class.${props.product.class}`),
});

const stock = () => ({
    key: 'stock',
    figure: props.product.coverOrders === null || props.product.stock === 'out'
        ? t('reports-sheet.why.stock.figure', { count: props.product.onHand })
        : t('reports-sheet.why.stock.cover', { count: props.product.onHand, cover: props.product.coverOrders }),
    limit: props.analysis.busiestEvent ? t('reports-sheet.why.stock.limit', { orders: props.analysis.busiestEvent.orders, event: props.analysis.busiestEvent.name }) : null,
    outcome: t(`reports-sheet.why.stock.${props.product.stock}`),
});

const checks = computed(() => [time(), pace(), margin(), abcClass(), ...(props.product.stock ? [stock()] : [])]);
</script>

<template>
    <section class="sheet-checks" aria-labelledby="sheet-checks-title">
        <h3 id="sheet-checks-title" class="subheading">{{ t('reports-sheet.why.title') }}</h3>
        <ul class="sheet-checks__list">
            <li v-for="check in checks" :key="check.key" class="sheet-checks__check">
                <span class="eyebrow sheet-checks__label">{{ t(`reports-sheet.why.${check.key}.label`) }}</span>
                <span v-if="check.outcome" class="sheet-checks__outcome">{{ check.outcome }}</span>
                <span class="sheet-checks__figure">{{ check.figure }}</span>
                <span v-if="check.limit" class="sheet-checks__limit">{{ check.limit }}</span>
            </li>
        </ul>
    </section>
</template>

<style scoped>
.sheet-checks { display: flex; flex-direction: column; gap: var(--space-2); padding-top: var(--space-4); border-top: 0.0625rem solid var(--color-border); }
.sheet-checks__list { display: flex; flex-direction: column; margin: 0; padding: 0; list-style: none; }

.sheet-checks__check {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 0.125rem var(--space-3);
    padding: var(--space-2) 0;
    border-bottom: 0.0625rem solid var(--color-border);
}

.sheet-checks__check:last-child { border-bottom: none; }
.sheet-checks__label { grid-column: 1; }
.sheet-checks__outcome { grid-column: 2; grid-row: 1; color: var(--color-ink); font-size: var(--font-size-sm); font-weight: 600; text-align: right; }
.sheet-checks__figure { grid-column: 1 / -1; color: var(--color-ink); font-size: var(--font-size); font-variant-numeric: tabular-nums; }
.sheet-checks__limit { grid-column: 1 / -1; color: var(--color-muted); font-size: var(--font-size-sm); line-height: 1.4; }
</style>
