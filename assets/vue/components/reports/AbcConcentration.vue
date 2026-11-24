<script setup>
import { computed } from 'vue';
import { RovingFocusGroup, RovingFocusItem } from 'reka-ui';
import { useI18n } from 'vue-i18n';
import BaseCard from '../ui/BaseCard.vue';
import ChartTip from '../ui/ChartTip.vue';
import EmptyState from '../ui/EmptyState.vue';
import FormDisclosure from '../ui/FormDisclosure.vue';
import { CLASSES, classTone } from '../../composables/useProductAnalysis.js';
import { formatCents, formatPercent, formatRatio } from '../../composables/useMoney.js';

const props = defineProps({
    analysis: { type: Object, required: true },
    selectedId: { type: String, default: '' },
});
const emit = defineEmits(['select']);
const { t } = useI18n();

const marks = computed(() => [props.analysis.rules.classAPercent / 100, props.analysis.rules.classBPercent / 100]);
const products = computed(() => props.analysis.products);
const earned = computed(() => products.value.reduce((sum, product) => sum + Math.max(0, product.margin), 0));
const classes = computed(() => CLASSES.map((abcClass) => {
    const members = products.value.filter((product) => product.class === abcClass);
    return {
        abcClass,
        tone: classTone(abcClass),
        count: members.length,
        margin: members.reduce((sum, product) => sum + product.margin, 0),
        earned: members.reduce((sum, product) => sum + Math.max(0, product.margin), 0),
    };
}));
const classOf = (abcClass) => classes.value.find((entry) => entry.abcClass === abcClass);
const countOf = (abcClass) => classOf(abcClass).count;
const shareOfClass = (abcClass) => formatRatio(classOf(abcClass).earned, earned.value);

const headline = computed(() => {
    const total = products.value.length;
    const head = t('reports-concentration.head', { count: countOf('A'), total, share: shareOfClass('A') }, countOf('A'));
    const tailCount = countOf('C');
    if (tailCount === 0) return head;
    const tail = t('reports-concentration.tail', { count: tailCount, share: shareOfClass('C') }, tailCount);
    return t('reports-concentration.sentence', { head, tail });
});

const shareOf = (product) => product.cumulativeMargin / earned.value;
const x = (index) => ((index + 0.5) / products.value.length) * 100;
const y = (share) => 100 - share * 100;
const line = computed(() => ['0,100', ...products.value.map((product, index) => `${x(index)},${y(shareOf(product))}`)].join(' '));
const area = computed(() => `${line.value} ${x(products.value.length - 1)},100`);
const columns = computed(() => ({ gridTemplateColumns: `repeat(${products.value.length}, minmax(0, 1fr))` }));
const slotCount = computed(() => ({ '--slots': products.value.length }));
const segments = (key) => classes.value.filter((entry) => entry[key] > 0);
const track = (key) => ({ gridTemplateColumns: segments(key).map((entry) => `${entry[key]}fr`).join(' ') });
const tip = (product, index) => t('reports-concentration.tip', {
    rank: index + 1,
    name: product.name,
    margin: formatCents(product.margin),
    share: formatPercent(shareOf(product)),
    abcClass: product.class,
});
</script>

<template>
    <BaseCard :title="t('reports-concentration.title')">
        <EmptyState v-if="analysis.orders === 0">{{ t('reports-concentration.empty') }}</EmptyState>
        <EmptyState v-else-if="earned <= 0">{{ t('reports-concentration.noMargin') }}</EmptyState>
        <figure v-else class="concentration">
            <p class="concentration__headline">{{ headline }}</p>

            <ul class="concentration__legend">
                <li v-for="entry in classes" :key="entry.abcClass" class="concentration__key">
                    <span class="concentration__swatch" :style="{ background: entry.tone }" aria-hidden="true" />
                    <strong>{{ t(`reports.analysis.classes.${entry.abcClass}`) }}</strong>
                    <span class="concentration__figures">
                        {{ t('reports-concentration.products', { count: entry.count }, entry.count) }} ·
                        {{ t('reports-concentration.legendMargin', { amount: formatCents(entry.margin), share: formatRatio(entry.earned, earned) }) }}
                    </span>
                </li>
            </ul>

            <div class="concentration__chart" :style="slotCount">
                <span class="concentration__label concentration__label--title">{{ t('reports-concentration.cumulated') }}</span>
                <div class="concentration__scale" aria-hidden="true">
                    <span v-for="mark in marks" :key="mark" class="concentration__tick" :style="{ bottom: `${mark * 100}%` }">{{ formatPercent(mark) }}</span>
                </div>
                <div class="concentration__plot">
                    <span v-for="mark in marks" :key="mark" class="concentration__hairline" :style="{ bottom: `${mark * 100}%` }" aria-hidden="true" />
                    <svg class="concentration__curve" viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true">
                        <polygon :points="area" class="concentration__area" />
                        <polyline :points="line" class="concentration__line" vector-effect="non-scaling-stroke" />
                    </svg>
                    <RovingFocusGroup class="concentration__slots" :style="columns" orientation="horizontal" loop>
                        <ChartTip v-for="(product, index) in products" :key="product.id">
                            <RovingFocusItem as-child>
                                <button
                                    type="button"
                                    class="concentration__slot"
                                    :aria-pressed="product.id === selectedId"
                                    :aria-label="t('reports.palmares.select', { name: product.name })"
                                    @click="emit('select', product)"
                                >
                                    <span class="concentration__point" :style="{ bottom: `${shareOf(product) * 100}%` }" />
                                </button>
                            </RovingFocusItem>
                            <template #content>{{ tip(product, index) }}</template>
                        </ChartTip>
                    </RovingFocusGroup>
                </div>

                <span />
                <span class="concentration__axis">{{ t('reports-concentration.axis') }}</span>

                <template v-for="key in ['earned', 'count']" :key="key">
                    <span class="concentration__label concentration__label--bar">{{ t(`reports-concentration.bars.${key === 'earned' ? 'margin' : 'products'}`) }}</span>
                    <span class="concentration__track" :style="track(key)" aria-hidden="true">
                        <span v-for="entry in segments(key)" :key="entry.abcClass" class="concentration__segment" :style="{ background: entry.tone }" />
                    </span>
                </template>
            </div>

            <FormDisclosure :title="t('reports.analysis.how.title')">
                <p class="concentration__how">{{ t('reports.analysis.how.margin') }}</p>
                <p class="concentration__how">{{ t('reports.analysis.how.classes', { a: analysis.rules.classAPercent, b: analysis.rules.classBPercent }) }}</p>
                <p class="concentration__how">{{ t('reports-concentration.how.curve') }}</p>
            </FormDisclosure>
        </figure>
    </BaseCard>
