import { test, expect } from './support/test.js';
import { choose } from './support/select.js';
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

test('prepare a restock for an event, move its order-by day, tick it ordered, then delete it', async ({ page, request }) => {
    const supplier = await createSupplier(request, { leadDays: 10 });
    const event = await createEvent(request, { startDate: inDays(23) });

    await page.goto('/planning');
    await page.getByRole('button', { name: 'Préparer un réassort' }).click();
    const dialog = page.getByRole('dialog', { name: 'Préparer un réassort' });
    await choose(page, dialog.getByRole('combobox', { name: 'Fournisseur' }), supplier.name);
    await expect(dialog.getByRole('spinbutton', { name: 'Délai de commande (jours)' })).toHaveValue('10');
    await dialog.getByRole('textbox', { name: 'Note' }).fill('stickers holo');
    await expect(dialog.getByRole('radio', { name: 'Une date' })).toHaveCount(0);
    await choose(page, dialog.getByRole('combobox', { name: 'Événement' }), event.name);
    await dialog.getByRole('button', { name: 'Préparer', exact: true }).click();
    await expect(page.getByTestId('toast').last()).toContainText(`Réassort chez ${supplier.name} préparé.`);

    const later = page.getByRole('region', { name: 'Plus tard' });
    const thisWeek = page.getByRole('region', { name: 'Cette semaine' });
    const lane = page.getByRole('region', { name: "Vue d'ensemble" }).getByRole('group', { name: event.name });
    await expect(lane.getByRole('img', { name: `Commander chez ${supplier.name} · ${dayIn(10)} · À commander` })).toBeVisible();
    await later.getByRole('button', { name: /^Plus tard/ }).click();
    const item = later.getByRole('listitem').filter({ hasText: supplier.name });
    await expect(item).toContainText(`Commander chez ${supplier.name}`);
    await expect(item).toContainText('stickers holo');
    await expect(item).toContainText('dans 10 j');
    await expect(item).toContainText(dayIn(10));
    await expect(item).toContainText(event.name);
    await expect(item).toContainText('À commander');
    await expect(item.getByRole('link', { name: 'Passer la commande' })).toHaveAttribute('href', new RegExp(`^/supplier-orders\\?restock=\\w+&supplier=${supplier.id}$`));

    await item.getByRole('button', { name: 'Modifier le réassort' }).click();
    const edit = page.getByRole('dialog', { name: 'Modifier le réassort' });
    await expect(edit.getByRole('combobox', { name: 'Fournisseur' })).toHaveText(supplier.name);
    await expect(edit.getByRole('textbox', { name: 'Note' })).toHaveValue('stickers holo');
    await expect(edit.getByRole('combobox', { name: 'Événement' })).toHaveText(event.name);
    const leadTime = edit.getByRole('spinbutton', { name: 'Délai de commande (jours)' });
    await leadTime.fill('16');
    await leadTime.press('Tab');
    await edit.getByRole('button', { name: 'Enregistrer', exact: true }).click();
    await expect(page.getByTestId('toast').last()).toContainText(`Réassort chez ${supplier.name} modifié.`);

    await expect(later).toHaveCount(0);
    const moved = thisWeek.getByRole('listitem').filter({ hasText: supplier.name });
    await expect(moved).toContainText('dans 4 j');
    await expect(moved).toContainText(dayIn(4));

    await moved.getByRole('checkbox', { name: 'Commandé' }).click();
    await expect(moved).toContainText(`Commandé le ${dayIn(0)}`);
    await expect(moved.getByRole('checkbox', { name: 'Commandé' })).toBeChecked();
    await expect(lane.getByRole('img', { name: `Commander chez ${supplier.name} · ${dayIn(4)} · Commandé le ${dayIn(0)}` })).toBeVisible();

    await page.goto(`/events/${event.id}`);
    const planned = page.getByRole('region', { name: 'Préparation' }).getByRole('listitem').filter({ hasText: supplier.name });
    await expect(planned.getByRole('checkbox', { name: 'Commandé' })).toBeChecked();
    await planned.getByRole('button', { name: 'Supprimer le réassort' }).click();
    await page.getByRole('alertdialog').getByRole('button', { name: 'Supprimer le réassort' }).click();
    await expect(page.getByTestId('toast').last()).toContainText(`Réassort chez ${supplier.name} supprimé.`);

    await page.goto('/planning');
    await expect(lane.getByRole('img', { name: /^Commander chez/ })).toHaveCount(0);
});

