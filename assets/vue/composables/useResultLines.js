const MINUS = '−';
const OPTIONAL_COSTS = ['supplies', 'consumedSupplies', 'channelCosts'];

export function resultLines(total, { label, urssafHint, turnoverOpen = false }) {
    const cost = (key) => ({ key, label: label(key), amount: total[key], sign: MINUS });
    return [
        { key: 'turnover', label: label('turnover'), amount: total.turnover, open: turnoverOpen },
        cost('costOfGoods'),
        ...OPTIONAL_COSTS.filter((key) => total[key] > 0).map(cost),
        cost('expenses'),
        { key: 'urssaf', label: 'URSSAF', hint: urssafHint, amount: total.urssaf, sign: MINUS },
        ...(total.donations > 0 ? [{ key: 'donations', label: label('donations'), amount: total.donations, sign: '+' }] : []),
    ];
}
