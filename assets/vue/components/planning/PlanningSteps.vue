<script setup>
import { useI18n } from 'vue-i18n';
import BaseCheckbox from '../ui/BaseCheckbox.vue';
import StatusBadge from '../ui/StatusBadge.vue';
import RestockRow from './RestockRow.vue';
import { formatDate, formatDateSpan } from '../../composables/useDate.js';
import { ownerUrl } from '../../composables/usePlanning.js';

defineProps({
    steps: { type: Array, required: true },
    editable: { type: Boolean, default: false },
});
const emit = defineEmits(['tick', 'edit', 'cancel']);
const { t } = useI18n();

const TONES = { late: 'danger', ongoing: 'warning', upcoming: 'neutral' };
</script>

<template>
    <ul class="planning-steps">
        <template v-for="step in steps" :key="step.kind === 'restock' ? step.id : `${step.release.id}-${step.label}`">
            <RestockRow v-if="step.kind === 'restock'" :restock="step" :editable="editable" @tick="(item, done) => emit('tick', item, done)" @edit="emit('edit', $event)" @cancel="emit('cancel', $event)" />
            <li v-else class="planning-steps__step">
                <BaseCheckbox :model-value="step.done" :aria-label="step.label" @update:model-value="emit('tick', step, $event)" />
                <span class="planning-steps__what">
                    <span class="planning-steps__label">{{ step.label }}</span>
                    <a :href="ownerUrl(step.owner)" class="planning-steps__owner">{{ step.owner.name }}</a>
                </span>
                <span class="planning-steps__when">
                    <span>{{ formatDateSpan(step.start, step.end) }}</span>
                    <span class="planning-steps__release">{{ t('planning.timeline.releaseOn', { day: formatDate(step.release.day) }) }}</span>
                </span>
                <StatusBadge :tone="TONES[step.state]">{{ t(`planning.states.${step.state}`) }}</StatusBadge>
            </li>
        </template>
    </ul>
</template>

<style scoped>
.planning-steps { display: flex; flex-direction: column; margin: 0; padding: 0; list-style: none; }
.planning-steps__step { display: grid; grid-template-columns: auto minmax(0, 1fr) auto auto; align-items: center; gap: var(--space-3); padding: var(--space-2) 0; border-top: 0.0625rem solid var(--color-border); }
.planning-steps__step:first-child { border-top: none; }
.planning-steps__what, .planning-steps__when { display: flex; flex-direction: column; }
.planning-steps__label { color: var(--color-ink); font-weight: 600; }
.planning-steps__owner { color: var(--color-muted); font-size: var(--font-size-sm); }
.planning-steps__when { align-items: flex-end; color: var(--color-muted); font-size: var(--font-size-sm); font-variant-numeric: tabular-nums; }
</style>
