import { test, expect } from './support/test.js';
import { fillDate } from './support/date.js';
import { choose } from './support/select.js';
import { unique } from './support/unique.js';
import { createDiscountRule, createEvent, createProduct } from './support/api.js';

test('place an order with automatic bundle discount while seeing the list', async ({ page, request }) => {
    const event = await createEvent(request);
    const sticker = await createProduct(request, { name: unique('Sticker'), sellingPrice: 400, buyingPrice: 100 });
    const tshirt = await createProduct(request, { name: unique('T-shirt'), sellingPrice: 2_000, variants: ['Mousse', 'Fougère'] });
    const bundle = await createDiscountRule(request, {
        name: unique('3 stickers pour 10 €'),
        conditions: [{ quantity: 3, targets: [{ kind: 'product', id: sticker.id }] }],
        action: { kind: 'fixedPrice', value: 1_000 },
    });

    await page.goto('/orders');
    await page.getByRole('button', { name: 'Nouvelle commande' }).click();
    const form = page.getByRole('dialog', { name: 'Nouvelle commande' }).locator('form');
    await fillDate(form.getByRole('group', { name: 'Date', exact: true }), `${event.startDate}T14:30`);

    // The variant is required for products that have variants.
    await choose(page, form.getByRole('combobox', { name: 'Produit' }), `${tshirt.name} — 20,00 €`);
    await form.getByRole('button', { name: 'Ajouter', exact: true }).click();
    await expect(form.getByRole('alert')).toContainText('Choisissez une variante');
    await form.getByRole('group', { name: 'Variante' }).getByRole('button', { name: 'Fougère' }).click();
    await form.getByRole('button', { name: 'Ajouter', exact: true }).click();

    await choose(page, form.getByRole('combobox', { name: 'Produit' }), `${sticker.name} — 4,00 €`);
    await expect(form.getByRole('group', { name: 'Variante' })).toHaveCount(0);
    await form.getByLabel('Quantité', { exact: true }).fill('3');
    await form.getByRole('button', { name: 'Ajouter', exact: true }).click();

    await expect(form.getByRole('group', { name: 'Événement' })).toContainText(event.name);
    await expect(form.getByText(bundle.name)).toBeVisible();
    await expect(form.getByTestId('order-total')).toHaveText(/30,00/);

    await form.getByRole('button', { name: 'Enregistrer la commande' }).click();

    const toast = page.getByTestId('toast');
    await expect(toast).toContainText(/Commande CMD-\d{8}-\w{6} enregistrée\./);
    const reference = (await toast.textContent()).match(/CMD-\d{8}-\w{6}/)[0];

    // The list refreshed without reloading the page, and the form is ready for the next order.
    const row = page.getByRole('row').filter({ hasText: reference });
    await expect(row).toContainText('30,00');
    await expect(page.getByRole('row').filter({ hasText: event.name }).getByRole('link', { name: event.name })).toBeVisible();
    await expect(form.getByRole('button', { name: 'Enregistrer la commande' })).toBeDisabled();

    await page.getByRole('dialog').getByRole('button', { name: 'Fermer' }).click();
    await row.getByRole('link', { name: reference }).click();
    await expect(page.getByRole('heading', { level: 1 })).toHaveText(`Commande ${reference}`);
    await expect(page.getByRole('cell', { name: `${tshirt.name} — Fougère` })).toBeVisible();
    await expect(page.locator('.order-margin')).toContainText('27,00');

    await page.getByRole('link', { name: bundle.name }).click();
    await expect(page.getByRole('dialog', { name: 'Modifier la remise' }).getByLabel('Nom')).toHaveValue(bundle.name);
});