</template>

<style scoped>
.concentration { display: flex; flex-direction: column; gap: var(--space-4); margin: 0; }
.concentration__headline { margin: 0; color: var(--color-ink); font-family: var(--font-display); font-size: 1.25rem; line-height: 1.3; }

.concentration__how { margin: 0; color: var(--color-muted); font-size: var(--font-size-sm); line-height: 1.5; }

.concentration__legend { display: flex; flex-wrap: wrap; gap: var(--space-2) var(--space-5); margin: 0; padding: 0; list-style: none; font-size: var(--font-size-sm); }
.concentration__key { display: flex; flex-wrap: wrap; align-items: center; gap: var(--space-1) var(--space-2); color: var(--color-ink); }
.concentration__swatch { width: 0.75rem; height: 0.75rem; border-radius: var(--radius-sm); }
.concentration__figures { color: var(--color-muted); font-variant-numeric: tabular-nums; }

.concentration__chart { display: grid; grid-template-columns: 3rem minmax(0, 1fr); gap: var(--space-1) var(--space-2); align-items: center; overflow-x: auto; }
.concentration__label { color: var(--color-muted); font-size: var(--font-size-xs); }
.concentration__label--title { grid-column: 1 / -1; }
.concentration__label--bar { text-align: right; }

.concentration__scale,
.concentration__plot { position: relative; height: 12rem; align-self: stretch; }
.concentration__tick { position: absolute; right: 0; transform: translateY(50%); color: var(--color-muted); font-size: var(--font-size-2xs); font-variant-numeric: tabular-nums; }
.concentration__plot { border-bottom: 0.0625rem solid var(--color-border-strong); }
.concentration__hairline { position: absolute; right: 0; left: 0; border-top: 0.0625rem dashed var(--color-border-strong); }
.concentration__curve { position: absolute; inset: 0; width: 100%; height: 100%; overflow: visible; pointer-events: none; }
.concentration__area { fill: var(--color-heat-1); }
.concentration__line { fill: none; stroke: var(--color-accent-strong); stroke-width: 2; stroke-linejoin: round; }

.concentration__slots { position: absolute; inset: 0; display: grid; }
.concentration__slot { position: relative; min-width: 0; padding: 0; border: 0; border-radius: var(--radius-sm); background: transparent; cursor: pointer; }
.concentration__slot:hover,
.concentration__slot:focus-visible,
.concentration__slot[aria-pressed='true'] { background: color-mix(in oklch, var(--color-ink) 6%, transparent); }
.concentration__point {
    position: absolute;
    left: 50%;
    width: 0.625rem;
    height: 0.625rem;
    transform: translate(-50%, 50%);
    border-radius: 50%;
    background: var(--color-accent-strong);
    box-shadow: 0 0 0 0.125rem var(--color-surface);
    opacity: 0;
    transition: opacity var(--transition);
}
.concentration__slot:hover .concentration__point,
.concentration__slot:focus-visible .concentration__point,
.concentration__slot[aria-pressed='true'] .concentration__point { opacity: 1; }

.concentration__axis { color: var(--color-muted); font-size: var(--font-size-2xs); }
.concentration__track { display: grid; gap: 0.125rem; height: 0.5rem; }
.concentration__segment { min-width: 0; border-radius: var(--radius-sm); }

@media (pointer: coarse) {
    .concentration__chart { grid-template-columns: 3rem minmax(calc(var(--slots) * 1.5rem), 1fr); padding-bottom: var(--space-1); }
}
</style>
