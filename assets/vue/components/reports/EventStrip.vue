<script setup>
import { computed } from 'vue';
import { VisuallyHidden } from 'reka-ui';
import { useI18n } from 'vue-i18n';
import ChartTip from '../ui/ChartTip.vue';

const props = defineProps({
    slots: { type: Array, required: true },
});

const { t } = useI18n();

const highest = computed(() => Math.max(1, ...props.slots.map((slot) => slot.units ?? 0)));
const height = (units) => `${(units / highest.value) * 100}%`;
const lines = computed(() => props.slots
    .map(({ name, units }) => (units === null ? t('reports-decisions.strip.absent', { event: name }) : t('reports-decisions.strip.units', { event: name, count: units }, units))));
</script>

<template>
    <ChartTip>
        <span class="event-strip" tabindex="0">
            <span class="event-strip__bars" aria-hidden="true">
                <span v-for="(slot, index) in slots" :key="index" :class="['event-strip__slot', { 'event-strip__slot--absent': slot.units === null }]">
                    <template v-if="slot.units === null">—</template>
                    <span v-else class="event-strip__bar" :style="{ height: height(slot.units) }" />
                </span>
            </span>
            <VisuallyHidden>{{ lines.join(', ') }}</VisuallyHidden>
        </span>
        <template #content>
            <span v-for="line in lines" :key="line">{{ line }}</span>
        </template>
    </ChartTip>
</template>

<style scoped>
.event-strip { display: inline-flex; padding: 0.125rem; border-radius: var(--radius-sm); vertical-align: middle; cursor: help; }
.event-strip__bars { display: inline-flex; align-items: flex-end; gap: 0.125rem; height: 1.25rem; }

.event-strip__slot {
    display: flex;
    align-items: flex-end;
    justify-content: center;
    width: 0.625rem;
    height: 100%;
    border-bottom: 0.0625rem solid var(--color-border-strong);
}

.event-strip__slot--absent { align-items: center; border-bottom-color: transparent; color: var(--color-subtle); font-size: var(--font-size-2xs); line-height: 1; }
.event-strip__bar { width: 100%; background: var(--color-accent); border-radius: 0.125rem 0.125rem 0 0; }
</style>
