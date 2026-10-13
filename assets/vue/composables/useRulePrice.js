import { t } from '../i18n/index.js';
import { formatCents, formatPercent, formatSignedCents } from './useMoney.js';
import { formatDate, localDay } from './useDate.js';
import { sameVariant } from './useVariantStock.js';

const offers = (product, variant) => !variant || product.variants.some((candidate) => sameVariant(candidate, variant));
const targets = (product, target) => (target.kind === 'type'
    ? product.typeId === target.id && (!target.collectionId || product.collectionId === target.collectionId)
    : product.id === target.id);
const chosen = (condition) => condition.targets.filter((target) => target.id);

export function pricingDay(startsOn, endsOn, today = localDay()) {
    if (endsOn && endsOn < today) return endsOn;
    if (startsOn && startsOn > today) return startsOn;
    return null;
}

export function priceAt(product, day) {
    const history = product.priceHistory ?? [];
    if (!day || history.length === 0) return product.sellingPrice;
    let price = history[0].price;
    for (const change of history) {
        if (change.sinceDay > day) break;
        price = change.price;
    }
    return price;
}

const matching = (products, condition, day) => products
    .filter((product) => chosen(condition).some((target) => targets(product, target) && offers(product, target.variant)))
    .map((product) => priceAt(product, day));

export function regularPrice(products, conditions, day = null) {
    let min = 0;
    let max = 0;
    for (const condition of conditions) {
        const prices = matching(products, condition, day);
        if (chosen(condition).length === 0 || prices.length === 0 || !condition.quantity) {
            return null;
        }
        min += Math.min(...prices) * condition.quantity;
        max += Math.max(...prices) * condition.quantity;
    }
    return conditions.length ? { min, max } : null;
}

export function savingOn(regular, action) {
    if (!action.value) {
        return 0;
    }
    if (action.kind === 'fixedPrice') {
        return Math.max(0, regular - action.value);
    }
    if (action.kind === 'amountOff') {
        return Math.min(action.value, regular);
    }
    return Math.round((regular * action.value) / 10000);
}

export function describeRange({ min, max }) {
    return min === max ? formatCents(min) : t('discounts.range', { min: formatCents(min), max: formatCents(max) });
}

export function describePeriod(startsOn, endsOn, alwaysKey = 'discounts.list.always') {
    if (startsOn && endsOn) {
        return t('discounts.list.between', { start: formatDate(startsOn), end: formatDate(endsOn) });
    }
    if (startsOn) {
        return t('discounts.list.from', { start: formatDate(startsOn) });
    }
    return endsOn ? t('discounts.list.until', { end: formatDate(endsOn) }) : t(alwaysKey);
}

export function describeAction(action) {
    if (action.kind === 'fixedPrice') {
        return t('discounts.action.fixedPrice', { price: formatCents(action.value) });
    }
    if (action.kind === 'amountOff') {
        return formatSignedCents(-action.value);
    }
    return formatPercent(-action.value / 10_000, 2);
}
