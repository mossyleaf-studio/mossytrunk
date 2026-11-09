<script setup>
import { computed, onMounted } from 'vue';
import { RadioGroupRoot } from 'reka-ui';
import { useI18n } from 'vue-i18n';
import BaseDatePicker from '../ui/BaseDatePicker.vue';
import BaseSelect from '../ui/BaseSelect.vue';
import FormField from '../ui/FormField.vue';
import RadioCard from '../ui/RadioCard.vue';
import { useEvents } from '../../composables/useEvents.js';

const props = defineProps({
    label: { type: String, required: true },
    dayLabel: { type: String, required: true },
    event: { type: Object, default: null },
});
const moment = defineModel('moment', { type: String, required: true });
const day = defineModel('day', { type: String, default: '' });
const eventId = defineModel('eventId', { type: String, default: '' });
const { t } = useI18n();

const { upcoming, load } = useEvents();
const eventOptions = computed(() => {
    const options = upcoming.value.map((event) => ({ value: event.id, label: event.name }));
    const current = props.event;
    return current && !options.some((option) => option.value === current.id) ? [{ value: current.id, label: current.name }, ...options] : options;
});

onMounted(load);
</script>

<template>
    <RadioGroupRoot v-model="moment" class="target-picker" :aria-label="label">
        <RadioCard value="day">{{ t('planning.form.onDay') }}</RadioCard>
        <RadioCard value="event">{{ t('planning.form.onEvent') }}</RadioCard>
    </RadioGroupRoot>
    <FormField v-if="moment === 'day'" as="group" :label="dayLabel">
        <BaseDatePicker v-model="day" />
    </FormField>
    <FormField v-else as="group" :label="t('planning.form.event')" :hint="eventOptions.length ? null : t('planning.form.noEvent')">
        <BaseSelect v-model="eventId" :options="eventOptions" :aria-label="t('planning.form.event')" />
    </FormField>
</template>

<style scoped>
.target-picker { display: flex; flex-wrap: wrap; gap: var(--space-2); }
</style>
