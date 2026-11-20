<script setup>
import {
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogOverlay,
    AlertDialogPortal,
    AlertDialogRoot,
    AlertDialogTitle,
} from 'reka-ui';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import BaseButton from './BaseButton.vue';
import IconButton from './IconButton.vue';

const { t } = useI18n();

defineProps({
    label: { type: String, required: true },
    confirmLabel: { type: String, default: undefined },
    message: { type: String, default: undefined },
    icon: { type: [Object, Function], default: null },
    variant: { type: String, default: 'ghost' },
    loading: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
});
const emit = defineEmits(['confirm']);

let trigger = null;
const used = ref(false);
const open = ref(false);

function ask(event) {
    trigger = event.currentTarget;
    used.value = true;
    open.value = true;
}

function refocus(event) {
    event.preventDefault();
    trigger?.focus();
}
</script>

<template>
    <IconButton v-if="icon" :icon="icon" :label="label" variant="danger" aria-haspopup="dialog" @click="ask" />
    <BaseButton v-else :variant="variant" :loading="loading" :disabled="disabled" aria-haspopup="dialog" @click="ask">{{ label }}</BaseButton>
    <AlertDialogRoot v-if="used" v-model:open="open">
        <AlertDialogPortal>
            <AlertDialogOverlay class="modal modal--dialog modal--alert">
                <AlertDialogContent class="modal__panel" @close-auto-focus="refocus">
                    <AlertDialogTitle class="modal__title">{{ label }}</AlertDialogTitle>
                    <AlertDialogDescription class="confirm-dialog__message">{{ message ?? t('ui.confirm.message') }}</AlertDialogDescription>
                    <div class="actions-row confirm-dialog__actions">
                        <AlertDialogCancel as-child><BaseButton variant="ghost">{{ t('common.cancel') }}</BaseButton></AlertDialogCancel>
                        <AlertDialogAction as-child><BaseButton variant="danger" @click="emit('confirm')">{{ confirmLabel ?? t('ui.confirm.action') }}</BaseButton></AlertDialogAction>
                    </div>
                </AlertDialogContent>
            </AlertDialogOverlay>
        </AlertDialogPortal>
    </AlertDialogRoot>
</template>

<style>
.confirm-dialog__message { margin: 0; color: var(--color-muted); }
.confirm-dialog__actions { margin-top: var(--space-2); }
</style>
