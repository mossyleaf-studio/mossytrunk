<script setup>
import { computed, onMounted, ref } from 'vue';
import { Pencil, Plus } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '../layouts/AppLayout.vue';
import BaseCard from '../components/ui/BaseCard.vue';
import BaseModal from '../components/ui/BaseModal.vue';
import IconButton from '../components/ui/IconButton.vue';
import NoteForm from '../components/orders/NoteForm.vue';
import GrantDiscountForm from '../components/orders/GrantDiscountForm.vue';
import IdentifyLineForm from '../components/orders/IdentifyLineForm.vue';
import MergeOrderForm from '../components/orders/MergeOrderForm.vue';
import SplitLineForm from '../components/orders/SplitLineForm.vue';
import BaseButton from '../components/ui/BaseButton.vue';
import ConfirmButton from '../components/ui/ConfirmButton.vue';
import OrderLines from '../components/orders/OrderLines.vue';
import OrderTotals from '../components/orders/OrderTotals.vue';
import OrderMargin from '../components/orders/OrderMargin.vue';
import PostageForm from '../components/orders/PostageForm.vue';
import OrderSupplies from '../components/orders/OrderSupplies.vue';
import SupplyForm from '../components/orders/SupplyForm.vue';
import PaymentMethod from '../components/orders/PaymentMethod.vue';
import StatusBadge from '../components/ui/StatusBadge.vue';
import BackLink from '../components/ui/BackLink.vue';
import { useOrder } from '../composables/useOrders.js';
import { useProducts } from '../composables/useProducts.js';
import { manualRulesOn, useDiscountRules } from '../composables/useDiscountRules.js';
import { useSalesChannels } from '../composables/useSalesChannels.js';
import { useModalTarget } from '../composables/useModalTarget.js';
import { useToast } from '../composables/useToast.js';
import { formatDate, formatDateTime } from '../composables/useDate.js';
import { visit } from '../composables/useNavigation.js';
import { listUrl } from '../composables/useQueryState.js';

const props = defineProps({
    orderId: { type: String, required: true },
});

const { t } = useI18n();
const { order, load, remove, refund, identifyLine, splitLine, mergeWith, candidates, addSupply, removeSupply, stamp, annotate, grantDiscount, withdrawDiscount } = useOrder(props.orderId);
const { rules: discountRules, load: loadDiscountRules } = useDiscountRules();
const { channels, load: loadChannels } = useSalesChannels();
const { articles: products, load: loadProducts } = useProducts();
const toast = useToast();
const postageOpen = ref(false);
const noteOpen = ref(false);
const supplyOpen = ref(false);
const mergeOpen = ref(false);
const discountOpen = ref(false);
const { target: identifying, open: identifyOpen } = useModalTarget();
const { target: splitting, open: splitOpen } = useModalTarget();
const offeredDiscounts = computed(() => (order.value ? manualRulesOn(discountRules.value, order.value.placedAt.slice(0, 10))
    .filter((rule) => !order.value.discounts.some((discount) => discount.manual && discount.ruleId === rule.id)) : []));
const channelSupplies = computed(() => channels.value.find((channel) => channel.id === order.value?.channelId)?.supplies ?? []);

async function onPostageSaved() {
    postageOpen.value = false;
    toast.success(t('orders.postage.saved'));
    await load();
}

async function onNoteSaved() {
    noteOpen.value = false;
    toast.success(t('orders.note.saved'));
    await load();
}

async function onSupplyAdded() {
    supplyOpen.value = false;
    toast.success(t('orders.supplies.added'));
    await load();
}

async function onSupplyRemoved(supply) {
    await toast.attempt(() => removeSupply(supply.id), t('orders.supplies.removed', { label: supply.label }));
    await load();
}

async function onDiscountGranted(name) {
    discountOpen.value = false;
    toast.success(t('orders.discounts.granted', { name }));
    await load();
}

async function onDiscountWithdrawn(discount) {
    await toast.attempt(() => withdrawDiscount(discount.ruleId), t('orders.discounts.withdrawn', { name: discount.label }));
    await load();
}

async function onMerged(other) {
    mergeOpen.value = false;
    toast.success(t('orders.merge.done', { reference: other.reference }));
    await load();
}

