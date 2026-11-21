<script setup>
import { computed, reactive, watch } from 'vue';
import { RadioGroupRoot } from 'reka-ui';
import { useI18n } from 'vue-i18n';
import BaseButton from '../ui/BaseButton.vue';
import FormActions from '../ui/FormActions.vue';
import FormError from '../ui/FormError.vue';
import FormField from '../ui/FormField.vue';
import FormSection from '../ui/FormSection.vue';
import RadioCard from '../ui/RadioCard.vue';
import ProductPicker from './ProductPicker.vue';
import VariantChoice from './VariantChoice.vue';
import { useFormSubmit } from '../../composables/useFormSubmit.js';
import { useProductTypes } from '../../composables/useProductTypes.js';
import { sameVariant } from '../../composables/useVariantStock.js';

const props = defineProps({
    product: { type: Object, required: true },
    products: { type: Array, required: true },
    submit: { type: Function, required: true },
});
const emit = defineEmits(['moved', 'cancel']);
const { t } = useI18n();
const { variantsOf } = useProductTypes();

const lastWord = (name) => name.trim().split(/\s+/).at(-1) ?? '';
const withoutLastWord = (name) => name.trim().split(/\s+/).slice(0, -1).join(' ');

const form = reactive({
    variant: props.product.variants[0] ?? '',
    mode: 'new',
    targetProductId: '',
    newProductName: props.product.variants.length === 0 ? withoutLastWord(props.product.name) : props.product.name,
    targetVariant: '',
});
const { saving, errors, run } = useFormSubmit();

const hasVariants = computed(() => props.product.variants.length > 0);
const otherProducts = computed(() => props.products.filter((product) => product.id !== props.product.id));
const modes = ['new', 'existing'];
const target = computed(() => props.products.find((product) => product.id === form.targetProductId) ?? null);
const targetName = computed(() => (form.mode === 'new' ? form.newProductName.trim() : target.value?.displayName ?? ''));

const targetTypeId = computed(() => (form.mode === 'new' ? props.product.typeId : target.value?.typeId) ?? null);
const targetVariants = computed(() => [...new Set([...variantsOf(targetTypeId.value), ...(form.mode === 'existing' ? target.value?.variants ?? [] : [])])]);
const targetChosen = computed(() => form.mode === 'new' || target.value !== null);

const suggestedVariant = computed(() => {
    if (hasVariants.value) return form.variant;
    const name = props.product.displayName;
    if (form.mode === 'existing' && target.value && name.startsWith(`${target.value.displayName} `)) {
        return name.slice(target.value.displayName.length + 1);
    }
    return lastWord(props.product.name);
});

watch([suggestedVariant, targetVariants], ([suggestion, variants]) => {
    form.targetVariant = variants.find((variant) => sameVariant(variant, suggestion)) ?? '';
}, { immediate: true });

function onSubmit() {
    return run(async () => {
        await props.submit({
            variant: hasVariants.value ? form.variant : null,
            targetProductId: form.mode === 'existing' ? form.targetProductId || null : null,
            newProductName: form.mode === 'new' ? form.newProductName : null,
            targetVariant: form.targetVariant,
        });
        emit('moved', { variant: form.targetVariant, target: targetName.value });
    });
}
</script>

<template>
    <form class="move-variant-form" novalidate @submit.prevent="onSubmit">
        <fieldset class="form-lock" :disabled="saving">
            <FormError v-if="errors.form">{{ errors.form }}</FormError>

            <FormSection>
                <FormField v-if="hasVariants" as="group" :label="t('products.move.variant')">
                    <VariantChoice v-model="form.variant" :options="product.variants" />
                </FormField>
                <FormField as="group" :label="t('products.move.destination')">
                    <RadioGroupRoot v-model="form.mode" class="move-variant-form__modes" :aria-label="t('products.move.destination')">
                        <RadioCard v-for="mode in modes" :key="mode" :value="mode">
                            <span class="move-variant-form__choice">
                                <span class="move-variant-form__name">{{ t(`products.move.modes.${mode}.label`) }}</span>
                                <span class="move-variant-form__description">{{ t(`products.move.modes.${mode}.description`) }}</span>
                            </span>
                        </RadioCard>
                    </RadioGroupRoot>
                </FormField>
                <FormField v-if="form.mode === 'new'" :label="t('products.move.newProductName')" :error="errors.newProductName">
                    <input v-model="form.newProductName" type="text">
                </FormField>
                <FormField v-if="form.mode === 'new'" as="group" :label="t('products.move.targetVariant')" :error="errors.targetVariant" :hint="targetVariants.length === 0 ? t('products.move.noTypeVariants') : null">
                    <VariantChoice v-if="targetVariants.length" v-model="form.targetVariant" :options="targetVariants" :none="t('products.move.noVariant')" />
                </FormField>
                <FormField
                    v-else
                    as="group"
                    :label="t('products.picker.product')"
                    :error="errors.targetProductId ?? errors.targetVariant"
                    :hint="targetChosen && targetVariants.length === 0 ? t('products.move.noTypeVariants') : null"
                >
                    <ProductPicker
                        v-model="form.targetProductId"
                        v-model:variant="form.targetVariant"
                        :products="otherProducts"
                        :variant-label="t('products.move.targetVariant')"
                        :preferred-variant="suggestedVariant"
                        :extra-variants="targetVariants"
                        :none="t('products.move.noVariant')"
                    />
                </FormField>
            </FormSection>

            <FormActions>
                <template #note>
                    <i18n-t :keypath="hasVariants ? 'products.move.summary' : 'products.move.wholeSummary'" scope="global">
                        <template #name>{{ product.displayName }}</template>
                        <template #target><strong>{{ targetName || '…' }}<template v-if="form.targetVariant"> — {{ form.targetVariant }}</template></strong></template>
                    </i18n-t>
                </template>
                <BaseButton variant="ghost" @click="emit('cancel')">{{ t('common.cancel') }}</BaseButton>
                <BaseButton type="submit" :loading="saving">{{ t('products.move.submit') }}</BaseButton>
            </FormActions>
        </fieldset>
    </form>
</template>

<style scoped>
.move-variant-form { display: flex; flex-direction: column; gap: var(--space-5); }
.move-variant-form strong { color: var(--color-ink); font-weight: 600; }

.move-variant-form__modes { display: grid; grid-template-columns: repeat(auto-fit, minmax(10rem, 1fr)); gap: var(--space-2); }

.move-variant-form__choice { display: flex; flex-direction: column; gap: 0.125rem; }
.move-variant-form__name { font-size: var(--font-size-md); font-weight: 500; color: var(--color-ink); }
.move-variant-form__description { color: var(--color-muted); font-size: var(--font-size-sm); line-height: 1.35; }
</style>
