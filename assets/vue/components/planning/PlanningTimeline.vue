<script setup>
import { computed, onMounted, ref } from 'vue';
import { Flag } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import { ScrollAreaRoot, ScrollAreaScrollbar, ScrollAreaThumb, ScrollAreaViewport, TooltipArrow, TooltipContent, TooltipPortal, TooltipRoot, TooltipTrigger } from 'reka-ui';
import EmptyState from '../ui/EmptyState.vue';
import { timelineOf } from '../../composables/usePlanningTimeline.js';

const props = defineProps({
    planning: { type: Object, required: true },
});
const { t } = useI18n();

const timeline = computed(() => timelineOf(props.planning));
const DAYS_SHOWN_BEFORE_TODAY = 2;
const scrollArea = ref(null);
const chart = ref(null);

onMounted(() => {
    const viewport = scrollArea.value?.viewport;
    if (!viewport) return;
    const label = chart.value.querySelector('.planning-timeline__label').offsetWidth;
    const day = (chart.value.offsetWidth - label) / timeline.value.days;
    viewport.scrollLeft = day * (timeline.value.today - DAYS_SHOWN_BEFORE_TODAY);
});
</script>

<template>
    <EmptyState v-if="!timeline.lanes.length">{{ t('planning.overview.empty') }}</EmptyState>
    <ScrollAreaRoot v-else ref="scrollArea" class="planning-timeline" type="auto">
        <ScrollAreaViewport class="planning-timeline__viewport">
            <div
                ref="chart"
                class="planning-timeline__chart"
                role="group"
                :aria-label="t('planning.overview.label', { from: timeline.from, to: timeline.to })"
                :style="{ '--days': timeline.days, '--weeks': timeline.weeks.length, '--today': timeline.today }"
            >
                <div class="planning-timeline__row planning-timeline__row--weeks" aria-hidden="true">
                    <span class="planning-timeline__label" />
                    <span class="planning-timeline__track">
                        <span v-for="week in timeline.weeks" :key="week.key" class="planning-timeline__week">{{ week.label }}</span>
                    </span>
                </div>

                <div v-for="lane in timeline.lanes" :key="lane.key" class="planning-timeline__lane" role="group" :aria-label="lane.name">
                    <div v-for="row in lane.rows" :key="row.key" :class="['planning-timeline__row', { 'planning-timeline__row--head': row.head }]">
                        <span class="planning-timeline__label">
                            <a v-if="row.href" :href="row.href" class="planning-timeline__name">{{ row.label }}</a>
                            <span v-else class="planning-timeline__name">{{ row.label }}</span>
                            <span v-if="row.meta" class="planning-timeline__meta">{{ row.meta }}</span>
                        </span>
                        <span class="planning-timeline__track">
                            <TooltipRoot v-for="(item, index) in row.items" :key="index">
                                <TooltipTrigger as-child>
                                    <span
                                        role="img"
                                        tabindex="0"
                                        :aria-label="item.label"
                                        :class="['planning-timeline__item', `planning-timeline__item--${item.shape}`, `planning-timeline__item--${item.tone}`]"
                                        :style="{ '--from': item.from, '--span': item.span }"
                                    >
                                        <template v-if="item.shape === 'flag'"><span class="planning-timeline__flag-text">{{ item.text }}</span><Flag size="0.875rem" aria-hidden="true" /></template>
                                        <template v-else-if="item.text">{{ item.text }}</template>
                                    </span>
                                </TooltipTrigger>
                                <TooltipPortal>
                                    <TooltipContent class="planning-timeline__tooltip" side="top" :side-offset="4" data-test="timeline-tooltip">
                                        <strong>{{ item.parts[0] }}</strong>
                                        <span v-for="part in item.parts.slice(1)" :key="part">{{ part }}</span>
                                        <TooltipArrow class="planning-timeline__arrow" :width="8" :height="4" />
                                    </TooltipContent>
                                </TooltipPortal>
                            </TooltipRoot>
                        </span>
                    </div>
                </div>

                <span class="planning-timeline__today" aria-hidden="true"><span class="planning-timeline__today-label">{{ t('planning.overview.today') }}</span></span>
            </div>
        </ScrollAreaViewport>
        <ScrollAreaScrollbar class="planning-timeline__scrollbar" orientation="horizontal">
            <ScrollAreaThumb class="planning-timeline__thumb" />
        </ScrollAreaScrollbar>
    </ScrollAreaRoot>
</template>

<style scoped>
.planning-timeline { --min-day: 1.125rem; --label-width: 13rem; position: relative; overflow: hidden; }
.planning-timeline__viewport { width: 100%; }
.planning-timeline__chart { position: relative; width: max(100%, calc(var(--label-width) + var(--min-day) * var(--days))); padding-bottom: var(--space-3); }

.planning-timeline__row { display: flex; min-height: 2.25rem; }
.planning-timeline__row--head { min-height: 2.75rem; }
.planning-timeline__lane { border-top: 0.0625rem solid var(--color-border); }
.planning-timeline__lane .planning-timeline__row + .planning-timeline__row .planning-timeline__label { padding-left: var(--space-4); }

