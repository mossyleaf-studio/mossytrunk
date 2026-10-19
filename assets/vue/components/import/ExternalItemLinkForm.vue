<script setup>
import { useI18n } from 'vue-i18n';
import BaseButton from '../ui/BaseButton.vue';
import FormActions from '../ui/FormActions.vue';
import FormError from '../ui/FormError.vue';
import FormSection from '../ui/FormSection.vue';
import OrderDraftLines from '../orders/OrderDraftLines.vue';
import OrderLinePicker from '../orders/OrderLinePicker.vue';
import { useDraftLines } from '../../composables/useOrderDraft.js';
import { useFormSubmit } from '../../composables/useFormSubmit.js';

const props = defineProps({
    item: { type: Object, required: true },
    products: { type: Array, required: true },
    submit: { type: Function, required: true },
});
const emit = defineEmits(['saved', 'cancel']);
const { t } = useI18n();
const draft = useDraftLines();
const { saving, errors, run } = useFormSubmit();

props.item.components.forEach(({ productId, variant, quantity }) => draft.add({ productId, variant, quantity }));

async function onSubmit() {
    await run(async () => {
        await props.submit(props.item.id, draft.requested());
        emit('saved', props.item);
    });
}
</script>

<template>
    <form class="item-link-form" novalidate @submit.prevent="onSubmit">
        <fieldset class="form-lock" :disabled="saving">
            <FormError v-if="errors.form || errors.lines">{{ errors.form ?? errors.lines }}</FormError>
            <FormSection :description="t('import.form.intro')">
                <OrderLinePicker :products="products" :preferred-variant="item.variation" @add="draft.add" />
                <OrderDraftLines :lines="draft.lines" :products="products" @quantity="draft.setQuantity" @remove="draft.remove" />
            </FormSection>
            <FormActions>
                <BaseButton variant="ghost" @click="emit('cancel')">{{ t('common.cancel') }}</BaseButton>
                <BaseButton type="submit" :loading="saving" :disabled="draft.lines.length === 0">{{ t('import.form.save') }}</BaseButton>
            </FormActions>
        </fieldset>
    </form>
</template>

<style scoped>
.item-link-form { display: flex; flex-direction: column; gap: var(--space-4); padding-top: var(--space-2); }
</style>
