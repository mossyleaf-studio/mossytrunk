<script setup>
import { computed } from 'vue';
import { TriangleAlert } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import BaseCheckbox from '../ui/BaseCheckbox.vue';
import DataTable from '../ui/DataTable.vue';
import EmptyState from '../ui/EmptyState.vue';
import MoneyAmount from '../ui/MoneyAmount.vue';
import StatusBadge from '../ui/StatusBadge.vue';
import PaymentMethod from './PaymentMethod.vue';
import { useCheckedRange } from '../../composables/useCheckedRange.js';
import { formatDay, formatTime } from '../../composables/useDate.js';

const props = defineProps({
    orders: { type: Array, required: true },
    highlightId: { type: String, default: null },
    filtered: { type: Boolean, default: false },
});
const checkedIds = defineModel('checkedIds', { type: Array, required: true });

const { t } = useI18n();

const placeOf = (order) => order.eventName ?? (order.source === 'manual' ? (order.channelName ?? t('orders.outsideEvents')) : t('orders.shop', { source: order.sourceLabel }));
const dayKey = (order) => `${order.placedAt.slice(0, 10)}|${order.eventId ?? order.channelId ?? order.source}`;

const grouped = computed(() => [...props.orders].sort((a, b) => b.placedAt.slice(0, 10).localeCompare(a.placedAt.slice(0, 10))
    || Number(a.eventId === null) - Number(b.eventId === null)
    || placeOf(a).localeCompare(placeOf(b))
    || b.placedAt.localeCompare(a.placedAt)));

const days = computed(() => {
    const totals = new Map();
    for (const order of props.orders) {
        const key = dayKey(order);
        const day = totals.get(key) ?? { count: 0, total: 0, profit: 0 };
        totals.set(key, { count: day.count + 1, total: day.total + (order.refundedAt ? 0 : order.total), profit: day.profit + (order.refundedAt ? 0 : order.profit) });
    }
    return totals;
});

const checkedSet = computed(() => new Set(checkedIds.value));
const allChecked = computed(() => props.orders.length > 0 && props.orders.every((order) => checkedSet.value.has(order.id)));

const idsByDay = computed(() => Map.groupBy(props.orders, dayKey));
const dayIds = (order) => (idsByDay.value.get(dayKey(order)) ?? []).map((dayOrder) => dayOrder.id);
const dayChecked = (order) => dayIds(order).every((id) => checkedSet.value.has(id));

function checkDay(order, checked) {
    const ids = new Set(dayIds(order));
    checkedIds.value = checked ? [...new Set([...checkedIds.value, ...ids])] : checkedIds.value.filter((id) => !ids.has(id));
}

function checkAll(checked) {
    checkedIds.value = checked ? props.orders.map((order) => order.id) : [];
}

const { noteShift, setChecked } = useCheckedRange(checkedIds, () => grouped.value.map((order) => order.id));

const withDayHeaders = (rows) => rows.map((order, index) => ({
    order,
    day: index === 0 || dayKey(rows[index - 1]) !== dayKey(order) ? days.value.get(dayKey(order)) : null,
}));
</script>

