<script setup>
import { computed } from 'vue';
import { ArrowRight, CalendarRange, Pencil, Trash2 } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import BaseSwitch from '../ui/BaseSwitch.vue';
import ConfirmButton from '../ui/ConfirmButton.vue';
import EmptyState from '../ui/EmptyState.vue';
import IconButton from '../ui/IconButton.vue';
import TypeMark from '../ui/TypeMark.vue';
import { describeAction, describePeriod, describeRange, pricingDay, regularPrice, savingOn } from '../../composables/useRulePrice.js';
import { formatDate } from '../../composables/useDate.js';

const props = defineProps({
    rules: { type: Array, required: true },
    products: { type: Array, required: true },
    types: { type: Array, default: () => [] },
    selectedId: { type: String, default: null },
});
const emit = defineEmits(['edit', 'toggle', 'remove']);
const { t } = useI18n();

const typesById = computed(() => new Map(props.types.map((type) => [type.id, type])));
const productsById = computed(() => new Map(props.products.map((product) => [product.id, product])));

function describe(target) {
    if (target.kind === 'type') {
        const type = typesById.value.get(target.id);
        return { name: type?.name ?? target.name, color: type?.color ?? null };
    }
    const product = productsById.value.get(target.id);
    return { name: product?.displayName ?? target.name, color: typesById.value.get(product?.typeId)?.color ?? null };
}

const targetKey = (target) => `${target.kind}-${target.id}-${target.collectionId}-${target.variant}`;
const conditionKey = (condition) => condition.targets.map(targetKey).join('|');

const period = (rule) => describePeriod(rule.startsOn, rule.endsOn);

const status = (key) => t(`discounts.status.${key}`);

function savingOf(rule) {
    const day = pricingDay(rule.startsOn, rule.endsOn);
    const regular = regularPrice(props.products, rule.conditions, day);
    if (!regular) {
        return null;
    }
    return {
        day,
        regular: describeRange(regular),
        saved: describeRange({ min: savingOn(regular.min, rule.action), max: savingOn(regular.max, rule.action) }),
    };
}

const savings = computed(() => new Map(props.rules.map((rule) => [rule.id, savingOf(rule)])));
</script>

<template>
    <EmptyState v-if="rules.length === 0">{{ t('discounts.list.empty') }}</EmptyState>
    <TransitionGroup v-else name="drop" tag="ul" class="discount-rule-list">
        <li
            v-for="rule in rules"
            :key="rule.id"
            :class="['discount-rule-list__item', {
                'discount-rule-list__item--inactive': rule.status !== 'running',
                'discount-rule-list__item--selected': rule.id === selectedId,
            }]"
            :data-test="`discount-rule-${rule.name}`"
        >
            <div class="discount-rule-list__main">
                <span class="discount-rule-list__name">{{ rule.name }}</span>
                <p class="discount-rule-list__deal">
                    <template v-for="(condition, index) in rule.conditions" :key="conditionKey(condition)">
                        <span v-if="index > 0" class="discount-rule-list__plus">+</span>{{ ' ' }}
                        <span class="discount-rule-list__condition">
                            <span class="discount-rule-list__quantity">{{ condition.quantity }} ×</span>
                            <template v-for="(target, position) in condition.targets" :key="targetKey(target)">
                                {{ ' ' }}<span v-if="position > 0" class="discount-rule-list__or">{{ t('discounts.list.or') }}</span>{{ position > 0 ? ' ' : '' }}<TypeMark :color="describe(target).color" /> <span>{{ describe(target).name }}</span> <span v-if="target.collectionName" class="discount-rule-list__variant">{{ target.collectionName }}</span> <span v-if="target.variant" class="discount-rule-list__variant">{{ target.variant }}</span>
                            </template>
                        </span>{{ ' ' }}
                    </template>
                    <ArrowRight class="discount-rule-list__arrow" size="1rem" aria-hidden="true" /> <span class="discount-rule-list__action">{{ describeAction(rule.action) }}</span>
                </p>
                <span class="discount-rule-list__period">
                    <CalendarRange size="0.875rem" aria-hidden="true" />
                    {{ period(rule) }}
                </span>
            </div>
            <dl v-if="savings.get(rule.id)" class="discount-rule-list__figures">
                <div class="discount-rule-list__figure">
                    <dt class="eyebrow">{{ t('discounts.list.regularPrice') }}</dt>
                    <dd>
                        {{ savings.get(rule.id).regular }}
                        <span v-if="savings.get(rule.id).day" class="discount-rule-list__priced-on">{{ t('discounts.list.pricedOn', { date: formatDate(savings.get(rule.id).day) }) }}</span>
                    </dd>
                </div>
                <div class="discount-rule-list__figure">
                    <dt class="eyebrow">{{ t('discounts.list.customerSaving') }}</dt>
                    <dd class="discount-rule-list__saved">{{ savings.get(rule.id).saved }}</dd>
                </div>
            </dl>
            <span v-else class="discount-rule-list__figures" />
            <label v-if="rule.status !== 'expired'" class="discount-rule-list__toggle" :title="rule.status === 'running' ? t('discounts.list.stop') : t('discounts.list.start')">
                <BaseSwitch :key="rule.status" :default-value="rule.status === 'running'" @update:model-value="emit('toggle', rule, $event)" />
                {{ status(rule.status) }}
            </label>
            <span v-else class="discount-rule-list__toggle discount-rule-list__toggle--expired">{{ status('expired') }}</span>
            <div class="discount-rule-list__actions">
                <IconButton :icon="Pencil" :label="t('discounts.list.edit', { name: rule.name })" @click="emit('edit', rule)" />
                <ConfirmButton :icon="Trash2" :label="t('discounts.list.remove', { name: rule.name })" @confirm="emit('remove', rule)" />
            </div>
        </li>
    </TransitionGroup>
