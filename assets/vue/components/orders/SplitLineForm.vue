<script setup>
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import BaseButton from '../ui/BaseButton.vue';
import BaseCheckbox from '../ui/BaseCheckbox.vue';
import FormActions from '../ui/FormActions.vue';
import FormError from '../ui/FormError.vue';
import FormSection from '../ui/FormSection.vue';
import MoneyAmount from '../ui/MoneyAmount.vue';
import OrderDraftLines from './OrderDraftLines.vue';
import OrderLinePicker from './OrderLinePicker.vue';
import { useDraftLines } from '../../composables/useOrderDraft.js';
import { useFormSubmit } from '../../composables/useFormSubmit.js';
import { formatCents } from '../../composables/useMoney.js';

const props = defineProps({
    line: { type: Object, required: true },
    others: { type: Array, default: () => [] },
    products: { type: Array, required: true },
    submit: { type: Function, required: true },
});
const emit = defineEmits(['saved', 'cancel']);
const { t } = useI18n();
const draft = useDraftLines();
const { saving, errors, run } = useFormSubmit();

const priceOf = (productId) => props.products.find((product) => product.id === productId)?.sellingPrice ?? 0;
const worth = computed(() => draft.lines.reduce((sum, line) => sum + priceOf(line.productId) * line.quantity, 0));
const alongWith = ref([]);
const paid = computed(() => props.others.filter((other) => alongWith.value.includes(other.id)).reduce((sum, other) => sum + other.total, props.line.total));
const gap = computed(() => worth.value - paid.value);

function include(other, checked) {
    alongWith.value = checked ? [...alongWith.value, other.id] : alongWith.value.filter((id) => id !== other.id);
}

async function onSubmit() {
    await run(async () => {
        await props.submit({ lines: draft.requested(), alongWith: alongWith.value });
        emit('saved');
    });
}
</script>

<template>
    <form class="split-line-form" novalidate @submit.prevent="onSubmit">
        <fieldset class="form-lock" :disabled="saving">
            <FormError v-if="errors.form || errors.lines">{{ errors.form ?? errors.lines }}</FormError>
            <div v-if="others.length" class="split-line-form__along">
                <label v-for="other in others" :key="other.id" class="split-line-form__other">
                    <BaseCheckbox :model-value="alongWith.includes(other.id)" @update:model-value="include(other, $event)" />
                    {{ t('orders.split.alongWith', { label: other.label, amount: formatCents(other.total) }) }}
                </label>
            </div>
            <FormSection :description="t('orders.split.intro')">
                <OrderLinePicker :products="products" @add="draft.add" />
                <OrderDraftLines :lines="draft.lines" :products="products" @quantity="draft.setQuantity" @remove="draft.remove" />
            </FormSection>
            <dl class="split-line-form__summary" data-test="split-summary">
                <div class="split-line-form__row">
                    <dt>{{ t('orders.split.paid') }}</dt>
                    <dd><MoneyAmount :cents="paid" /></dd>
                </div>
                <div class="split-line-form__row">
                    <dt>{{ t('orders.split.worth') }}</dt>
                    <dd><MoneyAmount :cents="worth" /></dd>
                </div>
                <div v-if="draft.lines.length && gap > 0" class="split-line-form__row split-line-form__row--discount">
                    <dt>{{ t('orders.split.discount') }}</dt>
                    <dd>− <MoneyAmount :cents="gap" /></dd>
                </div>
            </dl>
            <p v-if="draft.lines.length && gap < 0" class="split-line-form__warning" role="alert">{{ t('orders.split.worthLess') }}</p>
            <p v-else-if="draft.lines.length && gap > 0" class="split-line-form__hint">{{ t('orders.split.discountHint') }}</p>
            <FormActions>
                <BaseButton variant="ghost" @click="emit('cancel')">{{ t('common.cancel') }}</BaseButton>
                <BaseButton type="submit" :loading="saving" :disabled="draft.lines.length === 0">{{ t('orders.split.save') }}</BaseButton>
            </FormActions>
        </fieldset>
    </form>
</template>

<style scoped>
.split-line-form { display: flex; flex-direction: column; gap: var(--space-5); }
.split-line-form__along { display: flex; flex-direction: column; gap: var(--space-2); }
.split-line-form__other { display: flex; align-items: center; gap: var(--space-2); cursor: pointer; }
.split-line-form__summary { display: flex; flex-direction: column; gap: var(--space-1); margin: 0; }
.split-line-form__row { display: flex; justify-content: space-between; gap: var(--space-3); }
.split-line-form__row dd { margin: 0; }
.split-line-form__row--discount { color: var(--color-success); }
.split-line-form__warning { margin: 0; color: var(--color-danger); font-size: var(--font-size-md); }
.split-line-form__hint { margin: 0; color: var(--color-muted); font-size: var(--font-size-md); }
</style>
