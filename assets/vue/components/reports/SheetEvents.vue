<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const props = defineProps({
    product: { type: Object, required: true },
    analysis: { type: Object, required: true },
});
const { t } = useI18n();

const slots = computed(() => [
    ...props.analysis.events.map((event, index) => ({ key: event.id, name: event.name, units: props.product.byEvent[index] })),
    ...(props.analysis.offEventOrders > 0 ? [{ key: 'off', name: t('reports-sheet.where.offEvent'), units: props.product.offEvent }] : []),
]);
const highest = computed(() => Math.max(1, ...slots.value.map((slot) => slot.units ?? 0)));
const absent = computed(() => slots.value.some((slot) => slot.units === null));
</script>

<template>
    <section v-if="slots.length" class="sheet-events" aria-labelledby="sheet-events-title">
        <h3 id="sheet-events-title" class="subheading">{{ t('reports-sheet.where.title') }}</h3>
        <ul class="sheet-events__list">
            <li v-for="slot in slots" :key="slot.key" class="sheet-events__event">
                <span class="sheet-events__name">{{ slot.name }}</span>
                <span class="sheet-events__track" aria-hidden="true">
                    <span v-if="slot.units" class="sheet-events__bar" :style="{ width: `${(slot.units / highest) * 100}%` }" />
                </span>
                <span class="sheet-events__units">{{ slot.units === null ? '—' : t('reports-sheet.where.units', { count: slot.units }, slot.units) }}</span>
            </li>
        </ul>
        <p v-if="absent" class="sheet-events__note">{{ t('reports-sheet.where.absent') }}</p>
    </section>
</template>

<style scoped>
.sheet-events { display: flex; flex-direction: column; gap: var(--space-2); padding-top: var(--space-4); border-top: 0.0625rem solid var(--color-border); }
.sheet-events__list { display: flex; flex-direction: column; gap: var(--space-2); margin: 0; padding: 0; list-style: none; }

.sheet-events__event {
    display: grid;
    grid-template-columns: minmax(0, 8rem) minmax(0, 1fr) auto;
    align-items: center;
    gap: var(--space-3);
    font-size: var(--font-size-md);
}

.sheet-events__name { color: var(--color-ink); overflow-wrap: anywhere; }
.sheet-events__track { display: block; height: 0.5rem; border-left: 0.0625rem solid var(--color-border-strong); }
.sheet-events__bar { display: block; height: 100%; min-width: 0.25rem; background: var(--color-accent); border-radius: 0 var(--radius-sm) var(--radius-sm) 0; }
.sheet-events__units { color: var(--color-ink); font-variant-numeric: tabular-nums; text-align: right; white-space: nowrap; }
.sheet-events__note { margin: 0; color: var(--color-muted); font-size: var(--font-size-sm); }
</style>
