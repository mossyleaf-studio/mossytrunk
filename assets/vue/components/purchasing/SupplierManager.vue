<script setup>
import { reactive, ref } from 'vue';
import { Pencil } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import BaseButton from '../ui/BaseButton.vue';
import BaseNumberField from '../ui/BaseNumberField.vue';
import EditableList from '../ui/EditableList.vue';
import EmptyState from '../ui/EmptyState.vue';
import FormActions from '../ui/FormActions.vue';
import FormField from '../ui/FormField.vue';
import FormSection from '../ui/FormSection.vue';
import IconButton from '../ui/IconButton.vue';
import { useFormSubmit } from '../../composables/useFormSubmit.js';

const { t } = useI18n();

const props = defineProps({
    suppliers: { type: Array, required: true },
    save: { type: Function, required: true },
});
const emit = defineEmits(['saved']);

const editingId = ref(null);
const form = reactive({ name: '', contact: '', notes: '', leadDays: null });
const { saving, errors, run } = useFormSubmit('name');

function edit(supplier) {
    editingId.value = supplier?.id ?? null;
    Object.assign(form, { name: supplier?.name ?? '', contact: supplier?.contact ?? '', notes: supplier?.notes ?? '', leadDays: supplier?.leadDays ?? null });
    errors.value = {};
}

async function onSubmit() {
    await run(async () => {
        await props.save(editingId.value, { ...form, leadDays: form.leadDays || null });
        emit('saved', form.name);
        edit(null);
    });
}
</script>

<template>
    <div class="supplier-manager">
        <EmptyState v-if="suppliers.length === 0">{{ t('purchasing.suppliers.empty') }}</EmptyState>
        <EditableList v-else :items="suppliers" :editing-id="editingId">
            <template #default="{ item: supplier }">
                <div>
                    <strong>{{ supplier.name }}</strong>
                    <span v-if="supplier.contact" class="supplier-manager__contact">{{ supplier.contact }}</span>
                    <span v-if="supplier.leadDays" class="supplier-manager__contact">{{ t('purchasing.suppliers.leadTimeOf', supplier.leadDays) }}</span>
                    <p v-if="supplier.notes" class="supplier-manager__notes">{{ supplier.notes }}</p>
                </div>
                <IconButton :icon="Pencil" :label="t('purchasing.suppliers.edit', { name: supplier.name })" @click="edit(supplier)" />
            </template>
        </EditableList>

        <form class="supplier-manager__form" novalidate @submit.prevent="onSubmit">
            <fieldset class="form-lock" :disabled="saving">
                <FormSection :title="editingId ? t('purchasing.suppliers.editTitle') : t('purchasing.suppliers.addTitle')">
                    <FormField :label="t('purchasing.suppliers.name')" :error="errors.name">
                        <input v-model="form.name" type="text">
                    </FormField>
                    <FormField :label="t('purchasing.suppliers.contact')" :error="errors.contact" optional>
                        <input v-model="form.contact" type="text" :placeholder="t('purchasing.suppliers.contactPlaceholder')">
                    </FormField>
                    <FormField as="group" :label="t('purchasing.suppliers.leadTime')" :error="errors.leadDays" :hint="t('purchasing.suppliers.leadTimeHint')" optional>
                        <BaseNumberField v-model="form.leadDays" :min="1" :label="t('purchasing.suppliers.leadTime')" class="control--short" />
                    </FormField>
                    <FormField :label="t('purchasing.suppliers.notes')" :error="errors.notes" optional>
                        <textarea v-model="form.notes" rows="2" />
                    </FormField>
                </FormSection>
                <FormActions>
                    <BaseButton v-if="editingId" variant="ghost" @click="edit(null)">{{ t('common.cancel') }}</BaseButton>
                    <BaseButton type="submit" :loading="saving">{{ editingId ? t('common.save') : t('purchasing.suppliers.add') }}</BaseButton>
                </FormActions>
            </fieldset>
        </form>
    </div>
</template>

<style scoped>
.supplier-manager { display: flex; flex-direction: column; gap: var(--space-5); }
.supplier-manager__contact { display: block; color: var(--color-muted); font-size: var(--font-size-sm); }
.supplier-manager__notes { margin: var(--space-1) 0 0; color: var(--color-muted); font-size: var(--font-size-sm); white-space: pre-line; }
.supplier-manager__form { display: flex; flex-direction: column; gap: var(--space-4); }
</style>
