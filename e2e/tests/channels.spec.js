import { test, expect } from './support/test.js';
import { choose } from './support/select.js';
import { closeToast } from './support/toast.js';
import { unique } from './support/unique.js';
import { createEvent, createProduct, createType, restock } from './support/api.js';

test('a sales channel gets its own prices, one by one or in batch', async ({ page, request }) => {
    const main = (await (await request.get('/api/sales-channels')).json()).find((channel) => channel.main);
    const name = unique('Boutique');
    const type = await createType(request, unique('Badge'));
    const moss = await createProduct(request, { name: 'Mousse', sellingPrice: 400, type });
    const fern = await createProduct(request, { name: 'Fougère', sellingPrice: 600, type });

    await page.goto('/settings');
    await page.getByRole('link', { name: 'Canaux de vente' }).click();
    await expect(page).toHaveURL(/\/channels$/);
    const channels = page.getByRole('main');
    await expect(channels).toContainText(main.name);
    await expect(channels.getByRole('button', { name: `Supprimer ${main.name}` })).toHaveCount(0);
    await page.getByRole('button', { name: 'Ajouter un canal' }).click();
    const dialog = page.getByRole('dialog', { name: 'Ajouter un canal' });
    await dialog.getByLabel('Nom').fill(name);
    await dialog.getByRole('radio', { name: /^En ligne/ }).click();
    await dialog.getByRole('button', { name: 'Ajouter le canal' }).click();
    await expect(page.getByTestId('toast')).toContainText(`Canal « ${name} » ajouté.`);
    await expect(channels).toContainText(name);

    await page.goto('/products');
    await page.getByRole('group', { name: 'Filtrer par type' }).getByRole('button', { name: type.name }).click();
    await page.getByRole('checkbox', { name: 'Tout sélectionner' }).check();
    await page.getByRole('button', { name: 'Modifier la sélection' }).click();
    const batch = page.getByRole('dialog', { name: 'Modifier la sélection' });
    await choose(page, batch.getByRole('combobox', { name: 'Prix à modifier' }), name);
    await choose(page, batch.getByRole('combobox', { name: 'Nouveau prix' }), "D'après un autre prix");
    await expect(batch.getByRole('combobox', { name: 'À partir de' })).toContainText(main.name);
    await batch.getByRole('spinbutton', { name: 'Ajustement' }).fill('10');
    await batch.getByRole('spinbutton', { name: 'Ajustement' }).press('Tab');
    await expect(batch.getByRole('list', { name: 'Aperçu' })).toContainText('4,40');
    await batch.getByRole('button', { name: 'Appliquer à 2 produits' }).click();
    await closeToast(page, '2 produits mis à jour.');

    await expect(page.getByRole('columnheader', { name: name })).toBeVisible();
    await expect(page.getByRole('row').filter({ hasText: moss.displayName })).toContainText('4,40');
    await expect(page.getByRole('row').filter({ hasText: fern.displayName })).toContainText('6,60');

    await page.getByRole('button', { name: `Modifier ${moss.displayName}` }).click();
    await page.getByRole('dialog').getByLabel(`Prix ${name}`).fill('5');
    await page.getByRole('dialog').getByRole('button', { name: 'Enregistrer' }).click();
    await expect(page.getByRole('row').filter({ hasText: moss.displayName })).toContainText('5,00');

    await page.goto('/channels');
    await page.getByRole('link', { name }).click();
    await page.getByRole('searchbox', { name: 'Rechercher un produit' }).fill(type.name);
    await page.getByRole('button', { name: `Modifier le prix de ${fern.displayName}` }).click();
    const price = page.getByRole('dialog', { name: `Prix de ${fern.displayName} sur ${name}` });
    await price.getByRole('switch').click();
    await price.getByRole('button', { name: 'Enregistrer' }).click();
    await closeToast(page, `Prix de ${fern.displayName} enregistré.`);
    await expect(page.getByRole('row').filter({ hasText: fern.displayName }).getByRole('cell').nth(2)).toContainText('6,00');
    await expect(page.getByRole('dialog')).toHaveCount(0);
    await page.getByRole('button', { name: `Modifier le prix de ${moss.displayName}` }).click();
    const mossPrice = page.getByRole('dialog', { name: `Prix de ${moss.displayName} sur ${name}` });
    await mossPrice.getByLabel('Prix', { exact: true }).fill('4,80');
    await mossPrice.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(page.getByRole('row').filter({ hasText: moss.displayName })).toContainText('4,80');

    await page.getByRole('button', { name: `Supprimer ${name}` }).click();
    await page.getByRole('alertdialog').getByRole('button', { name: /Supprimer|Confirmer/ }).click();
    await expect(page.getByTestId('toast').last()).toContainText(`Canal « ${name} » supprimé.`);
});

