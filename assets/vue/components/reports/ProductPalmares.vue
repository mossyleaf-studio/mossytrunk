<script setup>
import { computed, ref } from 'vue';
import { ToggleGroupItem } from 'reka-ui';
import { useI18n } from 'vue-i18n';
import ChoiceGroup from '../ui/ChoiceGroup.vue';
import EmptyState from '../ui/EmptyState.vue';
import MoneyAmount from '../ui/MoneyAmount.vue';
import { formatCents, formatRatio } from '../../composables/useMoney.js';
import { normalize } from '../../composables/useSearch.js';

const props = defineProps({
    products: { type: Array, required: true },
    selectedId: { type: String, default: '' },
});
const emit = defineEmits(['select']);
const { t } = useI18n();

const FIRST = 15;
const SORTS = ['revenue', 'units', 'discount', 'margin'];
const sort = ref('revenue');
const showAll = ref(false);
const hovered = ref(null);
const search = ref('');

const byRank = computed(() => [...props.products].sort((a, b) => b[sort.value] - a[sort.value] || b.revenue - a.revenue));
const rankOf = computed(() => new Map(byRank.value.map((product, index) => [product.id, index + 1])));
const ranked = computed(() => {
    const needle = normalize(search.value.trim());
    return needle === '' ? byRank.value : byRank.value.filter((product) => normalize(`${product.name} ${product.typeName}`).includes(needle));
});
const shown = computed(() => (showAll.value || search.value.trim() !== '' ? ranked.value : ranked.value.slice(0, FIRST)));
const lengthOf = (product) => (sort.value === 'revenue' ? product.gross : Math.max(0, product[sort.value]));
const widest = computed(() => Math.max(1, ...props.products.map(lengthOf)));
const width = (product) => `${(lengthOf(product) / widest.value) * 100}%`;
const revenueShare = (product) => (product.gross ? (product.revenue / product.gross) * 100 : 0);
const fullPriceShare = (product) => (product.units ? (product.fullPriceUnits / product.units) * 100 : 0);
const meta = (product) => {
    const units = t('reports.palmares.units', { count: product.units }, product.units);
    if (sort.value === 'units') return product.discountedUnits > 0 ? `${units}, ${t('reports-palmares.discounted', { count: product.discountedUnits }, product.discountedUnits)}` : units;
    return product.discount > 0 ? `${units}, ${t('reports.palmares.discountShare', { share: formatRatio(product.discount, product.gross, 1) })}` : units;
};
const figure = (product) => (sort.value === 'units' ? t('reports.palmares.units', { count: product.units }, product.units) : formatCents(product[sort.value]));
</script>

