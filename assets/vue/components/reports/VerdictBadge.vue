<script setup>
import { useI18n } from 'vue-i18n';
import ChartTip from '../ui/ChartTip.vue';
import StatusBadge from '../ui/StatusBadge.vue';
import { VERDICT_TONES } from '../../composables/useProductAnalysis.js';

defineProps({
    verdict: { type: String, required: true },
    tip: { type: Boolean, default: true },
});

const { t } = useI18n();
</script>

<template>
    <ChartTip v-if="tip">
        <StatusBadge :tone="VERDICT_TONES[verdict]" tabindex="0" class="verdict-badge">{{ t(`reports.analysis.verdicts.${verdict}`) }}</StatusBadge>
        <template #content>{{ t(`reports.analysis.rules.${verdict}`) }}</template>
    </ChartTip>
    <StatusBadge v-else :tone="VERDICT_TONES[verdict]">{{ t(`reports.analysis.verdicts.${verdict}`) }}</StatusBadge>
</template>

<style scoped>
.verdict-badge { position: relative; cursor: help; }
.verdict-badge::after { content: ''; position: absolute; inset: -0.25rem -0.125rem; }
</style>
