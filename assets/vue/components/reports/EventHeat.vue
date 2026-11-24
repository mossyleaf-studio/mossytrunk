<script setup>
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import BaseCard from '../ui/BaseCard.vue';
import ChartTip from '../ui/ChartTip.vue';
import DataTable from '../ui/DataTable.vue';
import EmptyState from '../ui/EmptyState.vue';
import { formatRate } from '../../composables/useProductAnalysis.js';
import { formatDateSpan } from '../../composables/useDate.js';

const props = defineProps({
    analysis: { type: Object, required: true },
    selectedId: { type: String, default: '' },
});
const emit = defineEmits(['select']);
const { t } = useI18n();

const FIRST = 15;
const LEVELS = [1, 2, 3];
const showAll = ref(false);

const columns = computed(() => [
    ...props.analysis.events.map((event, index) => ({ key: event.id, event, orders: event.orders, units: (product) => product.byEvent[index] })),
    ...(props.analysis.offEventOrders > 0 ? [{ key: 'off', event: null, orders: props.analysis.offEventOrders, units: (product) => product.offEvent }] : []),
]);
const rate = (units, orders) => (orders > 0 ? (units / orders) * 100 : 0);
const highest = computed(() => Math.max(0, ...props.analysis.products.flatMap((product) => columns.value
    .map((column) => rate(column.units(product) ?? 0, column.orders)))));
const level = (units, orders) => (units > 0 && highest.value > 0 ? Math.max(1, Math.ceil((rate(units, orders) / highest.value) * 3)) : 0);
const bound = (step) => formatRate((highest.value * step) / 3);

const ranked = computed(() => [...props.analysis.products].sort((a, b) => b.units - a.units || a.name.localeCompare(b.name)));
const shown = computed(() => (showAll.value ? ranked.value : ranked.value.slice(0, FIRST)));
const nameOf = (column) => (column.event ? column.event.name : t('reports-events.offEvent'));
const tip = (column, units) => (units === null
    ? t('reports-events.tip.absent', { event: nameOf(column) })
    : t('reports-events.tip.units', { event: nameOf(column), count: units, rate: formatRate(rate(units, column.orders)) }, units));
</script>

<template>
    <BaseCard :title="t('reports-events.title')" class="event-heat">
        <EmptyState v-if="analysis.orders === 0">{{ t('reports-events.empty') }}</EmptyState>
        <div v-else class="event-heat__body">
            <p class="event-heat__key">
                <span>{{ t('reports-events.key.label') }}</span>
                <span v-for="step in LEVELS" :key="step" class="event-heat__key-item">
                    <span :class="['event-heat__swatch', `event-heat__level--${step}`]" aria-hidden="true" />{{ t('reports-events.key.upTo', { rate: bound(step) }) }}
                </span>
                <span class="event-heat__key-item"><span class="event-heat__dash" aria-hidden="true">—</span>{{ t('reports-events.key.absent') }}</span>
            </p>

            <DataTable fit dense>
                <template #head>
                    <tr>
                        <th scope="col" class="event-heat__sticky">{{ t('reports-events.product') }}</th>
                        <th v-for="column in columns" :key="column.key" scope="col" class="event-heat__event">
                            <a v-if="column.event" :href="`/events/${column.event.id}`" class="event-heat__link">{{ column.event.name }}</a>
                            <span v-else class="event-heat__off">{{ t('reports-events.offEvent') }}</span>
                            <span v-if="column.event" class="event-heat__meta">{{ formatDateSpan(column.event.from, column.event.to) }}</span>
                            <span class="event-heat__meta">{{ t('reports-events.orders', { count: column.orders }, column.orders) }}</span>
                        </th>
                        <th scope="col" class="event-heat__event event-heat__event--total">
                            {{ t('reports-events.total') }}
                            <span class="event-heat__meta">{{ t('reports-events.orders', { count: analysis.orders }, analysis.orders) }}</span>
                        </th>
                    </tr>
                </template>
                <tr v-for="product in shown" :key="product.id" :class="['event-heat__row', { 'event-heat__row--selected': product.id === selectedId }]">
                    <th scope="row" class="event-heat__sticky">
                        <button
                            type="button"
                            class="event-heat__product"
                            :aria-pressed="product.id === selectedId"
                            :aria-label="t('reports.palmares.select', { name: product.name })"
                            @click="emit('select', product)"
                        >
                            {{ product.name }}
                            <span class="event-heat__type">{{ product.typeName }}</span>
                        </button>
                    </th>
                    <td v-for="column in columns" :key="column.key" class="data-table__cell--number">
                        <ChartTip>
                            <span
                                :class="['event-heat__value', `event-heat__level--${column.units(product) === null ? 0 : level(column.units(product), column.orders)}`, { 'event-heat__value--absent': column.units(product) === null }]"
                                tabindex="0"
                            >{{ column.units(product) ?? '—' }}</span>
                            <template #content>{{ tip(column, column.units(product)) }}</template>
                        </ChartTip>
                    </td>
                    <td class="data-table__cell--number event-heat__total">{{ product.units }}</td>
                </tr>
            </DataTable>

            <button v-if="ranked.length > FIRST" type="button" class="event-heat__more" @click="showAll = !showAll">
                {{ showAll ? t('reports-events.showLess', { count: FIRST }) : t('reports-events.showAll', { count: ranked.length }) }}
            </button>
        </div>
    </BaseCard>
