<script setup>
import { computed, onMounted, ref } from 'vue';
import { RadioGroupRoot } from 'reka-ui';
import { useI18n } from 'vue-i18n';
import BaseButton from '../ui/BaseButton.vue';
import BaseDatePicker from '../ui/BaseDatePicker.vue';
import BaseSelect from '../ui/BaseSelect.vue';
import FormActions from '../ui/FormActions.vue';
import FormError from '../ui/FormError.vue';
import FormField from '../ui/FormField.vue';
import FormSection from '../ui/FormSection.vue';
import RadioCard from '../ui/RadioCard.vue';
import StepListEditor from './StepListEditor.vue';
import { useEvents } from '../../composables/useEvents.js';
import { useFormSubmit } from '../../composables/useFormSubmit.js';
import { usePlanning } from '../../composables/usePlanning.js';
import { useReleaseTemplate } from '../../composables/useReleaseTemplate.js';

const props = defineProps({
    owner: { type: Object, required: true },
    release: { type: Object, default: null },
});
const emit = defineEmits(['saved', 'cancel']);
const { t } = useI18n();

const { plan, replan } = usePlanning();
const { upcoming, load: loadEvents } = useEvents();
const { load: loadTemplate } = useReleaseTemplate();
const { saving, errors, run } = useFormSubmit('form', { byField: false });

const moment = ref(props.release?.event ? 'event' : 'day');
const day = ref(props.release && !props.release.event ? props.release.day : '');
const eventId = ref(props.release?.event?.id ?? '');
const steps = ref(props.release?.steps.map(({ label, days, done }) => ({ label, days, done })) ?? []);
const eventOptions = computed(() => {
    const options = upcoming.value.map((event) => ({ value: event.id, label: event.name }));
    const current = props.release?.event;
    return current && !options.some((option) => option.value === current.id) ? [{ value: current.id, label: current.name }, ...options] : options;
});

onMounted(async () => {
    const [template] = await Promise.all([props.release ? null : loadTemplate(), loadEvents()]);
    if (template) steps.value = template.steps.map((step) => ({ ...step }));
});

const onSubmit = () => run(async () => {
    const payload = {
        day: moment.value === 'day' ? day.value || null : null,
        eventId: moment.value === 'event' ? eventId.value || null : null,
        steps: steps.value,
    };
    await (props.release ? replan(props.release.id, payload) : plan(props.owner, payload));
    emit('saved');
});
</script>

<template>
    <form class="release-form" novalidate @submit.prevent="onSubmit">
        <fieldset class="form-lock" :disabled="saving">
            <FormError v-if="errors.form">{{ errors.form }}</FormError>
            <FormSection>
                <RadioGroupRoot v-model="moment" class="release-form__moments" :aria-label="t('planning.form.moment')">
                    <RadioCard value="day">{{ t('planning.form.onDay') }}</RadioCard>
                    <RadioCard value="event">{{ t('planning.form.onEvent') }}</RadioCard>
                </RadioGroupRoot>
                <FormField v-if="moment === 'day'" as="group" :label="t('planning.form.day')">
                    <BaseDatePicker v-model="day" />
                </FormField>
                <FormField v-else as="group" :label="t('planning.form.event')" :hint="eventOptions.length ? null : t('planning.form.noEvent')">
                    <BaseSelect v-model="eventId" :options="eventOptions" :aria-label="t('planning.form.event')" />
                </FormField>
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
.release-form__moments { display: flex; flex-wrap: wrap; gap: var(--space-2); }
</style>
