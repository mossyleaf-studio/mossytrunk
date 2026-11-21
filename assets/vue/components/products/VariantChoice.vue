<script setup>
import { computed } from 'vue';
import { ToggleGroupItem } from 'reka-ui';
import ChoiceGroup from '../ui/ChoiceGroup.vue';

const props = defineProps({
    options: { type: Array, required: true },
    none: { type: String, default: null },
});
const model = defineModel({ type: String, default: '' });

const NONE = '__none__';
const chosen = computed({
    get: () => model.value || (props.none ? NONE : ''),
    set: (value) => { model.value = value === NONE ? '' : value; },
});
</script>

<template>
    <ChoiceGroup v-model="chosen" class="variant-choice">
        <ToggleGroupItem v-if="none" :value="NONE" class="chip">{{ none }}</ToggleGroupItem>
        <ToggleGroupItem v-for="option in options" :key="option" :value="option" class="chip">{{ option }}</ToggleGroupItem>
    </ChoiceGroup>
</template>

<style scoped>
.variant-choice { display: flex; flex-wrap: wrap; gap: var(--space-1); }
</style>
