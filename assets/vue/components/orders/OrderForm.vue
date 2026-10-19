<script setup>
import { computed, watch, watchEffect } from 'vue';
import { useI18n } from 'vue-i18n';
import { ToggleGroupItem, ToggleGroupRoot } from 'reka-ui';
import BaseButton from '../ui/BaseButton.vue';
import BaseDatePicker from '../ui/BaseDatePicker.vue';
import BaseSelect from '../ui/BaseSelect.vue';
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
    channels: { type: Array, default: () => [] },
    events: { type: Array, default: () => [] },
    submit: { type: Function, required: true },
});
const emit = defineEmits(['placed']);

const { t } = useI18n();
const draft = useOrderDraft();
const { saving, errors, run } = useFormSubmit();

watchEffect(() => {
    draft.channelId.value ||= props.channels.find((channel) => channel.main)?.id ?? '';
});
const channel = computed(() => props.channels.find((option) => option.id === draft.channelId.value));
const channelOptions = computed(() => props.channels.map((option) => ({ value: option.id, label: option.name })));
const needsEvent = computed(() => channel.value?.kind !== 'online');

const eventOptions = computed(() => [...props.events]
    .sort((a, b) => b.startDate.localeCompare(a.startDate))
    .map((event) => ({ value: event.id, label: event.name })));
const coveringEvent = (day) => props.events.find((event) => event.startDate <= day && day <= event.endDate)?.id ?? '';
watch([() => draft.placedAt.value, () => props.events, needsEvent], () => {
    draft.eventId.value = needsEvent.value ? coveringEvent(draft.placedAt.value?.slice(0, 10) ?? '') : '';
}, { immediate: true });
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
                <FormField v-if="channels.length > 1" as="group" :label="t('orders.form.channel')" :error="errors.channelId">
                    <BaseSelect v-model="draft.channelId.value" :options="channelOptions" :aria-label="t('orders.form.channel')" />
                </FormField>
                <FormField v-if="needsEvent" as="group" :label="t('orders.form.event')" :error="errors.eventId">
                    <BaseSelect v-model="draft.eventId.value" :options="eventOptions" :placeholder="t('orders.form.chooseEvent')" :aria-label="t('orders.form.event')" />
                    <p v-if="!draft.eventId.value" class="order-form__fact order-form__fact--missing" role="alert">
                        {{ t('orders.form.noEvent') }} <a href="/events">{{ t('orders.form.createEvent') }}</a>
                    </p>
                </FormField>
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

            <FormSection>
                <FormField :label="t('orders.form.note')" :error="errors.note" optional>
                    <textarea v-model="draft.note.value" rows="2" :placeholder="t('orders.form.notePlaceholder')" />
                </FormField>
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
.order-form__fact { margin: 0; padding-top: var(--space-2); font-size: var(--font-size-md); }
.order-form__fact--missing { color: var(--color-danger); }
.order-form__fact--missing a { color: inherit; }
</style>
