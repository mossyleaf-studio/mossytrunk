<script setup>
import { useI18n } from 'vue-i18n';
import { Link2 } from '@lucide/vue';
import BaseButton from '../ui/BaseButton.vue';
import Callout from '../ui/Callout.vue';

defineProps({
    label: { type: String, required: true },
    count: { type: Number, required: true },
});
const { t } = useI18n();
</script>

<template>
    <Callout class="items-notice" role="status">
        <Link2 class="items-notice__icon" size="1.25rem" aria-hidden="true" />
        <p class="items-notice__text">
            <strong>{{ t('import.notice.count', { service: label }, count) }}</strong>
            {{ t('import.notice.text') }}
        </p>
        <BaseButton variant="secondary" href="/settings">{{ t('import.notice.link') }}</BaseButton>
    </Callout>
</template>

<style scoped>
.items-notice { align-items: center; }

.items-notice__icon { flex: none; color: var(--color-warning); }
.items-notice__text { flex: 1; margin: 0; }
.items-notice__text strong { color: var(--color-warning); }

@media (max-width: 40rem) {
    .items-notice { flex-direction: column; align-items: flex-start; }
}
</style>
