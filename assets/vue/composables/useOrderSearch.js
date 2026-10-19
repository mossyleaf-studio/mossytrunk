import { computed } from 'vue';
import { formatDay, formatNumericDay, formatTime } from './useDate.js';
import { queryFlag, queryText } from './useQueryState.js';
import { normalize } from './useSearch.js';

function searchableText(order) {
    const time = formatTime(order.placedAt);
    return normalize([
        order.reference,
        ...order.externalReferences,
        order.placedAt.slice(0, 10),
        formatNumericDay(order.placedAt),
        formatDay(order.placedAt),
        time,
        time.replace(':', 'h'),
    ].join(' '));
}

export function useOrderSearch(orders) {
    const search = queryText('search');
    const unassigned = queryFlag('unassigned', 'yes');
    const channel = queryText('channel');

    const unassignedCount = computed(() => orders.value.filter((order) => order.unidentifiedLines > 0).length);

    const texts = computed(() => new Map(orders.value.map((order) => [order.id, searchableText(order)])));
    const visible = computed(() => {
        const terms = normalize(search.value).split(/\s+/).filter(Boolean);
        return orders.value.filter((order) => (!unassigned.value || order.unidentifiedLines > 0)
            && (!channel.value || order.channelId === channel.value)
            && terms.every((term) => texts.value.get(order.id).includes(term)));
    });

    const filtering = computed(() => search.value !== '' || unassigned.value || channel.value !== '');

    return { search, unassigned, channel, unassignedCount, visible, filtering };
}
