<script setup>
import { computed, ref, useId } from 'vue';
import { ToggleGroupItem } from 'reka-ui';
import { useI18n } from 'vue-i18n';
import BaseCard from '../ui/BaseCard.vue';
import ChoiceGroup from '../ui/ChoiceGroup.vue';
import EmptyState from '../ui/EmptyState.vue';
import FormDisclosure from '../ui/FormDisclosure.vue';
import Notice from '../ui/Notice.vue';
import StockStateBadge from './StockStateBadge.vue';
import { queryText } from '../../composables/useQueryState.js';

const props = defineProps({
    analysis: { type: Object, required: true },
    selectedId: { type: String, default: '' },
});
const emit = defineEmits(['select']);
const { t, n } = useI18n();

const FIRST = 15;
const SHORTEST = 'shortest';
const compare = (a, b) => (a === b ? 0 : a < b ? -1 : 1);
const coverOf = (product) => product.coverOrders ?? Infinity;
const ORDERS = {
    shortest: (a, b) => compare(coverOf(a), coverOf(b)),
    longest: (a, b) => compare(coverOf(b), coverOf(a)),
    onHand: (a, b) => b.onHand - a.onHand,
};

const chosenSort = queryText('stockSort', SHORTEST);
const sort = computed({
    get: () => (Object.hasOwn(ORDERS, chosenSort.value) ? chosenSort.value : SHORTEST),
    set: (value) => { chosenSort.value = value; },
});
const showAll = ref(false);
const uid = useId();
const idOf = (product, part) => `${uid}-${product.id}-${part}`;

const current = computed(() => props.analysis.period.current);
const ordered = computed(() => [...props.analysis.products].sort((a, b) => ORDERS[sort.value](a, b) || b.units - a.units || a.name.localeCompare(b.name)));
const shown = computed(() => (showAll.value ? ordered.value : ordered.value.slice(0, FIRST)));
const example = computed(() => props.analysis.products.find((product) => product.coverOrders !== null && product.per100Orders !== null && product.onHand > 0));
const countWhere = (state) => props.analysis.products.filter((product) => product.stock === state).length;
const widest = computed(() => Math.max(1, ...props.analysis.products.map((product) => product.units + product.onHand)));
const share = (units) => `${(units / widest.value) * 100}%`;
</script>

<template>
    <BaseCard :title="t('reports-stock.title')" class="stock-cover">
        <EmptyState v-if="analysis.orders === 0">{{ t('reports-stock.empty') }}</EmptyState>
        <Notice v-else-if="!current" tone="success">{{ t('reports-stock.past') }}</Notice>
        <div v-else class="stock-cover__body">
            <div class="stock-cover__summary">
                <p class="stock-cover__headline">
                    {{ t('reports-stock.headline', { stock: t('reports-stock.units', { count: n(analysis.totals.onHand) }, analysis.totals.onHand), out: t('reports-stock.out', { count: countWhere('out') }, countWhere('out')), low: t('reports-stock.low', { count: countWhere('low') }, countWhere('low')) }) }}
                </p>
                <p v-if="analysis.busiestEvent" class="stock-cover__hint">
                    {{ t('reports-stock.busiest', { event: analysis.busiestEvent.name, orders: t('reports-stock.orders', { count: analysis.busiestEvent.orders }, analysis.busiestEvent.orders) }) }}
                </p>
            </div>

            <FormDisclosure :title="t('reports.analysis.how.title')">
                <p class="stock-cover__how">{{ t('reports-stock.howCover') }}</p>
                <p v-if="example" class="stock-cover__how">
                    {{ t('reports-stock.howExample', { name: example.name, onHand: n(example.onHand), exposure: n(example.exposure), units: n(example.units), cover: t('reports-stock.orders', { count: n(example.coverOrders) }, example.coverOrders) }) }}
                </p>
                <p v-if="analysis.busiestEvent" class="stock-cover__how">
                    {{ t('reports-stock.howLow', { event: analysis.busiestEvent.name, orders: t('reports-stock.orders', { count: analysis.busiestEvent.orders }, analysis.busiestEvent.orders) }) }}
                </p>
                <p class="stock-cover__how">{{ t('reports-stock.howNoSales') }}</p>
            </FormDisclosure>

            <div class="stock-cover__controls">
                <p class="stock-cover__legend">
                    <span class="stock-cover__swatch stock-cover__swatch--sold" aria-hidden="true" />{{ t('reports-stock.legendSold') }}
                    <span class="stock-cover__swatch stock-cover__swatch--stock" aria-hidden="true" />{{ t('reports-stock.legendStock') }}
                </p>
                <ChoiceGroup v-model="sort" class="stock-cover__sorts" :aria-label="t('reports-stock.sortBy')">
                    <ToggleGroupItem v-for="key in Object.keys(ORDERS)" :key="key" :value="key" class="stock-cover__sort">{{ t(`reports-stock.sorts.${key}`) }}</ToggleGroupItem>
                </ChoiceGroup>
            </div>

            <ol class="stock-cover__list">
                <li v-for="product in shown" :key="product.id" class="stock-cover__item">
                    <button
                        type="button"
                        :class="['stock-cover__row', { 'stock-cover__row--selected': product.id === selectedId }]"
                        :aria-pressed="product.id === selectedId"
                        :aria-label="t('reports.palmares.select', { name: product.name })"
                        :aria-describedby="`${idOf(product, 'figures')} ${idOf(product, 'cover')}`"
                        @click="emit('select', product)"
                    >
                        <span class="stock-cover__name">
                            {{ product.name }}
                            <span :id="idOf(product, 'figures')" class="stock-cover__meta">{{ t('reports-stock.figures', { sold: t('reports.palmares.units', { count: n(product.units) }, product.units), stock: t('reports-stock.onHand', { count: n(product.onHand) }) }) }}</span>
                        </span>
                        <span class="stock-cover__track" aria-hidden="true">
                            <span v-if="product.units > 0" class="stock-cover__sold" :style="{ width: share(product.units) }" />
                            <span v-if="product.onHand > 0" class="stock-cover__stock" :style="{ width: share(product.onHand) }" />
                        </span>
                        <span :id="idOf(product, 'cover')" class="stock-cover__cover">
                            <StockStateBadge :state="product.stock" />
                            <span v-if="product.stock !== 'out'" class="stock-cover__lasts">{{ product.coverOrders === null ? t('reports-stock.noSales') : t('reports-stock.lasts', { count: n(product.coverOrders) }, product.coverOrders) }}</span>
                        </span>
                    </button>
                </li>
            </ol>
            <button v-if="ordered.length > FIRST" type="button" class="stock-cover__more" @click="showAll = !showAll">
                {{ showAll ? t('reports-stock.showLess', { count: FIRST }) : t('reports-stock.showAll', { count: ordered.length }) }}
            </button>
        </div>
    </BaseCard>
