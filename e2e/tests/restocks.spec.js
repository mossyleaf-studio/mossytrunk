import { test, expect } from './support/test.js';
import { choose } from './support/select.js';
import { fillDate } from './support/date.js';
import { unique } from './support/unique.js';
import { createEvent, createProduct } from './support/api.js';

const shortDate = new Intl.DateTimeFormat('fr-FR', { dateStyle: 'medium', timeZone: 'UTC' });
const today = new Intl.DateTimeFormat('en-CA', { timeZone: 'Europe/Paris' }).format(new Date());
const inDays = (days) => new Date(Date.parse(today) + days * 86_400_000).toISOString().slice(0, 10);
const dayIn = (days) => shortDate.format(new Date(inDays(days)));

async function createSupplier(request, data = {}) {
    const response = await request.post('/api/suppliers', { data: { name: unique('Imprimerie'), ...data } });
    expect(response.status()).toBe(201);
    return response.json();
}

test('a supplier keeps its lead time', async ({ page, request }) => {
    const supplier = await createSupplier(request);

    await page.goto('/supplier-orders');
    await page.getByRole('button', { name: 'Fournisseurs' }).click();
    const dialog = page.getByRole('dialog', { name: 'Fournisseurs' });
    await dialog.getByRole('button', { name: `Modifier ${supplier.name}` }).click();
    const leadTime = dialog.getByRole('spinbutton', { name: 'Délai de commande (jours)' });
    await expect(leadTime).toHaveValue('');
    await leadTime.fill('12');
    await leadTime.press('Tab');
    await dialog.getByRole('button', { name: 'Enregistrer', exact: true }).click();
    await expect(page.getByTestId('toast').last()).toContainText(`Fournisseur « ${supplier.name} » enregistré.`);
    await expect(dialog.getByRole('listitem').filter({ hasText: supplier.name })).toContainText('Délai de commande : 12 jours');

    await page.reload();
    await page.getByRole('button', { name: 'Fournisseurs' }).click();
    await dialog.getByRole('button', { name: `Modifier ${supplier.name}` }).click();
    await expect(dialog.getByRole('spinbutton', { name: 'Délai de commande (jours)' })).toHaveValue('12');
});

test('prepare a restock by a date, move its order-by day, tick it ordered, then delete it', async ({ page, request }) => {
    const supplier = await createSupplier(request, { leadDays: 10 });

    await page.goto('/planning');
    await page.getByRole('button', { name: 'Préparer un réassort' }).click();
    const dialog = page.getByRole('dialog', { name: 'Préparer un réassort' });
    await choose(page, dialog.getByRole('combobox', { name: 'Fournisseur' }), supplier.name);
    await expect(dialog.getByRole('spinbutton', { name: 'Délai de commande (jours)' })).toHaveValue('10');
    await dialog.getByRole('textbox', { name: 'Note' }).fill('stickers holo');
    await dialog.getByRole('radio', { name: 'Une date' }).click();
    await fillDate(dialog.getByRole('group', { name: 'Date' }), inDays(20));
    await dialog.getByRole('button', { name: 'Préparer', exact: true }).click();
    await expect(page.getByTestId('toast').last()).toContainText(`Réassort chez ${supplier.name} préparé.`);

    const later = page.getByRole('region', { name: 'Plus tard' });
    const thisWeek = page.getByRole('region', { name: 'Cette semaine' });
    const restocks = page.getByRole('region', { name: 'Réassorts à venir' });
    const item = later.getByRole('listitem').filter({ hasText: supplier.name });
    await expect(item).toContainText(`Commander chez ${supplier.name}`);
    await expect(item).toContainText('stickers holo');
    await expect(item).toContainText(`À commander avant le ${dayIn(10)}`);
    await expect(item).toContainText(`Pour le ${dayIn(20)}`);
    await expect(item.getByRole('link', { name: 'Passer la commande' })).toHaveAttribute('href', new RegExp(`^/supplier-orders\\?restock=\\w+&supplier=${supplier.id}$`));
    const planned = restocks.getByRole('listitem').filter({ hasText: supplier.name });
    await expect(planned).toContainText('À commander');

    await item.getByRole('button', { name: 'Modifier le réassort' }).click();
    const edit = page.getByRole('dialog', { name: 'Modifier le réassort' });
    await expect(edit.getByRole('combobox', { name: 'Fournisseur' })).toHaveText(supplier.name);
    await expect(edit.getByRole('textbox', { name: 'Note' })).toHaveValue('stickers holo');
    const leadTime = edit.getByRole('spinbutton', { name: 'Délai de commande (jours)' });
    await leadTime.fill('16');
    await leadTime.press('Tab');
    await edit.getByRole('button', { name: 'Enregistrer', exact: true }).click();
    await expect(page.getByTestId('toast').last()).toContainText(`Réassort chez ${supplier.name} modifié.`);

    await expect(later.getByRole('listitem').filter({ hasText: supplier.name })).toHaveCount(0);
    const moved = thisWeek.getByRole('listitem').filter({ hasText: supplier.name });
    await expect(moved).toContainText(`À commander avant le ${dayIn(4)}`);

    await moved.getByRole('checkbox', { name: 'Commandé' }).click();
    await expect(thisWeek.getByRole('listitem').filter({ hasText: supplier.name })).toHaveCount(0);
    await expect(planned).toContainText(`Commandé le ${dayIn(0)}`);
    await expect(planned.getByRole('checkbox', { name: 'Commandé' })).toBeChecked();
    await expect(planned.getByRole('link', { name: 'Passer la commande' })).toHaveCount(0);

    await planned.getByRole('button', { name: 'Supprimer le réassort' }).click();
    await page.getByRole('alertdialog').getByRole('button', { name: 'Supprimer le réassort' }).click();
    await expect(page.getByTestId('toast').last()).toContainText(`Réassort chez ${supplier.name} supprimé.`);
    await expect(restocks.getByRole('listitem').filter({ hasText: supplier.name })).toHaveCount(0);
});