test('a channel offers supplies that are added to its orders, one by one or in batch', async ({ page, request }) => {
    const main = (await (await request.get('/api/sales-channels')).json()).find((channel) => channel.main);
    const type = await createType(request, unique('Emballage'), { prefixesNames: false });
    const sleeveName = unique('Pochette');
    const sleeve = await (await request.post('/api/products', { data: { name: sleeveName, typeId: type.id, kind: 'supply' } })).json();
    await restock(request, sleeve, { quantity: 50, totalPaid: 500 });
    const flyerName = unique('Flyer');
    const flyer = await (await request.post('/api/products', { data: { name: flyerName, typeId: type.id, kind: 'supply' } })).json();
    await restock(request, flyer, { quantity: 50, totalPaid: 100 });
    const event = await createEvent(request);
    const sticker = await createProduct(request, { name: unique('Sticker') });
    const orders = [];
    for (const time of ['10:00', '11:00']) {
        const response = await request.post('/api/orders', { data: { placedAt: `${event.startDate}T${time}`, lines: [{ productId: sticker.id, variant: null, quantity: 1 }] } });
        orders.push(await response.json());
    }

    await page.goto(`/channels/${main.id}`);
    await page.getByRole('group', { name: 'Fournitures' }).getByRole('button', { name: sleeveName }).click();
    await expect(page.getByTestId('toast').last()).toContainText('Fournitures du canal enregistrées.');
    await Promise.all([
        page.waitForResponse((response) => response.url().endsWith(`/api/sales-channels/${main.id}/supplies`) && response.request().method() === 'PUT'),
        page.getByRole('group', { name: 'Fournitures' }).getByRole('button', { name: flyerName }).click(),
    ]);

    await page.goto(`/orders/${orders[0].id}`);
    const supplies = page.getByRole('region', { name: 'Fournitures' });
    await supplies.getByRole('button', { name: 'Ajouter une fourniture' }).click();
    const dialog = page.getByRole('dialog', { name: 'Ajouter une fourniture' });
    await choose(page, dialog.getByRole('combobox', { name: 'Fourniture', exact: true }), sleeveName);
    await dialog.getByRole('spinbutton', { name: 'Quantité' }).fill('2');
    await dialog.getByRole('button', { name: 'Ajouter', exact: true }).click();
    await expect(supplies).toContainText(`2 × ${sleeveName}`);
    await expect(supplies).toContainText('0,20');

    await page.goto(`/orders?event=${event.id}`);
    for (const order of orders) {
        await page.getByRole('checkbox', { name: `Sélectionner la commande ${order.reference}` }).check();
    }
    await page.getByRole('region', { name: 'Sélection' }).getByRole('button', { name: 'Ajouter une fourniture' }).click();
    const batch = page.getByRole('dialog', { name: 'Ajouter une fourniture' });
    await batch.getByRole('button', { name: 'Une autre fourniture' }).click();
    await batch.getByRole('button', { name: 'Ajouter', exact: true }).click();
    await expect(page.getByTestId('toast').last()).toContainText('Fourniture ajoutée à 2 commandes.');

    await page.goto(`/orders/${orders[0].id}`);
    await expect(page.getByRole('region', { name: 'Fournitures' })).toContainText(`3 × ${sleeveName}`);
    await expect(page.getByRole('region', { name: 'Fournitures' })).toContainText(`1 × ${flyerName}`);
    await page.goto(`/products/${sleeve.id}`);
    await expect(page.locator('.product-movements').getByRole('link', { name: 'Fourniture de commande' })).toHaveCount(2);

    await page.goto(`/channels/${main.id}`);
    await page.getByRole('group', { name: 'Fournitures' }).getByRole('button', { name: sleeveName }).click();
    await expect(page.getByTestId('toast').last()).toContainText('Fournitures du canal enregistrées.');
});

test('a channel charges its costs on each order, and an order gets its postage', async ({ page, request }) => {
    const main = (await (await request.get('/api/sales-channels')).json()).find((channel) => channel.main);
    const label = unique('Commission');

    await page.goto(`/channels/${main.id}`);
    const costs = page.getByRole('region', { name: 'Frais par commande' });
    await costs.getByRole('button', { name: 'Ajouter un frais' }).click();
    const dialog = page.getByRole('dialog', { name: 'Ajouter un frais' });
    await dialog.getByLabel('Nom').fill(label);
    await dialog.getByRole('spinbutton').fill('10');
    await dialog.getByRole('button', { name: 'Ajouter un frais' }).click();
    await expect(page.getByTestId('toast').last()).toContainText(`Frais « ${label} » ajouté.`);
    await expect(costs).toContainText('10 %');

    const event = await createEvent(request);
    const print = await createProduct(request, { name: unique('Print'), sellingPrice: 1_500 });
    const order = await (await request.post('/api/orders', { data: { placedAt: `${event.startDate}T10:00`, lines: [{ productId: print.id, variant: null, quantity: 1 }] } })).json();

    await page.goto(`/orders/${order.id}`);
    const margin = page.getByRole('region', { name: 'Marge' });
    await expect(margin.getByRole('definition').filter({ hasText: '1,50' })).toBeVisible();
    await expect(margin).toContainText(label);
    await margin.getByRole('button', { name: "Modifier l'affranchissement" }).click();
    const postage = page.getByRole('dialog', { name: 'Affranchissement' });
    await postage.getByLabel('Affranchissement').fill('2,32');
    await postage.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(page.getByTestId('toast').last()).toContainText('Affranchissement enregistré.');
    await expect(margin).toContainText('2,32');

    await page.goto(`/channels/${main.id}`);
    await costs.getByRole('button', { name: `Supprimer ${label}` }).click();
    await page.getByRole('alertdialog').getByRole('button', { name: /Supprimer|Confirmer/ }).click();
    await expect(page.getByTestId('toast').last()).toContainText(`Frais « ${label} » supprimé.`);
});
