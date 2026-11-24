import { test, expect } from './support/test.js';
import { unique } from './support/unique.js';
import { choose } from './support/select.js';
import { createDiscountRule, createEvent, createProduct, createType, placeOrder, restock } from './support/api.js';

test('the product report ranks what sold and opens its decision sheet', async ({ page, request }) => {
    const event = await createEvent(request);
    const product = await createProduct(request, { name: unique('Carte'), sellingPrice: 500 });
    await restock(request, product, { quantity: 20, totalPaid: 2_000 });
    await request.post('/api/orders', { data: { placedAt: `${event.startDate}T10:00`, lines: [{ productId: product.id, variant: null, quantity: 3 }] } });

    await page.goto(`/reports/products?period=${event.startDate.slice(0, 4)}`);
    const palmares = page.getByRole('region', { name: 'Palmarès' });
    await palmares.getByRole('searchbox', { name: 'Rechercher un produit' }).fill(product.name);
    const row = palmares.getByRole('button', { name: `Voir le détail de ${product.displayName}` });
    await expect(row).toContainText('3 vendus');
    await row.click();

    await expect(page).toHaveURL(/product=/);
    const sheet = page.getByRole('article', { name: product.displayName });
    await expect(sheet.getByRole('link', { name: 'Page du produit' })).toBeVisible();
    await expect(sheet.getByRole('region', { name: 'Pourquoi ce verdict' })).toBeVisible();
    const receipt = sheet.getByRole('region', { name: "D'où vient la marge" });
    await expect(receipt).toContainText('15,00');
    await expect(receipt).toContainText('3,00');
    await expect(receipt).toContainText('12,00');
    await expect(sheet.getByRole('region', { name: "Où il s'est vendu" })).toContainText(event.name);
    await expect(sheet.getByRole('region', { name: 'Ventes par mois' })).toHaveCount(0);
    await expect(sheet.getByRole('region', { name: 'Remises' })).toHaveCount(0);

    await sheet.getByRole('button', { name: 'Fermer le détail' }).click();
    await expect(page).not.toHaveURL(/product=/);
    await expect(sheet).toHaveCount(0);
});

test('the report tells what sold at full price from what sold discounted', async ({ page, request }) => {
    const event = await createEvent(request);
    const product = await createProduct(request, { name: unique('Carte'), sellingPrice: 400 });
    await restock(request, product, { quantity: 20, totalPaid: 2_000 });
    await createDiscountRule(request, {
        name: unique('3 cartes pour 10 €'),
        conditions: [{ quantity: 3, targets: [{ kind: 'product', id: product.id }] }],
        action: { kind: 'fixedPrice', value: 1_000 },
    });
    await placeOrder(request, `${event.startDate}T10:00`, [{ product, quantity: 3 }]);
    await placeOrder(request, `${event.startDate}T11:00`, [{ product, quantity: 2 }]);

    const year = event.startDate.slice(0, 4);
    const analysis = await readAnalysis(request, year);
    const row = analysis.products.find((candidate) => candidate.id === product.id);
    expect(row.fullPriceUnits).toBe(2);
    expect(row.discountedUnits).toBe(3);
    expect(analysis.totals.discountedUnits).toBe(3);

    await page.goto(`/reports/products?period=${year}`);
    const palmares = page.getByRole('region', { name: 'Palmarès' });
    await palmares.getByRole('searchbox', { name: 'Rechercher un produit' }).fill(product.name);
    await palmares.getByRole('button', { name: 'Articles vendus', exact: true }).click();
    const palmaresRow = palmares.getByRole('button', { name: `Voir le détail de ${product.displayName}` });
    await expect(palmaresRow).toContainText('5 vendus, dont 3 remisés');

    await palmaresRow.click();
    const receipt = page.getByRole('article', { name: product.displayName }).getByRole('region', { name: "D'où vient la marge" });
    await expect(receipt).toContainText('5 vendus : 2 au plein tarif, 3 remisés');
    await expect(receipt).toContainText(money(row.gross));
    await expect(receipt).toContainText(money(row.discount));
    await expect(receipt).toContainText(money(row.revenue));
});

const YEAR = '2041';
const PAST_YEAR = '2025';

const money = (cents) => (cents / 100).toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const pace = (value) => value.toLocaleString('fr-FR', { minimumFractionDigits: 1, maximumFractionDigits: 1 });
const percent = (value) => new RegExp(`${value}\\s+%`);

async function readAnalysis(request, period = YEAR) {
    const response = await request.get(`/api/reports/products/analysis?period=${period}`);
    expect(response.status()).toBe(200);
    return response.json();
}

