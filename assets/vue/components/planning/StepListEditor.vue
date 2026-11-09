<script setup>
import { ArrowDown, ArrowUp, Plus, Trash2 } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import BaseButton from '../ui/BaseButton.vue';
import BaseNumberField from '../ui/BaseNumberField.vue';
import EmptyState from '../ui/EmptyState.vue';
import IconButton from '../ui/IconButton.vue';

const steps = defineModel({ type: Array, required: true });
const { t } = useI18n();
const keys = new WeakMap();
let lastKey = 0;
const keyOf = (step) => {
    if (!keys.has(step)) keys.set(step, ++lastKey);
    return keys.get(step);
};

function move(index, offset) {
    const next = [...steps.value];
    [next[index], next[index + offset]] = [next[index + offset], next[index]];
    steps.value = next;
}

const remove = (index) => { steps.value = steps.value.filter((_, position) => position !== index); };
const add = () => { steps.value = [...steps.value, { label: '', days: 1 }]; };
</script>

<template>
    <div class="step-list-editor">
        <ol v-if="steps.length" class="step-list-editor__steps">
            <li v-for="(step, index) in steps" :key="keyOf(step)" class="step-list-editor__step">
                <input v-model="step.label" type="text" class="control step-list-editor__label" :aria-label="t('planning.steps.label', { n: index + 1 })">
                <BaseNumberField v-model="step.days" :min="1" :label="t('planning.steps.days', { n: index + 1 })" class="step-list-editor__days" />
                <span class="step-list-editor__unit">{{ t('planning.steps.unit', step.days ?? 0) }}</span>
                <span class="step-list-editor__actions">
                    <IconButton :icon="ArrowUp" :label="t('planning.steps.moveUp', { n: index + 1 })" :disabled="index === 0" @click="move(index, -1)" />
                    <IconButton :icon="ArrowDown" :label="t('planning.steps.moveDown', { n: index + 1 })" :disabled="index === steps.length - 1" @click="move(index, 1)" />
                    <IconButton :icon="Trash2" variant="danger" :label="t('planning.steps.remove', { n: index + 1 })" @click="remove(index)" />
                </span>
            </li>
        </ol>
        <EmptyState v-else>{{ t('planning.steps.empty') }}</EmptyState>
        <BaseButton variant="secondary" class="step-list-editor__add" @click="add"><Plus size="1rem" aria-hidden="true" /> {{ t('planning.steps.add') }}</BaseButton>
    </div>
</template>

<style scoped>
.step-list-editor { display: flex; flex-direction: column; gap: var(--space-3); }
.step-list-editor__steps { display: flex; flex-direction: column; gap: var(--space-2); margin: 0; padding: 0; list-style: none; }
.step-list-editor__step { display: flex; flex-wrap: wrap; align-items: center; gap: var(--space-2); }
.step-list-editor__label { flex: 1 1 12rem; width: auto; }
.step-list-editor__days { flex: none; width: 7rem; }
.step-list-editor__unit { min-width: 2.5rem; color: var(--color-muted); font-size: var(--font-size-sm); }
.step-list-editor__actions { display: inline-flex; gap: var(--space-1); }
.step-list-editor__add { align-self: flex-start; }
</style>
