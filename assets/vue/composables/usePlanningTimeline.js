import { t } from '../i18n/index.js';
import { formatDate, formatDateSpan, formatDayMonth } from './useDate.js';
import { ownerUrl } from './usePlanning.js';
import { addDays, daysBetween } from './useRelativeDay.js';

const MIN_WEEKS = 6;
const RESTOCK_TONES = { late: 'late', upcoming: 'upcoming', done: 'done' };

const mondayOf = (day) => addDays(day, -((new Date(day).getUTCDay() + 6) % 7));
const latest = (days) => days.reduce((last, day) => (day > last ? day : last));

function rangeOf(planning, releases) {
    const start = addDays(mondayOf(planning.today), -7);
    const last = latest([
        planning.today,
        ...planning.events.map((event) => event.end),
        ...releases.map((release) => release.day),
        ...planning.restocks.flatMap((restock) => [restock.orderBy, restock.readyBy, restock.day]),
    ]);
    const weeks = Math.max(MIN_WEEKS, daysBetween(start, mondayOf(last)) / 7 + 1);

    return { start, weeks, days: weeks * 7 };
}

function place(range, from, to, item) {
    const first = Math.max(0, daysBetween(range.start, from));
    const end = item.tone === 'late' ? Math.max(0, daysBetween(range.start, to)) : daysBetween(range.start, to);
    if (end < 0) return [];
    const parts = item.parts.filter(Boolean);

    return [{ ...item, parts, from: first, span: end - first + 1, label: parts.join(' · ') }];
}

const stepItems = (range, release) => release.steps.flatMap((step) => place(range, step.start, step.end, {
    shape: 'segment',
    tone: step.state,
    text: step.label,
    parts: [step.label, release.owner.name, formatDateSpan(step.start, step.end), t(`planning.states.${step.state}`)],
}));

const restockItem = (range, restock) => place(range, restock.orderBy, restock.orderBy, {
    shape: 'diamond',
    tone: RESTOCK_TONES[restock.state],
    parts: [t('planning.restock.item.title', { supplier: restock.supplier.name }), formatDate(restock.orderBy), t(`planning.restock.states.${restock.state}`, { day: formatDate(restock.orderedOn) })],
});

const releaseRow = (range, release, head = false) => ({
    key: release.id,
    head,
    label: release.owner.name,
    href: ownerUrl(release.owner),
    meta: head ? t('planning.timeline.releaseOn', { day: formatDate(release.day) }) : null,
    items: stepItems(range, release),
});

function eventLane(range, event, releases, restocks) {
    const rows = [{
        key: event.id,
        head: true,
        label: event.name,
        href: `/events/${event.id}`,
        meta: formatDateSpan(event.start, event.end),
        items: [
            ...place(range, event.readyBy, event.readyBy, { shape: 'flag', tone: 'ready', text: t('planning.readiness.ready'), parts: [t('planning.preparation.readyBy', { day: formatDate(event.readyBy) })] }),
            ...place(range, event.start, event.end, { shape: 'bar', tone: 'event', parts: [event.name, formatDateSpan(event.start, event.end)] }),
        ],
    }, ...releases.map((release) => releaseRow(range, release))];
    rows.push(...restocks.map((restock) => ({ key: restock.id, label: t('planning.restock.item.title', { supplier: restock.supplier.name }), items: restockItem(range, restock) })));

    return { key: event.id, name: event.name, day: event.start, rows };
}

const legacyRestockLane = (range, restock) => {
    const name = t('planning.restock.item.title', { supplier: restock.supplier.name });

    return { key: restock.id, name, day: restock.day, rows: [{ key: restock.id, head: true, label: name, meta: t('planning.restock.item.target', { day: formatDate(restock.day) }), items: restockItem(range, restock) }] };
};

export function timelineOf(planning) {
    const releases = planning.releases.filter((release) => !release.past);
    const range = rangeOf(planning, releases);
    const events = new Set(planning.events.map((event) => event.id));
    const onEvent = (item, event) => item.event?.id === event.id;
    const lanes = [
        ...planning.events.map((event) => eventLane(range, event, releases.filter((release) => onEvent(release, event)), planning.restocks.filter((restock) => onEvent(restock, event)))),
        ...releases.filter((release) => !events.has(release.event?.id)).map((release) => ({ key: release.id, name: release.owner.name, day: release.day, rows: [releaseRow(range, release, true)] })),
        ...planning.restocks.filter((restock) => !events.has(restock.event?.id)).map((restock) => legacyRestockLane(range, restock)),
    ].sort((a, b) => a.day.localeCompare(b.day));

    return {
        lanes,
        days: range.days,
        today: daysBetween(range.start, planning.today),
        from: formatDate(range.start),
        to: formatDate(addDays(range.start, range.days - 1)),
        weeks: Array.from({ length: range.weeks }, (_, index) => {
            const monday = addDays(range.start, index * 7);
            return { key: monday, label: formatDayMonth(monday) };
        }),
    };
}
