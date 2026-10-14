<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { ToggleGroupItem, ToggleGroupRoot } from 'reka-ui';
import BaseButton from '../ui/BaseButton.vue';
import BaseDatePicker from '../ui/BaseDatePicker.vue';
import FormActions from '../ui/FormActions.vue';
import FormError from '../ui/FormError.vue';
import FormField from '../ui/FormField.vue';
import FormSection from '../ui/FormSection.vue';
import OrderDraftLines from './OrderDraftLines.vue';
import OrderLinePicker from './OrderLinePicker.vue';
import OrderTotals from './OrderTotals.vue';
import { useOrderDraft } from '../../composables/useOrderDraft.js';
import { useFormSubmit } from '../../composables/useFormSubmit.js';
import { manualRulesOn } from '../../composables/useDiscountRules.js';

const props = defineProps({
    products: { type: Array, required: true },
    discountRules: { type: Array, default: () => [] },
    submit: { type: Function, required: true },
});
const emit = defineEmits(['placed']);

const { t } = useI18n();
const draft = useOrderDraft();
const { saving, errors, run } = useFormSubmit();
const offeredDiscounts = computed(() => manualRulesOn(props.discountRules, draft.placedAt.value?.slice(0, 10) ?? ''));

async function onSubmit() {
    if (draft.lines.length === 0) {
        errors.value = { form: t('orders.form.noLines') };
        return;
    }
    await run(async () => {
        const order = await props.submit(draft.payload());
        draft.reset();
        emit('placed', order);
    });
}
</script>

<template>
    <form class="order-form" novalidate @submit.prevent="onSubmit">
        <fieldset class="form-lock" :disabled="saving">
            <FormSection>
                <FormField as="group" :label="t('orders.form.date')" :error="errors.placedAt">
                    <BaseDatePicker v-model="draft.placedAt.value" with-time />
                </FormField>
                <Transition name="fade">
                    <FormField v-if="draft.preview.value" as="group" :label="t('orders.form.event')">
                        <p v-if="draft.preview.value.event" class="order-form__fact">{{ draft.preview.value.event.name }}</p>
                        <p v-else class="order-form__fact order-form__fact--missing" role="alert">
                            {{ t('orders.form.noEvent') }} <a href="/events">{{ t('orders.form.createEvent') }}</a>
                        </p>
                    </FormField>
                </Transition>
            </FormSection>

            <FormSection :title="t('orders.form.items')">
                <OrderLinePicker :products="products" @add="draft.add" />
                <OrderDraftLines
                    :lines="draft.lines"
                    :products="products"
                    @quantity="draft.setQuantity"
                    @remove="draft.remove"
                />
            </FormSection>

            <FormSection v-if="offeredDiscounts.length" :title="t('orders.form.manualDiscounts')">
                <ToggleGroupRoot v-model="draft.discountRuleIds.value" type="multiple" class="order-form__discounts" :aria-label="t('orders.form.manualDiscounts')">
                    <ToggleGroupItem v-for="rule in offeredDiscounts" :key="rule.id" :value="rule.id" class="chip chip--accent">{{ rule.name }}</ToggleGroupItem>
                </ToggleGroupRoot>
            </FormSection>

            <FormError v-if="errors.form || draft.previewError.value">{{ errors.form ?? draft.previewError.value }}</FormError>

            <div class="order-form__checkout">
                <OrderTotals
                    v-if="draft.preview.value"
                    :subtotal="draft.preview.value.subtotal"
                    :discounts="draft.preview.value.discounts"
                    :total="draft.preview.value.total"
                />
                <FormActions sticky>
                    <BaseButton type="submit" :loading="saving" :disabled="draft.lines.length === 0">{{ t('orders.form.submit') }}</BaseButton>
                </FormActions>
            </div>
        </fieldset>
    </form>
</template>

<style scoped>
.order-form { flex: 1; display: flex; flex-direction: column; gap: var(--space-5); }
.order-form__checkout { display: flex; flex-direction: column; gap: var(--space-4); margin-top: auto; }

.order-form__discounts { display: flex; flex-wrap: wrap; gap: var(--space-2); }
.order-form__fact { margin: 0; padding-top: 0.5625rem; font-size: var(--font-size-md); font-weight: 500; color: var(--color-ink); }
.order-form__fact--missing { font-weight: 400; color: var(--color-danger); }
.order-form__fact--missing a { color: inherit; }
</style>