</template>

<style scoped>
.stock-cover__body { display: flex; flex-direction: column; gap: var(--space-3); min-width: 0; }
.stock-cover__summary { display: flex; flex-direction: column; gap: var(--space-1); }
.stock-cover__headline { margin: 0; font-size: 1.1rem; font-weight: 600; }
.stock-cover__hint { margin: 0; color: var(--color-muted); font-size: var(--font-size-sm); }
.stock-cover__how { margin: 0; color: var(--color-muted); font-size: var(--font-size-sm); }
.stock-cover__controls { display: flex; flex-wrap: wrap; align-items: baseline; justify-content: space-between; gap: var(--space-3); }
.stock-cover__sorts { display: flex; flex-wrap: wrap; gap: var(--space-3); }

.stock-cover__sort {
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

.stock-cover__sort:hover { color: var(--color-ink); }
.stock-cover__sort[data-state='on'] { border-bottom-color: var(--color-ink); color: var(--color-ink); font-weight: 600; }

.stock-cover__legend { display: flex; flex-wrap: wrap; align-items: center; gap: var(--space-1) var(--space-2); margin: 0; color: var(--color-muted); font-size: var(--font-size-sm); }
.stock-cover__swatch { display: inline-block; width: 0.75rem; height: 0.75rem; border-radius: 0.125rem; }
.stock-cover__swatch + .stock-cover__swatch,
.stock-cover__swatch--stock { margin-left: var(--space-2); }
.stock-cover__swatch--sold,
.stock-cover__sold { background: var(--color-accent); }
.stock-cover__swatch--stock,
.stock-cover__stock { background: var(--color-border-strong); }

.stock-cover__list { display: flex; flex-direction: column; margin: 0; padding: 0; list-style: none; }

.stock-cover__row {
    display: grid;
    grid-template-columns: minmax(0, 13rem) minmax(0, 1fr) 11rem;
    align-items: center;
    gap: var(--space-3);
    width: 100%;
    padding: var(--space-2);
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

.stock-cover__row:hover { background: var(--color-surface); }
.stock-cover__row:focus-visible { outline-offset: -0.125rem; }
.stock-cover__row--selected { border-left-color: var(--color-accent); background: var(--color-surface); }
.stock-cover__name { display: flex; flex-direction: column; min-width: 0; overflow-wrap: anywhere; }
.stock-cover__meta { color: var(--color-muted); font-size: var(--font-size-xs); }
.stock-cover__track { display: flex; gap: 0.125rem; height: 0.875rem; }
.stock-cover__sold,
.stock-cover__stock { flex: none; height: 100%; min-width: 0.125rem; }
.stock-cover__sold { border-radius: 0.125rem 0 0 0.125rem; }
.stock-cover__stock { border-radius: 0 0.125rem 0.125rem 0; }
.stock-cover__sold:only-child { border-radius: 0.125rem; }
.stock-cover__stock:only-child { border-radius: 0.125rem; }
.stock-cover__cover { display: flex; flex-wrap: wrap; align-items: center; justify-content: flex-end; gap: var(--space-1) var(--space-2); }
.stock-cover__lasts { font-variant-numeric: tabular-nums; font-weight: 600; text-align: right; white-space: nowrap; }

.stock-cover__more { align-self: flex-start; padding: 0; border: none; border-bottom: 0.0625rem dotted currentColor; background: none; color: var(--color-muted); font: inherit; font-size: var(--font-size-md); cursor: pointer; }

@media (max-width: 40rem) {
    .stock-cover__row { grid-template-columns: minmax(0, 1fr) auto; }
    .stock-cover__track { grid-column: 1 / -1; grid-row: 2; }
    .stock-cover__lasts { max-width: 7rem; white-space: normal; }
}
</style>
