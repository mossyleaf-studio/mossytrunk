<script setup>
import { computed, onMounted, reactive } from 'vue';
import { useI18n } from 'vue-i18n';
import BaseButton from '../ui/BaseButton.vue';
import BaseDateRangePicker from '../ui/BaseDateRangePicker.vue';
import FormActions from '../ui/FormActions.vue';
import FormError from '../ui/FormError.vue';
import FormField from '../ui/FormField.vue';
import FormSection from '../ui/FormSection.vue';
import ReadyMarginField from '../planning/ReadyMarginField.vue';
import { useFormSubmit } from '../../composables/useFormSubmit.js';
import { usePlanningSettings } from '../../composables/usePlanningSettings.js';

const props = defineProps({
    event: { type: Object, default: null },
    submit: { type: Function, required: true },
});
const emit = defineEmits(['saved', 'cancel']);
const { t } = useI18n();

const emptyForm = () => ({ name: '', location: '', startDate: '', endDate: '', readyDaysBefore: null });
const fromEvent = (event) => ({ name: event.name, location: event.location, startDate: event.startDate, endDate: event.endDate, readyDaysBefore: event.readyDaysBefore ?? null });
const form = reactive(props.event ? fromEvent(props.event) : emptyForm());
const { saving, errors, run } = useFormSubmit();
const isEditing = computed(() => props.event !== null);
const { settings, load: loadSettings } = usePlanningSettings();
const workspaceMargin = computed(() => settings.value?.readyDaysBefore ?? null);

onMounted(loadSettings);

async function onSubmit() {
    await run(async () => {
        await props.submit({ ...form, readyDaysBefore: form.readyDaysBefore ?? null });
        emit('saved', form.name);
        if (!isEditing.value) {
            Object.assign(form, emptyForm());
        }
    });
}
</script>

<template>
    <form class="event-form" novalidate @submit.prevent="onSubmit">
        <fieldset class="form-lock" :disabled="saving">
            <FormError v-if="errors.form">{{ errors.form }}</FormError>

            <FormSection>
                <FormField :label="t('events.form.name')" :error="errors.name">
                    <input v-model="form.name" type="text" required :placeholder="t('events.form.namePlaceholder')">
                </FormField>
                <FormField :label="t('events.form.location')" :error="errors.location">
                    <input v-model="form.location" type="text" required :placeholder="t('events.form.locationPlaceholder')">
                </FormField>
                <FormField as="group" :label="t('events.form.dates')" :error="errors.startDate ?? errors.endDate">
                    <BaseDateRangePicker v-model:start="form.startDate" v-model:end="form.endDate" :invalid="Boolean(errors.startDate ?? errors.endDate)" />
                </FormField>
                <FormField
                    as="group"
                    :label="t('events.form.readiness')"
                    :error="errors.readyDaysBefore"
                    :hint="workspaceMargin === null ? null : t('events.form.readinessHint', workspaceMargin)"
                    optional
                >
                    <ReadyMarginField v-model="form.readyDaysBefore" :placeholder="workspaceMargin" />
                </FormField>
            </FormSection>

            <FormActions>
                <BaseButton variant="ghost" @click="emit('cancel')">{{ t('common.cancel') }}</BaseButton>
                <BaseButton type="submit" :loading="saving">{{ isEditing ? t('common.save') : t('events.form.create') }}</BaseButton>
            </FormActions>
        </fieldset>
    </form>
</template>

<style scoped>
.event-form { display: flex; flex-direction: column; gap: var(--space-5); }
</style>
