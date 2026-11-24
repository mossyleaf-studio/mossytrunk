import { test, expect } from './support/test.js';
import { unique } from './support/unique.js';
import { choose } from './support/select.js';
import { createEvent, createProduct, createType, placeOrder, restock } from './support/api.js';

test('the product report ranks what sold and tells one product story', async ({ page, request }) => {
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
    const story = page.getByRole('article', { name: product.displayName });
    await expect(story).toContainText('17 en stock aujourd');
    await expect(story.getByRole('region', { name: 'Ventes par mois' })).toBeVisible();
    await expect(story.getByRole('region', { name: 'Mouvements de stock' })).toBeVisible();
    await expect(story.getByRole('region', { name: 'Remises' })).toBeVisible();

    await palmares.getByRole('button', { name: 'Articles vendus', exact: true }).click();
    await expect(row).toBeVisible();
});

const YEAR = '2041';
const PAST_YEAR = '2025';

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

    return { star, cheap, pricey, rare, unsold, late, expo, market };
}

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
