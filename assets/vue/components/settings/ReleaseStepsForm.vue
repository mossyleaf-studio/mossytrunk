<script setup>
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import BaseButton from '../ui/BaseButton.vue';
import FormActions from '../ui/FormActions.vue';
import FormError from '../ui/FormError.vue';
import StepListEditor from '../planning/StepListEditor.vue';
import { useFormSubmit } from '../../composables/useFormSubmit.js';

const props = defineProps({
    steps: { type: Array, required: true },
    submit: { type: Function, required: true },
});
const emit = defineEmits(['saved']);
const { t } = useI18n();

const draft = ref(props.steps.map((step) => ({ ...step })));
const { saving, errors, run } = useFormSubmit('form', { byField: false });

const onSubmit = () => run(async () => {
    await props.submit(draft.value);
    emit('saved');
});
</script>

<template>
    <form class="release-steps-form" novalidate @submit.prevent="onSubmit">
        <fieldset class="form-lock" :disabled="saving">
            <FormError v-if="errors.form">{{ errors.form }}</FormError>
            <StepListEditor v-model="draft" />
            <FormActions>
                <BaseButton type="submit" :loading="saving">{{ t('settings.releaseSteps.save') }}</BaseButton>
            </FormActions>
        </fieldset>
    </form>
</template>

<style scoped>
.release-steps-form { display: flex; flex-direction: column; gap: var(--space-4); }
</style>
