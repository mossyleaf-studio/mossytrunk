<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { ToggleGroupItem } from 'reka-ui';
import BaseModal from '../ui/BaseModal.vue';
import ProductForm from '../products/ProductForm.vue';
import { useProducts } from '../../composables/useProducts.js';
import { useToast } from '../../composables/useToast.js';
import { useFormSubmit } from '../../composables/useFormSubmit.js';
import { X } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import BaseButton from '../ui/BaseButton.vue';
import BaseDatePicker from '../ui/BaseDatePicker.vue';
import BaseMoneyField from '../ui/BaseMoneyField.vue';
import BaseNumberField from '../ui/BaseNumberField.vue';
import BaseSelect from '../ui/BaseSelect.vue';
import ChoiceGroup from '../ui/ChoiceGroup.vue';
import { formatCents } from '../../composables/useMoney.js';
import FormActions from '../ui/FormActions.vue';
import FormError from '../ui/FormError.vue';
import FormField from '../ui/FormField.vue';
import FormSection from '../ui/FormSection.vue';
import IconButton from '../ui/IconButton.vue';
import MoneyAmount from '../ui/MoneyAmount.vue';
import PurchaseLinePicker from './PurchaseLinePicker.vue';
import SupplierSelect from './SupplierSelect.vue';
import { landedCosts } from '../../composables/usePurchasing.js';
import { localDay } from '../../composables/useDate.js';

const { t } = useI18n();

const props = defineProps({
    order: { type: Object, default: null },
    products: { type: Array, required: true },
    suppliers: { type: Array, required: true },
    supplierId: { type: String, default: '' },
    saveSupplier: { type: Function, required: true },
    submit: { type: Function, required: true },
    reloadProducts: { type: Function, required: true },
});
const emit = defineEmits(['saved', 'cancel']);

const blank = () => ({ supplierId: props.supplierId, orderedOn: localDay(), receivedOn: null, supplierReference: '', lines: [], discount: 0, deliveryFees: 0, currency: 'EUR', exchangeRate: 1 });
const fromOrder = (order) => ({
    supplierId: order.supplier.id,
    orderedOn: order.orderedOn,
    receivedOn: order.receivedOn,
    supplierReference: order.supplierReference ?? '',
    lines: order.lines.map(({ productId, variant, label, orderedQuantity, totalPrice, receivedQuantity }) => ({ productId, variant, label, quantity: orderedQuantity, totalPrice, received: receivedQuantity })),
    discount: order.discount,
    deliveryFees: order.deliveryFees,
    currency: order.currency,
    exchangeRate: order.exchangeRate,
});
const form = reactive(props.order ? fromOrder(props.order) : blank());
const { saving, errors, run } = useFormSubmit();
const received = computed(() => props.order?.status === 'received');
const picker = ref(null);
const productOpen = ref(false);
const { create: createProduct } = useProducts();
const toast = useToast();
let createdId = null;

async function submitProduct(payload) {
    createdId = (await createProduct(payload)).id;
}

async function onProductCreated(name) {
    productOpen.value = false;
    toast.success(t('purchasing.form.productCreated', { name }));
    await props.reloadProducts();
    picker.value?.choose(createdId);
}

const BY_DISCOUNT = 'discount';
const BY_PAID = 'paid';
const adjustBy = ref(BY_DISCOUNT);
const paid = ref(null);

const subtotal = computed(() => form.lines.reduce((sum, line) => sum + (line.totalPrice ?? 0), 0));
const beforeDiscount = computed(() => subtotal.value + (form.deliveryFees ?? 0));
const paidTooHigh = computed(() => adjustBy.value === BY_PAID && paid.value !== null && paid.value > beforeDiscount.value);
const discount = computed(() => (adjustBy.value === BY_PAID ? Math.max(0, beforeDiscount.value - (paid.value ?? beforeDiscount.value)) : form.discount ?? 0));
const total = computed(() => beforeDiscount.value - discount.value);
const landed = computed(() => landedCosts(form.lines, discount.value, form.deliveryFees));

const DRAFT_KEY = 'mossytrunk.supplier-order-draft';
const DRAFT_DELAY = 300;
const draftRestored = ref(false);
const otherDraftKept = ref(false);
const keepsDraft = () => !props.order && !otherDraftKept.value;
let draftTimer = null;

function readDraft() {
    try {
        return JSON.parse(window.localStorage.getItem(DRAFT_KEY) ?? 'null');
    } catch {
        return null;
    }
}

function writeDraft(draft) {
    try {
        if (draft === null) window.localStorage.removeItem(DRAFT_KEY);
        else window.localStorage.setItem(DRAFT_KEY, JSON.stringify(draft));
    } catch {
        return;
    }
}

