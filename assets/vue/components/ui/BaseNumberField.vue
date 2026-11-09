<script setup>
import { Minus, Plus } from '@lucide/vue';
import { NumberFieldDecrement, NumberFieldIncrement, NumberFieldInput, NumberFieldRoot } from 'reka-ui';
import { useI18n } from 'vue-i18n';
import { intlLocale } from '../../i18n/locale.js';

const { t } = useI18n();

defineProps({
    label: { type: String, default: undefined },
    placeholder: { type: String, default: undefined },
});
</script>

<template>
    <NumberFieldRoot :locale="intlLocale()" class="control number-field">
        <NumberFieldInput class="number-field__input" :aria-label="label" :placeholder="placeholder" />
        <NumberFieldDecrement class="number-field__step number-field__step--decrement" :aria-label="t('ui.number.decrement')"><Minus size="0.875rem" aria-hidden="true" /></NumberFieldDecrement>
        <NumberFieldIncrement class="number-field__step" :aria-label="t('ui.number.increment')"><Plus size="0.875rem" aria-hidden="true" /></NumberFieldIncrement>
    </NumberFieldRoot>
</template>

<style scoped>
.number-field { display: flex; align-items: stretch; padding: 0; overflow: hidden; }

.number-field__input {
    flex: 1;
    min-width: 0;
    padding: var(--space-2) var(--space-1);
    border: none;
    background: none;
    text-align: center;
    font-variant-numeric: tabular-nums;
}

.number-field__input:focus { outline: none; }

.number-field__step {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 2rem;
    border: none;
    background: var(--color-bg);
    color: var(--color-muted);
    cursor: pointer;
    transition: color var(--transition), background var(--transition);
}

.number-field__step:hover:not(:disabled) { color: var(--color-ink); background: var(--color-border); }
.number-field__step:disabled { opacity: 0.4; cursor: not-allowed; }
.number-field__step--decrement { order: -1; }
</style>
