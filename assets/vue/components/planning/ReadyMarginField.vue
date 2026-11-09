<script setup>
import { useI18n } from 'vue-i18n';
import BaseNumberField from '../ui/BaseNumberField.vue';

const props = defineProps({
    placeholder: { type: Number, default: null },
});
const days = defineModel({ type: Number, default: null });
const { t } = useI18n();
</script>

<template>
    <span class="ready-margin-field">
        <span>{{ t('planning.readiness.ready') }}</span>
        <BaseNumberField
            v-model="days"
            :min="0"
            :label="t('planning.readiness.days')"
            :placeholder="props.placeholder === null ? undefined : String(props.placeholder)"
            class="ready-margin-field__days"
        />
        <span>{{ t('planning.readiness.unit', days ?? props.placeholder ?? 0) }}</span>
    </span>
</template>

<style scoped>
.ready-margin-field { display: inline-flex; flex-wrap: wrap; align-items: center; gap: var(--space-2); color: var(--color-text); }
.ready-margin-field__days { flex: none; width: 7rem; }
</style>
