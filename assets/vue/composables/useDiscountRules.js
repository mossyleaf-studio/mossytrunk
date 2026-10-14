import { computed, ref } from 'vue';
import { useApi } from './useApi.js';

export const manualRulesOn = (rules, day) => rules.filter((rule) => rule.manual
    && (!rule.startsOn || rule.startsOn <= day)
    && (!rule.endsOn || day <= rule.endsOn));

export function useDiscountRules() {
    const api = useApi();
    const rules = ref([]);

    async function load() {
        await api.load('/api/discount-rules', rules);
    }

    const create = (payload) => api.post('/api/discount-rules', payload);
    const update = (id, payload) => api.put(`/api/discount-rules/${id}`, payload);
    const setActive = (id, active) => (active
        ? api.put(`/api/discount-rules/${id}/activation`)
        : api.del(`/api/discount-rules/${id}/activation`));
    const remove = (id) => api.del(`/api/discount-rules/${id}`);

    const currentRules = computed(() => rules.value.filter((rule) => rule.status !== 'expired'));
    const pastRules = computed(() => rules.value
        .filter((rule) => rule.status === 'expired')
        .sort((a, b) => (b.endsOn ?? '').localeCompare(a.endsOn ?? '')));

    return { rules, currentRules, pastRules, load, create, update, setActive, remove };
}