<template>
    <section class="palmares" aria-labelledby="palmares-title">
        <header class="palmares__header">
            <h2 id="palmares-title" class="palmares__title">{{ t('reports.palmares.title') }}</h2>
            <ChoiceGroup v-model="sort" class="palmares__sorts" :aria-label="t('reports.palmares.sortBy')">
                <ToggleGroupItem v-for="key in SORTS" :key="key" :value="key" class="palmares__sort">{{ t(`reports.palmares.sorts.${key}`) }}</ToggleGroupItem>
            </ChoiceGroup>
        </header>
        <input v-model="search" class="control control--compact palmares__search" type="search" :placeholder="t('reports.palmares.search')" :aria-label="t('reports.palmares.search')">
        <p v-if="sort === 'revenue'" class="palmares__legend">
            <span class="palmares__swatch palmares__swatch--revenue" aria-hidden="true" />{{ t('reports.palmares.legendRevenue') }}
            <span class="palmares__swatch palmares__swatch--discount" aria-hidden="true" />{{ t('reports.palmares.legendDiscount') }}
        </p>

        <p v-else-if="sort === 'units'" class="palmares__legend">
            <span class="palmares__swatch palmares__swatch--revenue" aria-hidden="true" />{{ t('reports-palmares.legendFullPrice') }}
            <span class="palmares__swatch palmares__swatch--discount" aria-hidden="true" />{{ t('reports-palmares.legendDiscounted') }}
            <span class="palmares__definition">{{ t('reports.analysis.how.discounted') }}</span>
        </p>

        <ol class="palmares__list">
            <li v-for="product in shown" :key="product.id" class="palmares__item">
                <button
                    type="button"
                    :class="['palmares__row', { 'palmares__row--selected': product.id === selectedId }]"
                    :aria-pressed="product.id === selectedId"
                    :aria-label="t('reports.palmares.select', { name: product.name })"
                    @click="emit('select', product)"
                    @mouseenter="hovered = product.id"
                    @mouseleave="hovered = null"
                    @focus="hovered = product.id"
                    @blur="hovered = null"
                >
                    <span class="palmares__rank">{{ rankOf.get(product.id) }}</span>
                    <span class="palmares__name">
                        {{ product.name }}
                        <span class="palmares__meta">{{ meta(product) }}</span>
                    </span>
                    <span class="palmares__track" aria-hidden="true">
                        <span v-if="sort === 'revenue'" class="palmares__bar" :style="{ width: width(product) }">
                            <span class="palmares__revenue" :style="{ width: `${revenueShare(product)}%` }" />
                            <span v-if="product.discount > 0" class="palmares__discount" />
                        </span>
                        <span v-else-if="sort === 'discount'" class="palmares__bar" :style="{ width: width(product) }">
                            <span v-if="product.discount > 0" class="palmares__discount palmares__discount--alone" />
                        </span>
                        <span v-else-if="sort === 'units'" class="palmares__bar" :style="{ width: width(product) }">
                            <span v-if="product.fullPriceUnits > 0" class="palmares__revenue" :style="{ width: `${fullPriceShare(product)}%` }" />
                            <span v-if="product.discountedUnits > 0" :class="['palmares__discount', { 'palmares__discount--alone': product.fullPriceUnits === 0 }]" />
                        </span>
                        <span v-else class="palmares__bar" :style="{ width: width(product) }">
                            <span class="palmares__revenue palmares__revenue--alone" />
                        </span>
                    </span>
                    <span class="palmares__figure">{{ figure(product) }}</span>
                </button>
                <div v-if="hovered === product.id" class="palmares__tooltip" role="tooltip">
                    <strong>{{ product.name }}</strong>
                    <span>{{ t('reports-palmares.tooltip.fullPriceUnits', { count: product.fullPriceUnits }, product.fullPriceUnits) }}</span>
                    <span>{{ t('reports-palmares.tooltip.discountedUnits', { count: product.discountedUnits }, product.discountedUnits) }}</span>
                    <span>{{ t('reports.palmares.tooltip.gross') }} <MoneyAmount :cents="product.gross" /></span>
                    <span v-if="product.discount > 0">{{ t('reports.palmares.tooltip.discount') }} −<MoneyAmount :cents="product.discount" /> ({{ formatRatio(product.discount, product.gross, 1) }})</span>
                    <span>{{ t('reports.palmares.tooltip.revenue') }} <MoneyAmount :cents="product.revenue" /></span>
                    <span>{{ t('reports.palmares.tooltip.margin') }} <MoneyAmount :cents="product.margin" /><template v-if="product.unknownCost"> ({{ t('reports.palmares.tooltip.unknownCost') }})</template></span>
                    <span>{{ t('reports.palmares.tooltip.onHand') }} {{ product.onHand }}</span>
                </div>
            </li>
        </ol>
        <EmptyState v-if="shown.length === 0" inline>{{ t('reports.palmares.noMatch') }}</EmptyState>
        <button v-if="products.length > FIRST && search.trim() === ''" type="button" class="palmares__more" @click="showAll = !showAll">
            {{ showAll ? t('reports.palmares.showLess') : t('reports.palmares.showAll', { count: products.length }) }}
        </button>
    </section>
</template>

<style scoped>
.palmares { display: flex; flex-direction: column; gap: var(--space-3); min-width: 0; }
.palmares__header { display: flex; flex-wrap: wrap; align-items: baseline; justify-content: space-between; gap: var(--space-3); }
.palmares__title { margin: 0; font-size: 1.35rem; }
.palmares__sorts { display: flex; flex-wrap: wrap; gap: var(--space-3); }

.palmares__sort {
    padding: var(--space-1) 0;
    border: none;
    border-bottom: 0.125rem solid transparent;
    background: none;
    color: var(--color-muted);
    font: inherit;
    font-size: var(--font-size-sm);
    cursor: pointer;
    transition: color var(--transition), border-color var(--transition);
}

