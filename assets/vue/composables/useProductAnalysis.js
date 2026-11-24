import { perLocale } from '../i18n/locale.js';
import { formatCents } from './useMoney.js';

export const VERDICTS = ['restock', 'discontinue', 'reprice', 'watch', 'keep', 'too_early'];

export const VERDICT_TONES = {
    restock: 'warning',
    discontinue: 'danger',
    reprice: 'neutral',
    watch: 'neutral',
    keep: 'success',
    too_early: 'outline',
};

export const QUADRANTS = ['star', 'workhorse', 'puzzle', 'dog'];

export const POPULAR_QUADRANTS = ['star', 'workhorse'];

export const CLASSES = ['A', 'B', 'C'];

export const classTone = (abcClass) => `var(--color-ordinal-${CLASSES.indexOf(abcClass) + 1})`;

const paceFormatter = perLocale((locale) => new Intl.NumberFormat(locale, { minimumFractionDigits: 1, maximumFractionDigits: 1 }));

export const formatRate = (rate) => paceFormatter().format(rate);

const orDash = (value, format) => (value === null || value === undefined ? '—' : format(value));

const RESTOCK_REASONS = ['sold_out', 'low_cover'];

const REASON_PARAMS = {
    too_few_orders: (product, analysis) => ({ orders: product.ordersSinceArrival, enough: analysis.rules.enoughOrders }),
    arrived_late: (product, analysis) => ({ orders: product.ordersSinceArrival, half: analysis.orders }),
    low_cover: (product, analysis) => ({ cover: product.coverOrders, orders: analysis.busiestEvent?.orders, event: analysis.busiestEvent?.name }),
    workhorse: (product, analysis, type) => ({ limit: orDash(type?.unitMarginFrom, formatCents) }),
};

const reasonKey = (product) => (RESTOCK_REASONS.includes(product.reason) && !POPULAR_QUADRANTS.includes(product.quadrant) ? `${product.reason}_top_earner` : product.reason);

export function reasonSentence(product, analysis, t) {
    if (!product.reason) {
        return null;
    }
    const type = analysis.types.find((candidate) => candidate.id === product.typeId);
    return t(`reports.analysis.reasons.${reasonKey(product)}`, {
        pace: orDash(product.per100Orders, formatRate),
        margin: orDash(product.unitMargin, formatCents),
        limit: orDash(type?.popularFrom, formatRate),
        ...REASON_PARAMS[product.reason]?.(product, analysis, type),
    });
}
