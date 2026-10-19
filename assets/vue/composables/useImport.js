import { computed, ref } from 'vue';
import { useApi } from './useApi.js';
import { useToast } from './useToast.js';
import { formatDate } from './useDate.js';
import { t } from '../i18n/index.js';

export function useImport(service) {
    const api = useApi();
    const toast = useToast();
    const importing = ref(false);
    const problem = ref(null);
    const items = ref([]);

    const unlinked = computed(() => items.value.filter((item) => item.components.length === 0));

    async function loadItems() {
        await api.load(`/api/services/${service.key}/items`, items);
    }

    async function run() {
        importing.value = true;
        problem.value = null;
        try {
            const report = await api.post(`/api/services/${service.key}/import`);
            toast.success(summary(report));
            problem.value = describeProblem(report);
            await loadItems();
            return report;
        } catch (error) {
            problem.value = { message: error.message, dates: [] };
            return null;
        } finally {
            importing.value = false;
        }
    }

    const link = (itemId, lines) => api.put(`/api/services/${service.key}/items/${itemId}`, { lines });

    return { service, importing, problem, items, unlinked, loadItems, run, link, dismiss: () => { problem.value = null; } };
}

function summary(report) {
    const parts = [
        t('import.summary.ordersImported', report.ordersImported),
        t('import.summary.ordersAlreadyImported', report.ordersAlreadyImported),
    ];
    if (report.productsCreated > 0) {
        parts.push(t('import.summary.productsCreated', report.productsCreated));
    }
    if (report.typesCreated > 0) {
        parts.push(t('import.summary.typesCreated', report.typesCreated));
    }
    if (report.feesUpdated > 0) {
        parts.push(t('import.summary.feesUpdated', report.feesUpdated));
    }
    if (report.ordersCancelled > 0) {
        parts.push(t('import.summary.ordersCancelled', report.ordersCancelled));
    }
    if (report.ordersWaitingForItems > 0) {
        parts.push(t('import.summary.ordersWaitingForItems', report.ordersWaitingForItems));
    }
    return t('import.summary.done', { service: report.label, parts: parts.join(', ') });
}

function describeProblem(report) {
    const parts = [];
    if (report.ordersWithoutEvent > 0) {
        parts.push(t('import.problem.ordersWithoutEvent', report.ordersWithoutEvent));
    }
    if (report.salesWithoutItems > 0) {
        parts.push(t('import.problem.salesWithoutItems', report.salesWithoutItems));
    }
    if (parts.length === 0) {
        return null;
    }
    return { message: parts.join(' '), dates: report.datesWithoutEvent.map(formatDate) };
}