const openHow = async (scope) => {
    await scope.getByRole('button', { name: /Comment c.est calculé/ }).click();
};

async function arrangeSeason(request) {
    const type = await createType(request, unique('Sticker'), { prefixesNames: false });
    const sticker = async (label, sellingPrice, lot = null) => {
        const product = await createProduct(request, { name: unique(label), sellingPrice, type });
        if (lot) await restock(request, product, lot);
        return product;
    };
    const star = await sticker('Vedette', 300, { quantity: 80, totalPaid: 4_000 });
    const cheap = await sticker('Pastille', 100, { quantity: 200, totalPaid: 16_000 });
    const pricey = await sticker('Brume', 1_500, { quantity: 30, totalPaid: 3_000 });
    const rare = await sticker('Rosée', 300, { quantity: 10, totalPaid: 500 });
    const unsold = await sticker('Mirage', 300, { quantity: 10, totalPaid: 500 });
    const late = await sticker('Nouveau', 300);

    const expo = await createEvent(request, { startDate: `${YEAR}-03-14` });
    const market = await createEvent(request, { startDate: `${YEAR}-06-20` });
    const past = await createEvent(request, { startDate: `${PAST_YEAR}-06-14` });

    const sales = [
        ...[[star, 50], [cheap, 40], [pricey, 8], [rare, 2]].flatMap(([product, count]) => Array(count).fill([expo, product])),
        ...[[star, 30], [cheap, 20], [pricey, 2], [late, 8]].flatMap(([product, count]) => Array(count).fill([market, product])),
        [past, cheap],
    ];
    for (let i = 0; i < sales.length; i += 10) {
        await Promise.all(sales.slice(i, i + 10).map(([event, product], j) => placeOrder(request, `${event.startDate}T1${(i + j) % 8}:${String((i + j) % 60).padStart(2, '0')}`, [{ product }])));
    }

    return { type, star, cheap, pricey, rare, unsold, late, expo, market };
}

test.describe('decision sheet', () => {
    test.use({ viewport: { width: 1600, height: 1000 } });

    test('the sheet says why a product got its verdict with its own figures and receipt', async ({ page, request }) => {
        const { star, late, expo } = await arrangeSeason(request);
        const analysis = await readAnalysis(request);
        const row = analysis.products.find((candidate) => candidate.id === star.id);
        const type = analysis.types.find((candidate) => candidate.id === row.typeId);

        await page.goto(`/reports/products?period=${YEAR}&view=decisions&product=${star.id}`);
        const sheet = page.getByRole('article', { name: star.name });
        await expect(sheet.getByRole('link', { name: 'Page du produit' })).toHaveAttribute('href', `/products/${star.id}`);
        await expect(sheet).toContainText('À réassortir');
        await expect(sheet).toContainText("Il se vend bien");
        await expect(sheet).toContainText(`${row.ordersSinceArrival} commandes depuis son arrivée`);
        await expect(sheet).toContainText(`${row.units} vendus sur ${row.exposure} commandes`);
        await expect(sheet).toContainText(pace(row.per100Orders));

        const why = sheet.getByRole('region', { name: 'Pourquoi ce verdict' });
        await expect(why).toContainText(String(analysis.rules.enoughOrders));
        await expect(why).toContainText(pace(type.popularFrom));
        await expect(why).toContainText(pace(type.averagePace));
        await expect(why).toContainText(percent(analysis.rules.popularPercent));
        await expect(why).toContainText(money(type.unitMarginFrom));
        await expect(why).toContainText(money(type.averageUnitMargin));
        await expect(why).toContainText(percent(analysis.rules.profitablePercent));
        await expect(why).toContainText('Classe A');
        await expect(why).toContainText(analysis.busiestEvent.name);
        await expect(why).toContainText(String(analysis.busiestEvent.orders));

        const receipt = sheet.getByRole('region', { name: "D'où vient la marge" });
        await expect(receipt).toContainText(money(row.gross));
        await expect(receipt).toContainText(money(row.discount));
        await expect(receipt).toContainText(money(row.revenue));
        await expect(receipt).toContainText(money(row.cost));
        await expect(receipt).toContainText(money(row.margin));
        await expect(receipt).toContainText(money(row.unitMargin));

        const where = sheet.getByRole('region', { name: "Où il s'est vendu" });
        await expect(where.getByRole('listitem').filter({ hasText: expo.name })).toContainText('50');

        await page.goto(`/reports/products?period=${YEAR}&view=decisions&product=${late.id}`);
        const lateSheet = page.getByRole('article', { name: late.name });
        await expect(lateSheet).toContainText('Trop tôt');
        await expect(lateSheet.getByRole('region', { name: "Où il s'est vendu" }).getByRole('listitem').filter({ hasText: expo.name })).toContainText('—');
    });

    test('a past period gives the sheet no verdict and no stock check', async ({ page, request }) => {
        const { cheap } = await arrangeSeason(request);
        await page.goto(`/reports/products?period=${PAST_YEAR}&view=decisions&product=${cheap.id}`);
        const sheet = page.getByRole('article', { name: cheap.name });
        await expect(sheet).toContainText('Cette période est terminée');
        await expect(sheet.getByRole('region', { name: "D'où vient la marge" })).toBeVisible();
        await expect(sheet).not.toContainText('À réassortir');
        await expect(sheet).not.toContainText('À arrêter');
        await expect(sheet).not.toContainText('Épuisé');
    });
});

