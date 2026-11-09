<script setup>
import { computed } from 'vue';
import { ArrowRight, Pencil, Trash2 } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import BaseButton from '../ui/BaseButton.vue';
import BaseCheckbox from '../ui/BaseCheckbox.vue';
import ConfirmButton from '../ui/ConfirmButton.vue';
import IconButton from '../ui/IconButton.vue';
import StatusBadge from '../ui/StatusBadge.vue';
import { formatDate } from '../../composables/useDate.js';
import { dueOf, orderRestockUrl, ownerUrl } from '../../composables/usePlanning.js';
import { daysBetween, useRelativeDay } from '../../composables/useRelativeDay.js';

const props = defineProps({
    item: { type: Object, required: true },
    today: { type: String, required: true },
    editable: { type: Boolean, default: false },
});
const emit = defineEmits(['tick', 'edit', 'cancel']);
const { t } = useI18n();
const relativeDay = useRelativeDay();

const TONES = { late: 'danger', ongoing: 'warning', upcoming: 'neutral' };
const RESTOCK_TONES = { late: 'danger', upcoming: 'neutral', done: 'success' };

const restock = computed(() => props.item.kind === 'restock');
const due = computed(() => dueOf(props.item));
const dayOfStep = computed(() => (props.item.state === 'ongoing' && props.item.days > 1 ? daysBetween(props.item.start, props.today) + 1 : null));
</script>

<template>
    <li :class="['agenda-row', `agenda-row--${item.state}`]">
        <span class="agenda-row__when">
            <span class="agenda-row__relative">{{ relativeDay(today, due) }}</span>
            <span class="agenda-row__date">{{ formatDate(due) }}</span>
        </span>

        <span class="agenda-row__what">
            <span v-if="restock" class="agenda-row__title">
                {{ t('planning.restock.item.title', { supplier: item.supplier.name }) }}
                <span v-if="item.note" class="agenda-row__note">{{ item.note }}</span>
            </span>
            <span v-else class="agenda-row__title">{{ item.label }}</span>
            <span class="agenda-row__context">
                <template v-if="restock">
                    {{ item.event ? t('planning.agenda.for', { name: item.event.name }) : t('planning.restock.item.target', { day: formatDate(item.day) }) }}
                </template>
                <template v-else>
                    <a :href="ownerUrl(item.owner)">{{ item.owner.name }}</a>
                    <ArrowRight class="agenda-row__arrow" size="0.875rem" aria-hidden="true" />
                    <span>{{ item.event?.name ?? t('planning.timeline.releaseOn', { day: formatDate(item.release.day) }) }}</span>
                    <span v-if="item.days > 1" class="agenda-row__duration">· {{ t('planning.agenda.duration', item.days) }}</span>
                </template>
            </span>
        </span>

        <span class="agenda-row__status">
            <span v-if="dayOfStep" class="agenda-row__progress">{{ t('planning.agenda.dayOf', { day: dayOfStep, days: item.days }) }}</span>
            <StatusBadge v-if="restock" :tone="RESTOCK_TONES[item.state]">{{ t(`planning.restock.states.${item.state}`, { day: formatDate(item.orderedOn) }) }}</StatusBadge>
            <StatusBadge v-else :tone="TONES[item.state]">{{ t(`planning.states.${item.state}`) }}</StatusBadge>
            <template v-if="restock">
                <BaseButton v-if="!item.ordered" variant="secondary" :href="orderRestockUrl(item)">{{ t('planning.restock.item.order') }}</BaseButton>
                <template v-if="editable">
                    <IconButton :icon="Pencil" :label="t('planning.restock.item.edit')" @click="emit('edit', item)" />
                    <ConfirmButton
                        :icon="Trash2"
                        :label="t('planning.restock.item.cancel')"
                        :confirm-label="t('planning.restock.item.cancel')"
                        :message="t('planning.restock.item.cancelMessage')"
                        @confirm="emit('cancel', item)"
                    />
                </template>
                <BaseCheckbox :model-value="item.ordered" :aria-label="t('planning.restock.item.ordered')" @update:model-value="emit('tick', item, $event)" />
            </template>
            <BaseCheckbox v-else :model-value="item.done" :aria-label="item.label" @update:model-value="emit('tick', item, $event)" />
        </span>
    </li>
</template>

<style scoped>
.agenda-row {
    display: grid;
    grid-template-columns: 7rem minmax(0, 1fr) auto;
    align-items: center;
    gap: var(--space-4);
    padding: var(--space-3) 0;
    border-top: 0.0625rem solid var(--color-border);
}

.agenda-row:first-child { border-top: none; }
.agenda-row__when { display: flex; flex-direction: column; font-variant-numeric: tabular-nums; }
.agenda-row__relative { color: var(--color-ink); font-weight: 600; }
.agenda-row--late .agenda-row__relative { color: var(--color-danger); }
.agenda-row__date { color: var(--color-muted); font-size: var(--font-size-sm); }
.agenda-row__what { display: flex; flex-direction: column; gap: 0.125rem; min-width: 0; }
.agenda-row__title { color: var(--color-ink); font-weight: 600; }
.agenda-row__note { margin-left: var(--space-1); color: var(--color-muted); font-weight: 400; }
.agenda-row__context { display: flex; flex-wrap: wrap; align-items: center; gap: var(--space-1); color: var(--color-muted); font-size: var(--font-size-sm); }
.agenda-row__context a { color: var(--color-text); }
.agenda-row__arrow { flex-shrink: 0; color: var(--color-subtle); }
.agenda-row__status { display: flex; flex-wrap: wrap; align-items: center; justify-content: flex-end; gap: var(--space-2); }
.agenda-row__progress { color: var(--color-warning); font-size: var(--font-size-sm); font-weight: 600; font-variant-numeric: tabular-nums; }

@media (max-width: 48rem) {
    .agenda-row { grid-template-columns: minmax(0, 1fr); gap: var(--space-2); }
    .agenda-row__when { flex-direction: row; align-items: baseline; gap: var(--space-2); }
    .agenda-row__status { justify-content: flex-start; }
}
</style>