.planning-timeline__label {
    position: sticky;
    left: 0;
    z-index: 2;
    display: flex;
    flex: 0 0 var(--label-width);
    flex-direction: column;
    justify-content: center;
    min-width: 0;
    padding: var(--space-1) var(--space-3) var(--space-1) 0;
    border-right: 0.0625rem solid var(--color-border);
    background: var(--color-surface);
}

.planning-timeline__name { overflow: hidden; color: var(--color-text); font-size: var(--font-size-sm); text-overflow: ellipsis; white-space: nowrap; }
.planning-timeline__row--head .planning-timeline__name { color: var(--color-ink); font-size: var(--font-size-md); font-weight: 600; }
.planning-timeline__meta { overflow: hidden; color: var(--color-muted); font-size: var(--font-size-xs); text-overflow: ellipsis; white-space: nowrap; }

.planning-timeline__track {
    position: relative;
    flex: 1 1 auto;
    background-image: repeating-linear-gradient(to right, var(--color-border) 0 0.0625rem, transparent 0.0625rem calc(100% / var(--weeks)));
}

.planning-timeline__row--weeks { min-height: 2.75rem; }
.planning-timeline__row--weeks .planning-timeline__track { display: flex; background: none; }
.planning-timeline__week { flex: 1 1 0; padding: var(--space-1) var(--space-1) 0; border-left: 0.0625rem solid var(--color-border); color: var(--color-muted); font-size: var(--font-size-xs); font-variant-numeric: tabular-nums; white-space: nowrap; }

.planning-timeline__item {
    position: absolute;
    top: 50%;
    left: calc(100% * var(--from) / var(--days));
    width: calc(100% * var(--span) / var(--days));
    height: 1.375rem;
    transform: translateY(-50%);
    outline: none;
}

.planning-timeline__item:focus-visible { box-shadow: var(--focus-ring); }

.planning-timeline__item--segment {
    overflow: hidden;
    margin-left: 0.0625rem;
    width: calc(100% * var(--span) / var(--days) - 0.125rem);
    padding: 0 var(--space-1);
    border: 0.0625rem solid var(--color-border-strong);
    border-radius: var(--radius-sm);
    background: var(--color-bg);
    color: var(--color-text);
    font-size: var(--font-size-2xs);
    font-weight: 600;
    line-height: 1.25rem;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.planning-timeline__item--segment.planning-timeline__item--done { border-color: transparent; background: var(--color-success-soft); color: var(--color-success); text-decoration: line-through; }
.planning-timeline__item--segment.planning-timeline__item--late { border-color: var(--color-danger); background: var(--color-danger-soft); color: var(--color-danger); }
.planning-timeline__item--segment.planning-timeline__item--ongoing { border-color: var(--color-warning); background: var(--color-warning-soft); color: var(--color-warning); }

.planning-timeline__item--bar { height: 0.875rem; border-radius: var(--radius-pill); background: var(--color-accent); }

.planning-timeline__item--flag { display: flex; align-items: center; justify-content: center; color: var(--color-success); }
.planning-timeline__flag-text { position: absolute; right: 100%; color: var(--color-success); font-size: var(--font-size-xs); font-weight: 600; white-space: nowrap; }

.planning-timeline__item--diamond::before {
    position: absolute;
    top: 50%;
    left: 50%;
    width: 0.625rem;
    height: 0.625rem;
    border: 0.125rem solid var(--color-border-strong);
    background: var(--color-surface);
    content: "";
    transform: translate(-50%, -50%) rotate(45deg);
}

.planning-timeline__item--diamond.planning-timeline__item--late::before { border-color: var(--color-danger); background: var(--color-danger-soft); }
.planning-timeline__item--diamond.planning-timeline__item--done::before { border-color: var(--color-success); background: var(--color-success); }

.planning-timeline__today {
    position: absolute;
    top: 1.5rem;
    bottom: 0;
    left: calc(var(--label-width) + (100% - var(--label-width)) * (var(--today) + 0.5) / var(--days));
    z-index: 1;
    width: 0.125rem;
    margin-left: -0.0625rem;
    background: var(--color-ink);
    pointer-events: none;
}

.planning-timeline__today-label { position: absolute; top: 0; left: 50%; padding: 0 var(--space-1); border-radius: var(--radius-sm); background: var(--color-ink); color: var(--color-surface); font-size: var(--font-size-2xs); font-weight: 600; white-space: nowrap; transform: translateX(-50%); }

.planning-timeline__scrollbar { display: flex; height: 0.5rem; padding: 0.0625rem; touch-action: none; user-select: none; }
.planning-timeline__thumb { flex: 1; border-radius: var(--radius-pill); background: var(--color-border-strong); }

@media (max-width: 48rem) {
    .planning-timeline { --label-width: 7.5rem; }
}
</style>

<style>
.planning-timeline__tooltip {
    z-index: 70;
    display: flex;
    flex-direction: column;
    gap: 0.125rem;
    padding: var(--space-2) var(--space-3);
    border-radius: var(--radius-inner);
    background: var(--color-ink);
    color: var(--color-surface);
    font-size: var(--font-size-sm);
    animation: fade-in var(--transition);
}

.planning-timeline__arrow { fill: var(--color-ink); }
</style>
