<script setup>
import { nextTick, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import BaseButton from '../ui/BaseButton.vue';
import BaseNumberField from '../ui/BaseNumberField.vue';
import ProductPicker from '../products/ProductPicker.vue';

defineProps({
    products: { type: Array, required: true },
    preferredVariant: { type: String, default: null },
});
const emit = defineEmits(['add']);
const { t } = useI18n();

const root = ref(null);
const picker = ref(null);
const productId = ref('');
const variant = ref('');
const quantity = ref(1);

async function add() {
    if (!picker.value.validate()) {
        return;
    }
    emit('add', { productId: productId.value, variant: variant.value || null, quantity: Math.max(1, quantity.value ?? 1) });
    productId.value = '';
    quantity.value = 1;
    await nextTick();
    root.value?.querySelector('[role="combobox"]')?.focus();
}
</script>

<template>
    <div ref="root">
        <ProductPicker ref="picker" v-model="productId" v-model:variant="variant" :products="products" :preferred-variant="preferredVariant" with-price>
            <BaseNumberField v-model="quantity" class="order-line-picker__quantity" :min="1" :label="t('orders.picker.quantity')" @keydown.enter.prevent="add" />
            <BaseButton variant="secondary" @click="add">{{ t('common.add') }}</BaseButton>
        </ProductPicker>
    </div>
</template>

<style scoped>
.order-line-picker__quantity { flex: 0 0 6.5rem; }
</style>