function discardDraft() {
    writeDraft(null);
    Object.assign(form, blank());
    adjustBy.value = BY_DISCOUNT;
    paid.value = null;
    draftRestored.value = false;
}

onMounted(() => {
    if (props.order) return;
    const draft = readDraft();
    if (!draft?.form?.lines?.length && !draft?.form?.supplierId) return;
    if (props.supplierId && draft.form.supplierId && draft.form.supplierId !== props.supplierId) {
        otherDraftKept.value = true;
        return;
    }
    Object.assign(form, { ...blank(), ...draft.form, supplierId: props.supplierId || draft.form.supplierId });
    adjustBy.value = draft.adjustBy ?? BY_DISCOUNT;
    paid.value = draft.paid ?? null;
    draftRestored.value = true;
});

function cancelDraftSave() {
    clearTimeout(draftTimer);
    draftTimer = null;
}

function saveDraft() {
    cancelDraftSave();
    writeDraft({ form, adjustBy: adjustBy.value, paid: paid.value });
}

watch([form, adjustBy, paid], () => {
    if (!keepsDraft()) return;
    cancelDraftSave();
    draftTimer = setTimeout(saveDraft, DRAFT_DELAY);
}, { deep: true });

onBeforeUnmount(() => {
    if (draftTimer !== null && keepsDraft()) saveDraft();
});

const dollars = computed(() => form.currency === 'USD');
const rate = computed(() => (dollars.value ? form.exchangeRate ?? 0 : 1));
const inEuros = (cents) => Math.round(cents * rate.value);
const unitCost = (line, index) => (line.quantity > 0 ? inEuros(Math.round(landed.value[index] / line.quantity)) : 0);
const currencyOptions = computed(() => ['EUR', 'USD'].map((code) => ({ value: code, label: t(`purchasing.form.currencies.${code}`) })));
const rateFormat = { maximumFractionDigits: 6 };

function addLine(line) {
    const existing = form.lines.find((l) => l.productId === line.productId && l.variant === line.variant);
    if (existing) {
        existing.quantity += line.quantity;
        existing.totalPrice = (existing.totalPrice ?? 0) + line.totalPrice;
        if (received.value) existing.received = (existing.received ?? 0) + line.quantity;
        return;
    }
    form.lines.push(received.value ? { ...line, received: line.quantity } : line);
}

async function onSubmit() {
    if (paidTooHigh.value) {
        errors.value = { paid: t('purchasing.form.paidTooHigh') };
        return;
    }
    await run(async () => {
        await props.submit({
            supplierId: form.supplierId,
            orderedOn: form.orderedOn,
            receivedOn: received.value ? form.receivedOn : null,
            supplierReference: form.supplierReference.trim() || null,
            lines: form.lines.map(({ productId, variant, quantity, totalPrice, received: got }) => ({ productId, variant, quantity: quantity ?? 0, totalPrice: totalPrice ?? 0, received: received.value ? got ?? -1 : null })),
            discount: discount.value,
            deliveryFees: form.deliveryFees ?? 0,
            currency: form.currency,
            exchangeRate: dollars.value ? form.exchangeRate ?? 0 : 1,
        });
        cancelDraftSave();
        if (keepsDraft()) {
            writeDraft(null);
        }
        emit('saved');
    });
}
</script>

