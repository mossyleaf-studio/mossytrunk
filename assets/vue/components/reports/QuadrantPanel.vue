<script setup>
import { computed, useId } from 'vue';
import { useI18n } from 'vue-i18n';
import { RovingFocusGroup, RovingFocusItem } from 'reka-ui';
import ChartTip from '../ui/ChartTip.vue';
import { QUADRANTS, formatRate } from '../../composables/useProductAnalysis.js';
import { formatCents } from '../../composables/useMoney.js';

const props = defineProps({
    type: { type: Object, required: true },
    rules: { type: Object, required: true },
    products: { type: Array, required: true },
    selectedId: { type: String, default: '' },
});
const emit = defineEmits(['select']);
const { t } = useI18n();
const titleId = useId();

const HEADROOM = 1.25;
const pace = (product) => product.per100Orders ?? 0;

const xMax = computed(() => Math.max(...props.products.map(pace), props.type.popularFrom ?? 0, 1) * HEADROOM);
const yMax = computed(() => Math.max(...props.products.map((product) => product.unitMargin), props.type.unitMarginFrom ?? 0, 1) * HEADROOM);
const yMin = computed(() => Math.min(...props.products.map((product) => product.unitMargin), 0));
const left = (value) => `${(value / xMax.value) * 100}%`;
const bottom = (value) => `${((value - yMin.value) / (yMax.value - yMin.value)) * 100}%`;

const counts = computed(() => QUADRANTS.map((quadrant) => ({
    quadrant,
    count: props.products.filter((product) => product.quadrant === quadrant).length,
})));
const describe = (product) => t('reports-matrix.dot', {
    name: product.name,
    rate: formatRate(pace(product)),
    amount: formatCents(product.unitMargin),
    quadrant: t(`reports.analysis.quadrants.${product.quadrant}`),
});
</script>

<template>
    <section class="quadrant-panel" :aria-labelledby="titleId">
        <h3 :id="titleId" class="quadrant-panel__title">{{ type.name }}</h3>
        <div class="quadrant-panel__chart">
            <p class="quadrant-panel__axis quadrant-panel__axis--y">{{ t('reports-matrix.axis.unitMargin') }}</p>
            <div class="quadrant-panel__frame">
                <div class="quadrant-panel__plot">
                    <span v-for="quadrant in QUADRANTS" :key="quadrant" :class="['quadrant-panel__corner', `quadrant-panel__corner--${quadrant}`]">{{ t(`reports.analysis.quadrants.${quadrant}`) }}</span>
                    <span v-if="type.popularFrom !== null" class="quadrant-panel__line quadrant-panel__line--x" :style="{ left: left(type.popularFrom) }" aria-hidden="true" />
                    <span v-if="type.unitMarginFrom !== null" class="quadrant-panel__line quadrant-panel__line--y" :style="{ bottom: bottom(type.unitMarginFrom) }" aria-hidden="true" />
                    <span v-if="type.unitMarginFrom !== null" class="quadrant-panel__tick quadrant-panel__tick--y" :style="{ bottom: bottom(type.unitMarginFrom) }"><span class="quadrant-panel__tick-word">{{ t('reports-matrix.limit') }}</span> {{ formatCents(type.unitMarginFrom) }}</span>
                    <span v-if="type.popularFrom !== null" class="quadrant-panel__tick quadrant-panel__tick--x" :style="{ left: left(type.popularFrom) }"><span class="quadrant-panel__tick-word">{{ t('reports-matrix.limit') }}</span> {{ formatRate(type.popularFrom) }}</span>
                    <RovingFocusGroup as="div" class="quadrant-panel__dots">
                        <ChartTip v-for="product in products" :key="product.id">
                            <RovingFocusItem as-child>
                                <button
                                    type="button"
                                    class="quadrant-panel__dot"
                                    :style="{ left: left(pace(product)), bottom: bottom(product.unitMargin) }"
                                    :aria-pressed="product.id === selectedId"
                                    :aria-label="t('reports.palmares.select', { name: product.name })"
                                    @click="emit('select', product)"
                                />
                            </RovingFocusItem>
                            <template #content>{{ describe(product) }}</template>
                        </ChartTip>
                    </RovingFocusGroup>
                </div>
            </div>
            <p class="quadrant-panel__axis quadrant-panel__axis--x">{{ t('reports-matrix.axis.pace') }}</p>
        </div>
        <ul class="quadrant-panel__limits">
            <li v-if="type.popularFrom !== null" class="quadrant-panel__limit">
                <span class="quadrant-panel__key quadrant-panel__key--x" aria-hidden="true" />
                {{ t('reports-matrix.lines.pace', { limit: formatRate(type.popularFrom), percent: rules.popularPercent, type: type.name, average: formatRate(type.averagePace) }) }}
            </li>
            <li v-if="type.unitMarginFrom !== null" class="quadrant-panel__limit">
                <span class="quadrant-panel__key quadrant-panel__key--y" aria-hidden="true" />
                {{ t('reports-matrix.lines.unitMargin', { limit: formatCents(type.unitMarginFrom), percent: rules.profitablePercent, average: formatCents(type.averageUnitMargin) }) }}
            </li>
        </ul>
        <ul class="quadrant-panel__counts">
            <li v-for="{ quadrant, count } in counts" :key="quadrant">{{ t(`reports.analysis.quadrants.${quadrant}`) }} <strong class="quadrant-panel__count">{{ count }}</strong></li>
        </ul>
    </section>
