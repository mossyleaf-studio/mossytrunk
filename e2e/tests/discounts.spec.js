import { test, expect } from './support/test.js';
import { choose } from './support/select.js';
import { fillDate } from './support/date.js';
import { unique } from './support/unique.js';
import { createEvent, createProduct, createType } from './support/api.js';

test('create "2 prints and 1 sticker for 15 €", start it today and delete it', async ({ page, request }) => {
    const printType = await createType(request, unique('Print'));
    const stickerType = await createType(request, unique('Sticker'));
    const foret = await createProduct(request, { name: 'Forêt', sellingPrice: 1_500, type: printType });
    const riviere = await createProduct(request, { name: 'Rivière', sellingPrice: 1_500, type: printType });
    const mousse = await createProduct(request, { name: 'Mousse', sellingPrice: 400, type: stickerType });
    const event = await createEvent(request);
    const name = unique('2 prints et 1 sticker pour 15 €');

    await page.goto('/discounts');
    await page.getByRole('button', { name: 'Nouvelle remise' }).click();
    const form = page.getByRole('dialog', { name: 'Nouvelle remise' }).locator('form');
    await form.getByLabel('Nom').fill(name);
    await form.getByLabel('Quantité de la condition 1').fill('2');
    await choose(page, form.getByRole('combobox', { name: 'Type de la condition 1' }), printType.name);
    await form.getByRole('button', { name: 'Ajouter une condition' }).click();
    await choose(page, form.getByRole('combobox', { name: 'Type de la condition 2' }), stickerType.name);
    await form.getByRole('spinbutton', { name: 'Prix', exact: true }).fill('15');
    await form.getByRole('button', { name: /^Période/ }).click();
    await fillDate(form.getByRole('group', { name: /^Début/ }), event.startDate);
    await fillDate(form.getByRole('group', { name: /^Fin/ }), event.startDate);
    await expect(form.getByTestId('discount-rule-pricing')).toContainText('34,00');
    await expect(form.getByTestId('discount-rule-pricing')).toContainText('15,00');
    await form.getByRole('button', { name: 'Créer la remise' }).click();

    await expect(page.getByTestId('toast')).toContainText(`Remise « ${name} » créée.`);
    const item = page.getByTestId(`discount-rule-${name}`);
    await expect(item).toContainText(`2 × ${printType.name} + 1 × ${stickerType.name} pour 15,00`);
    await expect(item).toContainText('19,00');

    const preview = async (placedAt) => (await request.post('/api/orders/preview', {
        data: { placedAt, lines: [foret, riviere, mousse].map((p) => ({ productId: p.id, variant: null, quantity: 1 })) },
    })).json();
    const during = await preview(`${event.startDate}T12:00`);
    expect(during.discounts).toContainEqual({ label: name, amount: 1_900, ruleId: expect.any(String) });
    expect(during.total).toBe(1_500);

    await expect(item).toContainText('À venir');
    await item.getByRole('switch').check();
    await expect(item).toContainText('En cours');
    expect((await preview(`${event.startDate}T12:00`)).total).toBe(1_500);

    await item.getByRole('switch').click();
    await expect(page.getByTestId('toast').last()).toContainText('commence aujourd\'hui');
    await expect(item).toContainText('En cours');

    await item.getByRole('button', { name: 'Supprimer' }).click();
    await page.getByRole('alertdialog').getByRole('button', { name: 'Confirmer ?' }).click();
    await expect(item).toHaveCount(0);
});

test('a product variant condition and an amount off, edited afterwards', async ({ page, request }) => {
    const tshirt = await createProduct(request, { name: unique('T-shirt'), sellingPrice: 2_500, variants: ['S', 'M'] });
    const sticker = await createProduct(request, { name: unique('Sticker'), sellingPrice: 400 });
    const name = unique('T-shirt −5 €');

    await page.goto('/discounts');
    await page.getByRole('button', { name: 'Nouvelle remise' }).click();
    const form = page.getByRole('dialog', { name: 'Nouvelle remise' }).locator('form');
    await form.getByLabel('Nom').fill(name);
    await form.getByRole('group', { name: 'Cible de la condition 1' }).getByRole('button', { name: 'Produit' }).click();
    await choose(page, form.getByRole('combobox', { name: 'Produit de la condition 1' }), sticker.displayName);
    await expect(form.getByRole('group', { name: 'Variante de la condition 1' })).toHaveCount(0);
    await choose(page, form.getByRole('combobox', { name: 'Produit de la condition 1' }), tshirt.displayName);
    const variants = form.getByRole('group', { name: 'Variante de la condition 1' });
    await expect(variants.getByRole('button', { name: 'Toutes les variantes' })).toHaveAttribute('data-state', 'on');
    await variants.getByRole('button', { name: 'M', exact: true }).click();
    await form.getByRole('group', { name: 'Type d\'action' }).getByRole('button', { name: 'Remise en €' }).click();
    await form.getByRole('spinbutton', { name: 'Montant', exact: true }).fill('5');
    await form.getByRole('button', { name: 'Créer la remise' }).click();

    const item = page.getByTestId(`discount-rule-${name}`);
    await expect(item).toContainText(`1 × ${tshirt.displayName} M −5,00`);

    await item.getByRole('button', { name: `Modifier ${name}` }).click();
    const edit = page.getByRole('dialog', { name: 'Modifier la remise' }).locator('form');
    await expect(edit.getByRole('group', { name: 'Variante de la condition 1' }).getByRole('button', { name: 'M', exact: true })).toHaveAttribute('data-state', 'on');
    await edit.getByRole('group', { name: 'Type d\'action' }).getByRole('button', { name: 'Remise en %' }).click();
    await edit.getByRole('spinbutton', { name: 'Pourcentage' }).fill('20');
    await edit.getByRole('button', { name: 'Enregistrer' }).click();

    await expect(item).toContainText(`1 × ${tshirt.displayName} M −20 %`);
    await expect(item).toContainText('5,00');
});

