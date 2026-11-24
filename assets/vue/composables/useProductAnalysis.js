import { perLocale } from '../i18n/locale.js';

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

export const CLASSES = ['A', 'B', 'C'];

const paceFormatter = perLocale((locale) => new Intl.NumberFormat(locale, { minimumFractionDigits: 1, maximumFractionDigits: 1 }));

export const formatRate = (rate) => paceFormatter().format(rate);