</template>

<style scoped>
.quadrant-panel { display: flex; flex-direction: column; gap: var(--space-3); min-width: 0; }
.quadrant-panel__title { margin: 0; font-size: var(--font-size-md); }
.quadrant-panel__chart { display: flex; flex-direction: column; gap: var(--space-2); }

.quadrant-panel__axis {
    margin: 0;
    color: var(--color-muted);
    font-size: var(--font-size-xs);
    letter-spacing: var(--tracking-caps);
    text-transform: uppercase;
}

.quadrant-panel__axis--x { text-align: right; }
.quadrant-panel__frame { position: relative; padding: 0 0 1.25rem 4.5rem; }

.quadrant-panel__plot {
    position: relative;
    height: 15rem;
    border-bottom: 0.0625rem solid var(--color-border-strong);
    border-left: 0.0625rem solid var(--color-border-strong);
}

.quadrant-panel__tick {
    position: absolute;
    color: var(--color-ink);
    font-size: var(--font-size-xs);
    font-variant-numeric: tabular-nums;
    white-space: nowrap;
}

.quadrant-panel__tick--y { right: calc(100% + var(--space-2)); display: flex; flex-direction: column; align-items: flex-end; transform: translateY(50%); }
.quadrant-panel__tick--x { top: calc(100% + var(--space-1)); transform: translateX(-50%); }
.quadrant-panel__tick-word { color: var(--color-muted); font-size: var(--font-size-2xs); letter-spacing: var(--tracking-caps); text-transform: uppercase; }

.quadrant-panel__corner {
    position: absolute;
    z-index: 1;
    padding: 0 0.125rem;
    background: var(--color-surface);
    color: var(--color-muted);
    font-size: var(--font-size-2xs);
    letter-spacing: var(--tracking-caps);
    text-transform: uppercase;
}

.quadrant-panel__corner--star { top: var(--space-1); right: var(--space-1); }
.quadrant-panel__corner--puzzle { top: var(--space-1); left: var(--space-2); }
.quadrant-panel__corner--workhorse { right: var(--space-1); bottom: var(--space-1); }
.quadrant-panel__corner--dog { bottom: var(--space-1); left: var(--space-2); }

.quadrant-panel__line { position: absolute; background: var(--color-border-strong); }
.quadrant-panel__line--x { top: 0; bottom: 0; width: 0.0625rem; }
.quadrant-panel__line--y { right: 0; left: 0; height: 0.0625rem; }
.quadrant-panel__dots { position: absolute; z-index: 2; inset: 0; }

.quadrant-panel__dot {
    position: absolute;
    width: 1.5rem;
    height: 1.5rem;
    padding: 0;
    border: none;
    border-radius: 50%;
    background: none;
    cursor: pointer;
    transform: translate(-50%, 50%);
}

.quadrant-panel__dot::before {
    content: '';
    position: absolute;
    inset: 0.4375rem;
    border-radius: 50%;
    background: var(--color-accent);
    box-shadow: 0 0 0 0.125rem var(--color-surface);
    transition: background var(--transition), inset var(--transition);
}

.quadrant-panel__dot:hover::before { background: var(--color-accent-strong); inset: 0.3125rem; }
.quadrant-panel__dot[aria-pressed='true'] { z-index: 1; }
.quadrant-panel__dot[aria-pressed='true']::before { inset: 0.25rem; background: var(--color-ink); }

.quadrant-panel__limits {
    display: flex;
    flex-direction: column;
    gap: var(--space-1);
    margin: 0;
    padding: 0;
    font-size: var(--font-size-sm);
    list-style: none;
}

.quadrant-panel__limit { display: flex; align-items: baseline; gap: var(--space-2); }
.quadrant-panel__key { flex-shrink: 0; align-self: center; background: var(--color-border-strong); }
.quadrant-panel__key--x { width: 0.125rem; height: 0.875rem; margin: 0 0.375rem; }
.quadrant-panel__key--y { width: 0.875rem; height: 0.125rem; }

.quadrant-panel__counts {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-1) var(--space-4);
    margin: 0;
    padding: 0;
    color: var(--color-muted);
    font-size: var(--font-size-sm);
    list-style: none;
}

.quadrant-panel__count { color: var(--color-ink); font-variant-numeric: tabular-nums; }
</style>