.palmares__sort:hover { color: var(--color-ink); }
.palmares__sort[data-state='on'] { border-bottom-color: var(--color-ink); color: var(--color-ink); font-weight: 600; }

.palmares__search { width: 100%; max-width: 20rem; }

.palmares__legend { display: flex; flex-wrap: wrap; align-items: center; gap: var(--space-1) var(--space-2); margin: 0; color: var(--color-muted); font-size: var(--font-size-sm); }
.palmares__definition { flex-basis: 100%; }
.palmares__swatch { display: inline-block; width: 0.75rem; height: 0.75rem; border-radius: 0.125rem; }
.palmares__swatch + .palmares__swatch { margin-left: var(--space-2); }
.palmares__swatch--revenue { background: var(--color-accent); }

.palmares__swatch--discount,
.palmares__discount {
    background: repeating-linear-gradient(135deg, var(--color-accent) 0 0.125rem, var(--color-accent-soft) 0.125rem 0.3125rem);
}

.palmares__list { display: flex; flex-direction: column; margin: 0; padding: 0; list-style: none; }
.palmares__item { position: relative; }

.palmares__row {
    display: grid;
    grid-template-columns: 1.75rem minmax(0, 13rem) minmax(0, 1fr) 6.5rem;
    align-items: center;
    gap: var(--space-3);
    width: 100%;
    padding: var(--space-2) var(--space-2);
    border: none;
    border-left: 0.125rem solid transparent;
    border-radius: 0 var(--radius) var(--radius) 0;
    background: none;
    color: inherit;
    font: inherit;
    text-align: left;
    cursor: pointer;
    transition: background var(--transition), border-color var(--transition);
}

.palmares__row:hover { background: var(--color-surface); }
.palmares__row:focus-visible { outline-offset: -0.125rem; }
.palmares__row--selected { border-left-color: var(--color-accent); background: var(--color-surface); }
.palmares__rank { color: var(--color-subtle); font-family: var(--font-display); font-size: 1.1rem; font-variant-numeric: tabular-nums; text-align: right; }
.palmares__name { display: flex; flex-direction: column; min-width: 0; overflow: hidden; font-size: var(--font-size); text-overflow: ellipsis; white-space: nowrap; }
.palmares__meta { overflow: hidden; color: var(--color-muted); font-size: var(--font-size-xs); text-overflow: ellipsis; }
.palmares__track { display: block; height: 0.875rem; }
.palmares__bar { display: flex; height: 100%; min-width: 0.25rem; gap: 0.125rem; }
.palmares__revenue { flex: none; height: 100%; background: var(--color-accent); border-radius: 0.125rem 0 0 0.125rem; }
.palmares__discount { flex: 1 1 auto; height: 100%; border-radius: 0 var(--radius-sm) var(--radius-sm) 0; }
.palmares__revenue:only-child,
.palmares__revenue--alone { flex: 1 1 auto; border-radius: 0.125rem var(--radius-sm) var(--radius-sm) 0.125rem; }
.palmares__discount--alone { border-radius: 0.125rem var(--radius-sm) var(--radius-sm) 0.125rem; box-shadow: inset 0 0 0 0.0625rem var(--color-accent); }
.palmares__figure { font-variant-numeric: tabular-nums; font-weight: 600; text-align: right; white-space: nowrap; }

.palmares__tooltip {
    position: absolute;
    top: 100%;
    right: 0;
    z-index: 3;
    display: flex;
    flex-direction: column;
    gap: 0.125rem;
    padding: var(--space-2) var(--space-3);
    border-radius: var(--radius);
    background: var(--color-ink);
    color: var(--color-surface);
    font-size: var(--font-size-sm);
    white-space: nowrap;
    pointer-events: none;
}

.palmares__more { align-self: flex-start; padding: 0; border: none; border-bottom: 0.0625rem dotted currentColor; background: none; color: var(--color-muted); font: inherit; font-size: var(--font-size-md); cursor: pointer; }

@media (max-width: 40rem) {
    .palmares__row { grid-template-columns: 1.5rem minmax(0, 1fr) 5.5rem; }
    .palmares__track { grid-column: 2 / -1; grid-row: 2; }
}
</style>
