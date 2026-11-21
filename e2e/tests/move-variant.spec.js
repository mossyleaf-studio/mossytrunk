import { test, expect } from './support/test.js';
import { choose } from './support/select.js';
import { unique } from './support/unique.js';
import { createEvent, createProduct, createType, defineVariants } from './support/api.js';

test('products split per variant are gathered into one product, their sales with them', async ({ page, request }) => {
    const mug = unique('Mug');
    const type = await createType(request, unique('Mugs'), { prefixesNames: false });
    const lichen = await createProduct(request, { name: `${mug} Lichen`, sellingPrice: 1_200, type });
    const fougere = await createProduct(request, { name: `${mug} Fougère`, sellingPrice: 1_200, type });
    await defineVariants(request, type, ['Lichen', 'Fougère']);
    const event = await createEvent(request);
    const response = await request.post('/api/orders', {
        data: { placedAt: `${event.startDate}T11:00`, lines: [{ productId: lichen.id, variant: null, quantity: 2 }] },
    });
    expect(response.status()).toBe(201);
    const order = await response.json();

    await page.goto('/products');
    const search = page.getByLabel('Rechercher un produit');
    await search.fill(lichen.name);
    await page.getByRole('button', { name: `Faire de ${lichen.name} une variante` }).click();
    let dialog = page.getByRole('dialog', { name: 'Faire une variante de ce produit' });
    await expect(dialog.getByLabel('Nom du produit')).toHaveValue(mug);
    await expect(dialog.getByRole('group', { name: 'Dans la variante' }).getByRole('button', { name: 'Lichen' })).toHaveAttribute('data-state', 'on');
    await dialog.getByRole('button', { name: 'Déplacer' }).click();
    await expect(page.getByTestId('toast')).toContainText(`Déplacé vers « ${mug} — Lichen ».`);

    await search.fill(fougere.name);
    await page.getByRole('button', { name: `Faire de ${fougere.name} une variante` }).click();
    dialog = page.getByRole('dialog', { name: 'Faire une variante de ce produit' });
    await dialog.getByRole('radiogroup', { name: 'Destination' }).getByRole('radio', { name: /^Produit existant/ }).click();
    await choose(page, dialog.getByRole('combobox', { name: 'Produit', exact: true }), mug);
    await expect(dialog.getByRole('group', { name: 'Dans la variante' }).getByRole('button', { name: 'Fougère' })).toHaveAttribute('data-state', 'on');
    await dialog.getByRole('button', { name: 'Déplacer' }).click();
    await expect(page.getByTestId('toast').last()).toContainText(`Déplacé vers « ${mug} — Fougère ».`);

    await search.fill(mug);
    const rows = page.getByRole('row').filter({ hasText: mug });
    await expect(rows).toHaveCount(1);
    await expect(rows).toContainText('Lichen, Fougère');

    await page.goto(`/orders/${order.id}`);
    await expect(page.getByRole('cell', { name: `${mug} — Lichen` })).toBeVisible();
});

test('a product moved into a product without variants is offered no variant', async ({ page, request }) => {
    const type = await createType(request, unique('Badges'), { prefixesNames: false });
    const target = await createProduct(request, { name: unique('Badge'), type });
    const moved = await createProduct(request, { name: unique('Badge rond'), type });

    await page.goto('/products');
    await page.getByLabel('Rechercher un produit').fill(moved.name);
    await page.getByRole('button', { name: `Faire de ${moved.name} une variante` }).click();
    const dialog = page.getByRole('dialog', { name: 'Faire une variante de ce produit' });
    await dialog.getByRole('radiogroup', { name: 'Destination' }).getByRole('radio', { name: /^Produit existant/ }).click();
    await choose(page, dialog.getByRole('combobox', { name: 'Produit', exact: true }), target.name);
    await expect(dialog.getByRole('group', { name: 'Dans la variante' })).toHaveCount(0);
    await expect(dialog.getByText("Ce type n'a pas de variante")).toBeVisible();
});