async function onIdentified(name) {
    identifying.value = null;
    toast.success(t('orders.identify.saved', { name }));
    await load();
}

async function onSplit() {
    splitting.value = null;
    toast.success(t('orders.split.saved'));
    await load();
}

async function onRefund() {
    await refund();
    toast.success(t('orders.detail.refunded'));
    await load();
}

async function onDelete() {
    await remove();
    visit(listUrl('/orders'));
}

onMounted(() => Promise.all([load(), loadProducts(), loadChannels(), loadDiscountRules()]));
</script>

<template>
    <AppLayout :title="order ? t('orders.detail.title', { reference: order.reference }) : t('orders.detail.titleFallback')">
        <template #back><BackLink :href="listUrl('/orders')">{{ t('orders.detail.back') }}</BackLink></template>
        <template #actions>
            <BaseButton v-if="order && !order.refundedAt" variant="secondary" @click="mergeOpen = true">{{ t('orders.merge.open') }}</BaseButton>
            <ConfirmButton
                v-if="order && !order.refundedAt"
                :label="t('orders.detail.refund')"
                :confirm-label="t('orders.detail.confirmRefund')"
                :message="t('orders.detail.refundMessage')"
                variant="secondary"
                @confirm="onRefund"
            />
            <ConfirmButton v-if="order" :label="t('orders.detail.delete')" :confirm-label="t('orders.detail.confirmDelete')" :message="t('orders.detail.deleteMessage')" @confirm="onDelete" />
        </template>

        <div v-if="order" class="order-detail-page">
            <p class="order-detail-page__meta">
                <StatusBadge v-if="order.refundedAt" tone="warning">{{ t(order.cancelled ? 'orders.detail.cancelledOn' : 'orders.detail.refundedOn', { date: formatDate(order.refundedAt) }) }}</StatusBadge>
                {{ formatDateTime(order.placedAt) }} ·
                <a v-if="order.event" :href="`/events/${order.event.id}`">{{ order.event.name }}</a>
                <template v-else-if="order.source !== 'manual'">{{ t('orders.shop', { source: order.sourceLabel }) }}</template>
                <template v-else>{{ order.channelName ? t('orders.detail.channel', { channel: order.channelName }) : t('orders.outsideEvents') }}</template>
                <template v-if="order.channelName && (order.event || order.source !== 'manual')"> · {{ t('orders.detail.channel', { channel: order.channelName }) }}</template>
                <span v-if="order.source !== 'manual'"> · {{ t('orders.detail.importedFrom', { source: order.sourceLabel }) }}</span>
                <template v-if="order.paymentMethod"> · <PaymentMethod :method="order.paymentMethod" /></template>
                <template v-if="order.importedSales.length"> · {{ t('orders.detail.externalReferences', { source: order.sourceLabel, references: order.importedSales.map((sale) => sale.reference).join(', ') }) }}</template>
            </p>
            <div class="order-detail-page__grid">
                <BaseCard :title="t('orders.detail.items')">
                    <OrderLines :lines="order.lines" :identifiable="!order.refundedAt" @identify="identifying = $event" @split="splitting = $event" />
                </BaseCard>
                <div class="order-detail-page__side">
                    <BaseCard :title="t('orders.note.title')">
                        <template #actions>
                            <IconButton :icon="Pencil" :label="t('orders.note.edit')" @click="noteOpen = true" />
                        </template>
                        <p v-if="order.note" class="order-detail-page__note">{{ order.note }}</p>
                        <p v-else class="order-detail-page__hint">{{ t('orders.note.empty') }}</p>
                    </BaseCard>
                    <BaseCard :title="t('orders.detail.amount')">
                        <template v-if="!order.refundedAt && offeredDiscounts.length" #actions>
                            <BaseButton variant="secondary" @click="discountOpen = true"><Plus size="1rem" aria-hidden="true" /> {{ t('orders.discounts.add') }}</BaseButton>
                        </template>
                        <OrderTotals
                            :subtotal="order.subtotal"
                            :discounts="order.discounts"
                            :shipping="order.shipping"
                            :total="order.total"
                            link-rules
                            :withdrawable="!order.refundedAt"
                            @withdraw="onDiscountWithdrawn"
                        />
                    </BaseCard>
                    <BaseCard :title="t('orders.supplies.title')">
                        <template v-if="!order.refundedAt && channelSupplies.length" #actions>
                            <BaseButton variant="secondary" @click="supplyOpen = true"><Plus size="1rem" aria-hidden="true" /> {{ t('orders.supplies.add') }}</BaseButton>
                        </template>
                        <OrderSupplies :supplies="order.supplies" :removable="!order.refundedAt" @remove="onSupplyRemoved" />
                        <p v-if="!order.channelId" class="order-detail-page__hint">{{ t('orders.supplies.noChannel') }}</p>
                        <p v-else-if="!channelSupplies.length" class="order-detail-page__hint">
                            <a :href="`/channels/${order.channelId}`">{{ t('orders.supplies.noneOnChannel', { channel: order.channelName }) }}</a>
                        </p>
                    </BaseCard>
                    <BaseCard :title="t('orders.detail.margin')">
                        <OrderMargin
                            :total="order.total"
                            :cost-of-goods="order.costOfGoods"
                            :supplies-cost="order.suppliesCost"
                            :charges="order.charges"
                            :postage="order.postage"
                            :postage-editable="order.channelId !== null"
                            :margin="order.margin"
                            @edit-postage="postageOpen = true"
                        />
                    </BaseCard>
                </div>
            </div>
        </div>

        <BaseModal v-model:open="postageOpen" :title="t('orders.postage.title')">
            <PostageForm v-if="postageOpen && order" :postage="order.postage" :submit="stamp" @saved="onPostageSaved" @cancel="postageOpen = false" />
        </BaseModal>
        <BaseModal v-model:open="noteOpen" :title="t('orders.note.title')">
            <NoteForm v-if="noteOpen && order" :note="order.note" :submit="annotate" @saved="onNoteSaved" @cancel="noteOpen = false" />
        </BaseModal>
        <BaseModal v-model:open="supplyOpen" :title="t('orders.supplies.add')">
            <SupplyForm v-if="supplyOpen" :supplies="channelSupplies" :submit="addSupply" @saved="onSupplyAdded" @cancel="supplyOpen = false" />
        </BaseModal>
        <BaseModal v-model:open="discountOpen" :title="t('orders.discounts.add')">
            <GrantDiscountForm v-if="discountOpen" :rules="offeredDiscounts" :submit="grantDiscount" @saved="onDiscountGranted" @cancel="discountOpen = false" />
        </BaseModal>
        <BaseModal v-model:open="mergeOpen" :title="t('orders.merge.title')">
            <MergeOrderForm v-if="mergeOpen && order" :order="order" :candidates="candidates" :submit="mergeWith" @merged="onMerged" @cancel="mergeOpen = false" />
        </BaseModal>
        <BaseModal v-model:open="splitOpen" :title="t('orders.split.title')">
            <SplitLineForm v-if="splitting" :line="splitting" :products="products" :submit="(payload) => splitLine(splitting.id, payload)" @saved="onSplit" @cancel="splitting = null" />
        </BaseModal>
        <BaseModal v-model:open="identifyOpen" :title="t('orders.identify.title')">
            <IdentifyLineForm v-if="identifying" :line="identifying" :products="products" :submit="(payload) => identifyLine(identifying.id, payload)" @saved="onIdentified" @cancel="identifying = null" />
        </BaseModal>
    </AppLayout>
</template>

<style scoped>
.order-detail-page { display: flex; flex-direction: column; gap: var(--space-4); }
.order-detail-page__meta { margin: 0; color: var(--color-muted); }
.order-detail-page__grid { display: grid; grid-template-columns: minmax(0, 2fr) minmax(17.5rem, 1fr); gap: var(--space-4); align-items: start; }
.order-detail-page__hint { margin: var(--space-2) 0 0; color: var(--color-muted); font-size: var(--font-size-md); }
.order-detail-page__note { margin: 0; white-space: pre-line; }
.order-detail-page__side { display: flex; flex-direction: column; gap: var(--space-4); }

@media (max-width: 56.25rem) {
    .order-detail-page__grid { grid-template-columns: 1fr; }
}
</style>
