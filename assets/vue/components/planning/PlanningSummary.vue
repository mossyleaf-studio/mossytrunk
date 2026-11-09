<script setup>
import { computed } from 'vue';
import { CalendarDays, Flag, TriangleAlert } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import { formatDate } from '../../composables/useDate.js';
import { dueOf, nextDeadline, pending } from '../../composables/usePlanning.js';
import { daysBetween } from '../../composables/useRelativeDay.js';

const props = defineProps({
    planning: { type: Object, required: true },
});
const { t } = useI18n();

const next = computed(() => nextDeadline(props.planning));
const nextText = computed(() => {
    const item = next.value;
    const what = item.kind === 'restock'
        ? t('planning.restock.item.title', { supplier: item.supplier.name })
        : t('planning.summary.step', { label: item.label, owner: item.owner.name });
    const day = dueOf(item);

    return t('planning.summary.next', { what, day: formatDate(day), when: t('planning.summary.when', daysBetween(props.planning.today, day)) });
});
</script>

<template>
    <div class="planning-summary" role="group" :aria-label="t('planning.summary.label')">
        <a v-if="planning.late.length" href="#planning-late" class="planning-summary__chip planning-summary__chip--danger">
            <TriangleAlert size="1rem" aria-hidden="true" />{{ t('planning.summary.late', { n: planning.late.length }) }}
        </a>
        <a href="#planning-this-week" class="planning-summary__chip planning-summary__chip--warning">
            <CalendarDays size="1rem" aria-hidden="true" />{{ t('planning.summary.thisWeek', { n: pending(planning.thisWeek).length }) }}
        </a>
        <p v-if="next" class="planning-summary__chip planning-summary__chip--neutral">
            <Flag size="1rem" aria-hidden="true" />{{ nextText }}
        </p>
    </div>
</template>

<style scoped>
.planning-summary { display: flex; flex-wrap: wrap; align-items: center; gap: var(--space-2); }
.planning-summary__chip {
    display: inline-flex;
    align-items: center;
    gap: var(--space-2);
    margin: 0;
    padding: var(--space-2) var(--space-3);
    border: 0.0625rem solid transparent;
    border-radius: var(--radius-pill);
    font-size: var(--font-size-md);
    font-weight: 600;
    text-decoration: none;
    transition: border-color var(--transition);
}

a.planning-summary__chip:hover { border-color: currentcolor; }
.planning-summary__chip svg { flex-shrink: 0; }
.planning-summary__chip--danger { background: var(--color-danger-soft); color: var(--color-danger); }
.planning-summary__chip--warning { background: var(--color-warning-soft); color: var(--color-warning); }
.planning-summary__chip--neutral { border-color: var(--color-border); background: var(--color-surface); color: var(--color-text); font-weight: 500; }
.planning-summary__chip--neutral svg { color: var(--color-muted); }
</style>