test('warns when no event exists at the order date', async ({ page, request }) => {
    const sticker = await createProduct(request, { name: unique('Sticker') });

    await page.goto('/orders');
    await page.getByRole('button', { name: 'Nouvelle commande' }).click();
    const form = page.getByRole('dialog', { name: 'Nouvelle commande' }).locator('form');
    await fillDate(form.getByRole('group', { name: 'Date', exact: true }), '2099-12-31T10:00');
    await choose(page, form.getByRole('combobox', { name: 'Produit' }), `${sticker.name} — 4,00 €`);
    await form.getByRole('button', { name: 'Ajouter', exact: true }).click();

    await expect(form.getByText('Aucun événement à cette date.')).toBeVisible();
    await form.getByRole('button', { name: 'Enregistrer la commande' }).click();
    await expect(form.getByRole('alert').filter({ hasText: 'Créez d\'abord' })).toBeVisible();
});

test('place a gift outside events with a comment, then edit the comment', async ({ page, request }) => {
    const sticker = await createProduct(request, { name: unique('Sticker') });

    await page.goto('/orders');
    await page.getByRole('button', { name: 'Nouvelle commande' }).click();
    const form = page.getByRole('dialog', { name: 'Nouvelle commande' }).locator('form');
    await fillDate(form.getByRole('group', { name: 'Date', exact: true }), '2099-12-31T10:00');
    await choose(page, form.getByRole('combobox', { name: 'Produit' }), `${sticker.name} — 4,00 €`);
    await form.getByRole('button', { name: 'Ajouter', exact: true }).click();
    await expect(form.getByText('Aucun événement à cette date.')).toBeVisible();

    await form.getByRole('switch', { name: /Hors événement/ }).click();
    await expect(form.getByText('Aucun événement à cette date.')).toHaveCount(0);
    await form.getByLabel('Commentaire').fill('Cadeau pour Léa');
    await form.getByRole('button', { name: 'Enregistrer la commande' }).click();

    const toast = page.getByTestId('toast');
    await expect(toast).toContainText(/Commande CMD-\d{8}-\w{6} enregistrée\./);
    const reference = (await toast.textContent()).match(/CMD-\d{8}-\w{6}/)[0];
    await page.getByRole('dialog').getByRole('button', { name: 'Fermer' }).click();
    const row = page.getByRole('row').filter({ hasText: reference });
    await expect(row).toContainText('Cadeau pour Léa');

    await row.getByRole('link', { name: reference }).click();
    await expect(page.locator('.order-detail-page__meta')).toContainText('Hors événement');
    await page.getByRole('button', { name: 'Modifier le commentaire' }).click();
    const dialog = page.getByRole('dialog', { name: 'Commentaire' });
    await dialog.getByLabel('Commentaire').fill('Cadeau pour Paul');
    await dialog.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(page.getByTestId('toast').last()).toContainText('Commentaire enregistré.');
    await expect(page.getByRole('region', { name: 'Commentaire' })).toContainText('Cadeau pour Paul');
});

test('search orders by number, date and time', async ({ page, request }) => {
    const event = await createEvent(request);
    const sticker = await createProduct(request, { name: unique('Sticker') });
    const place = async (time) => (await (await request.post('/api/orders', {
        data: { placedAt: `${event.startDate}T${time}`, lines: [{ productId: sticker.id, variant: null, quantity: 1 }] },
    })).json());
    const morning = await place('09:15');
    const afternoon = await place('16:40');
    const [year, month, day] = event.startDate.split('-');
    const search = page.getByLabel('Rechercher une commande');
    const rows = (order) => page.getByRole('row').filter({ hasText: order.reference });

    await page.goto('/orders');
    await search.fill(afternoon.reference);
    await expect(rows(afternoon)).toHaveCount(1);
    await expect(rows(morning)).toHaveCount(0);

    await search.fill(`${day}/${month}/${year} 9h15`);
    await expect(rows(morning)).toHaveCount(1);
    await expect(rows(afternoon)).toHaveCount(0);

    await search.fill(`${event.startDate} 16:40`);
    await expect(rows(afternoon)).toHaveCount(1);
    await expect(rows(morning)).toHaveCount(0);

    await search.fill('pas une commande');
    await expect(page.getByText('Aucune commande ne correspond.')).toBeVisible();
});

