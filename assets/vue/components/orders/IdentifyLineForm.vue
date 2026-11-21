<script setup>
import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import BaseButton from '../ui/BaseButton.vue';
import FormActions from '../ui/FormActions.vue';
import FormField from '../ui/FormField.vue';
import FormSection from '../ui/FormSection.vue';
import MoneyAmount from '../ui/MoneyAmount.vue';
import ProductPicker from '../products/ProductPicker.vue';
import { useFormSubmit } from '../../composables/useFormSubmit.js';

const props = defineProps({
    line: { type: Object, required: true },
    products: { type: Array, required: true },
    submit: { type: Function, required: true },
});
const emit = defineEmits(['saved', 'cancel']);
const { t } = useI18n();

const picker = ref(null);
const productId = ref('');
const variant = ref('');
const { saving, errors, run } = useFormSubmit('productId');

watch(productId, () => { errors.value = {}; });

async function onSubmit() {
    if (!picker.value.validate()) {
        return;
    }
    await run(async () => {
        await props.submit({ productId: productId.value, variant: variant.value || null });
        emit('saved', props.products.find((product) => product.id === productId.value).displayName);
    });
}
</script>

<template>
    <form class="identify-line-form" novalidate @submit.prevent="onSubmit">
        <fieldset class="form-lock" :disabled="saving">
            <FormSection :description="t('orders.identify.intro')">
                <p class="identify-line-form__line">
                    {{ t('orders.identify.line', { quantity: line.quantity }) }} <MoneyAmount :cents="line.unitPrice" />
                </p>
                <FormField as="group" :label="t('products.picker.product')" :error="errors.productId ?? errors.variant">
                    <ProductPicker ref="picker" v-model="productId" v-model:variant="variant" :products="products" />
                </FormField>
            </FormSection>
            <FormActions>
                <BaseButton variant="ghost" @click="emit('cancel')">{{ t('common.cancel') }}</BaseButton>
                <BaseButton type="submit" :loading="saving">{{ t('orders.identify.save') }}</BaseButton>
            </FormActions>
        </fieldset>
    </form>
</template>

<style scoped>
.identify-line-form { display: flex; flex-direction: column; gap: var(--space-5); }
.identify-line-form__line { margin: 0; color: var(--color-ink); font-weight: 500; }
</style>