<template>
    <form class="supplier-order-form" novalidate @submit.prevent="onSubmit">
        <fieldset class="form-lock" :disabled="saving">
            <FormError v-if="errors.form">{{ errors.form }}</FormError>
            <p v-if="draftRestored" class="supplier-order-form__draft" role="status">
                {{ t('purchasing.form.draftRestored') }}
                <BaseButton variant="ghost" @click="discardDraft">{{ t('purchasing.form.draftDiscard') }}</BaseButton>
            </p>
            <p v-if="otherDraftKept" class="supplier-order-form__draft" role="status">{{ t('purchasing.form.draftKept') }}</p>

            <FormSection>
                <FormField as="group" :label="t('purchasing.form.supplier')" :error="errors.supplierId">
                    <SupplierSelect v-model="form.supplierId" :suppliers="suppliers" :save="saveSupplier" />
                </FormField>
                <FormField as="group" :label="t('purchasing.form.orderedOn')" :error="errors.orderedOn">
                    <div class="control--short"><BaseDatePicker v-model="form.orderedOn" :aria-label="t('purchasing.form.orderedOn')" /></div>
                </FormField>
                <FormField as="group" :label="t('purchasing.form.currency')" :error="errors.currency">
                    <BaseSelect v-model="form.currency" :options="currencyOptions" :aria-label="t('purchasing.form.currency')" class="control--short" />
                </FormField>
                <FormField v-if="dollars" as="group" :label="t('purchasing.form.exchangeRate')" :error="errors.exchangeRate" :hint="t('purchasing.form.exchangeRateHint')">
                    <BaseNumberField v-model="form.exchangeRate" :min="0" :step="0.01" :format-options="rateFormat" :label="t('purchasing.form.exchangeRate')" class="control--short" />
                </FormField>
                <FormField v-if="received" as="group" :label="t('purchasing.form.receivedOn')" :error="errors.receivedOn">
                    <div class="control--short"><BaseDatePicker v-model="form.receivedOn" :aria-label="t('purchasing.form.receivedOn')" /></div>
                </FormField>
                <FormField :label="t('purchasing.form.supplierReference')" :error="errors.supplierReference" :hint="t('purchasing.form.supplierReferenceHint')" optional>
                    <input v-model="form.supplierReference" type="text" maxlength="100" autocomplete="off" class="control--medium">
                </FormField>
            </FormSection>

            <FormSection :title="t('purchasing.form.orderedProducts')" :description="received ? t('purchasing.form.receivedHint') : null">
                <table v-if="form.lines.length" class="supplier-order-form__lines">
                    <thead class="supplier-order-form__head">
                        <tr>
                            <th>{{ t('purchasing.form.product') }}</th>
                            <th>{{ t('purchasing.form.quantity') }}</th>
                            <th v-if="received">{{ t('purchasing.form.received') }}</th>
                            <th>{{ t('purchasing.form.totalPrice') }}</th>
                            <th>{{ t('purchasing.form.unitCost') }}</th>
                            <th />
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(line, index) in form.lines" :key="`${line.productId}|${line.variant ?? ''}`" :class="['supplier-order-form__line', { 'supplier-order-form__line--received': received }]">
                            <td class="supplier-order-form__label">
                                {{ line.label }}
                                <span v-if="errors[`lines[${index}].quantity`] || errors[`lines[${index}].totalPrice`] || errors[`lines[${index}].received`]" class="supplier-order-form__line-error" role="alert">
                                    {{ errors[`lines[${index}].quantity`] ?? errors[`lines[${index}].totalPrice`] ?? errors[`lines[${index}].received`] }}
                                </span>
                            </td>
                            <td><BaseNumberField v-model="line.quantity" :min="1" :label="t('purchasing.form.quantityOf', { label: line.label })" /></td>
                            <td v-if="received"><BaseNumberField v-model="line.received" :min="0" :label="t('purchasing.form.receivedOf', { label: line.label })" /></td>
                            <td class="supplier-order-form__price"><BaseMoneyField v-model="line.totalPrice" :currency="form.currency" :aria-label="t('purchasing.form.totalPriceOf', { label: line.label })" /></td>
                            <td class="supplier-order-form__unit-cost"><span aria-hidden="true">{{ t('purchasing.form.unitCost') }}</span> <MoneyAmount class="supplier-order-form__unit-amount" :cents="unitCost(line, index)" /></td>
                            <td><IconButton :icon="X" :label="t('purchasing.form.remove', { label: line.label })" @click="form.lines.splice(index, 1)" /></td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr class="supplier-order-form__subtotal">
                            <td>{{ t('purchasing.form.products') }}</td>
                            <td><MoneyAmount :cents="subtotal" :currency="form.currency" /></td>
                        </tr>
                    </tfoot>
                </table>
                <p v-if="errors.lines" class="supplier-order-form__line-error" role="alert">{{ errors.lines }}</p>
                <PurchaseLinePicker ref="picker" :products="products" :currency="form.currency" @add="addLine" @create="productOpen = true" />
            </FormSection>

            <FormSection :title="t('purchasing.form.extras')" :description="t('purchasing.form.extrasHint')">
                <FormField :label="t('purchasing.form.deliveryFees')" :error="errors.deliveryFees">
                    <BaseMoneyField v-model="form.deliveryFees" :currency="form.currency" class="control--short" />
                </FormField>
                <FormField as="group" :label="t('purchasing.form.adjustBy')">
                    <ChoiceGroup v-model="adjustBy" class="supplier-order-form__adjust" :aria-label="t('purchasing.form.adjustBy')">
                        <ToggleGroupItem :value="BY_DISCOUNT" class="supplier-order-form__adjust-mode">{{ t('purchasing.form.byDiscount') }}</ToggleGroupItem>
                        <ToggleGroupItem :value="BY_PAID" class="supplier-order-form__adjust-mode">{{ t('purchasing.form.byPaid') }}</ToggleGroupItem>
                    </ChoiceGroup>
                </FormField>
                <FormField v-if="adjustBy === BY_DISCOUNT" :label="t('purchasing.form.discount')" :error="errors.discount">
                    <BaseMoneyField v-model="form.discount" :currency="form.currency" class="control--short" />
                </FormField>
                <template v-else>
                    <FormField :label="t('purchasing.form.paid')" :error="errors.paid ?? (paidTooHigh ? t('purchasing.form.paidTooHigh') : null)" :hint="t('purchasing.form.paidHint')">
                        <BaseMoneyField v-model="paid" :currency="form.currency" class="control--short" />
                    </FormField>
                    <p class="supplier-order-form__deduced">{{ t('purchasing.form.discountFromPaid') }} <MoneyAmount :cents="discount" :currency="form.currency" /></p>
                </template>
                <p class="supplier-order-form__total">{{ t('purchasing.form.totalPaid') }} <strong><MoneyAmount :cents="total" :currency="form.currency" /></strong>
                    <span v-if="dollars" class="supplier-order-form__in-euros">{{ t('purchasing.form.inEuros', { amount: formatCents(inEuros(total)) }) }}</span></p>
            </FormSection>

            <FormActions sticky>
                <BaseButton variant="ghost" @click="emit('cancel')">{{ t('common.cancel') }}</BaseButton>
                <BaseButton type="submit" :loading="saving">{{ order ? t('common.save') : t('purchasing.form.place') }}</BaseButton>
            </FormActions>
        </fieldset>
        <BaseModal v-model:open="productOpen" :title="t('purchasing.form.newProductTitle')">
            <ProductForm v-if="productOpen" :product="null" :submit="submitProduct" @saved="onProductCreated" @cancel="productOpen = false" />
        </BaseModal>
    </form>
