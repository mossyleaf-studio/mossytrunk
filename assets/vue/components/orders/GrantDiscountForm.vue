<script setup>
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import BaseButton from '../ui/BaseButton.vue';
import BaseSelect from '../ui/BaseSelect.vue';
import FormActions from '../ui/FormActions.vue';
import FormError from '../ui/FormError.vue';
import FormField from '../ui/FormField.vue';
import FormSection from '../ui/FormSection.vue';
import { useFormSubmit } from '../../composables/useFormSubmit.js';

const props = defineProps({
    rules: { type: Array, required: true },
    submit: { type: Function, required: true },
});
const emit = defineEmits(['saved', 'cancel']);
const { t } = useI18n();

const ruleId = ref(props.rules[0]?.id ?? '');
const { saving, errors, run } = useFormSubmit();

async function onSubmit() {
    await run(async () => {
        await props.submit({ ruleId: ruleId.value });
        emit('saved', props.rules.find((rule) => rule.id === ruleId.value)?.name ?? '');
    });
}
</script>

<template>
    <form class="grant-discount-form" novalidate @submit.prevent="onSubmit">
        <fieldset class="form-lock" :disabled="saving">
            <FormError v-if="errors.form">{{ errors.form }}</FormError>
            <FormSection>
                <FormField as="group" :label="t('orders.discounts.rule')" :error="errors.ruleId" :hint="t('orders.discounts.hint')">
                    <BaseSelect v-model="ruleId" :options="rules.map((rule) => ({ value: rule.id, label: rule.name }))" :aria-label="t('orders.discounts.rule')" />
                </FormField>
            </FormSection>
            <FormActions>
                <BaseButton variant="ghost" @click="emit('cancel')">{{ t('common.cancel') }}</BaseButton>
                <BaseButton type="submit" :loading="saving">{{ t('orders.discounts.apply') }}</BaseButton>
            </FormActions>
        </fieldset>
    </form>
</template>

<style scoped>
.grant-discount-form { display: flex; flex-direction: column; gap: var(--space-5); }
</style>