</template>

<style scoped>
.discount-rule-list { margin: 0; padding: 0; list-style: none; }

.discount-rule-list__item {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto 7rem auto;
    align-items: center;
    gap: var(--space-4);
    padding: var(--space-4) var(--space-2);
    border-bottom: 0.0625rem solid var(--color-border);
    transition: opacity var(--transition), background var(--transition);
}

.discount-rule-list__item:last-child { border-bottom: none; }
.discount-rule-list__item--inactive .discount-rule-list__main,
.discount-rule-list__item--inactive .discount-rule-list__figures { opacity: 0.6; }
.discount-rule-list__item--selected { background: var(--color-accent-soft); }

.discount-rule-list__main { display: flex; flex-direction: column; gap: var(--space-2); min-width: 0; }
.discount-rule-list__name { font-weight: 600; }

.discount-rule-list__deal {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--space-2);
    margin: 0;
    font-size: var(--font-size-md);
}

.discount-rule-list__condition {
    display: inline-flex;
    align-items: center;
    gap: var(--space-2);
    padding: 0.125rem var(--space-3) 0.125rem var(--space-2);
    border: 0.0625rem solid var(--color-border);
    border-radius: var(--radius-pill);
    background: var(--color-bg);
}

.discount-rule-list__quantity { font-weight: 600; font-variant-numeric: tabular-nums; }
.discount-rule-list__variant {
    padding: 0 var(--space-2);
    border-radius: var(--radius-pill);
    background: var(--color-surface);
    color: var(--color-muted);
    font-size: var(--font-size-sm);
}

.discount-rule-list__plus,
.discount-rule-list__or { color: var(--color-muted); }
.discount-rule-list__arrow { color: var(--color-subtle); }
.discount-rule-list__action {
    padding: 0.125rem var(--space-3);
    border-radius: var(--radius-pill);
    background: var(--color-accent-soft);
    color: var(--color-accent-strong);
    font-weight: 600;
    white-space: nowrap;
}

.discount-rule-list__period { display: inline-flex; align-items: center; gap: var(--space-1); color: var(--color-muted); font-size: var(--font-size-md); }

.discount-rule-list__figures { display: flex; flex-wrap: wrap; gap: var(--space-2) var(--space-5); margin: 0; }
.discount-rule-list__figure { display: flex; flex-direction: column; align-items: flex-end; }
.discount-rule-list__figure dd { margin: 0; font-weight: 600; font-variant-numeric: tabular-nums; white-space: nowrap; }
.discount-rule-list__saved { color: var(--color-success); }

.discount-rule-list__toggle { display: flex; align-items: center; gap: var(--space-2); font-size: var(--font-size-md); cursor: pointer; }
.discount-rule-list__toggle--expired { color: var(--color-muted); cursor: default; }
.discount-rule-list__actions { display: flex; align-items: center; gap: var(--space-1); }

@media (max-width: 50rem) {
    .discount-rule-list__item { grid-template-columns: minmax(0, 1fr) auto; }
    .discount-rule-list__main { grid-column: 1 / -1; }
}
.discount-rule-list__priced-on { display: block; color: var(--color-muted); font-size: var(--font-size-xs); font-weight: 400; }
</style>
