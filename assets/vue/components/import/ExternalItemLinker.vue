<script setup>
import { computed } from 'vue';
import { I18nT, useI18n } from 'vue-i18n';
import BaseButton from '../ui/BaseButton.vue';
import EmptyState from '../ui/EmptyState.vue';
import ExternalItemRow from './ExternalItemRow.vue';

const props = defineProps({
    label: { type: String, required: true },
    items: { type: Array, required: true },
    products: { type: Array, required: true },
    submit: { type: Function, required: true },
    importing: { type: Boolean, default: false },
    canImport: { type: Boolean, default: true },
});
const emit = defineEmits(['linked', 'reimport']);
const { t } = useI18n();

const unlinked = computed(() => props.items.filter((item) => item.components.length === 0));
const linked = computed(() => props.items.filter((item) => item.components.length > 0));
</script>

<template>
    <div class="item-linker">
        <I18nT keypath="import.linker.intro" tag="p" class="item-linker__intro">
            <template #service>{{ label }}</template>
            <template #example><code>STI-MOUSSE</code></template>
        </I18nT>

        <section v-if="unlinked.length" :aria-label="t('import.linker.unlinked')">
            <ul class="item-linker__list">
                <ExternalItemRow v-for="item in unlinked" :key="item.id" :item="item" :products="products" :submit="submit" @linked="emit('linked', $event)" />
            </ul>
        </section>
        <EmptyState v-else>{{ t('import.linker.allLinked', { service: label }) }}</EmptyState>

        <details v-if="linked.length" class="item-linker__linked">
            <summary>{{ t('import.linker.linked', { count: linked.length }) }}</summary>
            <ul class="item-linker__list">
                <ExternalItemRow v-for="item in linked" :key="item.id" :item="item" :products="products" :submit="submit" @linked="emit('linked', $event)" />
            </ul>
        </details>

        <div v-if="canImport" class="actions-row">
            <BaseButton :loading="importing" @click="emit('reimport')">{{ t('import.linker.reimport', { service: label }) }}</BaseButton>
        </div>
    </div>
</template>

<style scoped>
.item-linker { display: flex; flex-direction: column; gap: var(--space-4); }
.item-linker__intro { margin: 0; color: var(--color-muted); font-size: var(--font-size-md); }
.item-linker__intro code { padding: 0 var(--space-1); border-radius: var(--radius-sm); background: var(--color-bg); color: var(--color-ink); }
.item-linker__list { margin: 0; padding: 0; list-style: none; }
.item-linker__linked summary { color: var(--color-muted); cursor: pointer; }
</style>
