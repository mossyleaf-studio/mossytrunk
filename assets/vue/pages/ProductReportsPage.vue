<script setup>
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { ToggleGroupItem } from 'reka-ui';
import { useI18n } from 'vue-i18n';
import AppLayout from '../layouts/AppLayout.vue';
import BaseSelect from '../components/ui/BaseSelect.vue';
import ChoiceGroup from '../components/ui/ChoiceGroup.vue';
import AbcConcentration from '../components/reports/AbcConcentration.vue';
import EventHeat from '../components/reports/EventHeat.vue';
import ProductDecisions from '../components/reports/ProductDecisions.vue';
import ProductPalmares from '../components/reports/ProductPalmares.vue';
import ProductStory from '../components/reports/ProductStory.vue';
import QuadrantMatrix from '../components/reports/QuadrantMatrix.vue';
import StockCover from '../components/reports/StockCover.vue';
import { formatCents } from '../composables/useMoney.js';
import { LAST_TWELVE_MONTHS, SINCE_THE_START, VIEWS, useProductReports } from '../composables/useProductReports.js';

const { t } = useI18n();
const { period, productId, view, report, analysis, story, loadAll, loadStory } = useProductReports();
const busy = ref(false);

const periodOptions = computed(() => [
    { value: LAST_TWELVE_MONTHS, label: t('reports.period.last12') },
    ...(report.value?.years ?? []).map((year) => ({ value: String(year), label: t('reports.period.year', { year }) })),
    { value: SINCE_THE_START, label: t('reports.period.all') },
]);
const totals = computed(() => report.value?.totals ?? null);

async function select(product) {
    productId.value = product.id === productId.value ? '' : product.id;
    await loadStory();
    await nextTick();
    if (productId.value && window.matchMedia(view.value !== 'palmares' ? '(width < 110rem)' : '(max-width: 64rem)').matches) {
        const smooth = !window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        document.getElementById('product-story-title')?.scrollIntoView({ behavior: smooth ? 'smooth' : 'auto', block: 'start' });
    }
}

function closeStory() {
    productId.value = '';
    loadStory();
}

watch(period, async () => {
    busy.value = true;
    await loadAll().finally(() => { busy.value = false; });
});
onMounted(loadAll);
</script>

<template>
    <AppLayout :title="t('reports.title')">
        <template #actions>
            <BaseSelect v-model="period" :options="periodOptions" :aria-label="t('reports.period.label')" class="product-reports__period" />
        </template>

        <div v-if="report" :class="['product-reports', { 'product-reports--busy': busy }]" :aria-busy="busy">
            <p class="product-reports__summary">
                <template v-if="totals.units > 0">{{ t('reports.summary', { revenue: formatCents(totals.revenue), units: totals.units, discount: formatCents(totals.discount) }) }}</template>
                <template v-else>{{ t('reports.summaryEmpty') }}</template>
            </p>

            <ChoiceGroup v-model="view" class="product-reports__views" :aria-label="t('reports.views.label')">
                <ToggleGroupItem v-for="key in VIEWS" :key="key" :value="key" class="product-reports__view">{{ t(`reports.views.${key}`) }}</ToggleGroupItem>
            </ChoiceGroup>

            <template v-if="view === 'palmares'">
                <div v-if="report.products.length" :class="['product-reports__grid', { 'product-reports__grid--story': story }]">
                    <ProductPalmares :products="report.products" :selected-id="productId" @select="select" />
                    <ProductStory v-if="story" :key="story.product.id" :story="story" class="product-reports__story" @close="closeStory" />
                    <p v-else class="product-reports__hint">{{ t('reports.story.choose') }}</p>
                </div>
            </template>
            <div v-else-if="analysis" :class="['product-reports__wide', { 'product-reports__wide--story': story }]">
                <ProductDecisions v-if="view === 'decisions'" :analysis="analysis" :selected-id="productId" @select="select" />
                <AbcConcentration v-else-if="view === 'concentration'" :analysis="analysis" :selected-id="productId" @select="select" />
                <QuadrantMatrix v-else-if="view === 'matrix'" :analysis="analysis" :selected-id="productId" @select="select" />
                <StockCover v-else-if="view === 'stock'" :analysis="analysis" :selected-id="productId" @select="select" />
                <EventHeat v-else-if="view === 'events'" :analysis="analysis" :selected-id="productId" @select="select" />
                <ProductStory v-if="story" :key="story.product.id" :story="story" class="product-reports__story" @close="closeStory" />
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
.product-reports { display: flex; flex-direction: column; gap: var(--space-5); transition: opacity var(--transition); }
.product-reports--busy { opacity: 0.55; }
.product-reports__period { min-width: 11rem; }
.product-reports__summary { max-width: 46rem; margin: 0; font-family: var(--font-display); font-size: 1.5rem; line-height: 1.3; color: var(--color-ink); }

.product-reports__views { display: flex; flex-wrap: wrap; gap: var(--space-4); border-bottom: 0.0625rem solid var(--color-border); }

.product-reports__view {
    margin-bottom: -0.0625rem;
    padding: var(--space-2) 0;
    border: none;
    border-bottom: 0.125rem solid transparent;
    background: none;
    color: var(--color-muted);
    font: inherit;
    cursor: pointer;
    transition: color var(--transition), border-color var(--transition);
}

.product-reports__view:hover { color: var(--color-ink); }
.product-reports__view[data-state='on'] { border-bottom-color: var(--color-accent); color: var(--color-ink); font-weight: 600; }

.product-reports__grid { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 0.8fr); gap: var(--space-6); align-items: start; }
.product-reports__wide { display: grid; grid-template-columns: minmax(0, 1fr); gap: var(--space-6); align-items: start; }
.product-reports__wide--story { grid-template-columns: minmax(0, 1fr) minmax(0, 28rem); }
.product-reports__story { position: sticky; top: var(--space-4); max-height: calc(100vh - var(--space-6)); overflow-y: auto; }
.product-reports__hint { margin: var(--space-6) 0 0; padding: var(--space-5); border: 0.0625rem dashed var(--color-border-strong); border-radius: var(--radius); color: var(--color-muted); font-size: var(--font-size); }

@media (width < 110rem) {
    .product-reports__wide--story { grid-template-columns: minmax(0, 1fr); }
    .product-reports__wide .product-reports__story { position: static; order: -1; max-height: none; overflow: visible; }
}

@media (max-width: 64rem) {
    .product-reports__grid { grid-template-columns: minmax(0, 1fr); }

    .product-reports__story { position: static; order: -1; max-height: none; overflow: visible; }
    .product-reports__hint { display: none; }
}
</style>
