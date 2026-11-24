<script setup>
import { computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { TriangleAlert } from '@lucide/vue';
import BaseCard from '../ui/BaseCard.vue';
import ChartTip from '../ui/ChartTip.vue';
import DataTable from '../ui/DataTable.vue';
import EmptyState from '../ui/EmptyState.vue';
import FormDisclosure from '../ui/FormDisclosure.vue';
import MoneyAmount from '../ui/MoneyAmount.vue';
import Notice from '../ui/Notice.vue';
import SortableHeader from '../ui/SortableHeader.vue';
import ClassChip from './ClassChip.vue';
import EventStrip from './EventStrip.vue';
import StockStateBadge from './StockStateBadge.vue';
import VerdictBadge from './VerdictBadge.vue';
import VerdictTiles from './VerdictTiles.vue';
import { formatCents } from '../../composables/useMoney.js';
import { QUADRANTS, VERDICTS, formatRate, reasonSentence } from '../../composables/useProductAnalysis.js';
import { queryText } from '../../composables/useQueryState.js';
import { normalize } from '../../composables/useSearch.js';
import { useSort } from '../../composables/useSort.js';

const props = defineProps({
    analysis: { type: Object, required: true },
    selectedId: { type: String, default: '' },
});
const emit = defineEmits(['select']);
const { t } = useI18n();

const ALL = 'all';
const columns = {
    name: (product) => product.name,
    verdict: (product) => (product.verdict === null ? null : VERDICTS.indexOf(product.verdict)),
    class: (product) => product.class,
    quadrant: (product) => (product.quadrant === null ? null : QUADRANTS.indexOf(product.quadrant)),
    units: (product) => product.units,
    pace: (product) => product.per100Orders,
    margin: (product) => product.margin,
    unitMargin: (product) => product.unitMargin,
    onHand: (product) => product.onHand,
    cover: (product) => product.coverOrders,
};
const FIGURES = ['units', 'pace', 'margin', 'unitMargin'];
const UNITS = ['pace', 'unitMargin', 'cover'];

const chosenVerdict = queryText('verdict', ALL);
const verdict = computed({
    get: () => (VERDICTS.includes(chosenVerdict.value) ? chosenVerdict.value : ALL),
    set: (value) => { chosenVerdict.value = value; },
});
const search = queryText('q');
const sort = queryText('sort', 'margin');
const dir = queryText('dir', 'desc');

const current = computed(() => props.analysis.period.current);
const filtered = computed(() => {
    const needle = normalize(search.value.trim());
    return props.analysis.products.filter((product) => (!current.value || verdict.value === ALL || product.verdict === verdict.value)
        && (needle === '' || normalize(`${product.name} ${product.typeName}`).includes(needle)));
});
const { sorted, sortKey, direction, sortBy, ariaSort } = useSort(filtered, columns, Object.hasOwn(columns, sort.value) ? sort.value : 'margin', dir.value === 'asc' ? 'ascending' : 'descending');
watch([sortKey, direction], ([key, order]) => {
    sort.value = key;
    dir.value = order === 'ascending' ? 'asc' : 'desc';
});

const slots = (product) => [
    ...props.analysis.events.map((event, index) => ({ name: event.name, units: product.byEvent[index] })),
    ...(props.analysis.offEventOrders > 0 ? [{ name: t('reports-decisions.strip.offEvent'), units: product.offEvent }] : []),
];
const orDash = (value, format) => (value === null ? '—' : format(value));

const judgedTypes = computed(() => props.analysis.types.filter((type) => type.popularFrom !== null));
</script>

<template>
    <BaseCard :title="t('reports-decisions.title')" class="product-decisions">
        <EmptyState v-if="analysis.orders === 0">{{ t('reports-decisions.empty') }}</EmptyState>
        <div v-else class="product-decisions__body">
            <VerdictTiles v-if="current" v-model="verdict" :products="analysis.products" />
            <Notice v-else tone="success">{{ t('reports-decisions.past') }}</Notice>
            <FormDisclosure :title="t('reports.analysis.how.title')" class="product-decisions__how">
                <p class="product-decisions__explain">{{ t('reports.analysis.how.pace') }}</p>
                <p class="product-decisions__explain">{{ t('reports.analysis.how.unitMargin') }}</p>
                <p class="product-decisions__explain">{{ t('reports-decisions.how.limits', { popular: analysis.rules.popularPercent, profitable: analysis.rules.profitablePercent }) }}</p>
                <ul v-if="judgedTypes.length > 0" class="product-decisions__explain product-decisions__list">
                    <li v-for="type in judgedTypes" :key="type.id">
                        {{ t('reports-decisions.how.type', { type: type.name, average: orDash(type.averagePace, formatRate), limit: formatRate(type.popularFrom), marginAverage: orDash(type.averageUnitMargin, formatCents), marginLimit: orDash(type.unitMarginFrom, formatCents) }) }}
                    </li>
                </ul>
                <p class="product-decisions__explain">{{ t('reports.analysis.how.classes', { a: analysis.rules.classAPercent, b: analysis.rules.classBPercent }) }}</p>
                <div class="product-decisions__explain">
                    {{ t('reports-decisions.how.quadrant') }}
                    <ul class="product-decisions__list">
                        <li v-for="quadrant in QUADRANTS" :key="quadrant">{{ t(`reports.analysis.how.quadrants.${quadrant}`) }}</li>
                    </ul>
                </div>
                <template v-if="current">
                    <p class="product-decisions__explain">{{ t('reports.analysis.how.cover') }}</p>
                    <p v-if="analysis.busiestEvent" class="product-decisions__explain">{{ t('reports.analysis.how.lowStock', { event: analysis.busiestEvent.name, orders: analysis.busiestEvent.orders }) }}</p>
                    <div class="product-decisions__explain">
                        {{ t('reports-decisions.how.verdictsIntro') }}
                        <dl class="product-decisions__verdicts">
                            <template v-for="key in VERDICTS" :key="key">
                                <dt><VerdictBadge :verdict="key" :tip="false" /></dt>
                                <dd class="product-decisions__meaning">{{ t(`reports-decisions.how.verdicts.${key}`, { enough: analysis.rules.enoughOrders, orders: analysis.orders }) }}</dd>
                            </template>
                        </dl>
                    </div>
                </template>
            </FormDisclosure>
            <input v-model="search" class="control control--compact product-decisions__search" type="search" :placeholder="t('reports-decisions.search')" :aria-label="t('reports-decisions.search')">

            <DataTable dense remember-page :page-size="100" :items="sorted">
                <template #head>
                    <tr>
                        <SortableHeader :sort="ariaSort('name')" @sort="sortBy('name')">{{ t('reports-decisions.columns.product') }}</SortableHeader>
                        <SortableHeader v-if="current" :sort="ariaSort('verdict')" @sort="sortBy('verdict')">{{ t('reports-decisions.columns.verdict') }}</SortableHeader>
                        <SortableHeader :sort="ariaSort('class')" @sort="sortBy('class')">{{ t('reports-decisions.columns.class') }}</SortableHeader>
                        <SortableHeader :sort="ariaSort('quadrant')" @sort="sortBy('quadrant')">{{ t('reports-decisions.columns.quadrant') }}</SortableHeader>
                        <SortableHeader v-for="key in FIGURES" :key="key" :sort="ariaSort(key)" numeric @sort="sortBy(key)">
                            <span class="product-decisions__heading">
                                {{ t(`reports-decisions.columns.${key}`) }}
                                <span v-if="UNITS.includes(key)" class="product-decisions__unit">{{ t(`reports-decisions.units.${key}`) }}</span>
                            </span>
                        </SortableHeader>
                        <template v-if="current">
                            <SortableHeader :sort="ariaSort('onHand')" numeric @sort="sortBy('onHand')">{{ t('reports-decisions.columns.stock') }}</SortableHeader>
                            <SortableHeader :sort="ariaSort('cover')" numeric @sort="sortBy('cover')">
                                <span class="product-decisions__heading">
                                    {{ t('reports-decisions.columns.cover') }}
                                    <span class="product-decisions__unit">{{ t('reports-decisions.units.cover') }}</span>
                                </span>
                            </SortableHeader>
                        </template>
                        <th class="product-decisions__strip">{{ t('reports-decisions.columns.events') }}</th>
                    </tr>
                </template>
                <template #default="{ rows }">
                    <tr v-for="product in rows" :key="product.id" :class="{ 'product-decisions__row--selected': product.id === selectedId }">
                        <td>
                            <button
                                type="button"
                                class="product-decisions__product"
                                :aria-pressed="product.id === selectedId"
                                :aria-label="t('reports.palmares.select', { name: product.name })"
                                @click="emit('select', product)"
                            >
                                {{ product.name }}
                                <span class="product-decisions__type">{{ product.typeName }}</span>
                            </button>
                        </td>
                        <td v-if="current"><VerdictBadge :verdict="product.verdict" :reason="reasonSentence(product, analysis, t)" /></td>
                        <td><ClassChip :abc-class="product.class" /></td>
                        <td class="product-decisions__quadrant">{{ orDash(product.quadrant, (quadrant) => t(`reports.analysis.quadrants.${quadrant}`)) }}</td>
                        <td class="data-table__cell--number">{{ product.units }}</td>
                        <td class="data-table__cell--number">{{ orDash(product.per100Orders, formatRate) }}</td>
                        <td class="data-table__cell--number">
                            <ChartTip v-if="product.unknownCost">
                                <span class="product-decisions__unknown" tabindex="0">
                                    <TriangleAlert size="0.875rem" role="img" :aria-label="t('reports.palmares.tooltip.unknownCost')" />
                                </span>
                                <template #content>{{ t('reports.palmares.tooltip.unknownCost') }}</template>
                            </ChartTip>
                            <MoneyAmount :cents="product.margin" />
                        </td>
                        <td class="data-table__cell--number"><template v-if="product.unitMargin === null">—</template><MoneyAmount v-else :cents="product.unitMargin" /></td>
                        <template v-if="current">
                            <td class="data-table__cell--number">
                                <span class="product-decisions__stock">{{ product.onHand }}<StockStateBadge :state="product.stock" /></span>
                            </td>
                            <td class="data-table__cell--number">{{ orDash(product.coverOrders, String) }}</td>
                        </template>
                        <td class="product-decisions__strip"><EventStrip :slots="slots(product)" /></td>
                    </tr>
                </template>
            </DataTable>
            <EmptyState v-if="sorted.length === 0" inline>{{ t('reports-decisions.noMatch') }}</EmptyState>
        </div>
    </BaseCard>
</template>

<style scoped>
.product-decisions { min-width: 0; }
.product-decisions__body { display: flex; flex-direction: column; gap: var(--space-4); }
.product-decisions__how { max-width: 48rem; }
.product-decisions__explain { margin: 0; color: var(--color-muted); font-size: var(--font-size-sm); line-height: 1.5; }
.product-decisions__list { display: flex; flex-direction: column; gap: var(--space-1); margin: var(--space-1) 0 0; padding-left: var(--space-4); }
.product-decisions__verdicts { display: grid; grid-template-columns: max-content 1fr; align-items: baseline; gap: var(--space-2) var(--space-3); margin: var(--space-2) 0 0; }
.product-decisions__meaning { margin: 0; }
.product-decisions__heading { display: inline-flex; flex-direction: column; align-items: flex-end; line-height: 1.2; }
.product-decisions__unit { max-width: 5.5rem; color: var(--color-muted); white-space: normal; text-align: right; font-size: var(--font-size-2xs); font-weight: 400; text-transform: none; letter-spacing: normal; }
.product-decisions__search { width: 100%; max-width: 20rem; }

.product-decisions__product {
    display: flex;
    flex-direction: column;
    min-width: 9rem;
    max-width: 16rem;
    padding: 0;
    border: none;
    background: none;
    color: var(--color-ink);
    font: inherit;
    font-weight: 600;
    text-align: left;
    cursor: pointer;
}

.product-decisions__product:hover { color: var(--color-accent-strong); }
.product-decisions__type { color: var(--color-muted); font-size: var(--font-size-xs); font-weight: 400; }
.product-decisions__row--selected { box-shadow: inset 0.125rem 0 0 var(--color-accent); }
.product-decisions__quadrant { color: var(--color-muted); white-space: nowrap; }
.product-decisions__unknown { display: inline-flex; align-items: center; justify-content: center; min-width: 1.5rem; min-height: 1.5rem; margin-right: var(--space-1); color: var(--color-warning); vertical-align: middle; cursor: help; }
.product-decisions__stock { display: inline-flex; flex-direction: column; align-items: flex-end; gap: 0.125rem; }

@media (width < 100rem) {
    .product-decisions__strip { display: none; }
}
</style>
