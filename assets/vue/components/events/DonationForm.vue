<script setup>
import { reactive } from 'vue';
import { useI18n } from 'vue-i18n';
import BaseButton from '../ui/BaseButton.vue';
import BaseMoneyField from '../ui/BaseMoneyField.vue';
import FormActions from '../ui/FormActions.vue';
import FormField from '../ui/FormField.vue';
import FormSection from '../ui/FormSection.vue';
import { useFormSubmit } from '../../composables/useFormSubmit.js';

const props = defineProps({
    submit: { type: Function, required: true },
});
const emit = defineEmits(['saved', 'cancel']);
const { t } = useI18n();

const blank = () => ({ label: '', amount: null });
const form = reactive(blank());
const { saving, errors, run } = useFormSubmit('amount');

async function onSubmit() {
    await run(async () => {
        await props.submit({ label: form.label || null, amount: form.amount ?? 0 });
        emit('saved', form.amount ?? 0);
        Object.assign(form, blank());
    });
}
</script>

<template>
    <form class="donation-form" novalidate @submit.prevent="onSubmit">
        <fieldset class="form-lock" :disabled="saving">
            <FormSection>
                <FormField :label="t('events.donationForm.amount')" :error="errors.amount">
                    <BaseMoneyField v-model="form.amount" class="control--short" />
                </FormField>
                <FormField optional :label="t('events.donationForm.label')" :error="errors.label">
                    <input v-model="form.label" type="text" :placeholder="t('events.donationForm.labelPlaceholder')">
                </FormField>
            </FormSection>

            <FormActions>
                <BaseButton variant="ghost" @click="emit('cancel')">{{ t('common.cancel') }}</BaseButton>
                <BaseButton type="submit" :loading="saving">{{ t('events.donationForm.add') }}</BaseButton>
            </FormActions>
        </fieldset>
    </form>
</template>

<style scoped>
.donation-form { display: flex; flex-direction: column; gap: var(--space-5); }
</style>
