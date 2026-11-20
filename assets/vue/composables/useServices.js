import { computed, ref } from 'vue';
import { useApi } from './useApi.js';

export const SALES_CONTEXTS = [
    { value: 'at_event', label: 'settings.salesContexts.atEvent.label', description: 'settings.salesContexts.atEvent.description' },
    { value: 'online', label: 'settings.salesContexts.online.label', description: 'settings.salesContexts.online.description' },
];

export const UNKNOWN_ITEMS = [
    { value: 'create_product', label: 'settings.unknownItemPolicies.createProduct.label', description: 'settings.unknownItemPolicies.createProduct.description' },
    { value: 'link_by_hand', label: 'settings.unknownItemPolicies.linkByHand.label', description: 'settings.unknownItemPolicies.linkByHand.description' },
];

export function isReady(service) {
    const connection = service.connection;
    return Boolean(connection?.configured && (!service.authorizes || connection.authorized));
}

export function useServices() {
    const api = useApi();
    const services = ref([]);

    const added = computed(() => services.value.filter((service) => service.connection));
    const ready = computed(() => added.value.filter(isReady));

    async function load() {
        await api.load('/api/services', services);
    }

    const add = (service, payload) => api.post('/api/services', { service, ...payload });
    const update = (service, payload) => api.put(`/api/services/${service}`, payload);
    const remove = (service) => api.del(`/api/services/${service}`);
    const disconnect = (service) => api.del(`/api/services/${service}/authorization`);

    function importCatalogue(service, file) {
        const data = new FormData();
        data.append('file', file);
        return api.post(`/api/services/${service}/catalogue`, data);
    }

    const readCatalogue = (service) => api.post(`/api/services/${service}/catalogue/read`);
    const publishReferences = (service) => api.post(`/api/services/${service}/references`);
    const publishCatalogue = (service) => api.post(`/api/services/${service}/catalogue/publish`);

    return { services, added, ready, load, add, update, remove, disconnect, importCatalogue, readCatalogue, publishReferences, publishCatalogue };
}
