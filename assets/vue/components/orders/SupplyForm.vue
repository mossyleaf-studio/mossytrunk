<script setup>
import { reactive } from 'vue';
import { useI18n } from 'vue-i18n';
import { Plus, X } from '@lucide/vue';
import BaseButton from '../ui/BaseButton.vue';
import BaseNumberField from '../ui/BaseNumberField.vue';
import BaseSelect from '../ui/BaseSelect.vue';
import FormActions from '../ui/FormActions.vue';
import FormError from '../ui/FormError.vue';
import FormSection from '../ui/FormSection.vue';
import IconButton from '../ui/IconButton.vue';
import { useFormSubmit } from '../../composables/useFormSubmit.js';

const props = defineProps({
    supplies: { type: Array, required: true },
    intro: { type: String, default: null },
    submit: { type: Function, required: true },
});
const emit = defineEmits(['saved', 'cancel']);
const { t } = useI18n();

const variantsOf = (supplyId) => props.supplies.find((supply) => supply.id === supplyId)?.variants ?? [];
const lineFor = (supplyId) => ({ supplyId, variant: variantsOf(supplyId)[0] ?? null, quantity: 1 });
const lines = reactive([lineFor(props.supplies[0]?.id ?? '')]);
const { saving, errors, run } = useFormSubmit();

const supplyOptions = props.supplies.map((supply) => ({ value: supply.id, label: supply.name }));
const variantOptions = (line) => variantsOf(line.supplyId).map((variant) => ({ value: variant, label: variant }));
const numbered = (key, index) => (index === 0 ? t(key) : `${t(key)} ${index + 1}`);
const lineError = (index) => ['supplyId', 'variant', 'quantity'].map((field) => errors.value[`supplies[${index}].${field}`]).find(Boolean);

function choose(line, supplyId) {
    Object.assign(line, lineFor(supplyId), { quantity: line.quantity });
}

function addLine() {
    const unused = props.supplies.find((supply) => !lines.some((line) => line.supplyId === supply.id)) ?? props.supplies[0];
    lines.push(lineFor(unused?.id ?? ''));
}

async function onSubmit() {
    await run(async () => {
        const result = await props.submit({ supplies: lines.map((line) => ({ supplyId: line.supplyId, variant: line.variant, quantity: line.quantity ?? 0 })) });
        emit('saved', result?.updated ?? 1);
    });
}
</script>

<template>
    <form class="supply-form" novalidate @submit.prevent="onSubmit">
        <fieldset class="form-lock" :disabled="saving">
            <FormError v-if="errors.form ?? errors.supplies">{{ errors.form ?? errors.supplies }}</FormError>
            <FormSection :description="intro">
                <ol class="supply-form__lines">
                    <li v-for="(line, index) in lines" :key="index" class="supply-form__line">
                        <BaseSelect
                            :model-value="line.supplyId"
                            :options="supplyOptions"
                            :aria-label="numbered('orders.supplies.supply', index)"
                            class="supply-form__supply"
                            @update:model-value="(supplyId) => choose(line, supplyId)"
                        />
                        <BaseSelect
                            v-if="variantsOf(line.supplyId).length"
                            v-model="line.variant"
                            :options="variantOptions(line)"
                            :aria-label="numbered('orders.supplies.variant', index)"
                        />
                        <BaseNumberField v-model="line.quantity" :min="1" :label="numbered('orders.supplies.quantity', index)" class="supply-form__quantity" />
                        <IconButton
                            v-if="lines.length > 1"
                            :icon="X"
                            :label="t('orders.supplies.removeLine', { number: index + 1 })"
                            @click="lines.splice(index, 1)"
                        />
                        <span v-if="lineError(index)" class="supply-form__error" role="alert">{{ lineError(index) }}</span>
                    </li>
                </ol>
                <BaseButton variant="ghost" class="supply-form__add" @click="addLine">
                    <Plus size="1rem" aria-hidden="true" /> {{ t('orders.supplies.addLine') }}
                </BaseButton>
            </FormSection>
            <FormActions>
                <BaseButton variant="ghost" @click="emit('cancel')">{{ t('common.cancel') }}</BaseButton>
                <BaseButton type="submit" :loading="saving">{{ t('common.add') }}</BaseButton>
            </FormActions>
        </fieldset>
    </form>
</template>

<style scoped>
.supply-form { display: flex; flex-direction: column; gap: var(--space-5); }
.supply-form__lines { display: flex; flex-direction: column; gap: var(--space-2); margin: 0; padding: 0; list-style: none; }
.supply-form__line { display: flex; flex-wrap: wrap; align-items: center; gap: var(--space-2); }
.supply-form__supply { flex: 1 1 14rem; min-width: 0; }
.supply-form__quantity { width: 8rem; }
.supply-form__error { flex-basis: 100%; color: var(--color-danger); font-size: var(--font-size-md); }
.supply-form__add { align-self: flex-start; }
</style>