test.describe('decisions', () => {
    test.use({ viewport: { width: 1600, height: 1000 } });

    test('the decisions view gives each product a verdict, filters by verdict and keeps its state in the URL', async ({ page, request }) => {
        const { star, cheap, rare, unsold, late } = await arrangeSeason(request);

        await page.goto(`/reports/products?period=${YEAR}`);
        await page.getByRole('group', { name: 'Vue' }).getByRole('button', { name: 'Décisions' }).click();
        await expect(page).toHaveURL(/view=decisions/);

        const decisions = page.getByRole('region', { name: 'Décisions' });
        const tiles = decisions.getByRole('group', { name: 'Verdict' });
        const tile = (name) => tiles.getByRole('button', { name: new RegExp(name) });
        await expect(tile('Tous')).toContainText('6');
        await expect(tile('À réassortir')).toContainText('1');
        await expect(tile('À arrêter')).toContainText('2');
        await expect(tile('Prix à revoir')).toContainText('1');
        await expect(tile('À garder')).toContainText('1');
        await expect(tile('Trop tôt')).toContainText('1');
        await expect(tile('À surveiller')).toBeDisabled();

        const row = (product) => decisions.getByRole('row').filter({ has: page.getByRole('button', { name: `Voir le détail de ${product.name}` }) });
        await expect(row(star)).toContainText('À réassortir');
        await expect(row(star)).toContainText('80');
        await expect(row(star)).toContainText('200,00');
        await expect(row(star)).toContainText('Épuisé');
        await expect(row(cheap)).toContainText('Prix à revoir');
        await expect(row(late)).toContainText('Trop tôt');
        const strip = (product) => row(product).getByRole('cell').last();
        await expect(strip(late)).toContainText('—');
        await expect(strip(star)).not.toContainText('—');

        await tile('À arrêter').click();
        await expect(page).toHaveURL(/verdict=discontinue/);
        await expect(row(rare)).toBeVisible();
        await expect(row(unsold)).toBeVisible();
        await expect(row(star)).toHaveCount(0);

        await decisions.getByRole('searchbox', { name: 'Rechercher un produit' }).fill(unsold.name);
        await expect(row(rare)).toHaveCount(0);
        await decisions.getByRole('columnheader', { name: 'Vendus' }).getByRole('button').click();
        await expect(page).toHaveURL(/sort=units/);

        await page.reload();
        await expect(tile('À arrêter')).toHaveAttribute('aria-pressed', 'true');
        await expect(row(unsold)).toBeVisible();
        await expect(row(rare)).toHaveCount(0);
        await expect(decisions.getByRole('columnheader', { name: 'Vendus' })).toHaveAttribute('aria-sort', 'descending');

        await openHow(decisions);
        const analysis = await readAnalysis(request);
        await expect(decisions).toContainText(percent(analysis.rules.popularPercent));
        await expect(decisions).toContainText(percent(analysis.rules.profitablePercent));
        await expect(decisions).toContainText(percent(analysis.rules.classAPercent));
        await expect(decisions).toContainText(percent(analysis.rules.classBPercent));
        await expect(decisions).toContainText(String(analysis.rules.enoughOrders));
        await expect(decisions).toContainText(analysis.busiestEvent.name);

        const badge = row(unsold).getByText('À arrêter');
        await badge.scrollIntoViewIfNeeded();
        await badge.hover();
        await expect(badge).toHaveAccessibleDescription(/Aucune unité vendue sur la période/);

        await row(unsold).getByRole('button', { name: `Voir le détail de ${unsold.name}` }).click();
        await expect(page).toHaveURL(/product=/);
        await expect(page.getByRole('article', { name: unsold.name })).toBeVisible();

        await choose(page, page.getByRole('combobox', { name: 'Période' }), `Année ${PAST_YEAR}`);
        await expect(page).toHaveURL(/view=decisions/);
        await decisions.getByRole('searchbox', { name: 'Rechercher un produit' }).fill('');
        await expect(row(cheap)).toBeVisible();
        await expect(tiles).toHaveCount(0);
        await expect(decisions.getByRole('columnheader', { name: 'Verdict' })).toHaveCount(0);

        await page.goto(`/reports/products?period=${YEAR}&view=decisions&sort=valueOf`);
        await expect(row(star)).toBeVisible();
        await expect(decisions.getByRole('columnheader', { name: 'Marge', exact: true })).toHaveAttribute('aria-sort', 'descending');
    });
});

