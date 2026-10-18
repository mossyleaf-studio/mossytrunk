import { computed, reactive, ref } from 'vue';
import { useApi } from './useApi.js';
import { useDebouncedQuery } from './useDebouncedQuery.js';
import { nowForInput } from './useDate.js';

export function useDraftLines() {
    const lines = reactive([]);

    const sameTuple = (a, b) => a.productId === b.productId && (a.variant ?? null) === (b.variant ?? null);

    function add({ productId, variant, quantity }) {
        const existing = lines.find((line) => sameTuple(line, { productId, variant }));
        if (existing) {
            existing.quantity += quantity;
            return;
        }
        lines.push({ key: `${productId}|${variant ?? ''}`, productId, variant: variant ?? null, quantity });
    }

    function setQuantity(key, quantity) {
        const line = lines.find((l) => l.key === key);
        if (!line) return;
        if (quantity < 1) {
            remove(key);
            return;
        }
        line.quantity = quantity;
    }

    function remove(key) {
        const index = lines.findIndex((l) => l.key === key);
        if (index !== -1) lines.splice(index, 1);
    }

    const requested = () => lines.map(({ productId, variant, quantity }) => ({ productId, variant, quantity }));

    return { lines, add, setQuantity, remove, requested };
}

export function useOrderDraft() {
    const api = useApi();
    const placedAt = ref(nowForInput());
    const { lines, add, setQuantity, remove, requested } = useDraftLines();
    const discountRuleIds = ref([]);
    const outsideEvents = ref(false);
    const note = ref('');

    function reset() {
        lines.splice(0, lines.length);
        discountRuleIds.value = [];
        note.value = '';
        preview.value = null;
        error.value = null;
    }

    const payload = () => ({
        placedAt: placedAt.value,
        lines: requested(),
        discountRuleIds: discountRuleIds.value,
        outsideEvents: outsideEvents.value,
        note: note.value,
    });

    const { result: preview, error } = useDebouncedQuery(
        [placedAt, lines, discountRuleIds, outsideEvents],
        () => (lines.length === 0 || !placedAt.value ? null : api.query('/api/orders/preview', payload())),
        { delay: 200, deep: true },
    );
    const previewError = computed(() => error.value?.message ?? null);

    return { placedAt, lines, discountRuleIds, outsideEvents, note, preview, previewError, add, setQuantity, remove, reset, payload };
}
