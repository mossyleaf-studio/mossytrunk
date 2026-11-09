import { ref } from 'vue';
import { useApi } from './useApi.js';

const SETTINGS_URL = '/api/planning-settings';

export function usePlanningSettings() {
    const api = useApi();
    const settings = ref(null);

    return {
        settings,
        load: () => api.load(SETTINGS_URL, settings),
        define: (payload) => api.put(SETTINGS_URL, payload),
    };
}
