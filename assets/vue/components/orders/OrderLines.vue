<script setup>
import { PackageSearch, Split } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import DataTable from '../ui/DataTable.vue';
import IconButton from '../ui/IconButton.vue';
import MoneyAmount from '../ui/MoneyAmount.vue';
import StatusBadge from '../ui/StatusBadge.vue';

defineProps({
    lines: { type: Array, required: true },
    identifiable: { type: Boolean, default: false },
});
const emit = defineEmits(['identify', 'split']);

const { t } = useI18n();
</script>

<template>
    <DataTable :items="lines">
        <template #head>
            <tr>
                <th>{{ t('orders.lines.product') }}</th>
                <th class="data-table__cell--number">{{ t('orders.lines.quantity') }}</th>
                <th class="data-table__cell--number">{{ t('orders.lines.unitPrice') }}</th>
                <th class="data-table__cell--number">{{ t('orders.lines.total') }}</th>
            </tr>
        </template>
        <template #default="{ rows }">
            <tr v-for="(line, index) in rows" :key="`${line.productId}-${line.label}-${index}`">
                <td>
                    <span v-if="line.productId === null" class="order-lines__unknown">
                        <StatusBadge tone="warning" :title="t('orders.lines.unknownProductHint')">{{ line.label }}</StatusBadge>
                        <IconButton v-if="identifiable" :icon="PackageSearch" :label="t('orders.lines.identify')" @click="emit('identify', line)" />
                        <IconButton v-if="identifiable" :icon="Split" :label="t('orders.lines.split')" @click="emit('split', line)" />
                    </span>
                    <template v-else>{{ line.label }}</template>
                </td>
                <td class="data-table__cell--number">{{ line.quantity }}</td>
                <td class="data-table__cell--number"><MoneyAmount :cents="line.unitPrice" /></td>
                <td class="data-table__cell--number"><MoneyAmount :cents="line.total" /></td>
            </tr>
        </template>
    </DataTable>
</template>

<style scoped>
.order-lines__unknown { display: inline-flex; align-items: center; gap: var(--space-2); }
</style>
