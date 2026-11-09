import { useI18n } from 'vue-i18n';

const DAY = 86_400_000;

export const daysBetween = (from, to) => Math.round((Date.parse(to) - Date.parse(from)) / DAY);

export const addDays = (day, days) => new Date(Date.parse(day) + days * DAY).toISOString().slice(0, 10);

export function useRelativeDay() {
    const { t } = useI18n();

    return (today, day) => {
        const days = daysBetween(today, day);
        if (days === 0) return t('planning.relative.today');
        if (days === 1) return t('planning.relative.tomorrow');
        return days > 0 ? t('planning.relative.in', { n: days }) : t('planning.relative.ago', { n: -days });
    };
}
