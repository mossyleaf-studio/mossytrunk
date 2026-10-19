<script setup>
import { computed, reactive } from 'vue';
import { useI18n } from 'vue-i18n';
import BaseButton from '../ui/BaseButton.vue';
import BaseSelect from '../ui/BaseSelect.vue';
import FormActions from '../ui/FormActions.vue';
import FormError from '../ui/FormError.vue';
import FormField from '../ui/FormField.vue';
import FormSection from '../ui/FormSection.vue';
import ServiceOptions from '../settings/ServiceOptions.vue';
import { useFormSubmit } from '../../composables/useFormSubmit.js';

const props = defineProps({
    channel: { type: Object, default: null },
    services: { type: Array, required: true },
    submit: { type: Function, required: true },
});
const emit = defineEmits(['saved', 'cancel']);
const { t } = useI18n();

const NONE = '__none__';
const KINDS = ['market', 'online', 'direct'].map((kind) => ({ value: kind, label: `channels.kinds.${kind}`, description: `channels.kindHints.${kind}` }));
const form = reactive({ name: props.channel?.name ?? '', kind: props.channel?.kind ?? 'online', service: props.channel?.service ?? NONE });
const { saving, errors, run } = useFormSubmit();

const serviceOptions = computed(() => [
    { value: NONE, label: t('common.none') },
    ...props.services.map((service) => ({ value: service.key, label: service.label })),
]);

async function onSubmit() {
    await run(async () => {
        await props.submit({ name: form.name, kind: form.kind, service: form.service === NONE ? null : form.service });
        emit('saved', form.name.trim());
    });
}
</script>

<template>
    <form class="sales-channel-form" novalidate @submit.prevent="onSubmit">
        <fieldset class="form-lock" :disabled="saving">
            <FormError v-if="errors.form">{{ errors.form }}</FormError>
            <FormSection>
                <FormField :label="t('channels.name')" :error="errors.name">
                    <input v-model="form.name" type="text" required maxlength="100" :placeholder="t('channels.namePlaceholder')">
                </FormField>
                <FormField as="group" :label="t('channels.kind')" :error="errors.kind">
                    <ServiceOptions v-model="form.kind" :options="KINDS" :label="t('channels.kind')" />
                </FormField>
                <FormField as="group" :label="t('channels.service')" :error="errors.service" :hint="t('channels.serviceHint')">
                    <BaseSelect v-model="form.service" :options="serviceOptions" :aria-label="t('channels.service')" />
                </FormField>
            </FormSection>
            <FormActions>
                <BaseButton variant="ghost" @click="emit('cancel')">{{ t('common.cancel') }}</BaseButton>
                <BaseButton type="submit" :loading="saving">{{ t(channel ? 'common.save' : 'channels.create') }}</BaseButton>
            </FormActions>
        </fieldset>
    </form>
</template>

<style scoped>
.sales-channel-form { display: flex; flex-direction: column; gap: var(--space-5); }
</style>