</template>

<style scoped>
.event-heat { min-width: 0; }
.event-heat__body { display: flex; flex-direction: column; gap: var(--space-4); }

.event-heat__key { display: flex; flex-wrap: wrap; align-items: center; gap: var(--space-2) var(--space-4); margin: 0; color: var(--color-muted); font-size: var(--font-size-sm); }
.event-heat__key-item { display: inline-flex; align-items: center; gap: var(--space-2); }
.event-heat__swatch { width: 1.25rem; height: 0.75rem; border-radius: var(--radius-sm); }
.event-heat__dash { color: var(--color-muted); }

.event-heat__sticky { position: sticky; left: 0; z-index: 1; background: var(--color-surface); box-shadow: inset -0.0625rem 0 0 var(--color-border); }
.event-heat__row:hover .event-heat__sticky { background: var(--color-hover); }
.event-heat__row--selected .event-heat__sticky { box-shadow: inset 0.125rem 0 0 var(--color-accent), inset -0.0625rem 0 0 var(--color-border); }

.event-heat .event-heat__event { text-align: right; vertical-align: bottom; }
.event-heat__event--total { color: var(--color-ink); }
.event-heat__link { display: block; color: var(--color-ink); font-weight: 600; text-decoration: none; }
.event-heat__link:hover { color: var(--color-accent-strong); text-decoration: underline; }
.event-heat__off { display: block; color: var(--color-ink); font-weight: 600; }
.event-heat__meta { display: block; color: var(--color-muted); font-size: var(--font-size-xs); font-weight: 400; }

.event-heat__product {
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
    white-space: normal;
    cursor: pointer;
}

.event-heat__product:hover { color: var(--color-accent-strong); }
.event-heat__type { color: var(--color-muted); font-size: var(--font-size-xs); font-weight: 400; }

.event-heat__total { color: var(--color-ink); font-weight: 600; }

.event-heat__value {
    display: inline-block;
    min-width: 3rem;
    padding: 0.125rem var(--space-2);
    border-radius: var(--radius-sm);
    color: var(--color-ink);
    text-align: right;
    cursor: help;
}

.event-heat__value--absent { color: var(--color-muted); }
.event-heat__level--0 { background: none; }
.event-heat__level--1 { background: var(--color-heat-1); }
.event-heat__level--2 { background: var(--color-heat-2); }
.event-heat__level--3 { background: var(--color-accent); color: var(--color-on-accent); }

.event-heat__more { align-self: flex-start; padding: 0; border: none; border-bottom: 0.0625rem dotted currentColor; background: none; color: var(--color-muted); font: inherit; font-size: var(--font-size-md); cursor: pointer; }
</style>
