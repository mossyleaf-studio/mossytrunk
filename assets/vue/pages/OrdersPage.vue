<script setup>
import { computed, onMounted, ref, shallowRef, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { RefreshCw } from '@lucide/vue';
import AppLayout from '../layouts/AppLayout.vue';
import BaseButton from '../components/ui/BaseButton.vue';
import BaseCard from '../components/ui/BaseCard.vue';
import BaseModal from '../components/ui/BaseModal.vue';
import ConfirmButton from '../components/ui/ConfirmButton.vue';
import SelectionBar from '../components/ui/SelectionBar.vue';
import EventFilter from '../components/orders/EventFilter.vue';
import OrderForm from '../components/orders/OrderForm.vue';
import OrderFilters from '../components/orders/OrderFilters.vue';
import OrderList from '../components/orders/OrderList.vue';
import SupplyForm from '../components/orders/SupplyForm.vue';
import ImportProblem from '../components/import/ImportProblem.vue';
import ExternalItemsNotice from '../components/import/ExternalItemsNotice.vue';
import { useImport } from '../composables/useImport.js';
import { isReady, useServices } from '../composables/useServices.js';
import { useEvents } from '../composables/useEvents.js';
import { useOrderSearch } from '../composables/useOrderSearch.js';
import { useCheckedChannel, useOrders } from '../composables/useOrders.js';
import { useDiscountRules } from '../composables/useDiscountRules.js';
import { useProducts } from '../composables/useProducts.js';
import { useSalesChannels } from '../composables/useSalesChannels.js';
import { useToast } from '../composables/useToast.js';

const { orders, eventFilter, load, place, removeSelected, addSupplies, recomputeCharges, fillMissingCosts, missingCosts } = useOrders();
const { channels, load: loadChannels } = useSalesChannels();
const { search, unassigned, unassignedCount, visible, filtering } = useOrderSearch(orders);
const { articles: products, load: loadProducts } = useProducts();
const { events, load: loadEvents } = useEvents();
const { rules: discountRules, load: loadDiscountRules } = useDiscountRules();
const services = useServices();
const toast = useToast();
const { t } = useI18n();
const importers = shallowRef([]);
const readyImporters = computed(() => importers.value.filter((importer) => isReady(importer.service)));
const syncing = ref(false);
const formOpen = ref(false);
const lastPlacedId = ref(null);
const checkedIds = ref([]);
const { channel: checkedChannel, supplyBlocker } = useCheckedChannel(orders, checkedIds, channels);
const supplyOpen = ref(false);
const filling = ref(false);

async function onFillCosts() {
    filling.value = true;
    try {
        const { filled } = await fillMissingCosts();
        if (filled > 0) toast.success(t('orders.page.costsFilled', filled));
        else toast.error(t('orders.page.noCostToFill'));
        await load();
    } finally {
        filling.value = false;
    }
}

async function deleteChecked() {
    const { deleted } = await removeSelected(checkedIds.value);
    toast.success(t('orders.selection.deleted', deleted));
    checkedIds.value = [];
    await load();
}

async function recomputeChecked() {
    const { updated } = await recomputeCharges(checkedIds.value);
    toast.success(t('orders.selection.recomputed', updated));
    checkedIds.value = [];
    await load();
}

async function onSuppliesAdded(count) {
    supplyOpen.value = false;
    toast.success(t('orders.supplies.addedTo', count));
    checkedIds.value = [];
    await load();
}

async function onPlaced(order) {
    toast.success(t('orders.page.placed', { reference: order.reference }));
    lastPlacedId.value = order.id;
    await load();
}

async function sync() {
    syncing.value = true;
    try {
        for (const importer of readyImporters.value) {
            await importer.run();
        }
        await Promise.all([load(), loadProducts(), services.load()]);
    } finally {
        syncing.value = false;
    }
}

const itemsToLink = (importer) => services.added.value.find((service) => service.key === importer.service.key)?.connection.itemsToLink ?? 0;

watch(eventFilter, () => {
    checkedIds.value = [];
    load();
});
watch([search, unassigned], () => {
    checkedIds.value = [];
});
onMounted(async () => {
    await Promise.all([load(), loadProducts(), loadEvents(), services.load(), loadChannels(), loadDiscountRules()]);
    importers.value = services.added.value.map(useImport);
});
</script>

<template>
    <AppLayout :title="t('orders.page.title')">
        <template #actions>
            <BaseButton v-if="readyImporters.length" variant="secondary" :loading="syncing" @click="sync">
                <RefreshCw size="1rem" aria-hidden="true" /> {{ t('orders.page.sync') }}
            </BaseButton>
            <BaseButton v-if="missingCosts" variant="secondary" :loading="filling" @click="onFillCosts">{{ t('orders.page.fillCosts') }}</BaseButton>
            <BaseButton @click="formOpen = true">{{ t('orders.page.new') }}</BaseButton>
        </template>

        <template v-for="importer in importers" :key="importer.service.key">
            <Transition name="drop">
                <ImportProblem v-if="importer.problem.value" class="orders-page__problem" :problem="importer.problem.value" @dismiss="importer.dismiss" />
            </Transition>
            <ExternalItemsNotice
                v-if="itemsToLink(importer)"
                class="orders-page__problem"
                :label="importer.service.label"
                :count="itemsToLink(importer)"
            />
        </template>

        <BaseCard>
            <template #actions>
                <EventFilter v-model="eventFilter" :events="events" />
            </template>
            <OrderFilters v-model:search="search" v-model:unassigned="unassigned" :unassigned-count="unassignedCount" />
            <OrderList v-model:checked-ids="checkedIds" :orders="visible" :filtered="filtering" :highlight-id="lastPlacedId" />
        </BaseCard>
        <SelectionBar :count="checkedIds.length" :summary="t('orders.selection.count', checkedIds.length)" @clear="checkedIds = []">
            <BaseButton variant="secondary" @click="recomputeChecked">{{ t('orders.selection.recompute') }}</BaseButton>
            <BaseButton variant="secondary" :disabled="supplyBlocker !== null" :title="supplyBlocker" @click="supplyOpen = true">{{ t('orders.selection.addSupply') }}</BaseButton>
            <ConfirmButton
                variant="danger"
                :label="t('orders.selection.delete')"
                :confirm-label="t('orders.selection.confirmDelete', checkedIds.length)"
                :message="t('orders.selection.deleteMessage', checkedIds.length)"
                @confirm="deleteChecked"
            />
        </SelectionBar>

        <BaseModal v-model:open="supplyOpen" :title="t('orders.supplies.add')">
            <SupplyForm
                v-if="supplyOpen && checkedChannel"
                :supplies="checkedChannel.supplies"
                :intro="t('orders.supplies.intro', { channel: checkedChannel.name, count: checkedIds.length }, checkedIds.length)"
                :submit="(payload) => addSupplies(checkedIds, payload)"
                @saved="onSuppliesAdded"
                @cancel="supplyOpen = false"
            />
        </BaseModal>
        <BaseModal v-model:open="formOpen" :title="t('orders.page.new')" variant="drawer">
            <OrderForm :products="products" :discount-rules="discountRules" :channels="channels" :events="events" :submit="place" @placed="onPlaced" />
        </BaseModal>
    </AppLayout>
</template>

<style scoped>
.orders-page__problem { margin-bottom: var(--space-4); }
</style>
