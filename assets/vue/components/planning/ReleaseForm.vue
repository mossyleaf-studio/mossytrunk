<script setup>
import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import BaseButton from '../ui/BaseButton.vue';
import FormActions from '../ui/FormActions.vue';
import FormError from '../ui/FormError.vue';
import FormSection from '../ui/FormSection.vue';
import StepListEditor from './StepListEditor.vue';
import TargetPicker from './TargetPicker.vue';
import { useFormSubmit } from '../../composables/useFormSubmit.js';
import { usePlanning, useTarget } from '../../composables/usePlanning.js';
import { useReleaseTemplate } from '../../composables/useReleaseTemplate.js';

const props = defineProps({
    owner: { type: Object, required: true },
    release: { type: Object, default: null },
});
const emit = defineEmits(['saved', 'cancel']);
const { t } = useI18n();

const { plan, replan } = usePlanning();
const { load: loadTemplate } = useReleaseTemplate();
const { saving, errors, run } = useFormSubmit('form', { byField: false });

const { moment, day, eventId, payload: target } = useTarget(props.release);
const steps = ref(props.release?.steps.map(({ label, days, done }) => ({ label, days, done })) ?? []);

onMounted(async () => {
    const template = props.release ? null : await loadTemplate();
    if (template) steps.value = template.steps.map((step) => ({ ...step }));
});

const onSubmit = () => run(async () => {
    const payload = { ...target(), steps: steps.value };
    await (props.release ? replan(props.release.id, payload) : plan(props.owner, payload));
    emit('saved');
});
</script>

<template>
    <form class="release-form" novalidate @submit.prevent="onSubmit">
        <fieldset class="form-lock" :disabled="saving">
            <FormError v-if="errors.form">{{ errors.form }}</FormError>
            <FormSection>
                <TargetPicker v-model:moment="moment" v-model:day="day" v-model:event-id="eventId" :event="release?.event" :label="t('planning.form.moment')" :day-label="t('planning.form.day')" />
            </FormSection>
            <FormSection :title="t('planning.form.steps')" :description="t('planning.form.stepsHint')">
                <StepListEditor v-model="steps" />
            </FormSection>
            <FormActions>
                <BaseButton variant="ghost" @click="emit('cancel')">{{ t('common.cancel') }}</BaseButton>
                <BaseButton type="submit" :loading="saving">{{ release ? t('common.save') : t('planning.form.plan') }}</BaseButton>
            </FormActions>
        </fieldset>
    </form>
</template>

<style scoped>
.release-form { display: flex; flex-direction: column; gap: var(--space-5); }
</style>
