<script setup>
import { CalendarClock, Pencil } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import BaseCheckbox from '../ui/BaseCheckbox.vue';
import ConfirmButton from '../ui/ConfirmButton.vue';
import IconButton from '../ui/IconButton.vue';
import StatusBadge from '../ui/StatusBadge.vue';
import { formatDate, formatDateSpan } from '../../composables/useDate.js';

defineProps({
    release: { type: Object, required: true },
    editable: { type: Boolean, default: false },
    readonly: { type: Boolean, default: false },
});
const emit = defineEmits(['tick', 'edit', 'cancel']);
const { t } = useI18n();

const TONES = { late: 'danger', ongoing: 'warning', done: 'success', upcoming: 'neutral' };
</script>

<template>
    <section class="release-timeline" :aria-label="t('planning.timeline.label')">
        <header class="release-timeline__header">
            <p class="release-timeline__release">
                <CalendarClock size="1rem" aria-hidden="true" />
                {{ t('planning.timeline.releaseOn', { day: formatDate(release.day) }) }}
                <span v-if="release.event" class="release-timeline__event">· {{ release.event.name }}</span>
            </p>
            <div v-if="editable" class="release-timeline__actions">
                <IconButton :icon="Pencil" :label="t('planning.timeline.edit')" @click="emit('edit')" />
                <ConfirmButton
                    :label="t('planning.timeline.cancel')"
                    :confirm-label="t('planning.timeline.cancel')"
                    :message="t('planning.timeline.cancelMessage')"
                    @confirm="emit('cancel')"
                />
            </div>
        </header>
        <ol v-if="release.steps.length" class="release-timeline__steps">
            <li v-for="step in release.steps" :key="step.label" :class="['release-timeline__step', { 'release-timeline__step--done': step.done }]">
                <BaseCheckbox :model-value="step.done" :aria-label="step.label" :disabled="readonly" @update:model-value="emit('tick', step.label, $event)" />
                <span class="release-timeline__label">{{ step.label }}</span>
                <span class="release-timeline__dates">{{ formatDateSpan(step.start, step.end) }}</span>
                <StatusBadge :tone="TONES[step.state]">{{ t(`planning.states.${step.state}`) }}</StatusBadge>
            </li>
        </ol>
        <p v-else class="release-timeline__empty">{{ t('planning.timeline.noSteps') }}</p>
    </section>
</template>

<style scoped>
.release-timeline { display: flex; flex-direction: column; gap: var(--space-3); padding: var(--space-4); border: 0.0625rem solid var(--color-border); border-radius: var(--radius); }
.release-timeline__header { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: var(--space-2); }
.release-timeline__release { display: inline-flex; align-items: center; gap: var(--space-2); margin: 0; color: var(--color-ink); font-weight: 600; }
.release-timeline__event { color: var(--color-muted); font-weight: 400; }
.release-timeline__actions { display: flex; align-items: center; gap: var(--space-1); }
.release-timeline__steps { display: flex; flex-direction: column; margin: 0; padding: 0; list-style: none; }
.release-timeline__step { display: grid; grid-template-columns: auto minmax(0, 1fr) auto auto; align-items: center; gap: var(--space-3); padding: var(--space-2) 0; border-top: 0.0625rem solid var(--color-border); }
.release-timeline__step--done .release-timeline__label { color: var(--color-muted); text-decoration: line-through; }
.release-timeline__dates { color: var(--color-muted); font-size: var(--font-size-sm); font-variant-numeric: tabular-nums; }
.release-timeline__empty { margin: 0; color: var(--color-muted); font-size: var(--font-size-sm); }
</style>
