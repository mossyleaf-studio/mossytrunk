<script setup>
import { onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import BaseButton from '../ui/BaseButton.vue';
import BaseNumberField from '../ui/BaseNumberField.vue';
import FormActions from '../ui/FormActions.vue';
import FormError from '../ui/FormError.vue';
import FormField from '../ui/FormField.vue';
import FormSection from '../ui/FormSection.vue';
import SupplierSelect from '../purchasing/SupplierSelect.vue';
import TargetPicker from './TargetPicker.vue';
import { useFormSubmit } from '../../composables/useFormSubmit.js';
import { usePlanning, useTarget } from '../../composables/usePlanning.js';
import { useSuppliers } from '../../composables/usePurchasing.js';

const props = defineProps({
    restock: { type: Object, default: null },
    event: { type: Object, default: null },
});
const emit = defineEmits(['saved', 'cancel']);
const { t } = useI18n();

const { planRestock, reviseRestock } = usePlanning();
const { suppliers, load: loadSuppliers, save: saveSupplier } = useSuppliers();
const { saving, errors, run } = useFormSubmit('form');

const supplierId = ref(props.restock?.supplier.id ?? '');
const note = ref(props.restock?.note ?? '');
const leadDays = ref(props.restock?.leadDays ?? null);
const { moment, day, eventId, payload: target } = useTarget(props.restock ?? (props.event && { event: props.event }));
const currentEvent = props.restock?.event ?? props.event;
const supplierOf = (id) => suppliers.value.find((supplier) => supplier.id === id);

watch(supplierId, (id) => {
    const lead = supplierOf(id)?.leadDays;
    if (lead) leadDays.value = lead;
});

async function createSupplier(id, payload) {
    const supplier = await saveSupplier(id, payload);
    await loadSuppliers();
    return supplier;
}

onMounted(loadSuppliers);

const onSubmit = () => run(async () => {
    const payload = { supplierId: supplierId.value, note: note.value || null, ...target(), leadDays: leadDays.value || 0 };
    await (props.restock ? reviseRestock(props.restock.id, payload) : planRestock(payload));
    emit('saved', supplierOf(supplierId.value)?.name ?? '');
});
</script>

<template>
    <form class="restock-form" novalidate @submit.prevent="onSubmit">
        <fieldset class="form-lock" :disabled="saving">
            <FormError v-if="errors.form">{{ errors.form }}</FormError>
            <FormSection>
                <FormField as="group" :label="t('purchasing.form.supplier')" :error="errors.supplierId">
                    <SupplierSelect v-model="supplierId" :suppliers="suppliers" :save="createSupplier" />
                </FormField>
                <FormField :label="t('planning.restock.form.note')" :error="errors.note" optional>
                    <input v-model="note" type="text" maxlength="255" :placeholder="t('planning.restock.form.notePlaceholder')">
                </FormField>
                <TargetPicker v-model:moment="moment" v-model:day="day" v-model:event-id="eventId" :event="currentEvent" :label="t('planning.restock.form.moment')" :day-label="t('planning.restock.form.day')" />
                <FormField as="group" :label="t('planning.restock.form.leadTime')" :error="errors.leadDays" :hint="t('planning.restock.form.leadTimeHint')">
                    <BaseNumberField v-model="leadDays" :min="1" :label="t('planning.restock.form.leadTime')" class="control--short" />
                </FormField>
            </FormSection>
            <FormActions>
                <BaseButton variant="ghost" @click="emit('cancel')">{{ t('common.cancel') }}</BaseButton>
                <BaseButton type="submit" :loading="saving">{{ restock ? t('common.save') : t('planning.restock.form.submit') }}</BaseButton>
            </FormActions>
        </fieldset>
    </form>
</template>

<style scoped>
.restock-form { display: flex; flex-direction: column; gap: var(--space-5); }
</style>
