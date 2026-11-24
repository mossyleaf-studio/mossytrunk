<script setup>
import { TooltipArrow, TooltipContent, TooltipPortal, TooltipRoot, TooltipTrigger } from 'reka-ui';
</script>

<template>
    <TooltipRoot :delay-duration="0">
        <TooltipTrigger as-child><slot /></TooltipTrigger>
        <TooltipPortal>
            <TooltipContent class="chart-tip" side="top" :side-offset="4">
                <slot name="content" />
                <TooltipArrow class="chart-tip__arrow" :width="8" :height="4" />
            </TooltipContent>
        </TooltipPortal>
    </TooltipRoot>
</template>

<style>
.chart-tip {
    z-index: 70;
    display: flex;
    flex-direction: column;
    gap: 0.125rem;
    max-width: 20rem;
    padding: var(--space-2) var(--space-3);
    border-radius: var(--radius-inner);
    background: var(--color-ink);
    color: var(--color-surface);
    font-size: var(--font-size-sm);
    animation: fade-in var(--transition);
}

.chart-tip__arrow { fill: var(--color-ink); }
</style>
