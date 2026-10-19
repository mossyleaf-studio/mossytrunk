<script setup>
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import BaseButton from '../ui/BaseButton.vue';
import ExternalItemLinkForm from './ExternalItemLinkForm.vue';

const props = defineProps({
    item: { type: Object, required: true },
    products: { type: Array, required: true },
    submit: { type: Function, required: true },
});
const emit = defineEmits(['linked']);
const { t } = useI18n();

const editing = ref(false);
const linked = computed(() => props.item.components.length > 0);
const summary = computed(() => props.item.components
    .map(({ name, variant, quantity }) => `${variant ? `${name} — ${variant}` : name} ×${quantity}`)
    .join(', '));

function onSaved(item) {
    editing.value = false;
    emit('linked', item);
}
</script>

<template>
    <li :class="['external-item', { 'external-item--linked': linked }]">
        <div class="external-item__head">
            <div class="external-item__source">
                <span class="external-item__title" :title="item.label">{{ item.label }}</span>
                <span v-if="item.variation" class="external-item__variation">{{ item.variation }}</span>
                <span :class="['external-item__target', { 'external-item__target--missing': !linked }]">{{ linked ? summary : t('import.row.toLink') }}</span>
            </div>
            <BaseButton v-if="!editing" :variant="linked ? 'ghost' : 'secondary'" @click="editing = true">{{ linked ? t('common.edit') : t('import.row.link') }}</BaseButton>
        </div>
        <ExternalItemLinkForm v-if="editing" :item="item" :products="products" :submit="submit" @saved="onSaved" @cancel="editing = false" />
    </li>
</template>

<style scoped>
.external-item { display: flex; flex-direction: column; gap: var(--space-2); padding: var(--space-3) 0; border-bottom: 0.0625rem solid var(--color-border); }
.external-item__head { display: flex; align-items: center; justify-content: space-between; gap: var(--space-3); }
.external-item__source { display: flex; flex-direction: column; min-width: 0; }
.external-item__title { overflow: hidden; font-weight: 600; text-overflow: ellipsis; white-space: nowrap; }
.external-item__variation { color: var(--color-muted); font-size: var(--font-size-md); }
.external-item__target { color: var(--color-text); font-size: var(--font-size-md); }
.external-item__target--missing { color: var(--color-warning); }
.external-item--linked .external-item__title { font-weight: 400; color: var(--color-muted); }
</style>
