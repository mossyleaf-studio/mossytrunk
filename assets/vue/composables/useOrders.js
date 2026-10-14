import { computed, ref, shallowRef } from 'vue';
import { t } from '../i18n/index.js';
import { useApi } from './useApi.js';
import { queryText } from './useQueryState.js';

export function useOrders() {
    const api = useApi();
    const orders = shallowRef([]);
    const eventFilter = queryText('event');

    async function load() {
        const query = eventFilter.value ? `?eventId=${encodeURIComponent(eventFilter.value)}` : '';
        await api.load(`/api/orders${query}`, orders);
    }

    const place = (payload) => api.post('/api/orders', payload);
    const removeSelected = (orderIds) => api.post('/api/orders/deletion', { orderIds });
    const addSupplies = (orderIds, payload) => api.post('/api/orders/supplies', { orderIds, ...payload });
    const recomputeCharges = (orderIds) => api.post('/api/orders/charges', { orderIds });
    const fillMissingCosts = () => api.post('/api/orders/missing-costs');
    const missingCosts = computed(() => orders.value.some((order) => order.unknownCosts > 0));

    return { orders, eventFilter, load, place, removeSelected, addSupplies, recomputeCharges, fillMissingCosts, missingCosts };
}

export function useCheckedChannel(orders, checkedIds, channels) {
    const channelIds = computed(() => {
        const checked = new Set(checkedIds.value);
        return [...new Set(orders.value.filter((order) => checked.has(order.id)).map((order) => order.channelId ?? null))];
    });
    const channel = computed(() => (channelIds.value.length === 1 ? channels.value.find((candidate) => candidate.id === channelIds.value[0]) ?? null : null));
    const supplyBlocker = computed(() => {
        if (channelIds.value.length > 1) return t('orders.supplies.severalChannels');
        if (!channel.value) return t('orders.supplies.noChannel');
        if (channel.value.supplies.length === 0) return t('orders.supplies.noneOnChannel', { channel: channel.value.name });
        return null;
    });

    return { channel, supplyBlocker };
}

export function useOrder(orderId) {
    const api = useApi();
    const order = ref(null);

    async function load() {
        await api.load(`/api/orders/${orderId}`, order);
    }

    const remove = () => api.del(`/api/orders/${orderId}`);
    const refund = () => api.post(`/api/orders/${orderId}/refund`);
    const identifyLine = (lineId, payload) => api.put(`/api/orders/${orderId}/lines/${lineId}/product`, payload);
    const mergeWith = (otherOrderId) => api.post(`/api/orders/${orderId}/merge`, { orderId: otherOrderId });
    const candidates = () => api.get(`/api/orders/${orderId}/merge-candidates`);
    const addSupply = (payload) => api.post('/api/orders/supplies', { orderIds: [orderId], ...payload });
    const removeSupply = (supplyLineId) => api.del(`/api/orders/${orderId}/supplies/${supplyLineId}`);

    const stamp = (payload) => api.put(`/api/orders/${orderId}/postage`, payload);
    const grantDiscount = (payload) => api.post(`/api/orders/${orderId}/discounts`, payload);
    const withdrawDiscount = (ruleId) => api.del(`/api/orders/${orderId}/discounts/${ruleId}`);

    return { order, load, remove, refund, identifyLine, mergeWith, candidates, addSupply, removeSupply, stamp, grantDiscount, withdrawDiscount };
}
