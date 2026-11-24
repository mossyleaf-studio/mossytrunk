<script setup>
import { useI18n } from 'vue-i18n';
import StatusBadge from '../ui/StatusBadge.vue';

defineProps({
    state: { type: String, default: null },
});

const { t } = useI18n();
const TONES = { out: 'danger', low: 'warning' };
</script>

<template>
    <StatusBadge v-if="TONES[state]" :tone="TONES[state]">{{ t(`reports.analysis.stock.${state}`) }}</StatusBadge>
</template>