test('prepare a restock from an event, and the dashboard shows it when it is late', async ({ page, request }) => {
    const supplier = await createSupplier(request, { leadDays: 7 });
    const event = await createEvent(request, { startDate: inDays(5) });

    await page.goto(`/events/${event.id}`);
    await page.getByRole('button', { name: 'Préparer un réassort' }).click();
    const dialog = page.getByRole('dialog', { name: 'Préparer un réassort' });
    await expect(dialog.getByRole('combobox', { name: 'Événement' })).toHaveCount(0);
    await choose(page, dialog.getByRole('combobox', { name: 'Fournisseur' }), supplier.name);
    await expect(dialog.getByRole('spinbutton', { name: 'Délai de commande (jours)' })).toHaveValue('7');
    await dialog.getByRole('button', { name: 'Préparer', exact: true }).click();
    await expect(page.getByTestId('toast').last()).toContainText(`Réassort chez ${supplier.name} préparé.`);

    await page.goto('/dashboard');
    const card = page.getByRole('region', { name: 'Planning', exact: true });
    const item = card.getByRole('listitem').filter({ hasText: supplier.name });
    await expect(item).toContainText(`Commander chez ${supplier.name}`);
    await expect(item).toContainText('il y a 5 j');
    await expect(item).toContainText(dayIn(-5));
    await expect(item).toContainText(event.name);
    await expect(item).toContainText('En retard');
    await expect(item.getByRole('link', { name: 'Passer la commande' })).toBeVisible();
});

test('place the order of a restock from the planning, and the restock is ordered through it', async ({ page, request }) => {
    const supplier = await createSupplier(request, { leadDays: 5 });
    const product = await createProduct(request, { name: unique('Sticker') });
    const event = await createEvent(request, { startDate: inDays(30) });
    const planned = await request.post('/api/restocks', { data: { supplierId: supplier.id, note: 'stickers holo', eventId: event.id, leadDays: 5 } });
    expect(planned.status()).toBe(201);

    const draft = { form: { supplierId: 'another-supplier', lines: [{ productId: product.id, variant: null, label: product.name, quantity: 3, totalPrice: 300 }] } };

    await page.goto('/planning');
    await page.evaluate((value) => window.localStorage.setItem('mossytrunk.supplier-order-draft', JSON.stringify(value)), draft);
    const later = page.getByRole('region', { name: 'Plus tard' });
    await later.getByRole('button', { name: /^Plus tard/ }).click();
    await later.getByRole('listitem').filter({ hasText: supplier.name }).getByRole('link', { name: 'Passer la commande' }).click();

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
    await later.getByRole('button', { name: /^Plus tard/ }).click();
    const done = later.getByRole('listitem').filter({ hasText: supplier.name });
    await expect(done).toContainText(`Commandé le ${dayIn(0)}`);
    await expect(done.getByRole('checkbox', { name: 'Commandé' })).toBeChecked();
    const lane = page.getByRole('region', { name: "Vue d'ensemble" }).getByRole('group', { name: event.name });
    await expect(lane.getByRole('img', { name: `Commander chez ${supplier.name} · ${dayIn(22)} · Commandé le ${dayIn(0)}` })).toBeVisible();

    await page.goto(`/events/${event.id}`);
    const ordered = page.getByRole('region', { name: 'Préparation' }).getByRole('listitem').filter({ hasText: supplier.name });
    await expect(ordered).toContainText(`Commandé le ${dayIn(0)}`);
    await expect(ordered.getByRole('link', { name: /^CMF-/ })).toBeVisible();
    await expect(ordered.getByRole('link', { name: 'Passer la commande' })).toHaveCount(0);
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
    await expect(dialog.getByRole('combobox', { name: 'Événement' })).toHaveCount(0);
    await choose(page, dialog.getByRole('combobox', { name: 'Fournisseur' }), supplier.name);
    await dialog.getByRole('button', { name: 'Préparer', exact: true }).click();
    await expect(page.getByTestId('toast').last()).toContainText(`Réassort chez ${supplier.name} préparé.`);

    await page.goto('/planning');
    const lane = page.getByRole('region', { name: "Vue d'ensemble" }).getByRole('group', { name: event.name });
    await expect(lane.getByRole('img', { name: `Prêt le ${dayIn(37)}` })).toBeAttached();
    await expect(lane.getByRole('img', { name: `Commander chez ${supplier.name} · ${dayIn(34)} · À commander` })).toBeAttached();
    const later = page.getByRole('region', { name: 'Plus tard' });
    await later.getByRole('button', { name: /^Plus tard/ }).click();
    const item = later.getByRole('listitem').filter({ hasText: supplier.name });
    await expect(item).toContainText(event.name);
    await expect(item).toContainText('dans 34 j');
    await expect(item).toContainText(dayIn(34));
});

