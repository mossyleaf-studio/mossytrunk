<script setup>
import { useI18n } from 'vue-i18n';
import { classTone } from '../../composables/useProductAnalysis.js';

defineProps({
    abcClass: { type: String, required: true },
});

const { t } = useI18n();
</script>

<template>
    <span class="class-chip" :title="t(`reports.analysis.classes.${abcClass}`)">
        <span class="class-chip__swatch" :style="{ background: classTone(abcClass) }" aria-hidden="true" />
        {{ abcClass }}
    </span>
</template>

<style scoped>
.class-chip {
    display: inline-flex;
    align-items: center;
    gap: var(--space-1);
    color: var(--color-ink);
    font-size: var(--font-size-sm);
    font-weight: 600;
}

.class-chip__swatch {
    width: 0.625rem;
    height: 0.625rem;
    border-radius: var(--radius-sm);
}
</style>
