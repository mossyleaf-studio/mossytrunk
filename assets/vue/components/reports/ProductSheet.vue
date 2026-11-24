<script setup>
import { computed } from 'vue';
import { X } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import IconButton from '../ui/IconButton.vue';
import Notice from '../ui/Notice.vue';
import SheetChecks from './SheetChecks.vue';
import SheetEvents from './SheetEvents.vue';
import SheetReceipt from './SheetReceipt.vue';
import VerdictBadge from './VerdictBadge.vue';
import { reasonSentence } from '../../composables/useProductAnalysis.js';

const props = defineProps({
    product: { type: Object, required: true },
    analysis: { type: Object, default: null },
});
const emit = defineEmits(['close']);
const { t } = useI18n();

const type = computed(() => props.analysis?.types.find((candidate) => candidate.id === props.product.typeId) ?? null);
const reason = computed(() => (props.analysis ? reasonSentence(props.product, props.analysis, t) : null));
const past = computed(() => props.analysis !== null && !props.analysis.period.current);
</script>

<template>
    <article class="product-sheet" aria-labelledby="product-sheet-title">
        <header class="product-sheet__header">
            <div class="product-sheet__identity">
                <p class="eyebrow">{{ product.typeName }}</p>
                <h2 id="product-sheet-title" class="product-sheet__name" tabindex="-1">{{ product.name }}</h2>
                <a :href="`/products/${product.id}`" class="product-sheet__link">{{ t('reports-sheet.openProduct') }}</a>
            </div>
            <IconButton :icon="X" :label="t('reports-sheet.close')" @click="emit('close')" />
        </header>

        <div v-if="reason" class="product-sheet__verdict">
            <VerdictBadge :verdict="product.verdict" :tip="false" />
            <p class="product-sheet__reason">{{ reason }}</p>
        </div>
        <Notice v-else-if="past" tone="success">{{ t('reports-sheet.past') }}</Notice>
        <Notice v-else-if="analysis === null" tone="success">{{ t('reports-sheet.archived') }}</Notice>

        <SheetChecks v-if="analysis" :product="product" :analysis="analysis" :type="type" />
        <SheetReceipt :product="product" />
        <SheetEvents v-if="analysis" :product="product" :analysis="analysis" />
    </article>
</template>

<style scoped>
.product-sheet {
    display: flex;
    flex-direction: column;
    gap: var(--space-4);
    padding: var(--space-5);
    border: 0.0625rem solid var(--color-border);
    border-radius: var(--radius);
    background: var(--color-surface);
}

.product-sheet__header { display: flex; align-items: flex-start; justify-content: space-between; gap: var(--space-3); }
.product-sheet__identity { display: flex; flex-direction: column; gap: var(--space-1); min-width: 0; }
.product-sheet__name { margin: 0; font-size: 1.6rem; line-height: 1.15; overflow-wrap: anywhere; }
.product-sheet__link { align-self: flex-start; color: var(--color-accent-strong); font-size: var(--font-size-md); }
.product-sheet__verdict { display: flex; flex-direction: column; align-items: flex-start; gap: var(--space-2); }
.product-sheet__reason { margin: 0; color: var(--color-ink); font-size: var(--font-size); line-height: 1.45; }
</style>