test('a restock of an event already over can be ticked or deleted, not edited', async ({ page, request }) => {
    const supplier = await createSupplier(request, { leadDays: 3 });
    const event = await createEvent(request, { startDate: inDays(20) });
    const planned = await request.post('/api/restocks', { data: { supplierId: supplier.id, note: null, eventId: event.id, leadDays: 3 } });
    expect(planned.status()).toBe(201);
    const moved = await request.put(`/api/events/${event.id}`, { data: { name: event.name, location: 'Lyon', startDate: inDays(-20), endDate: inDays(-20), readyDaysBefore: null } });
    expect(moved.ok()).toBe(true);

    await page.goto(`/events/${event.id}`);
    const row = page.getByRole('region', { name: 'Préparation' }).getByRole('listitem').filter({ hasText: supplier.name });
    await expect(row.getByRole('checkbox', { name: 'Commandé' })).toBeVisible();
    await expect(row.getByRole('button', { name: 'Supprimer le réassort' })).toBeVisible();
    await expect(row.getByRole('button', { name: 'Modifier le réassort' })).toHaveCount(0);
});

test('a restock needs an upcoming event and a lead time', async ({ page, request }) => {
    const supplier = await createSupplier(request);
    const event = await createEvent(request, { startDate: inDays(20) });
    const ongoing = await createEvent(request, { startDate: inDays(-1), endDate: inDays(1) });

    await page.goto('/planning');
    await page.getByRole('button', { name: 'Préparer un réassort' }).click();
    const dialog = page.getByRole('dialog', { name: 'Préparer un réassort' });
    await choose(page, dialog.getByRole('combobox', { name: 'Fournisseur' }), supplier.name);
    const leadTime = dialog.getByRole('spinbutton', { name: 'Délai de commande (jours)' });
    await leadTime.fill('4');
    await leadTime.press('Tab');
    await dialog.getByRole('button', { name: 'Préparer', exact: true }).click();
    await expect(dialog.getByRole('group', { name: 'Événement' })).toContainText('Événement invalide.');

    await dialog.getByRole('combobox', { name: 'Événement' }).click();
    await expect(page.getByRole('option', { name: ongoing.name, exact: true })).toHaveCount(0);
    await page.getByRole('option', { name: event.name, exact: true }).click();
    await leadTime.fill('');
    await leadTime.press('Tab');
    await dialog.getByRole('button', { name: 'Préparer', exact: true }).click();
    await expect(dialog.getByRole('alert')).toContainText("Le délai de commande est d'au moins 1 jour.");
});