<template>
    <EmptyState v-if="orders.length === 0">{{ filtered ? t('orders.list.noMatch') : t('orders.list.empty') }}</EmptyState>
    <DataTable remember-page fixed v-else :items="grouped" class="order-list">
        <template #head>
            <tr>
                <th class="order-list__check">
                    <BaseCheckbox :model-value="allChecked" :aria-label="t('orders.list.selectAll')" @update:model-value="checkAll" />
                </th>
                <th class="order-list__col-reference">{{ t('orders.list.reference') }}</th>
                <th class="order-list__col-time">{{ t('orders.list.time') }}</th>
                <th class="data-table__cell--number order-list__col-figure">{{ t('orders.list.items') }}</th>
                <th class="data-table__cell--number order-list__col-figure">{{ t('orders.list.discounts') }}</th>
                <th class="data-table__cell--number order-list__col-figure">{{ t('orders.list.total') }}</th>
                <th class="data-table__cell--number order-list__col-figure" :title="t('orders.list.profitHint')">{{ t('orders.list.profit') }}</th>
                <th class="order-list__col-payment">{{ t('orders.list.payment') }}</th>
                <th>{{ t('orders.list.source') }}</th>
            </tr>
        </template>
        <template #default="{ rows }">
            <template v-for="{ order, day } in withDayHeaders(rows)" :key="order.id">
                <tr v-if="day" class="order-list__day">
                    <td class="order-list__check">
                        <BaseCheckbox
                            :model-value="dayChecked(order)"
                            :aria-label="t('orders.list.selectDay', { day: formatDay(order.placedAt), place: placeOf(order) })"
                            @update:model-value="checkDay(order, $event)"
                        />
                    </td>
                    <th scope="rowgroup" colspan="4">
                        <span class="order-list__date">{{ formatDay(order.placedAt) }}</span>
                        <a v-if="order.eventId" class="order-list__event" :href="`/events/${order.eventId}`">{{ order.eventName }}</a>
                        <span v-else class="order-list__event order-list__event--online">{{ placeOf(order) }}</span>
                    </th>
                    <td class="data-table__cell--number order-list__day-total"><MoneyAmount :cents="day.total" /></td>
                    <td class="data-table__cell--number order-list__day-total"><MoneyAmount :cents="day.profit" signed /></td>
                    <td class="order-list__day-count" colspan="2">{{ t('orders.list.count', day.count) }}</td>
                </tr>
                <tr :class="['order-list__row', { 'order-list__row--new': order.id === highlightId, 'order-list__row--unassigned': order.unidentifiedLines > 0 }]">
                    <td class="order-list__check">
                        <BaseCheckbox
                            :model-value="checkedSet.has(order.id)"
                            :aria-label="t('orders.list.select', { reference: order.reference })"
                            @pointerdown="noteShift"
                            @keydown="noteShift"
                            @update:model-value="setChecked(order.id, $event)"
                        />
                    </td>
                    <td class="order-list__reference">
                        <TriangleAlert
                            v-if="order.unidentifiedLines > 0"
                            class="order-list__unassigned"
                            size="0.875rem"
                            role="img"
                            :aria-label="t('orders.list.unassigned', order.unidentifiedLines)"
                        />
                        <a :href="`/orders/${order.id}`">{{ order.reference }}</a>
                    </td>
                    <td class="order-list__time">{{ formatTime(order.placedAt) }}</td>
                    <td class="data-table__cell--number">{{ order.itemCount }}</td>
                    <td class="data-table__cell--number">
                        <MoneyAmount v-if="order.discountTotal > 0" :cents="-order.discountTotal" />
                        <span v-else class="order-list__none">—</span>
                    </td>
                    <td :class="['data-table__cell--number', 'order-list__total', { 'order-list__total--refunded': order.refundedAt }]"><MoneyAmount :cents="order.total" /></td>
                    <td class="data-table__cell--number order-list__profit">
                        <span v-if="order.refundedAt" class="order-list__none">—</span>
                        <template v-else>
                            <TriangleAlert
                                v-if="order.unknownCosts > 0"
                                class="order-list__unassigned"
                                size="0.875rem"
                                role="img"
                                :aria-label="t('orders.list.unknownCost', order.unknownCosts)"
                            />
                            <MoneyAmount :cents="order.profit" signed />
                        </template>
                    </td>
                    <td class="order-list__payment"><PaymentMethod :method="order.paymentMethod" /></td>
                    <td class="order-list__source">
                        <StatusBadge v-if="order.refundedAt" tone="warning">{{ t('orders.list.refunded') }}</StatusBadge>
                        <StatusBadge v-if="order.source !== 'manual'" tone="outline">{{ order.sourceLabel }}</StatusBadge>
                        <span v-if="order.externalReferences.length" class="order-list__external">{{ order.externalReferences.join(', ') }}</span>
                        <span v-if="order.note" class="order-list__note" :title="order.note">{{ order.note }}</span>
                    </td>
                </tr>
            </template>
        </template>
    </DataTable>
</template>

<style scoped>
.order-list__check { width: 2.5rem; }
.order-list__col-reference { width: 17%; }
.order-list__col-time { width: 9%; }
.order-list__col-figure { width: 10%; }
.order-list__col-payment { width: 12%; }

.order-list__day > * { padding-top: var(--space-4); background: var(--color-bg); border-bottom-color: var(--color-border-strong); }
.order-list__day th { text-align: left; font-size: inherit; color: inherit; white-space: normal; }
.order-list__date { font-weight: 600; color: var(--color-ink); }
.order-list__event { margin-left: var(--space-3); color: var(--color-muted); font-weight: 400; }
.order-list__day-total { font-weight: 700; }
.order-list__day-count { color: var(--color-muted); font-size: var(--font-size-md); }

.order-list__time { font-variant-numeric: tabular-nums; }
.order-list__row--unassigned > td:first-child { box-shadow: inset 0.1875rem 0 0 var(--color-warning); }
.order-list__row--unassigned { background: var(--color-warning-soft); }
.order-list__unassigned { margin-right: var(--space-1); color: var(--color-warning); vertical-align: -0.125rem; }
.order-list__payment { color: var(--color-muted); font-size: var(--font-size-md); }
.order-list__total { font-weight: 600; }
.order-list__total--refunded { color: var(--color-subtle); text-decoration: line-through; }
.order-list__none { color: var(--color-subtle); }

.order-list__reference { font-size: var(--font-size-sm); font-variant-numeric: tabular-nums; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.order-list__source { font-size: var(--font-size-sm); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.order-list__note { color: var(--color-muted); font-style: italic; }
.order-list__external { margin-left: var(--space-2); color: var(--color-subtle); font-size: var(--font-size-xs); }

.order-list__row--new { animation: order-list-highlight 2.4s ease-out; }

@keyframes order-list-highlight {
    from { background: var(--color-accent-soft); }
    to { background: transparent; }
}
.order-list__event--online { color: var(--color-muted); }
</style>
