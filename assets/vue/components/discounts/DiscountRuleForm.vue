<script setup>
import { computed, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { Plus, Trash2, X } from '@lucide/vue';
import { ToggleGroupItem, ToggleGroupRoot } from 'reka-ui';
import BaseButton from '../ui/BaseButton.vue';
import BaseCombobox from '../ui/BaseCombobox.vue';
import BaseDatePicker from '../ui/BaseDatePicker.vue';
import BaseMoneyField from '../ui/BaseMoneyField.vue';
import BaseNumberField from '../ui/BaseNumberField.vue';
import BaseSelect from '../ui/BaseSelect.vue';
import ChoiceGroup from '../ui/ChoiceGroup.vue';
import FormActions from '../ui/FormActions.vue';
import FormDisclosure from '../ui/FormDisclosure.vue';
import FormError from '../ui/FormError.vue';
import FormField from '../ui/FormField.vue';
import FormSection from '../ui/FormSection.vue';
import IconButton from '../ui/IconButton.vue';
import { describePeriod, describeRange, pricingDay, regularPrice, savingOn } from '../../composables/useRulePrice.js';
import { formatDate } from '../../composables/useDate.js';
import { useFormSubmit } from '../../composables/useFormSubmit.js';

const props = defineProps({
    rule: { type: Object, default: null },
    products: { type: Array, required: true },
    types: { type: Array, required: true },
    submit: { type: Function, required: true },
});
const emit = defineEmits(['saved', 'cancel']);
const { t } = useI18n();

const ACTIONS = [
    { value: 'fixedPrice', label: 'discounts.form.fixedPrice', valueLabel: 'discounts.form.itemsPrice' },
    { value: 'amountOff', label: 'discounts.form.amountOff', valueLabel: 'discounts.form.discountAmount' },
    { value: 'percentOff', label: 'discounts.form.percentOff', valueLabel: 'discounts.form.percent' },
];

const emptyTarget = () => ({ kind: 'type', id: '', variant: '', collectionId: '' });
const emptyCondition = () => ({ quantity: 1, targets: [emptyTarget()] });
const emptyForm = () => ({ name: '', conditions: [emptyCondition()], actionKind: 'fixedPrice', amount: null, percent: null, startsOn: '', endsOn: '' });
const fromRule = (rule) => ({
    name: rule.name,
    conditions: rule.conditions.map(({ quantity, targets }) => ({ quantity, targets: targets.map(({ kind, id, variant, collectionId }) => ({ kind, id, variant: variant ?? '', collectionId: collectionId ?? '' })) })),
    actionKind: rule.action.kind,
    amount: rule.action.kind === 'percentOff' ? null : rule.action.value,
    percent: rule.action.kind === 'percentOff' ? rule.action.value / 100 : null,
    startsOn: rule.startsOn ?? '',
    endsOn: rule.endsOn ?? '',
});
const form = reactive(props.rule ? fromRule(props.rule) : emptyForm());
const { saving, errors, run } = useFormSubmit();
const isEditing = computed(() => props.rule !== null);

const offered = (subjects, target) => subjects.filter((subject) => !subject.archived || subject.id === target.id);
const productOptions = (target) => offered(props.products, target).map((product) => ({ value: product.id, label: product.displayName }));
const typeOptions = (target) => offered(props.types, target).map((type) => ({ value: type.id, label: type.name }));
const optionsFor = (target) => (target.kind === 'type' ? typeOptions(target) : productOptions(target));
const subjectOf = (target) => (target.kind === 'type' ? props.types : props.products).find((subject) => subject.id === target.id);
const archivedVariantsOf = (target) => (target.kind === 'type'
    ? subjectOf(target)?.archivedVariants ?? []
    : (subjectOf(target)?.variants ?? []).filter((variant) => !subjectOf(target).activeVariants.includes(variant)));
const variantsOf = (target) => (subjectOf(target)?.variants ?? [])
    .filter((variant) => variant === target.variant || !archivedVariantsOf(target).includes(variant));
const variantOptions = (target) => [
    { value: '', label: t('discounts.form.allVariants') },
    ...variantsOf(target).map((variant) => ({ value: variant, label: variant })),
];
const collectionsOf = (target) => (target.kind === 'type' ? [...new Map(props.products
    .filter((product) => product.typeId === target.id && product.collectionId)
    .map((product) => [product.collectionId, product.collectionName])).entries()] : []);
const collectionOptions = (target) => [
    { value: '', label: t('discounts.form.allCollections') },
    ...collectionsOf(target).map(([value, label]) => ({ value, label })),
];
const targetNumber = (index, position) => (position === 0 ? `${index + 1}` : t('discounts.form.targetNumber', { condition: index + 1, target: position + 1 }));

const action = computed(() => ({
    kind: form.actionKind,
    value: form.actionKind === 'percentOff' ? Math.round((form.percent ?? 0) * 100) : (form.amount ?? 0),
}));

const pricing = computed(() => {
    const day = pricingDay(form.startsOn, form.endsOn);
    const regular = regularPrice(props.products, form.conditions, day);
    if (!regular) {
        return null;
    }
    return {
        day,
        regular,
        customer: {
            min: regular.min - savingOn(regular.min, action.value),
            max: regular.max - savingOn(regular.max, action.value),
        },
    };
});

const valueLabel = computed(() => t(ACTIONS.find((option) => option.value === form.actionKind).valueLabel));

const periodOpen = ref(false);
const periodSummary = computed(() => describePeriod(form.startsOn, form.endsOn, 'discounts.form.always'));

function setKind(target, kind) {
    if (kind && kind !== target.kind) {
        target.kind = kind;
        target.id = '';
        target.variant = '';
        target.collectionId = '';
    }
}

function setSubject(target, id) {
    if (id !== target.id) {
        target.id = id;
        target.variant = '';
        target.collectionId = '';
    }
}

const conditionError = (index) => errors.value[`conditions[${index}].quantity`] ?? errors.value[`conditions[${index}].targets`];
const targetError = (index, position) => ['id', 'variant', 'collectionId'].map((field) => errors.value[`conditions[${index}].targets[${position}].${field}`]).find(Boolean);

function removeCondition(index) {
    form.conditions.splice(index, 1);
}

async function onSubmit() {
    await run(async () => {
        await props.submit({
            name: form.name,
            conditions: form.conditions.map((condition) => ({
                quantity: Number(condition.quantity) || 0,
                targets: condition.targets.map((target) => ({ kind: target.kind, id: target.id, variant: target.variant || null, collectionId: target.collectionId || null })),
            })),
            action: action.value,
            startsOn: form.startsOn || null,
            endsOn: form.endsOn || null,
        });
        emit('saved', form.name);
        if (!isEditing.value) {
            Object.assign(form, emptyForm());
        }
    });
}
</script>

<template>
    <form class="discount-rule-form" novalidate @submit.prevent="onSubmit">
        <fieldset class="form-lock" :disabled="saving">
            <FormError v-if="errors.form">{{ errors.form }}</FormError>

            <FormSection>
                <FormField :label="t('discounts.form.name')" :error="errors.name">
                    <input v-model="form.name" type="text" :placeholder="t('discounts.form.namePlaceholder')">
                </FormField>
            </FormSection>

            <FormSection :title="t('discounts.form.conditions')" :description="t('discounts.form.conditionsHint')">
                <ol class="discount-rule-form__conditions">
                    <li v-for="(condition, index) in form.conditions" :key="index" class="discount-rule-form__condition" :data-test="`condition-${index}`">
                        <BaseNumberField v-model="condition.quantity" class="discount-rule-form__quantity" :min="1" :label="t('discounts.form.conditionQuantity', { number: index + 1 })" />
                        <span class="discount-rule-form__times" aria-hidden="true">×</span>
                        <div class="discount-rule-form__group">
                            <ol class="discount-rule-form__targets">
                                <li v-for="(target, position) in condition.targets" :key="position" :class="['discount-rule-form__target-row', { 'discount-rule-form__target-row--alternative': position > 0 }]">
                                    <span v-if="position > 0" class="eyebrow discount-rule-form__or">{{ t('discounts.form.or') }}</span>
                                    <ToggleGroupRoot
                                        :model-value="target.kind"
                                        type="single"
                                        class="discount-rule-form__kinds discount-rule-form__condition-kinds"
                                        :aria-label="t('discounts.form.conditionTarget', { number: targetNumber(index, position) })"
                                        @update:model-value="(kind) => setKind(target, kind)"
                                    >
                                        <ToggleGroupItem value="type" class="chip">{{ t('discounts.form.type') }}</ToggleGroupItem>
                                        <ToggleGroupItem value="product" class="chip">{{ t('discounts.form.product') }}</ToggleGroupItem>
                                    </ToggleGroupRoot>
                                    <IconButton
                                        v-if="condition.targets.length > 1"
                                        class="discount-rule-form__remove-target"
                                        :icon="X"
                                        :label="t('discounts.form.removeTarget', { number: targetNumber(index, position) })"
                                        @click="condition.targets.splice(position, 1)"
                                    />
                                    <div class="discount-rule-form__target">
                                        <BaseCombobox
                                            :model-value="target.id"
                                            :options="optionsFor(target)"
                                            :aria-label="target.kind === 'type' ? t('discounts.form.conditionType', { number: targetNumber(index, position) }) : t('discounts.form.conditionProduct', { number: targetNumber(index, position) })"
                                            :placeholder="target.kind === 'type' ? t('discounts.form.chooseType') : t('discounts.form.searchProduct')"
                                            @update:model-value="(id) => setSubject(target, id ?? '')"
                                        />
                                        <BaseSelect
                                            v-if="collectionsOf(target).length"
                                            v-model="target.collectionId"
                                            :options="collectionOptions(target)"
                                            :aria-label="t('discounts.form.conditionCollection', { number: targetNumber(index, position) })"
                                        />
                                        <BaseSelect
                                            v-if="variantsOf(target).length"
                                            v-model="target.variant"
                                            :options="variantOptions(target)"
                                            :aria-label="t('discounts.form.conditionVariant', { number: targetNumber(index, position) })"
                                        />
                                    </div>
                                    <span v-if="targetError(index, position)" class="discount-rule-form__condition-error" role="alert">{{ targetError(index, position) }}</span>
                                </li>
                            </ol>
                            <BaseButton variant="ghost" class="discount-rule-form__add-target" @click="condition.targets.push(emptyTarget())">
                                <Plus size="1rem" aria-hidden="true" /> {{ t('discounts.form.addTarget') }}
                            </BaseButton>
                        </div>
                        <IconButton
                            class="discount-rule-form__remove"
                            :icon="Trash2"
                            :label="t('discounts.form.removeCondition', { number: index + 1 })"
                            :disabled="form.conditions.length === 1"
                            @click="removeCondition(index)"
                        />
                        <span v-if="conditionError(index)" class="discount-rule-form__condition-error" role="alert">{{ conditionError(index) }}</span>
                    </li>
                </ol>
                <p v-if="errors.conditions" class="discount-rule-form__condition-error" role="alert">{{ errors.conditions }}</p>
                <BaseButton variant="ghost" class="discount-rule-form__add" @click="form.conditions.push(emptyCondition())">
                    <Plus size="1rem" aria-hidden="true" /> {{ t('discounts.form.addCondition') }}
                </BaseButton>
            </FormSection>

            <FormSection :title="t('discounts.form.action')">
                <ChoiceGroup v-model="form.actionKind" class="discount-rule-form__kinds" :aria-label="t('discounts.form.actionKind')">
                    <ToggleGroupItem v-for="option in ACTIONS" :key="option.value" :value="option.value" class="chip">{{ t(option.label) }}</ToggleGroupItem>
                </ChoiceGroup>
                <FormField v-if="form.actionKind === 'percentOff'" as="group" :label="valueLabel" :error="errors['action.value'] ?? errors['action.kind']">
                    <span class="discount-rule-form__value">
                        <BaseNumberField v-model="form.percent" :min="0" :max="100" :step="0.5" :label="valueLabel" />
                        <span class="discount-rule-form__unit" aria-hidden="true">%</span>
                    </span>
                </FormField>
                <FormField v-else :label="valueLabel" :error="errors['action.value'] ?? errors['action.kind']">
                    <BaseMoneyField v-model="form.amount" class="discount-rule-form__value" />
                </FormField>
                <FormField v-if="pricing" as="group" :label="t('discounts.form.customerPrice')">
                    <p class="discount-rule-form__fact" data-test="discount-rule-pricing">
                        <i18n-t keypath="discounts.form.pricing" scope="global">
                            <template #customer><strong>{{ describeRange(pricing.customer) }}</strong></template>
                            <template #regular>{{ describeRange(pricing.regular) }}</template>
                        </i18n-t>
                        <span v-if="pricing.day" class="discount-rule-form__priced-on">{{ t('discounts.list.pricedOn', { date: formatDate(pricing.day) }) }}</span>
                    </p>
                </FormField>
            </FormSection>

            <FormDisclosure v-model:open="periodOpen" :title="t('discounts.form.period')" :summary="periodSummary">
                <FormField as="group" optional :label="t('discounts.form.startsOn')" :error="errors.startsOn">
                    <BaseDatePicker v-model="form.startsOn" :aria-label="t('discounts.form.validFrom')" />
                </FormField>
                <FormField as="group" optional :label="t('discounts.form.endsOn')" :error="errors.endsOn">
                    <BaseDatePicker v-model="form.endsOn" :aria-label="t('discounts.form.validUntil')" />
                </FormField>
            </FormDisclosure>

            <FormActions>
                <BaseButton variant="ghost" @click="emit('cancel')">{{ t('common.cancel') }}</BaseButton>
                <BaseButton type="submit" :loading="saving">{{ isEditing ? t('common.save') : t('discounts.form.create') }}</BaseButton>
            </FormActions>
        </fieldset>
    </form>
</template>

<style scoped>
.discount-rule-form { display: flex; flex-direction: column; gap: var(--space-5); }

.discount-rule-form__conditions { display: flex; flex-direction: column; gap: var(--space-4); margin: 0; padding: 0; list-style: none; }
.discount-rule-form__condition {
    display: grid;
    grid-template-columns: 6.5rem auto minmax(0, 1fr) auto;
    grid-template-areas: "quantity times group remove";
    align-items: start;
    gap: var(--space-2);
}
.discount-rule-form__quantity { grid-area: quantity; }
.discount-rule-form__times { grid-area: times; padding-top: 0.5625rem; color: var(--color-muted); }
.discount-rule-form__group { grid-area: group; display: flex; flex-direction: column; align-items: flex-start; gap: var(--space-2); min-width: 0; }
.discount-rule-form__targets { display: flex; flex-direction: column; gap: var(--space-3); width: 100%; margin: 0; padding: 0; list-style: none; }
.discount-rule-form__target-row {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    grid-template-areas:
        "kinds remove-target"
        "target target";
    align-items: center;
    gap: var(--space-2);
}
.discount-rule-form__target-row--alternative {
    grid-template-areas:
        "or or"
        "kinds remove-target"
        "target target";
}
.discount-rule-form__or { grid-area: or; }
.discount-rule-form__condition-kinds { grid-area: kinds; }
.discount-rule-form__target-row :deep(.discount-rule-form__remove-target) { grid-area: remove-target; }
.discount-rule-form__condition :deep(.discount-rule-form__remove) { grid-area: remove; }
.discount-rule-form__target { grid-area: target; display: grid; grid-template-columns: minmax(0, 1fr); grid-auto-columns: auto; grid-auto-flow: column; gap: var(--space-2); }
.discount-rule-form__condition-error { grid-column: 1 / -1; margin: 0; color: var(--color-danger); font-size: var(--font-size-md); }
.discount-rule-form__add { align-self: flex-start; }

.discount-rule-form__value { display: flex; align-items: center; gap: var(--space-2); max-width: 11rem; }
.discount-rule-form__unit { color: var(--color-muted); }
.discount-rule-form__fact { margin: 0; padding-top: 0.5625rem; font-size: var(--font-size-md); color: var(--color-muted); }
.discount-rule-form__fact strong { color: var(--color-ink); font-weight: 600; }

.discount-rule-form__kinds { display: flex; flex-wrap: wrap; gap: var(--space-1); }

@container form (max-width: 28rem) {
    .discount-rule-form__condition { grid-template-areas: "quantity times . remove" "group group group group"; }
    .discount-rule-form__target { grid-auto-flow: row; }
}
.discount-rule-form__priced-on { display: block; color: var(--color-muted); font-size: var(--font-size-xs); }
</style>
