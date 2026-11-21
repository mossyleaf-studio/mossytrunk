<script setup>
import { computed, ref, watch } from 'vue';
import { ToggleGroupItem } from 'reka-ui';
import { Plus } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import BaseButton from '../ui/BaseButton.vue';
import BaseMoneyField from '../ui/BaseMoneyField.vue';
import BaseNumberField from '../ui/BaseNumberField.vue';
import BaseSelect from '../ui/BaseSelect.vue';
import ChoiceGroup from '../ui/ChoiceGroup.vue';
import FieldError from '../ui/FieldError.vue';
import ProductPicker from '../products/ProductPicker.vue';
import VariantPicker from '../products/VariantPicker.vue';
import { useProductTypes } from '../../composables/useProductTypes.js';
import { sameVariant } from '../../composables/useVariantStock.js';

const { t } = useI18n();

const props = defineProps({
    products: { type: Array, required: true },
    currency: { type: String, default: 'EUR' },
});
const emit = defineEmits(['add', 'create']);
const { activeTypes, variantsOf } = useProductTypes();

const PRODUCT = 'product';
const TYPE = 'type';
const mode = ref(PRODUCT);
const picker = ref(null);
const productId = ref('');
const variant = ref('');
const typeId = ref('');
const typeVariants = ref([]);
const ANY = '__any__';
const collectionId = ref(ANY);
const PER_UNIT = 'unit';
const FOR_ALL = 'all';
const pricing = ref(PER_UNIT);
const quantity = ref(1);
const totalPrice = ref(null);
const unitPrice = ref(null);
const error = ref(null);

const purchasable = computed(() => props.products.filter((p) => !p.archived));
const typeOptions = computed(() => activeTypes.value
    .filter((type) => purchasable.value.some((p) => p.typeId === type.id))
    .map((type) => ({ value: type.id, label: type.name })));

const label = (item, chosen) => (chosen ? `${item.displayName} — ${chosen}` : item.displayName);
const wanted = (candidate) => typeVariants.value.length === 0 || typeVariants.value.some((chosen) => sameVariant(chosen, candidate));

const collectionOptions = computed(() => {
    const seen = new Map();
    purchasable.value.filter((p) => p.typeId === typeId.value && p.collectionId).forEach((p) => seen.set(p.collectionId, p.collectionName));
    return [{ value: ANY, label: t('purchasing.picker.anyCollection') }, ...[...seen].map(([value, label]) => ({ value, label }))];
});
const inCollection = (p) => collectionId.value === ANY || p.collectionId === collectionId.value;

const typeItems = computed(() => purchasable.value
    .filter((p) => p.typeId === typeId.value && inCollection(p))
    .flatMap((p) => {
        if (p.activeVariants.length === 0) {
            return typeVariants.value.length === 0 ? [{ product: p, variant: null }] : [];
        }
        return p.activeVariants.filter(wanted).map((chosen) => ({ product: p, variant: chosen }));
    }));

watch(typeId, () => {
    typeVariants.value = [];
    collectionId.value = ANY;
    error.value = null;
});

watch(mode, () => { error.value = null; });

function reset() {
    productId.value = '';
    typeId.value = '';
    quantity.value = 1;
    totalPrice.value = null;
    unitPrice.value = null;
    error.value = null;
}

function addProduct() {
    if (!picker.value.validate()) {
        return;
    }
    const chosen = variant.value || null;
    emit('add', {
        productId: productId.value,
        variant: chosen,
        label: label(props.products.find((p) => p.id === productId.value), chosen),
        quantity: Math.max(1, quantity.value ?? 1),
        totalPrice: totalPrice.value ?? 0,
    });
    reset();
}

function choose(id) {
    mode.value = PRODUCT;
    productId.value = id ?? '';
}

defineExpose({ choose });

function addType() {
    if (!typeId.value) {
        error.value = t('purchasing.picker.chooseType');
        return;
    }
    if (typeItems.value.length === 0) {
        error.value = t('purchasing.picker.nothingToAdd');
        return;
    }
    const units = Math.max(1, quantity.value ?? 1);
    const count = typeItems.value.length;
    const total = unitPrice.value ?? 0;
    const shareOf = (index) => (pricing.value === FOR_ALL ? Math.floor(total / count) + (index < total % count ? 1 : 0) : total * units);
    typeItems.value.forEach((item, index) => {
        emit('add', {
            productId: item.product.id,
            variant: item.variant,
            label: label(item.product, item.variant),
            quantity: units,
            totalPrice: shareOf(index),
        });
    });
    reset();
}
</script>

