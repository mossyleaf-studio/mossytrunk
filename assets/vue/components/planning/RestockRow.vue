<script setup>
import { Pencil, Trash2 } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import BaseButton from '../ui/BaseButton.vue';
import BaseCheckbox from '../ui/BaseCheckbox.vue';
import ConfirmButton from '../ui/ConfirmButton.vue';
import IconButton from '../ui/IconButton.vue';
import StatusBadge from '../ui/StatusBadge.vue';
import { formatDate } from '../../composables/useDate.js';
import { orderRestockUrl } from '../../composables/usePlanning.js';

defineProps({
    restock: { type: Object, required: true },
    editable: { type: Boolean, default: false },
});
const emit = defineEmits(['tick', 'edit', 'cancel']);
const { t } = useI18n();

const TONES = { late: 'danger', upcoming: 'neutral', done: 'success' };
</script>

<template>
    <li class="restock-row">
        <BaseCheckbox :model-value="restock.ordered" :aria-label="t('planning.restock.item.ordered')" @update:model-value="emit('tick', restock, $event)" />
        <span class="restock-row__what">
            <span class="restock-row__label">{{ t('planning.restock.item.title', { supplier: restock.supplier.name }) }}</span>
            <span v-if="restock.note" class="restock-row__meta">{{ restock.note }}</span>
        </span>
        <span class="restock-row__when">
            <span>{{ t('planning.restock.item.orderBy', { day: formatDate(restock.orderBy) }) }}</span>
            <a v-if="restock.supplierOrder" :href="`/supplier-orders/${restock.supplierOrder.id}`">{{ restock.supplierOrder.reference }}</a>
        </span>
        <StatusBadge :tone="TONES[restock.state]">{{ t(`planning.restock.states.${restock.state}`, { day: formatDate(restock.orderedOn) }) }}</StatusBadge>
        <span class="restock-row__actions">
            <BaseButton v-if="!restock.ordered" variant="secondary" :href="orderRestockUrl(restock)">{{ t('planning.restock.item.order') }}</BaseButton>
            <IconButton v-if="editable" :icon="Pencil" :label="t('planning.restock.item.edit')" @click="emit('edit', restock)" />
            <ConfirmButton
                :icon="Trash2"
                :label="t('planning.restock.item.cancel')"
                :confirm-label="t('planning.restock.item.cancel')"
                :message="t('planning.restock.item.cancelMessage')"
                @confirm="emit('cancel', restock)"
            />
        </span>
    </li>
</template>

<style scoped>
.restock-row { display: grid; grid-template-columns: auto minmax(0, 1fr) auto auto auto; align-items: center; gap: var(--space-3); padding: var(--space-2) 0; border-top: 0.0625rem solid var(--color-border); }
.restock-row:first-child { border-top: none; }
.restock-row__what, .restock-row__when { display: flex; flex-direction: column; }
.restock-row__label { color: var(--color-ink); font-weight: 600; }
.restock-row__meta { color: var(--color-muted); font-size: var(--font-size-sm); }
.restock-row__when { align-items: flex-end; color: var(--color-muted); font-size: var(--font-size-sm); font-variant-numeric: tabular-nums; }
.restock-row__actions { display: flex; align-items: center; justify-content: flex-end; gap: var(--space-1); }

@media (max-width: 48rem) {
    .restock-row { grid-template-columns: auto minmax(0, 1fr); }
    .restock-row > :nth-child(n + 3) { grid-column: 2; justify-self: start; align-items: flex-start; }
}
</style>
