<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { ToggleGroupItem, ToggleGroupRoot } from 'reka-ui';
import BaseButton from '../ui/BaseButton.vue';
import BaseCombobox from '../ui/BaseCombobox.vue';
import BaseNumberField from '../ui/BaseNumberField.vue';
import FieldError from '../ui/FieldError.vue';
import { formatCents } from '../../composables/useMoney.js';

const props = defineProps({
    products: { type: Array, required: true },
    preferredVariant: { type: String, default: null },
});
const emit = defineEmits(['add']);
const { t } = useI18n();

const root = ref(null);
const productId = ref('');
const variant = ref('');
const quantity = ref(1);
const error = ref(null);

const product = computed(() => props.products.find((p) => p.id === productId.value) ?? null);
const needsVariant = computed(() => (product.value?.variants.length ?? 0) > 0);
const productOptions = computed(() => props.products.filter((p) => !p.archived).map((p) => ({ value: p.id, label: `${p.displayName} — ${formatCents(p.sellingPrice)}` })));

watch(productId, () => {
    variant.value = product.value?.activeVariants.find((option) => option.toLowerCase() === props.preferredVariant?.toLowerCase()) ?? '';
    error.value = null;
});

function chooseVariant(value) {
    variant.value = value ?? '';
    error.value = null;
}

async function add() {
    if (!product.value) {
        error.value = t('orders.picker.chooseProduct');
        return;
    }
    if (needsVariant.value && !variant.value) {
        error.value = t('orders.picker.chooseVariant', { product: product.value.displayName });
        return;
    }
    emit('add', { productId: product.value.id, variant: needsVariant.value ? variant.value : null, quantity: Math.max(1, quantity.value ?? 1) });
    productId.value = '';
    quantity.value = 1;
    error.value = null;
    await nextTick();
    root.value?.querySelector('[role="combobox"]')?.focus();
}
</script>

<template>
    <div ref="root" class="order-line-picker">
        <div class="order-line-picker__row">
            <BaseCombobox v-model="productId" :options="productOptions" :placeholder="t('orders.picker.search')" :aria-label="t('orders.picker.product')" />
            <BaseNumberField v-model="quantity" class="order-line-picker__quantity" :min="1" :label="t('orders.picker.quantity')" @keydown.enter.prevent="add" />
            <BaseButton variant="secondary" @click="add">{{ t('common.add') }}</BaseButton>
        </div>
        <Transition name="fade">
            <ToggleGroupRoot
                v-if="needsVariant"
                :model-value="variant"
                type="single"
                class="order-line-picker__variants"
                :aria-label="t('orders.picker.variant')"
                @update:model-value="chooseVariant"
            >
                <ToggleGroupItem v-for="option in product.activeVariants" :key="option" :value="option" class="chip">{{ option }}</ToggleGroupItem>
            </ToggleGroupRoot>
        </Transition>
        <FieldError v-if="error">{{ error }}</FieldError>
    </div>
</template>

<style scoped>
.order-line-picker { display: flex; flex-direction: column; gap: var(--space-2); }
.order-line-picker__row { display: flex; align-items: center; gap: var(--space-2); }
.order-line-picker__row > :first-child { flex: 1; min-width: 0; }
.order-line-picker__quantity { flex: 0 0 6.5rem; }

.order-line-picker__variants { display: flex; flex-wrap: wrap; gap: var(--space-1); }

@container form (max-width: 24rem) {
    .order-line-picker__row { flex-wrap: wrap; }
    .order-line-picker__row > :first-child { flex-basis: 100%; }
}
</style>
