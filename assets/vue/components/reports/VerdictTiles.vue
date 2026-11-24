<script setup>
import { computed } from 'vue';
import { ToggleGroupItem } from 'reka-ui';
import { useI18n } from 'vue-i18n';
import ChoiceGroup from '../ui/ChoiceGroup.vue';
import VerdictBadge from './VerdictBadge.vue';
import { VERDICTS } from '../../composables/useProductAnalysis.js';

const props = defineProps({
    products: { type: Array, required: true },
});
const verdict = defineModel({ type: String, required: true });

const { t } = useI18n();

const counts = computed(() => Object.fromEntries(VERDICTS.map((key) => [key, props.products.filter((product) => product.verdict === key).length])));
</script>

<template>
    <ChoiceGroup v-model="verdict" class="verdict-tiles" :aria-label="t('reports-decisions.tiles.label')">
        <ToggleGroupItem value="all" class="verdict-tiles__tile">
            <span class="verdict-tiles__count">{{ products.length }}</span>
            <span class="verdict-tiles__all">{{ t('reports-decisions.tiles.all') }}</span>
        </ToggleGroupItem>
        <ToggleGroupItem v-for="key in VERDICTS" :key="key" :value="key" :disabled="counts[key] === 0" class="verdict-tiles__tile">
            <span class="verdict-tiles__count">{{ counts[key] }}</span>
            <VerdictBadge :verdict="key" :tip="false" />
        </ToggleGroupItem>
    </ChoiceGroup>
</template>

<style scoped>
.verdict-tiles { display: grid; grid-template-columns: repeat(auto-fill, minmax(8.5rem, 1fr)); gap: var(--space-2); }

.verdict-tiles__tile {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: var(--space-1);
    padding: var(--space-3);
    border: 0.0625rem solid transparent;
    border-radius: var(--radius);
    background: var(--color-bg);
    color: inherit;
    font: inherit;
    text-align: left;
    cursor: pointer;
    transition: border-color var(--transition), background var(--transition);
}

.verdict-tiles__tile:hover:not([data-disabled]) { border-color: var(--color-border-strong); }
.verdict-tiles__tile[data-state='on'] { border-color: var(--color-accent); box-shadow: inset 0 0 0 0.0625rem var(--color-accent); }
.verdict-tiles__tile[data-disabled] { cursor: default; opacity: 0.5; }
.verdict-tiles__count { color: var(--color-ink); font-family: var(--font-display); font-size: 1.75rem; line-height: 1; font-variant-numeric: tabular-nums; }
.verdict-tiles__all { color: var(--color-muted); font-size: var(--font-size-2xs); font-weight: 600; text-transform: uppercase; letter-spacing: 0.04rem; }
</style>