test('prepare a restock from an event, and the dashboard shows it when it is late', async ({ page, request }) => {
    const supplier = await createSupplier(request, { leadDays: 7 });
    const event = await createEvent(request, { startDate: inDays(5) });

    await page.goto(`/events/${event.id}`);
    await page.getByRole('button', { name: 'Préparer un réassort' }).click();
    const dialog = page.getByRole('dialog', { name: 'Préparer un réassort' });
    await expect(dialog.getByRole('combobox', { name: 'Événement' })).toHaveText(event.name);
    await choose(page, dialog.getByRole('combobox', { name: 'Fournisseur' }), supplier.name);
    await expect(dialog.getByRole('spinbutton', { name: 'Délai de commande (jours)' })).toHaveValue('7');
    await dialog.getByRole('button', { name: 'Préparer', exact: true }).click();
    await expect(page.getByTestId('toast').last()).toContainText(`Réassort chez ${supplier.name} préparé.`);

    await page.goto('/dashboard');
    const card = page.getByRole('region', { name: 'Planning', exact: true });
    const item = card.getByRole('listitem').filter({ hasText: supplier.name });
    await expect(item).toContainText(`Commander chez ${supplier.name}`);
    await expect(item).toContainText(`À commander avant le ${dayIn(-2)}`);
    await expect(item).toContainText(event.name);
    await expect(item).toContainText('En retard');
});

