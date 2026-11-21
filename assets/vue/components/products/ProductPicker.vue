<script setup>
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import BaseCombobox from '../ui/BaseCombobox.vue';
import FieldError from '../ui/FieldError.vue';
import VariantChoice from './VariantChoice.vue';
import { formatCents } from '../../composables/useMoney.js';
import { sameVariant } from '../../composables/useVariantStock.js';

const props = defineProps({
    products: { type: Array, required: true },
    label: { type: String, default: null },
    placeholder: { type: String, default: null },
    variantLabel: { type: String, default: null },
    withPrice: { type: Boolean, default: false },
    preferredVariant: { type: String, default: null },
    extraVariants: { type: Array, default: () => [] },
    none: { type: String, default: null },
});
const productId = defineModel({ type: String, default: '' });
const variant = defineModel('variant', { type: String, default: '' });
const { t } = useI18n();
const error = ref(null);

const offered = computed(() => props.products.filter((product) => !product.archived || product.id === productId.value));
const product = computed(() => offered.value.find((candidate) => candidate.id === productId.value) ?? null);
const nameOf = (candidate) => (props.withPrice ? `${candidate.displayName} — ${formatCents(candidate.sellingPrice)}` : candidate.displayName);
const productOptions = computed(() => offered.value.map((candidate) => ({ value: candidate.id, label: nameOf(candidate) })));
const variants = computed(() => (product.value ? [...product.value.activeVariants, ...props.extraVariants]
    .filter((option, index, all) => all.findIndex((other) => sameVariant(other, option)) === index) : []));

function suggestion() {
    const preferred = props.preferredVariant ? variants.value.find((option) => sameVariant(option, props.preferredVariant)) : null;
    if (preferred) return preferred;
    return !props.none && variants.value.length === 1 ? variants.value[0] : '';
}

watch([productId, () => props.preferredVariant], () => {
    variant.value = suggestion();
    error.value = null;
});
watch(variant, () => { error.value = null; });

function validate() {
    if (!product.value) {
        error.value = t('products.picker.chooseProduct');
        return false;
    }
    if (!props.none && variants.value.length > 0 && !variant.value) {
        error.value = t('products.picker.chooseVariant', { product: product.value.displayName });
        return false;
    }
    return true;
}

defineExpose({ validate });
</script>

<template>
    <div class="product-picker">
        <div class="product-picker__row">
            <BaseCombobox
                v-model="productId"
                :options="productOptions"
                :placeholder="placeholder ?? t('products.picker.search')"
                :aria-label="label ?? t('products.picker.product')"
            />
            <slot />
        </div>
        <Transition name="fade">
            <VariantChoice v-if="variants.length" v-model="variant" :options="variants" :none="none" :aria-label="variantLabel ?? t('products.picker.variant')" />
        </Transition>
        <FieldError v-if="error">{{ error }}</FieldError>
    </div>
</template>

<style scoped>
.product-picker { display: flex; flex-direction: column; gap: var(--space-2); min-width: 0; }
.product-picker__row { display: flex; align-items: center; gap: var(--space-2); }
.product-picker__row > :first-child { flex: 1; min-width: 0; }

@container form (max-width: 24rem) {
    .product-picker__row { flex-wrap: wrap; }
    .product-picker__row > :first-child { flex-basis: 100%; }
}
</style>