<template>
    <div class="purchase-line-picker">
        <ChoiceGroup v-model="mode" class="purchase-line-picker__modes" :aria-label="t('common.add')">
            <ToggleGroupItem :value="PRODUCT" class="chip">{{ t('purchasing.picker.oneProduct') }}</ToggleGroupItem>
            <ToggleGroupItem :value="TYPE" class="chip">{{ t('purchasing.picker.wholeType') }}</ToggleGroupItem>
        </ChoiceGroup>
        <BaseButton variant="ghost" class="purchase-line-picker__create" @click="emit('create')"><Plus size="1rem" aria-hidden="true" /> {{ t('purchasing.form.newProduct') }}</BaseButton>

        <template v-if="mode === PRODUCT">
            <div class="purchase-line-picker__field purchase-line-picker__product">
                <span class="purchase-line-picker__label" aria-hidden="true">{{ t('products.picker.product') }}</span>
                <ProductPicker ref="picker" v-model="productId" v-model:variant="variant" :products="products" />
            </div>
            <div class="purchase-line-picker__field purchase-line-picker__quantity">
                <span class="purchase-line-picker__label" aria-hidden="true">{{ t('purchasing.picker.quantity') }}</span>
                <BaseNumberField v-model="quantity" :min="1" :label="t('purchasing.picker.orderedQuantity')" />
            </div>
            <label class="purchase-line-picker__field purchase-line-picker__price">
                <span class="purchase-line-picker__label">{{ t('purchasing.picker.totalPrice') }}</span>
                <BaseMoneyField v-model="totalPrice" :currency="currency" />
            </label>
            <BaseButton variant="secondary" class="purchase-line-picker__add" @click="addProduct">{{ t('common.add') }}</BaseButton>
        </template>

        <template v-else>
            <div class="purchase-line-picker__field purchase-line-picker__product">
                <span class="purchase-line-picker__label" aria-hidden="true">{{ t('purchasing.picker.type') }}</span>
                <BaseSelect v-model="typeId" :options="typeOptions" :placeholder="t('purchasing.picker.chooseTypePlaceholder')" :aria-label="t('purchasing.picker.type')" />
            </div>
            <div v-if="typeId && collectionOptions.length > 1" class="purchase-line-picker__field purchase-line-picker__product">
                <span class="purchase-line-picker__label" aria-hidden="true">{{ t('purchasing.picker.collection') }}</span>
                <BaseSelect v-model="collectionId" :options="collectionOptions" :aria-label="t('purchasing.picker.collection')" />
            </div>
            <div v-if="typeId && variantsOf(typeId).length" class="purchase-line-picker__field purchase-line-picker__product">
                <span class="purchase-line-picker__label" aria-hidden="true">{{ t('purchasing.picker.onlyVariants') }}</span>
                <VariantPicker v-model="typeVariants" :options="variantsOf(typeId)" />
            </div>
            <div class="purchase-line-picker__field purchase-line-picker__quantity">
                <span class="purchase-line-picker__label" aria-hidden="true">{{ t('purchasing.picker.quantityEach') }}</span>
                <BaseNumberField v-model="quantity" :min="1" :label="t('purchasing.picker.quantityEach')" />
            </div>
            <div class="purchase-line-picker__field purchase-line-picker__pricing">
                <span class="purchase-line-picker__label" aria-hidden="true">{{ t('purchasing.picker.priceFor') }}</span>
                <ChoiceGroup v-model="pricing" class="segmented" :aria-label="t('purchasing.picker.priceFor')">
                    <ToggleGroupItem :value="PER_UNIT" class="segmented__item">{{ t('purchasing.picker.perUnit') }}</ToggleGroupItem>
                    <ToggleGroupItem :value="FOR_ALL" class="segmented__item">{{ t('purchasing.picker.forAll') }}</ToggleGroupItem>
                </ChoiceGroup>
            </div>
            <label class="purchase-line-picker__field purchase-line-picker__price">
                <span class="purchase-line-picker__label">{{ t(pricing === FOR_ALL ? 'purchasing.picker.forAll' : 'purchasing.picker.unitPrice') }}</span>
                <BaseMoneyField v-model="unitPrice" :currency="currency" />
            </label>
            <BaseButton variant="secondary" class="purchase-line-picker__add" :disabled="!typeId" @click="addType">
                {{ t('purchasing.picker.addLines', { count: typeItems.length }, typeItems.length) }}
            </BaseButton>
        </template>
        <FieldError v-if="error" class="purchase-line-picker__error">{{ error }}</FieldError>
    </div>
</template>

<style scoped>
.purchase-line-picker {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-end;
    gap: var(--space-3) var(--space-2);
    padding: var(--space-3);
    border-radius: var(--radius);
    background: var(--color-bg);
}

.purchase-line-picker__modes { display: flex; flex: 1 1 auto; gap: var(--space-2); }
.purchase-line-picker__create { margin-left: auto; }

.purchase-line-picker__field { display: flex; flex-direction: column; gap: var(--space-1); min-width: 0; }
.purchase-line-picker__label { color: var(--color-muted); font-size: var(--font-size-sm); }
.purchase-line-picker__product { flex: 1 1 100%; }
.purchase-line-picker__quantity { flex: 0 0 7rem; }
.purchase-line-picker__price { flex: 0 0 7.5rem; }

.purchase-line-picker__pricing { flex: 0 0 auto; }


.purchase-line-picker__add { margin-left: auto; }
.purchase-line-picker__error { flex: 1 1 100%; }
</style>
