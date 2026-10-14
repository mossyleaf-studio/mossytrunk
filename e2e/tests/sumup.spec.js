import { test, expect } from './support/test.js';
import { addSumUp, createProduct } from './support/api.js';
import { unique } from './support/unique.js';
import { choose } from './support/select.js';

test('import SumUp orders: one error for uncovered dates, no duplicates on re-import', async ({ page, request }) => {
    await addSumUp(request);
    const products = await (await request.get('/api/products')).json();
    if (!products.some((product) => product.displayName === 'Sticker Mousse')) {
        await createProduct(request, { name: 'Sticker Mousse', sellingPrice: 400 });
    }
    const response = await request.post('/api/events', {
        data: { name: 'Salon de printemps 2030', location: 'Lyon', startDate: '2030-03-14', endDate: '2030-03-15' },
    });
    expect(response.status()).toBe(201);

    await page.goto('/orders');
    await page.getByRole('button', { name: 'Importer depuis SumUp' }).click();

    await expect(page.getByTestId('toast')).toContainText('2 commandes importées');
    await expect(page.getByTestId('toast')).toContainText('2 produits créés');
    const problem = page.getByTestId('import-problem');
    await expect(problem).toHaveCount(1);
    await expect(problem).toContainText('2 commandes non importées');
    await expect(problem).toContainText('21 mars 2030, 23 mars 2030');
    await choose(page, page.getByRole('combobox', { name: 'Événement' }), 'Salon de printemps 2030');
    await expect(page.getByRole('row').filter({ hasText: 'TFAKE0001' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Salon de printemps 2030' }).first()).toBeVisible();
    await expect(page.getByRole('row').filter({ hasText: 'TFAKE0002' })).toContainText('SumUp');
    await expect(page.getByRole('row').filter({ hasText: 'TFAKE0002' })).toContainText('Espèces');
    await expect(page.getByRole('row').filter({ hasText: 'TFAKE0001' })).toContainText('Carte');

    await page.getByRole('button', { name: 'Importer depuis SumUp' }).click();
    await expect(page.getByTestId('toast').last()).toContainText('0 commande importée, 2 déjà importées');
    await expect(page.getByRole('row').filter({ hasText: /TFAKE000[12]/ })).toHaveCount(2);

    await page.goto('/products');
    await page.getByLabel('Rechercher un produit').fill('Tote bag');
    await expect(page.getByRole('row').filter({ hasText: 'Tote bag' })).toContainText('0,00');
});

test('an amount typed on the terminal is imported without product, then linked to one', async ({ page, request }) => {
    await addSumUp(request);
    await request.post('/api/events', { data: { name: 'Salon de printemps 2030', location: 'Lyon', startDate: '2030-03-14', endDate: '2030-03-15' } });
    await request.post('/api/services/sumup/import');

    await page.goto('/orders');
    await choose(page, page.getByRole('combobox', { name: 'Événement' }), 'Salon de printemps 2030');
    await page.getByRole('button', { name: /commandes? avec .* sans produit/ }).click();
    const unassigned = page.getByRole('row').filter({ hasText: 'TFAKE0002' });
    await expect(unassigned.getByRole('img', { name: /à associer à un produit/ })).toBeVisible();
    await unassigned.getByRole('link', { name: /^CMD-/ }).click();
    await expect(page.getByRole('row').filter({ hasText: 'Produit inconnu' })).toContainText('2,00');

    await page.getByRole('button', { name: 'Choisir le produit' }).click();
    const dialog = page.getByRole('dialog', { name: 'Choisir le produit' });
    await choose(page, dialog.getByRole('combobox', { name: 'Produit' }), 'Tote bag');
    await dialog.getByRole('button', { name: 'Associer' }).click();

    await expect(page.getByTestId('toast')).toContainText('Vente associée à « Tote bag ».');
    await expect(page.getByRole('row').filter({ hasText: 'Produit inconnu' })).toHaveCount(0);
    await expect(page.getByRole('row').filter({ hasText: 'Tote bag' })).toHaveCount(2);
});

test('an amount typed on the terminal for several items is split into their products, the difference becoming a discount', async ({ page, request }) => {
    await addSumUp(request);
    const badge = await createProduct(request, { name: unique('Badge'), sellingPrice: 150 });
    await request.post('/api/events', { data: { name: 'Salon de printemps 2030', location: 'Lyon', startDate: '2030-03-14', endDate: '2030-03-15' } });
    await request.post('/api/services/sumup/import');

    await page.goto('/orders');
    await choose(page, page.getByRole('combobox', { name: 'Événement' }), 'Salon de printemps 2030');
    await page.getByRole('row').filter({ hasText: 'TFAKE0002' }).getByRole('link', { name: /^CMD-/ }).click();

    await page.getByRole('button', { name: 'Répartir en plusieurs produits' }).click();
    const dialog = page.getByRole('dialog', { name: 'Répartir en plusieurs produits' });
    await choose(page, dialog.getByRole('combobox', { name: 'Produit' }), `${badge.name} — 1,50 €`);
    await dialog.getByLabel('Quantité', { exact: true }).fill('2');
    await dialog.getByRole('button', { name: 'Ajouter', exact: true }).click();
    await expect(dialog.getByTestId('split-summary')).toContainText('Remise ajoutée');
    await expect(dialog.getByTestId('split-summary')).toContainText('1,00');
    await dialog.getByRole('button', { name: 'Répartir' }).click();

    await expect(page.getByTestId('toast')).toContainText('Vente répartie entre les produits choisis.');
    await expect(page.getByRole('row').filter({ hasText: 'Produit inconnu' })).toHaveCount(0);
    await expect(page.getByRole('row').filter({ hasText: badge.name })).toContainText('3,00');
    await expect(page.getByText('Remise SumUp')).toBeVisible();
});

test('two SumUp sales of one customer are merged into one order', async ({ page, request }) => {
    await addSumUp(request);
    await request.post('/api/events', { data: { name: 'Salon de printemps 2030', location: 'Lyon', startDate: '2030-03-14', endDate: '2030-03-15' } });
    await request.post('/api/services/sumup/import');

    await page.goto('/orders');
    await choose(page, page.getByRole('combobox', { name: 'Événement' }), 'Salon de printemps 2030');
    await page.getByRole('row').filter({ hasText: 'TFAKE0001' }).getByRole('link', { name: /^CMD-/ }).click();
    await page.getByRole('button', { name: 'Fusionner avec…' }).click();
    const dialog = page.getByRole('dialog', { name: 'Fusionner deux commandes' });
    await dialog.getByRole('radio').filter({ hasText: 'TFAKE0002' }).click();
    await dialog.getByRole('button', { name: 'Fusionner', exact: true }).click();

    await expect(page.getByTestId('toast')).toContainText('fusionnée dans celle-ci');
    await expect(page.getByText('TFAKE0001, TFAKE0002')).toBeVisible();
    await expect(page.getByText('Mixte')).toBeVisible();

    await page.goto('/orders');
    await choose(page, page.getByRole('combobox', { name: 'Événement' }), 'Salon de printemps 2030');
    await expect(page.getByRole('row').filter({ hasText: /TFAKE000[12]/ })).toHaveCount(1);
});

test('the catalogue goes to SumUp as a CSV, and SumUp\'s catalogue links its items back', async ({ page, request }) => {
    await addSumUp(request);
    const candle = unique('Bougie');
    await createProduct(request, { name: candle, sellingPrice: 1_800 });

    await page.goto('/products');
    const download = page.waitForEvent('download');
    await page.getByRole('link', { name: 'Exporter pour SumUp' }).click();
    const file = await download;
    expect(file.suggestedFilename()).toMatch(/^catalogue-sumup-\d{4}-\d{2}-\d{2}\.csv$/);

    await page.goto('/settings');
    await page.getByLabel('Importer le catalogue').setInputFiles({
        name: 'sumup.csv',
        mimeType: 'text/csv',
        buffer: Buffer.from(`Item name,Variations,Price,SKU,Category\r\n${candle},,18.00,,\r\n${unique('Inconnue')},,9.00,,\r\n`),
    });
    await expect(page.getByTestId('toast').last()).toContainText('Catalogue SumUp importé');
    await expect(page.getByTestId('toast').last()).toContainText('sur 2');
});
