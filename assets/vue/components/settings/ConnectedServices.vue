<script setup>
import { Download, Link2, Pencil, RefreshCw, Trash2 } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import BaseButton from '../ui/BaseButton.vue';
import ConfirmButton from '../ui/ConfirmButton.vue';
import CatalogueImportButton from './CatalogueImportButton.vue';
import IconButton from '../ui/IconButton.vue';
import StatusBadge from '../ui/StatusBadge.vue';
import { isReady, SALES_CONTEXTS, UNKNOWN_ITEMS } from '../../composables/useServices.js';

const { t } = useI18n();

defineProps({
    services: { type: Array, required: true },
    importing: { type: String, default: null },
    importingSales: { type: String, default: null },
});
const emit = defineEmits(['edit', 'remove', 'disconnect', 'import-sales', 'open-items', 'import-catalogue', 'read-catalogue', 'publish-references']);

const labelOf = (options, value) => {
    const label = options.find((option) => option.value === value)?.label;
    return label ? t(label) : value;
};

function status(service) {
    const connection = service.connection;
    if (!connection.configured) return { tone: 'danger', label: t('settings.connected.status.incomplete') };
    if (!service.authorizes) return { tone: 'success', label: t('settings.connected.status.ready') };
    return connection.authorized ? { tone: 'success', label: t('settings.connected.status.connected') } : { tone: 'warning', label: t('settings.connected.status.toConnect') };
}
</script>

<template>
    <ul class="connected-services">
        <li v-for="service in services" :key="service.key" class="connected-services__row">
            <span class="connected-services__mark" aria-hidden="true">{{ service.label.slice(0, 2) }}</span>
            <div class="connected-services__body">
                <p class="connected-services__title">
                    <span class="connected-services__name">{{ service.label }}</span>
                    <span v-if="service.connection.accountName" class="connected-services__account">{{ service.connection.accountName }}</span>
                    <StatusBadge :tone="status(service).tone">{{ status(service).label }}</StatusBadge>
                </p>
                <dl class="connected-services__options">
                    <div>
                        <dt>{{ t('settings.connected.sales') }}</dt>
                        <dd>{{ labelOf(SALES_CONTEXTS, service.connection.salesContext) }}</dd>
                    </div>
                    <div>
                        <dt>{{ t('settings.connected.unknownItem') }}</dt>
                        <dd>{{ labelOf(UNKNOWN_ITEMS, service.connection.unknownItems) }}</dd>
                    </div>
                </dl>
                <p v-if="service.connection.itemsToLink" class="connected-services__waiting">
                    <button type="button" @click="emit('open-items', service)">{{ t('settings.connected.itemsToLink', service.connection.itemsToLink) }}</button>
                </p>
            </div>
            <div class="actions-row connected-services__actions">
                <BaseButton v-if="isReady(service)" variant="secondary" :loading="importingSales === service.key" @click="emit('import-sales', service)">
                    <RefreshCw size="1rem" aria-hidden="true" /> {{ t('settings.connected.importSales') }}
                </BaseButton>
                <BaseButton variant="secondary" @click="emit('open-items', service)">
                    <Link2 size="1rem" aria-hidden="true" /> {{ t('settings.connected.items') }}
                </BaseButton>
                <CatalogueImportButton
                    v-if="service.importsCatalogue"
                    :label="t('settings.connected.importCatalogue')"
                    :loading="importing === service.key"
                    @chosen="emit('import-catalogue', service, $event)"
                />
                <BaseButton
                    v-if="service.readsCatalogue && service.connection.authorized"
                    variant="secondary"
                    :loading="importing === service.key"
                    @click="emit('read-catalogue', service)"
                >
                    <Download size="1rem" aria-hidden="true" /> {{ t('settings.connected.importCatalogue') }}
                </BaseButton>
                <ConfirmButton
                    v-if="service.publishesReferences && service.connection.authorized"
                    :label="t('settings.connected.publishReferences', { label: service.label })"
                    :confirm-label="t('settings.connected.publishReferencesConfirm')"
                    :message="t('settings.connected.publishReferencesMessage', { label: service.label })"
                    @confirm="emit('publish-references', service)"
                />
                <BaseButton
                    v-if="service.authorizes && service.connection.configured && !service.connection.authorized"
                    :href="`/settings/${service.key}/connect`"
                    data-turbo="false"
                >
                    {{ t('settings.connected.connect') }}
                </BaseButton>
                <ConfirmButton
                    v-if="service.connection.authorized"
                    :label="t('settings.connected.disconnect')"
                    :confirm-label="t('settings.connected.disconnect')"
                    :message="t('settings.connected.disconnectMessage', { label: service.label })"
                    @confirm="emit('disconnect', service)"
                />
                <IconButton :icon="Pencil" :label="t('settings.connected.edit', { label: service.label })" @click="emit('edit', service)" />
                <ConfirmButton
                    :icon="Trash2"
                    :label="t('settings.connected.remove', { label: service.label })"
                    :confirm-label="t('settings.connected.removeConfirm')"
                    :message="t('settings.connected.removeMessage')"
                    @confirm="emit('remove', service)"
                />
            </div>
        </li>
    </ul>
</template>

<style scoped>
.connected-services { margin: 0; padding: 0; list-style: none; }

.connected-services__row {
    display: grid;
    grid-template-columns: auto 1fr auto;
    align-items: start;
    gap: var(--space-3) var(--space-4);
    padding: var(--space-4) 0;
    border-top: 0.0625rem solid var(--color-border);
}

.connected-services__row:first-child { border-top: none; padding-top: 0; }

.connected-services__mark {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 2.25rem;
    height: 2.25rem;
    border-radius: 50%;
    background: var(--color-accent-soft);
    color: var(--color-accent-strong);
    font-family: var(--font-display);
    font-size: 1rem;
}

.connected-services__body { display: flex; flex-direction: column; gap: var(--space-2); min-width: 0; }
.connected-services__title { display: flex; flex-wrap: wrap; align-items: center; gap: var(--space-2); margin: 0; }
.connected-services__name { font-family: var(--font-display); font-size: 1.125rem; color: var(--color-ink); }
.connected-services__account { color: var(--color-muted); }

.connected-services__options { display: flex; flex-wrap: wrap; gap: var(--space-1) var(--space-5); margin: 0; font-size: var(--font-size-md); }
.connected-services__options div { display: flex; gap: var(--space-2); }
.connected-services__options dt { color: var(--color-muted); }
.connected-services__options dd { margin: 0; color: var(--color-text); }

.connected-services__waiting { margin: 0; font-size: var(--font-size-md); }
.connected-services__waiting button { padding: 0; border: none; background: none; color: var(--color-warning); font: inherit; text-decoration: underline; cursor: pointer; }


@media (max-width: 40rem) {
    .connected-services__row { grid-template-columns: auto 1fr; }
    .connected-services__actions { grid-column: 1 / -1; justify-content: flex-start; }
}
</style>
