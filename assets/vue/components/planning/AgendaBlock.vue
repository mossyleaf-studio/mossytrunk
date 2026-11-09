<script setup>
import { useId } from 'vue';
import { ChevronRight } from '@lucide/vue';
import { CollapsibleContent, CollapsibleRoot, CollapsibleTrigger } from 'reka-ui';
import EmptyState from '../ui/EmptyState.vue';
import AgendaRow from './AgendaRow.vue';
import { itemKey } from '../../composables/usePlanning.js';

defineProps({
    title: { type: String, required: true },
    items: { type: Array, required: true },
    today: { type: String, required: true },
    tone: { type: String, default: 'neutral' },
    empty: { type: String, default: null },
    collapsible: { type: Boolean, default: false },
    editable: { type: Boolean, default: false },
});
const emit = defineEmits(['tick', 'edit', 'cancel']);
const titleId = useId();
</script>

<template>
    <CollapsibleRoot as="section" :default-open="!collapsible" :class="['agenda-block', `agenda-block--${tone}`]" :aria-labelledby="titleId">
        <h3 class="agenda-block__heading">
            <CollapsibleTrigger v-if="collapsible" class="agenda-block__trigger">
                <ChevronRight class="agenda-block__chevron" size="1rem" aria-hidden="true" />
                <span :id="titleId">{{ title }}</span>
                <span class="agenda-block__count">{{ items.length }}</span>
            </CollapsibleTrigger>
            <template v-else>
                <span :id="titleId">{{ title }}</span>
                <span v-if="items.length" class="agenda-block__count">{{ items.length }}</span>
            </template>
        </h3>
        <CollapsibleContent class="agenda-block__content">
            <ul v-if="items.length" class="agenda-block__rows">
                <AgendaRow
                    v-for="item in items"
                    :key="itemKey(item)"
                    :item="item"
                    :today="today"
                    :editable="editable"
                    @tick="(row, done) => emit('tick', row, done)"
                    @edit="emit('edit', $event)"
                    @cancel="emit('cancel', $event)"
                />
            </ul>
            <EmptyState v-else inline>{{ empty }}</EmptyState>
        </CollapsibleContent>
    </CollapsibleRoot>
</template>

<style scoped>
.agenda-block { display: flex; flex-direction: column; gap: var(--space-2); scroll-margin-top: var(--space-5); }
.agenda-block__heading { display: flex; align-items: center; gap: var(--space-2); margin: 0; color: var(--color-ink); font-family: var(--font-body); font-size: var(--font-size); font-weight: 600; }
.agenda-block__trigger { display: inline-flex; align-items: center; gap: var(--space-2); padding: 0; border: none; background: none; color: inherit; font: inherit; cursor: pointer; }
.agenda-block__trigger:focus-visible { border-radius: var(--radius-sm); box-shadow: var(--focus-ring); outline: none; }
.agenda-block__chevron { color: var(--color-muted); transition: transform var(--transition); }
.agenda-block__trigger[data-state="open"] .agenda-block__chevron { transform: rotate(90deg); }
.agenda-block__count { min-width: 1.375rem; padding: 0 var(--space-2); border-radius: var(--radius-pill); background: var(--color-bg); color: var(--color-muted); font-size: var(--font-size-xs); font-variant-numeric: tabular-nums; text-align: center; }
.agenda-block--danger .agenda-block__count { background: var(--color-danger-soft); color: var(--color-danger); }
.agenda-block--warning .agenda-block__count { background: var(--color-warning-soft); color: var(--color-warning); }
.agenda-block__rows { margin: 0; padding: 0; list-style: none; }
.agenda-block__content { overflow: hidden; }
.agenda-block__content[data-state="open"] { animation: agenda-block-open var(--transition); }
.agenda-block__content[data-state="closed"] { animation: agenda-block-close var(--transition); }

@keyframes agenda-block-open { from { height: 0; } to { height: var(--reka-collapsible-content-height); } }
@keyframes agenda-block-close { from { height: var(--reka-collapsible-content-height); } to { height: 0; } }

@media (prefers-reduced-motion: reduce) {
    .agenda-block__chevron { transition: none; }
    .agenda-block__content[data-state] { animation: none; }
}
</style>
