import { computed, ref } from 'vue';
import { useApi } from './useApi.js';
import { queryText } from './useQueryState.js';

export const LAST_TWELVE_MONTHS = '12m';
export const SINCE_THE_START = 'all';
export const VIEWS = ['palmares', 'decisions', 'concentration', 'matrix', 'stock', 'events'];

export function useProductReports() {
    const api = useApi();
    const period = queryText('period', LAST_TWELVE_MONTHS);
    const productId = queryText('product');
    const chosenView = queryText('view', VIEWS[0]);
    const view = computed({
        get: () => (VIEWS.includes(chosenView.value) ? chosenView.value : VIEWS[0]),
        set: (value) => { chosenView.value = value; },
    });
    const report = ref(null);
    const analysis = ref(null);

    const query = () => `?period=${encodeURIComponent(period.value)}`;
    const loadReport = () => api.load(`/api/reports/products${query()}`, report);
    const loadAnalysis = () => api.load(`/api/reports/products/analysis${query()}`, analysis);
    const loadAll = () => Promise.all([loadReport(), loadAnalysis()]);

    return { period, productId, view, report, analysis, loadAll };
}