test('stopping a running discount ends it yesterday; an expired discount has no switch', async ({ page, request }) => {
    const sticker = await createProduct(request, { name: unique('Sticker'), sellingPrice: 400 });
    const name = unique('Sticker −1 €');
    const response = await request.post('/api/discount-rules', {
        data: { name, conditions: [{ quantity: 1, targets: [{ kind: 'product', id: sticker.id }] }], action: { kind: 'amountOff', value: 100 }, startsOn: '2026-01-01', endsOn: null },
    });
    expect(response.status()).toBe(201);

    await page.goto('/discounts');
    const item = page.getByTestId(`discount-rule-${name}`);
    await expect(item).toContainText('En cours');
    await item.getByRole('switch').click();

    const past = page.getByRole('region', { name: 'Passées' }).getByTestId(`discount-rule-${name}`);
    await expect(past).toContainText('Expirée');
    await expect(past.getByRole('switch')).toHaveCount(0);
});

test('three items picked among several products are reduced by 2 €', async ({ page, request }) => {
    const mossy = await createProduct(request, { name: unique('Mossy'), sellingPrice: 600 });
    const eevee = await createProduct(request, { name: unique('Évoli'), sellingPrice: 400 });
    const event = await createEvent(request);
    const name = unique('3 prints Mossy ou Évoli');

    await page.goto('/discounts');
    await page.getByRole('button', { name: 'Nouvelle remise' }).click();
    const form = page.getByRole('dialog', { name: 'Nouvelle remise' }).locator('form');
    await form.getByLabel('Nom').fill(name);
    await form.getByLabel('Quantité de la condition 1').fill('3');
    await form.getByRole('group', { name: 'Cible de la condition 1' }).getByRole('button', { name: 'Produit' }).click();
    await choose(page, form.getByRole('combobox', { name: 'Produit de la condition 1' }), mossy.displayName);
    await form.getByRole('button', { name: 'Ajouter un produit ou un type' }).click();
    await form.getByRole('group', { name: 'Cible de la condition 1, choix 2' }).getByRole('button', { name: 'Produit' }).click();
    await choose(page, form.getByRole('combobox', { name: 'Produit de la condition 1, choix 2' }), eevee.displayName);
    await form.getByRole('button', { name: 'Remise en €' }).click();
    await form.getByLabel('Montant').fill('2');
    await expect(form.getByTestId('discount-rule-pricing')).toContainText('12,00');
    await form.getByRole('button', { name: 'Créer la remise' }).click();

    const item = page.getByTestId(`discount-rule-${name}`);
    await expect(item).toContainText(`3 × ${mossy.displayName} ou ${eevee.displayName}`);

    const preview = await (await request.post('/api/orders/preview', {
        data: { placedAt: `${event.startDate}T12:00`, lines: [{ productId: mossy.id, variant: null, quantity: 2 }, { productId: eevee.id, variant: null, quantity: 1 }] },
    })).json();
    expect(preview.discounts).toContainEqual({ label: name, amount: 200, ruleId: expect.any(String) });

    await item.getByRole('button', { name: `Modifier ${name}` }).click();
    const edit = page.getByRole('dialog', { name: 'Modifier la remise' });
    await expect(edit.getByRole('combobox', { name: 'Produit de la condition 1, choix 2' })).toHaveValue(eevee.displayName);
    await edit.getByRole('button', { name: 'Retirer le choix 1, choix 2' }).click();
    await edit.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(item).not.toContainText(eevee.displayName);
});

test('an expired discount shows the regular price and saving of its own days', async ({ page, request }) => {
    const print = await createProduct(request, { name: unique('Tirage'), sellingPrice: 500 });
    expect((await request.post(`/api/products/${print.id}/prices`, { data: { price: 400, since: '2026-07-01' } })).status()).toBe(204);
    const name = unique('Ancien lot');
    const response = await request.post('/api/discount-rules', {
        data: { name, conditions: [{ quantity: 3, targets: [{ kind: 'product', id: print.id }] }], action: { kind: 'fixedPrice', value: 1_000 }, startsOn: '2026-07-08', endsOn: '2026-07-14' },
    });
    expect(response.status()).toBe(201);

    await page.goto('/discounts');
    const rule = page.getByTestId(`discount-rule-${name}`);
    await expect(rule).toContainText('12,00');
    await expect(rule).toContainText('2,00');
    await expect(rule).toContainText('prix du 14 juil. 2026');
});