test.describe('views on the analysis', () => {
    test.use({ viewport: { width: 1600, height: 1000 } });

    const openView = async (page, key, label, query = '') => {
        await page.goto(`/reports/products?period=${YEAR}${query}`);
        await page.getByRole('group', { name: 'Vue' }).getByRole('button', { name: label }).click();
        await expect(page).toHaveURL(new RegExp(`view=${key}`));
        return page.getByRole('region', { name: label, exact: true });
    };
    const select = (scope, product) => scope.getByRole('button', { name: `Voir le détail de ${product.name}` });

    test('the concentration view says how many products bring the margin and opens a product from the curve', async ({ page, request }) => {
        const { star, pricey, cheap } = await arrangeSeason(request);
        const view = await openView(page, 'concentration', 'Concentration');

        await expect(view).toContainText(/2 produits sur 6 apportent 89\s+% de la marge/);
        await expect(view).toContainText(/3 n.en apportent que 4\s+%/);
        for (const label of ['Classe A', 'Classe B', 'Classe C']) await expect(view).toContainText(label);
        await openHow(view);
        const { rules } = await readAnalysis(request);
        await expect(view).toContainText(percent(rules.classAPercent));
        await expect(view).toContainText(percent(rules.classBPercent));
        await expect(view).toContainText(/additionne la marge/);

        await select(view, star).focus();
        await expect(select(view, star)).toHaveAccessibleDescription(/200,00/);
        await expect(select(view, star)).toHaveAccessibleDescription(/classe A/);
        await select(view, cheap).focus();
        await expect(select(view, cheap)).toHaveAccessibleDescription(/classe C/);

        await select(view, pricey).click();
        await expect(page).toHaveURL(/product=/);
        await expect(select(view, pricey)).toHaveAttribute('aria-pressed', 'true');
        await expect(page.getByRole('article', { name: pricey.name })).toBeVisible();
    });

    test('the matrix view places the judged products of a type and lists the others apart', async ({ page, request }) => {
        const { type, star, cheap, pricey, rare, unsold, late } = await arrangeSeason(request);
        const view = await openView(page, 'matrix', 'Matrice');

        const panel = view.getByRole('region', { name: type.name });
        for (const product of [star, cheap, pricey, rare]) await expect(select(panel, product)).toBeVisible();
        for (const product of [unsold, late]) await expect(select(panel, product)).toHaveCount(0);
        for (const quadrant of ['Vedette', 'Cheval de trait', 'Énigme', 'Poids mort']) await expect(panel).toContainText(quadrant);
        await expect(panel).toContainText('13,3');
        await expect(panel).toContainText('2,00');
        const analysis = await readAnalysis(request);
        const stickers = analysis.types.find((candidate) => candidate.id === type.id);
        await expect(panel).toContainText(pace(stickers.popularFrom));
        await expect(panel).toContainText(pace(stickers.averagePace));
        await expect(panel).toContainText(percent(analysis.rules.popularPercent));
        await expect(panel).toContainText(money(stickers.unitMarginFrom));
        await expect(panel).toContainText(money(stickers.averageUnitMargin));
        await expect(panel).toContainText(percent(analysis.rules.profitablePercent));
        await openHow(view);

        const apart = view.getByRole('region', { name: 'Hors matrice' });
        await expect(select(apart, late)).toBeVisible();
        await expect(apart).toContainText('Trop tôt');
        await expect(select(apart, unsold)).toBeVisible();
        await expect(apart).toContainText('À arrêter');
        await expect(select(apart, unsold)).toHaveAccessibleDescription(/0 vendu/);
        for (const product of [star, cheap, pricey, rare]) await expect(select(apart, product)).toHaveCount(0);

        await select(panel, cheap).click();
        await expect(page).toHaveURL(/product=/);
        await expect(select(panel, cheap)).toHaveAttribute('aria-pressed', 'true');
        await expect(page.getByRole('article', { name: cheap.name })).toBeVisible();

        await choose(page, page.getByRole('combobox', { name: 'Période' }), `Année ${PAST_YEAR}`);
        await expect(page).toHaveURL(/view=matrix/);
        await expect(view).toContainText('Cette période est terminée');
    });

    test('the stock view shows how long each stock lasts, sorts it and has no stock reading on a past period', async ({ page, request }) => {
        const { star, cheap, pricey, rare, unsold, expo } = await arrangeSeason(request);
        const view = await openView(page, 'stock', 'Stock');

        await expect(view).toContainText(expo.name);
        await expect(view).toContainText('100 commandes');
        await openHow(view);
        await expect(view).toContainText(`l'événement le plus chargé de la période, ${expo.name}`);
        await expect(view).toContainText('20 en stock × 160 commandes ÷ 10 vendus = 320 commandes');
        const item = (product) => view.getByRole('listitem').filter({ has: select(page, product) });
        await expect(item(star)).toContainText('Épuisé');
        await expect(item(star)).toContainText('80 vendus');
        await expect(item(star)).toContainText('0 en stock');
        await expect(item(pricey)).toContainText('10 vendus');
        await expect(item(pricey)).toContainText('20 en stock');
        await expect(item(pricey)).toContainText('tient 320 commandes');
        await expect(item(rare)).toContainText('tient 640 commandes');
        await expect(item(unsold)).toContainText('10 en stock');
        await expect(item(unsold)).not.toContainText('tient');
        await expect(select(page, star)).toHaveAccessibleDescription(/Épuisé/);
        await expect(select(page, pricey)).toHaveAccessibleDescription(/tient 320 commandes/);
        await expect(view.getByRole('listitem').first()).toContainText('Épuisé');

        await view.getByRole('group', { name: 'Classer par' }).getByRole('button', { name: 'Unités en stock' }).click();
        await expect(page).toHaveURL(/stockSort=onHand/);
        await expect(view.getByRole('listitem').first()).toContainText(cheap.name);
        await page.reload();
        await expect(view.getByRole('listitem').first()).toContainText(cheap.name);

        await select(view, pricey).click();
        await expect(page).toHaveURL(/product=/);
        await expect(page.getByRole('article', { name: pricey.name })).toBeVisible();

        await choose(page, page.getByRole('combobox', { name: 'Période' }), `Année ${PAST_YEAR}`);
        await expect(page).toHaveURL(/view=stock/);
        await expect(view.getByText(/tient \d+ commandes/)).toHaveCount(0);
        await expect(view.getByText('Épuisé')).toHaveCount(0);
        await expect(view).toContainText("Le stock se lit à aujourd'hui");
    });

    test('the events view gives the units of each product at each event, a dash where it was not on sale', async ({ page, request }) => {
        const { star, cheap, late, expo, market } = await arrangeSeason(request);
        const view = await openView(page, 'events', 'Événements');

        const table = view.getByRole('table');
        await expect(table.getByRole('columnheader').filter({ has: page.getByRole('link', { name: expo.name }) })).toHaveCount(1);
        await expect(table.getByRole('link', { name: expo.name })).toHaveAttribute('href', `/events/${expo.id}`);
        await expect(table.getByRole('link', { name: market.name })).toHaveAttribute('href', `/events/${market.id}`);
        await expect(table.getByRole('columnheader', { name: /^Total/ })).toHaveCount(1);
        await expect(table.getByRole('columnheader', { name: /Hors événement/ })).toHaveCount(0);

        await openHow(view);
        await expect(view).toContainText(/le plus haut taux du tableau/);

        const cells = (product) => table.getByRole('row').filter({ has: select(page, product) }).getByRole('cell');
        await expect(cells(star).nth(0)).toContainText('50');
        await expect(cells(star).nth(1)).toContainText('30');
        await expect(cells(star).nth(2)).toContainText('80');
        await expect(cells(cheap).nth(0)).toContainText('40');
        await expect(cells(cheap).nth(1)).toContainText('20');
        await expect(cells(late).nth(0)).toContainText('—');
        await expect(cells(late).nth(1)).toContainText('8');
        await expect(cells(star).nth(0)).not.toContainText('—');

        await select(table, late).click();
        await expect(page).toHaveURL(/product=/);
        await expect(page.getByRole('article', { name: late.name })).toBeVisible();
    });
});