</template>

<style scoped>
.supplier-order-form { display: flex; flex-direction: column; gap: var(--space-5); }
.supplier-order-form__lines,
.supplier-order-form__lines tbody,
.supplier-order-form__lines tfoot { display: block; }
.supplier-order-form__lines { font-size: var(--font-size-md); }

.supplier-order-form__head {
    position: absolute;
    width: 0.0625rem;
    height: 0.0625rem;
    overflow: hidden;
    clip-path: inset(50%);
    white-space: nowrap;
}

.supplier-order-form__line {
    display: grid;
    grid-template-columns: 7rem 7.5rem minmax(0, 1fr) auto;
    align-items: center;
    gap: var(--space-2);
    padding: var(--space-3) 0;
    border-bottom: 0.0625rem solid var(--color-border);
}

.supplier-order-form__line--received { grid-template-columns: 7rem 7rem 7.5rem minmax(0, 1fr) auto; }
.supplier-order-form__line td { padding: 0; }
.supplier-order-form__label { grid-column: 1 / -1; color: var(--color-ink); font-weight: 500; }

.supplier-order-form__unit-cost { color: var(--color-muted); text-align: right; font-variant-numeric: tabular-nums; }
.supplier-order-form__unit-amount { margin-left: var(--space-1); color: var(--color-ink); }

.supplier-order-form__subtotal {
    display: flex;
    justify-content: space-between;
    padding: var(--space-2) 0;
    color: var(--color-muted);
    font-variant-numeric: tabular-nums;
}

.supplier-order-form__subtotal td { padding: 0; }
.supplier-order-form__line-error { display: block; margin: 0; color: var(--color-danger); font-size: var(--font-size-sm); }

.supplier-order-form__total {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    margin: 0;
    padding-top: var(--space-3);
    border-top: 0.0625rem solid var(--color-border);
    font-size: var(--font-size-md);
    font-weight: 500;
}

.supplier-order-form__total strong { font-family: var(--font-display); font-size: 1.2rem; font-weight: 400; color: var(--color-ink); font-variant-numeric: tabular-nums; }
.supplier-order-form__draft { display: flex; align-items: center; justify-content: space-between; gap: var(--space-2); margin: 0; padding: var(--space-2) var(--space-3); border-radius: var(--radius); background: var(--color-accent-soft); font-size: var(--font-size-md); }
.supplier-order-form__in-euros { color: var(--color-muted); font-size: var(--font-size-sm); }
.supplier-order-form__deduced { margin: 0; color: var(--color-muted); font-size: var(--font-size-md); }
.supplier-order-form__adjust { display: inline-flex; align-self: flex-start; border: 0.0625rem solid var(--color-border-strong); border-radius: var(--radius); overflow: hidden; }

.supplier-order-form__adjust-mode {
    min-height: 2.25rem;
    padding: 0 var(--space-3);
    border: none;
    background: var(--color-surface);
    color: var(--color-muted);
    font: inherit;
    font-size: var(--font-size-sm);
    cursor: pointer;
    transition: background var(--transition), color var(--transition);
}

.supplier-order-form__adjust-mode + .supplier-order-form__adjust-mode { border-left: 0.0625rem solid var(--color-border-strong); }
.supplier-order-form__adjust-mode[data-state='on'] { background: var(--color-ink); color: var(--color-surface); }
.supplier-order-form__adjust-mode:focus-visible { outline-offset: -0.125rem; }
</style>
