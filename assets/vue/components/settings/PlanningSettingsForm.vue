<script setup>
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import BaseButton from '../ui/BaseButton.vue';
import FormActions from '../ui/FormActions.vue';
import FormError from '../ui/FormError.vue';
import FormField from '../ui/FormField.vue';
import FormSection from '../ui/FormSection.vue';
import ReadyMarginField from '../planning/ReadyMarginField.vue';
import StepListEditor from '../planning/StepListEditor.vue';
import { useFormSubmit } from '../../composables/useFormSubmit.js';

const props = defineProps({
    settings: { type: Object, required: true },
    submit: { type: Function, required: true },
});
const emit = defineEmits(['saved']);
const { t } = useI18n();

const steps = ref(props.settings.steps.map((step) => ({ ...step })));
const readyDaysBefore = ref(props.settings.readyDaysBefore);
const { saving, errors, run } = useFormSubmit('form', { byField: false });

const onSubmit = () => run(async () => {
    if (!Number.isInteger(readyDaysBefore.value)) {
        throw new Error(t('settings.planning.marginRequired'));
    }
    await props.submit({ steps: steps.value, readyDaysBefore: readyDaysBefore.value });
    emit('saved');
});
</script>

<template>
    <form class="planning-settings-form" novalidate @submit.prevent="onSubmit">
        <fieldset class="form-lock" :disabled="saving">
            <FormError v-if="errors.form">{{ errors.form }}</FormError>
            <FormSection>
                <FormField as="group" :label="t('settings.planning.margin')" :hint="t('settings.planning.marginHint')">
                    <ReadyMarginField v-model="readyDaysBefore" />
                </FormField>
            </FormSection>
            <FormSection :title="t('settings.planning.steps')" :description="t('settings.planning.stepsHint')">
                <StepListEditor v-model="steps" />
            </FormSection>
            <FormActions>
                <BaseButton type="submit" :loading="saving">{{ t('settings.planning.save') }}</BaseButton>
            </FormActions>
        </fieldset>
    </form>
</template>

<style scoped>
.planning-settings-form { display: flex; flex-direction: column; gap: var(--space-4); }
</style>
