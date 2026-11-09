<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { ToggleGroupItem } from 'reka-ui';
import { useI18n } from 'vue-i18n';
import AppLayout from '../layouts/AppLayout.vue';
import BaseButton from '../components/ui/BaseButton.vue';
import BaseCard from '../components/ui/BaseCard.vue';
import BaseModal from '../components/ui/BaseModal.vue';
import ChoiceGroup from '../components/ui/ChoiceGroup.vue';
import SupplierManager from '../components/purchasing/SupplierManager.vue';
import SupplierOrderForm from '../components/purchasing/SupplierOrderForm.vue';
import SupplierOrderList from '../components/purchasing/SupplierOrderList.vue';
import { useProducts } from '../composables/useProducts.js';
import { useProductTypes } from '../composables/useProductTypes.js';
import { usePlanning } from '../composables/usePlanning.js';
import { useSupplierOrders, useSuppliers } from '../composables/usePurchasing.js';
import { queryText, takeQuery } from '../composables/useQueryState.js';
import { useToast } from '../composables/useToast.js';

const { t } = useI18n();

const { orders, load, create } = useSupplierOrders();
const { suppliers, load: loadSuppliers, save: saveSupplier } = useSuppliers();
const { products, load: loadProducts } = useProducts();
const { load: loadTypes } = useProductTypes();
const { orderRestock } = usePlanning();
const toast = useToast();

const restock = ref(takeQuery('restock'));
const restockSupplier = ref(takeQuery('supplier') ?? '');
const formOpen = ref(restock.value !== null);
let placedId = null;
const suppliersOpen = ref(false);
const status = queryText('status', 'ordered');

const awaiting = computed(() => orders.value.filter((order) => order.status === 'ordered'));
const visible = computed(() => (status.value === 'all' ? orders.value : orders.value.filter((order) => order.status === status.value)));
const filters = computed(() => [
    { value: 'ordered', label: t('purchasing.page.filters.ordered', { count: awaiting.value.length }) },
    { value: 'received', label: t('purchasing.page.filters.received') },
    { value: 'all', label: t('purchasing.page.filters.all') },
]);

async function saveAndReload(id, payload) {
    const supplier = await saveSupplier(id, payload);
    await loadSuppliers();
    return supplier;
}

watch(formOpen, (open) => {
    if (open) return;
    restock.value = null;
    restockSupplier.value = '';
});

async function place(payload) {
    placedId = (await create(payload)).id;
}

async function linkRestock(id) {
    try {
        await orderRestock(id, placedId);
    } catch (exception) {
        toast.error(exception.message);
    }
}

async function onOrderSaved() {
    const restockId = restock.value;
    formOpen.value = false;
    toast.success(t('purchasing.page.orderPlaced'));
    status.value = 'ordered';
    if (restockId) await linkRestock(restockId);
    await load();
}

async function onSupplierSaved(name) {
    toast.success(t('purchasing.page.supplierSaved', { name }));
    await Promise.all([loadSuppliers(), load()]);
}

onMounted(async () => {
    await Promise.all([load(), loadSuppliers(), loadProducts(), loadTypes()]);
    if (status.value === 'ordered' && awaiting.value.length === 0 && orders.value.length > 0) {
        status.value = 'all';
    }
});
</script>

<template>
    <AppLayout :title="t('purchasing.page.title')">
        <template #actions>
            <BaseButton variant="secondary" @click="suppliersOpen = true">{{ t('purchasing.page.suppliers') }}</BaseButton>
            <BaseButton @click="formOpen = true">{{ t('purchasing.page.newOrder') }}</BaseButton>
        </template>

        <BaseCard>
            <ChoiceGroup v-model="status" class="supplier-orders-page__filters" :aria-label="t('purchasing.page.filterLabel')">
                <ToggleGroupItem v-for="filter in filters" :key="filter.value" :value="filter.value" class="chip">{{ filter.label }}</ToggleGroupItem>
            </ChoiceGroup>
            <SupplierOrderList :orders="visible" />
        </BaseCard>

        <BaseModal v-model:open="formOpen" :title="t('purchasing.page.newOrderTitle')" variant="drawer">
            <SupplierOrderForm :products="products" :suppliers="suppliers" :supplier-id="restockSupplier" :save-supplier="saveAndReload" :submit="place" :reload-products="loadProducts" @saved="onOrderSaved" @cancel="formOpen = false" />
        </BaseModal>
        <BaseModal v-model:open="suppliersOpen" :title="t('purchasing.page.suppliers')">
            <SupplierManager :suppliers="suppliers" :save="saveSupplier" @saved="onSupplierSaved" />
        </BaseModal>
    </AppLayout>
</template>

<style scoped>
.supplier-orders-page__filters { display: flex; gap: var(--space-2); flex-wrap: wrap; margin-bottom: var(--space-4); }
</style>
