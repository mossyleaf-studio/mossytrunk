import { ref } from 'vue';
import { useApi } from './useApi.js';

export const releaseOf = (planning, kind, id) => planning?.releases.find((release) => release.owner.kind === kind && release.owner.id === id) ?? null;

export function usePlanning() {
    const api = useApi();
    const planning = ref(null);
    const tick = (id, step, done) => api.put(`/api/releases/${id}/steps`, { step, done });
    const tickRestock = (id, ordered) => api.put(`/api/restocks/${id}/ordered`, { ordered });

    return {
        planning,
        load: () => api.load('/api/planning', planning),
        plan: (owner, payload) => api.post(`/api/${owner.kind === 'collection' ? 'design-collections' : 'designs'}/${owner.id}/release`, payload),
        replan: (id, payload) => api.put(`/api/releases/${id}`, payload),
        cancel: (id) => api.del(`/api/releases/${id}`),
        tick,
        tickRestock,
        tickItem: (item, done) => (item.kind === 'restock' ? tickRestock(item.id, done) : tick(item.release.id, item.label, done)),
        planRestock: (payload) => api.post('/api/restocks', payload),
        reviseRestock: (id, payload) => api.put(`/api/restocks/${id}`, payload),
        cancelRestock: (id) => api.del(`/api/restocks/${id}`),
        orderRestock: (id, supplierOrderId) => api.put(`/api/restocks/${id}/supplier-order`, { supplierOrderId }),
    };
}

export function useTarget(source) {
    const moment = ref(source?.event ? 'event' : 'day');
    const day = ref(source && !source.event ? source.day : '');
    const eventId = ref(source?.event?.id ?? '');
    const payload = () => ({
        day: moment.value === 'day' ? day.value || null : null,
        eventId: moment.value === 'event' ? eventId.value || null : null,
    });

    return { moment, day, eventId, payload };
}

export const orderRestockUrl = (restock) => `/supplier-orders?restock=${restock.id}&supplier=${restock.supplier.id}`;

export const ownerUrl = (owner) => (owner.kind === 'collection' ? `/designs?collection=${owner.id}` : `/designs/${owner.id}`);

export function useDesignRelease(designId) {
    const api = useApi();
    const designRelease = ref(null);

    return { designRelease, load: () => api.load(`/api/designs/${designId}/release`, designRelease) };
}
