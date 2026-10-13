<script setup>
import { VisuallyHidden } from 'reka-ui';
import { useI18n } from 'vue-i18n';
import { Trash2 } from '@lucide/vue';
import DataTable from '../ui/DataTable.vue';
import IconButton from '../ui/IconButton.vue';
import MoneyAmount from '../ui/MoneyAmount.vue';

defineProps({
    donations: { type: Array, required: true },
    total: { type: Number, required: true },
});
const emit = defineEmits(['remove']);
const { t } = useI18n();
const labelOf = (donation) => donation.label ?? t('events.donations.unlabelled');
</script>

<template>
    <DataTable fit :items="donations">
        <template #head>
            <tr>
                <th>{{ t('events.donations.label') }}</th>
                <th class="data-table__cell--number">{{ t('events.donations.amount') }}</th>
                <th class="data-table__cell--actions"><VisuallyHidden>{{ t('events.donations.actions') }}</VisuallyHidden></th>
            </tr>
        </template>
        <template #default="{ rows }">
            <tr v-for="donation in rows" :key="donation.id">
                <td>{{ labelOf(donation) }}</td>
                <td class="data-table__cell--number"><MoneyAmount :cents="donation.amount" /></td>
                <td class="data-table__cell--actions">
                    <IconButton :icon="Trash2" :label="t('events.donations.remove', { label: labelOf(donation) })" variant="danger" @click="emit('remove', donation)" />
                </td>
            </tr>
        </template>
        <template #foot>
            <tr>
                <td>{{ t('events.donations.total') }}</td>
                <td class="data-table__cell--number"><MoneyAmount :cents="total" /></td>
                <td />
            </tr>
        </template>
    </DataTable>
</template>
