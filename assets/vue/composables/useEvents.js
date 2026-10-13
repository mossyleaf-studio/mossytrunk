import { computed, ref } from 'vue';
import { useApi } from './useApi.js';

export function useEvents() {
    const api = useApi();
    const events = ref([]);

    async function load() {
        await api.load('/api/events', events);
    }

    const create = (payload) => api.post('/api/events', payload);

    const upcoming = computed(() => events.value
        .filter((event) => event.timing !== 'past')
        .sort((a, b) => a.startDate.localeCompare(b.startDate)));
    const past = computed(() => events.value
        .filter((event) => event.timing === 'past')
        .sort((a, b) => b.startDate.localeCompare(a.startDate)));

    return { events, upcoming, past, load, create };
}

export function useEvent(eventId) {
    const api = useApi();
    const event = ref(null);
    const report = ref(null);

    async function load() {
        await Promise.all([
            api.load(`/api/events/${eventId}`, event),
            api.load(`/api/events/${eventId}/report`, report),
        ]);
    }

    const update = (payload) => api.put(`/api/events/${eventId}`, payload);
    const addExpense = (payload) => api.post(`/api/events/${eventId}/expenses`, payload);
    const reviseExpense = (expenseId, payload) => api.put(`/api/events/${eventId}/expenses/${expenseId}`, payload);
    const removeExpense = (expenseId) => api.del(`/api/events/${eventId}/expenses/${expenseId}`);

    const receiveDonation = (payload) => api.post(`/api/events/${eventId}/donations`, payload);
    const removeDonation = (donationId) => api.del(`/api/events/${eventId}/donations/${donationId}`);

    return { event, report, load, update, addExpense, reviseExpense, removeExpense, receiveDonation, removeDonation };
}
