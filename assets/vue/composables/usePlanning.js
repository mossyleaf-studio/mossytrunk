import { ref } from 'vue';
import { useApi } from './useApi.js';

export const releaseOf = (planning, kind, id) => planning?.releases.find((release) => release.owner.kind === kind && release.owner.id === id) ?? null;

export function usePlanning() {
    const api = useApi();
    const planning = ref(null);

    return {
        planning,
        load: () => api.load('/api/planning', planning),
        plan: (owner, payload) => api.post(`/api/${owner.kind === 'collection' ? 'design-collections' : 'designs'}/${owner.id}/release`, payload),
        replan: (id, payload) => api.put(`/api/releases/${id}`, payload),
        cancel: (id) => api.del(`/api/releases/${id}`),
        tick: (id, step, done) => api.put(`/api/releases/${id}/steps`, { step, done }),
    };
}

export const ownerUrl = (owner) => (owner.kind === 'collection' ? `/designs?collection=${owner.id}` : `/designs/${owner.id}`);

export function useDesignRelease(designId) {
    const api = useApi();
    const designRelease = ref(null);

    return { designRelease, load: () => api.load(`/api/designs/${designId}/release`, designRelease) };
}
