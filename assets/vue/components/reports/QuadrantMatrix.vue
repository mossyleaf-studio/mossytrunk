<script setup>
import { computed, useId } from 'vue';
import { useI18n } from 'vue-i18n';
import BaseCard from '../ui/BaseCard.vue';
import EmptyState from '../ui/EmptyState.vue';
import FormDisclosure from '../ui/FormDisclosure.vue';
import Notice from '../ui/Notice.vue';
import QuadrantPanel from './QuadrantPanel.vue';
import VerdictBadge from './VerdictBadge.vue';
import { QUADRANTS } from '../../composables/useProductAnalysis.js';

const props = defineProps({
    analysis: { type: Object, required: true },
    selectedId: { type: String, default: '' },
});
const emit = defineEmits(['select']);
const { t } = useI18n();
const apartId = useId();

const panels = computed(() => props.analysis.types
    .map((type) => ({ type, products: props.analysis.products.filter((product) => product.typeId === type.id && product.quadrant !== null) }))
    .filter(({ products }) => products.length > 0));
const apart = computed(() => props.analysis.products.filter((product) => product.quadrant === null));
</script>

<template>
    <BaseCard :title="t('reports-matrix.title')" class="quadrant-matrix">
        <EmptyState v-if="analysis.orders === 0">{{ t('reports-matrix.empty') }}</EmptyState>
        <div v-else class="quadrant-matrix__body">
            <p class="quadrant-matrix__lead">{{ t('reports-matrix.lead') }}</p>
            <Notice v-if="!analysis.period.current" tone="success">{{ t('reports-matrix.past') }}</Notice>
            <FormDisclosure :title="t('reports.analysis.how.title')">
                <p class="quadrant-matrix__how">{{ t('reports.analysis.how.pace') }}</p>
                <p class="quadrant-matrix__how">{{ t('reports.analysis.how.unitMargin') }}</p>
                <p class="quadrant-matrix__how">{{ t('reports-matrix.how.lines', { pace: analysis.rules.popularPercent, margin: analysis.rules.profitablePercent }) }}</p>
                <ul class="quadrant-matrix__how quadrant-matrix__names">
                    <li v-for="quadrant in QUADRANTS" :key="quadrant">{{ t(`reports.analysis.how.quadrants.${quadrant}`) }}</li>
                </ul>
            </FormDisclosure>
            <div v-if="panels.length > 0" class="quadrant-matrix__panels">
                <QuadrantPanel
                    v-for="{ type, products } in panels"
                    :key="type.id"
                    :type="type"
                    :rules="analysis.rules"
                    :products="products"
                    :selected-id="selectedId"
                    @select="emit('select', $event)"
                />
            </div>
            <EmptyState v-else inline>{{ t('reports-matrix.noneJudged') }}</EmptyState>

            <section v-if="apart.length > 0" class="quadrant-matrix__apart" :aria-labelledby="apartId">
                <h3 :id="apartId" class="quadrant-matrix__subtitle">{{ t('reports-matrix.apart.title') }}</h3>
                <p class="quadrant-matrix__lead">{{ t('reports-matrix.apart.lead') }}</p>
                <ul class="quadrant-matrix__list">
                    <li v-for="product in apart" :key="product.id" class="quadrant-matrix__item">
                        <button
                            type="button"
                            :class="['quadrant-matrix__product', { 'quadrant-matrix__product--selected': product.id === selectedId }]"
                            :aria-pressed="product.id === selectedId"
                            :aria-label="t('reports.palmares.select', { name: product.name })"
                            :aria-describedby="`${apartId}-${product.id}`"
                            @click="emit('select', product)"
                        >
                            {{ product.name }}
                            <span :id="`${apartId}-${product.id}`" class="quadrant-matrix__meta">{{ product.typeName }} · {{ t('reports-matrix.apart.units', product.units) }}</span>
                        </button>
                        <VerdictBadge v-if="product.verdict !== null" :verdict="product.verdict" />
                    </li>
                </ul>
            </section>
        </div>
    </BaseCard>
</template>

<style scoped>
.quadrant-matrix { min-width: 0; }
.quadrant-matrix__body { display: flex; flex-direction: column; gap: var(--space-5); }
.quadrant-matrix__lead { margin: 0; color: var(--color-muted); font-size: var(--font-size-sm); }
.quadrant-matrix__how { margin: 0; font-size: var(--font-size-sm); }
.quadrant-matrix__names { display: flex; flex-direction: column; gap: var(--space-1); padding-left: var(--space-5); }

.quadrant-matrix__panels {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(min(100%, 24rem), 1fr));
    gap: var(--space-6) var(--space-5);
}

.quadrant-matrix__apart { display: flex; flex-direction: column; gap: var(--space-2); padding-top: var(--space-4); border-top: 0.0625rem solid var(--color-border); }
.quadrant-matrix__subtitle { margin: 0; font-size: var(--font-size-md); }

.quadrant-matrix__list {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(min(100%, 18rem), 1fr));
    gap: var(--space-1) var(--space-4);
    margin: 0;
    padding: 0;
    list-style: none;
}

.quadrant-matrix__item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-3);
    padding: var(--space-2) 0;
    border-bottom: 0.0625rem solid var(--color-border);
}

.quadrant-matrix__product {
    display: flex;
    flex-direction: column;
    min-width: 0;
    padding: 0;
    border: none;
    background: none;
    color: var(--color-ink);
    font: inherit;
    font-weight: 600;
    text-align: left;
    cursor: pointer;
}

.quadrant-matrix__product:hover,
.quadrant-matrix__product--selected { color: var(--color-accent-strong); }

.quadrant-matrix__meta { color: var(--color-muted); font-size: var(--font-size-xs); font-weight: 400; }
</style>