test('delete an order from its detail page', async ({ page, request }) => {
    const event = await createEvent(request);
    const sticker = await createProduct(request, { name: unique('Sticker') });
    const response = await request.post('/api/orders', {
        data: { placedAt: `${event.startDate}T10:00`, lines: [{ productId: sticker.id, variant: null, quantity: 1 }] },
    });
    const order = await response.json();

    await page.goto(`/orders/${order.id}`);
    await page.getByRole('button', { name: 'Supprimer la commande' }).click();
    await page.getByRole('alertdialog').getByRole('button', { name: 'Confirmer la suppression' }).click();

    await expect(page).toHaveURL(/\/orders$/);
    await expect(page.getByRole('row').filter({ hasText: order.reference })).toHaveCount(0);
});

test('refund an order: it stays in the history and its items come back to stock', async ({ page, request }) => {
    const event = await createEvent(request);
    const sticker = await createProduct(request, { name: unique('Sticker'), buyingPrice: 100 });
    const response = await request.post('/api/orders', {
        data: { placedAt: `${event.startDate}T10:00`, lines: [{ productId: sticker.id, variant: null, quantity: 2 }] },
    });
    const order = await response.json();

    await page.goto(`/orders/${order.id}`);
    await page.getByRole('button', { name: 'Rembourser' }).click();
    await page.getByRole('alertdialog').getByRole('button', { name: 'Confirmer le remboursement' }).click();

    await expect(page.getByText(/Remboursée le/)).toBeVisible();
    await expect(page.getByRole('button', { name: 'Rembourser' })).toHaveCount(0);

    await page.goto(`/products/${sticker.id}`);
    const movements = page.locator('.product-movements');
    await expect(movements.getByRole('link', { name: 'Retour de commande' })).toHaveAttribute('href', `/orders/${order.id}`);
    await expect(movements.getByRole('link', { name: 'Vente' })).toBeVisible();
});

test('select every order of a day from its header', async ({ page, request }) => {
    const event = await createEvent(request);
    const sticker = await createProduct(request, { name: unique('Sticker') });
    for (const time of ['10:00', '11:00']) {
        await request.post('/api/orders', { data: { placedAt: `${event.startDate}T${time}`, lines: [{ productId: sticker.id, variant: null, quantity: 1 }] } });
    }

    await page.goto(`/orders?event=${event.id}`);
    const day = page.getByRole('checkbox', { name: /^Sélectionner les commandes du / });
    await day.check();
    await expect(page.getByRole('region', { name: 'Sélection' })).toContainText('2 commandes sélectionnées');
    await day.uncheck();
    await expect(page.getByRole('region', { name: 'Sélection' })).toHaveCount(0);
});

test('delete several orders from the list', async ({ page, request }) => {
    const event = await createEvent(request);
    const sticker = await createProduct(request, { name: unique('Sticker') });
    const orders = [];
    for (const time of ['10:00', '11:00', '12:00']) {
        const response = await request.post('/api/orders', {
            data: { placedAt: `${event.startDate}T${time}`, lines: [{ productId: sticker.id, variant: null, quantity: 1 }] },
        });
        orders.push(await response.json());
    }
    const [first, second, kept] = orders;

    await page.goto(`/orders?event=${event.id}`);
    await page.getByRole('checkbox', { name: `Sélectionner la commande ${first.reference}` }).check();
    await page.getByRole('checkbox', { name: `Sélectionner la commande ${second.reference}` }).check();
    await expect(page.getByRole('region', { name: 'Sélection' })).toContainText('2 commandes sélectionnées');
    await page.getByRole('button', { name: 'Supprimer la sélection' }).click();
    await page.getByRole('alertdialog').getByRole('button', { name: 'Supprimer 2 commandes ?' }).click();

    await expect(page.getByTestId('toast')).toContainText('2 commandes supprimées.');
    await expect(page.getByRole('row').filter({ hasText: first.reference })).toHaveCount(0);
    await expect(page.getByRole('row').filter({ hasText: second.reference })).toHaveCount(0);
    await expect(page.getByRole('row').filter({ hasText: kept.reference })).toBeVisible();
    await expect(page.getByRole('region', { name: 'Sélection' })).toHaveCount(0);
});