test('place the order of a restock from the planning, and the restock is ordered through it', async ({ page, request }) => {
    const supplier = await createSupplier(request, { leadDays: 5 });
    const product = await createProduct(request, { name: unique('Sticker') });
    const planned = await request.post('/api/restocks', { data: { supplierId: supplier.id, note: 'stickers holo', day: inDays(30), leadDays: 5 } });
    expect(planned.status()).toBe(201);

    const draft = { form: { supplierId: 'another-supplier', lines: [{ productId: product.id, variant: null, label: product.name, quantity: 3, totalPrice: 300 }] } };

    await page.goto('/planning');
    await page.evaluate((value) => window.localStorage.setItem('mossytrunk.supplier-order-draft', JSON.stringify(value)), draft);
    const restocks = page.getByRole('region', { name: 'Réassorts à venir' });
    await restocks.getByRole('listitem').filter({ hasText: supplier.name }).getByRole('link', { name: 'Passer la commande' }).click();

    const form = page.getByRole('dialog', { name: 'Nouvelle commande fournisseur' }).locator('form').first();
    await expect(form.getByRole('combobox', { name: 'Fournisseur' })).toHaveText(supplier.name);
    await expect(form.getByRole('status')).toContainText('Votre brouillon chez un autre fournisseur est gardé pour plus tard.');
    await expect(page).toHaveURL(/\/supplier-orders$/);
    expect(await page.evaluate(() => window.sessionStorage.getItem('mossytrunk.list-urls') ?? '')).not.toContain('restock');
    await choose(page, form.getByRole('combobox', { name: 'Produit' }), product.name);
    await form.getByRole('spinbutton', { name: 'Quantité commandée' }).fill('50');
    await form.getByLabel('Prix total', { exact: true }).fill('25');
    await form.getByRole('button', { name: 'Ajouter', exact: true }).click();
    await form.getByRole('button', { name: 'Passer la commande' }).click();
    await expect(page.getByTestId('toast').last()).toContainText('Commande fournisseur passée.');
    expect(await page.evaluate(() => JSON.parse(window.localStorage.getItem('mossytrunk.supplier-order-draft')))).toEqual(draft);

    await page.goto('/planning');
    const ordered = restocks.getByRole('listitem').filter({ hasText: supplier.name });
    await expect(ordered).toContainText(`Commandé le ${dayIn(0)}`);
    await expect(ordered.getByRole('link', { name: /^CMF-/ })).toBeVisible();
    await expect(ordered.getByRole('link', { name: 'Passer la commande' })).toHaveCount(0);
    await expect(page.getByRole('region', { name: 'Plus tard' }).getByRole('listitem').filter({ hasText: supplier.name })).toHaveCount(0);
});

test('prepare a restock from an upcoming event page, it shows on the planning for that event, and a started event offers none', async ({ page, request }) => {
    const supplier = await createSupplier(request, { leadDays: 3 });
    const event = await createEvent(request, { startDate: inDays(40) });
    const past = await createEvent(request, { startDate: inDays(-40) });
    const ongoing = await createEvent(request, { startDate: inDays(-1), endDate: inDays(1) });

    for (const started of [past, ongoing]) {
        await page.goto(`/events/${started.id}`);
        await expect(page.getByRole('heading', { level: 1, name: started.name })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Préparer un réassort' })).toHaveCount(0);
    }

    await page.goto(`/events/${event.id}`);
    await page.getByRole('button', { name: 'Préparer un réassort' }).click();
    const dialog = page.getByRole('dialog', { name: 'Préparer un réassort' });
    await expect(dialog.getByRole('combobox', { name: 'Événement' })).toHaveText(event.name);
    await choose(page, dialog.getByRole('combobox', { name: 'Fournisseur' }), supplier.name);
    await dialog.getByRole('button', { name: 'Préparer', exact: true }).click();
    await expect(page.getByTestId('toast').last()).toContainText(`Réassort chez ${supplier.name} préparé.`);

    await page.goto('/planning');
    const item = page.getByRole('region', { name: 'Réassorts à venir' }).getByRole('listitem').filter({ hasText: supplier.name });
    await expect(item).toContainText(event.name);
    await expect(item).toContainText(`Pour le ${dayIn(40)}`);
    await expect(item).toContainText(`À commander avant le ${dayIn(37)}`);
});

test('a restock needs a lead time and a target day not past', async ({ page, request }) => {
    const supplier = await createSupplier(request);

    await page.goto('/planning');
    await page.getByRole('button', { name: 'Préparer un réassort' }).click();
    const dialog = page.getByRole('dialog', { name: 'Préparer un réassort' });
    await choose(page, dialog.getByRole('combobox', { name: 'Fournisseur' }), supplier.name);
    await dialog.getByRole('radio', { name: 'Une date' }).click();
    await fillDate(dialog.getByRole('group', { name: 'Date' }), inDays(20));
    await dialog.getByRole('button', { name: 'Préparer', exact: true }).click();
    await expect(dialog.getByRole('alert')).toContainText("Le délai de commande est d'au moins 1 jour.");

    const leadTime = dialog.getByRole('spinbutton', { name: 'Délai de commande (jours)' });
    await leadTime.fill('4');
    await leadTime.press('Tab');
    await fillDate(dialog.getByRole('group', { name: 'Date' }), inDays(-1));
    await dialog.getByRole('button', { name: 'Préparer', exact: true }).click();
    await expect(dialog.getByRole('alert')).toContainText("Un réassort se prépare pour aujourd'hui ou un jour à venir.");
});
