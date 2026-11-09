import { ref } from 'vue';
import { useApi } from './useApi.js';

const TEMPLATE_URL = '/api/release-template';

export function useReleaseTemplate() {
    const api = useApi();
    const template = ref(null);

    return {
        template,
        load: () => api.load(TEMPLATE_URL, template),
        define: (steps) => api.put(TEMPLATE_URL, { steps }),
    };
}
