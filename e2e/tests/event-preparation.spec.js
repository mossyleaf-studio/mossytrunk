import { test, expect } from './support/test.js';
import { choose } from './support/select.js';
import { unique } from './support/unique.js';
import { createEvent } from './support/api.js';

const shortDate = new Intl.DateTimeFormat('fr-FR', { dateStyle: 'medium', timeZone: 'UTC' });
const dayBefore = (iso, days) => shortDate.format(new Date(Date.parse(iso) - days * 86_400_000));
const span = (iso, from, to) => (from === to ? dayBefore(iso, from) : `${dayBefore(iso, from)} → ${dayBefore(iso, to)}`);
const today = new Intl.DateTimeFormat('en-CA', { timeZone: 'Europe/Paris' }).format(new Date());
const MARGIN = "Jours de marge avant l'événement";

async function setMargin(field, days) {
    await field.fill(String(days));
    await field.press('Tab');
}

async function createSupplier(request, data = {}) {
    const response = await request.post('/api/suppliers', { data: { name: unique('Imprimerie'), ...data } });
    expect(response.status()).toBe(201);
    return response.json();
}

async function planCollectionOn(request, event, steps) {
    const name = unique('Sous-bois');
    const created = await request.post('/api/design-collections', { data: { name, description: '' } });
    expect(created.status()).toBe(201);
    const collection = { id: (await created.json()).id, name };
    const planned = await request.post(`/api/design-collections/${collection.id}/release`, { data: { eventId: event.id, steps } });
    expect(planned.status()).toBe(201);
    return collection;
}

test('set how many days before an event everything is ready in the settings', async ({ page }) => {
    await page.goto('/settings');
    const card = page.getByRole('region', { name: 'Planning', exact: true });
    const margin = card.getByRole('spinbutton', { name: MARGIN });
    await expect(margin).toHaveValue('3');
    await setMargin(margin, 5);
    await card.getByRole('button', { name: 'Enregistrer', exact: true }).click();
    await expect(page.getByTestId('toast')).toContainText('Réglages du planning enregistrés.');

    await page.reload();
    await expect(margin).toHaveValue('5');

    await margin.fill('');
    await margin.press('Tab');
    await card.getByRole('button', { name: 'Enregistrer', exact: true }).click();
    await expect(card.getByRole('alert')).toContainText('Indiquez la marge en jours, 0 pour le jour même.');
    await page.reload();
    await expect(margin).toHaveValue('5');
});

test('an event overrides the margin and shows its ready-by day', async ({ page, request }) => {
    const event = await createEvent(request);

    await page.goto(`/events/${event.id}`);
    const preparation = page.getByRole('region', { name: 'Préparation' });
    await expect(preparation).toContainText(`Prêt le ${dayBefore(event.startDate, 3)} · 3 jours avant`);

    await page.getByRole('button', { name: 'Modifier', exact: true }).click();
    const dialog = page.getByRole('dialog', { name: "Modifier l'événement" });
    const margin = dialog.getByRole('spinbutton', { name: MARGIN });
    await expect(margin).toHaveValue('');
    await expect(margin).toHaveAttribute('placeholder', '3');
    await setMargin(margin, 2);
    await dialog.getByRole('button', { name: 'Enregistrer', exact: true }).click();
    await expect(page.getByTestId('toast')).toContainText(`Événement « ${event.name} » mis à jour.`);

    await expect(preparation).toContainText(`Prêt le ${dayBefore(event.startDate, 2)} · 2 jours avant`);

    await page.getByRole('button', { name: 'Modifier', exact: true }).click();
    await setMargin(margin, 0);
    await dialog.getByRole('button', { name: 'Enregistrer', exact: true }).click();
    await expect(preparation).toContainText(`Prêt le ${dayBefore(event.startDate, 0)} · le jour même`);
});

test('a collection planned on an event ends its last step on the ready-by day', async ({ page, request }) => {
    const event = await createEvent(request, { readyDaysBefore: 2 });
    const collection = await planCollectionOn(request, event, [{ label: 'Dessins', days: 3 }, { label: 'Photos', days: 1 }]);

    await page.goto(`/designs?collection=${collection.id}`);
    const timeline = page.getByRole('region', { name: collection.name }).getByRole('region', { name: 'Planning de sortie' });
    await expect(timeline.getByRole('listitem').filter({ hasText: 'Photos' })).toContainText(span(event.startDate, 2, 2));
    await expect(timeline.getByRole('listitem').filter({ hasText: 'Dessins' })).toContainText(span(event.startDate, 5, 3));

    await page.goto(`/events/${event.id}`);
    const preparation = page.getByRole('region', { name: 'Préparation' });
    await expect(preparation.getByRole('link', { name: collection.name })).toHaveAttribute('href', `/designs?collection=${collection.id}`);
    const photos = preparation.getByRole('listitem').filter({ hasText: 'Photos' });
    await expect(photos).toContainText(span(event.startDate, 2, 2));
    await photos.getByRole('checkbox', { name: 'Photos' }).click();
    await expect(photos).toContainText('Fait');
});

test('prepare a restock from the event page, ordered a lead time before its ready-by day', async ({ page, request }) => {
    const supplier = await createSupplier(request, { leadDays: 7 });
    const event = await createEvent(request, { readyDaysBefore: 2 });

    await page.goto(`/events/${event.id}`);
    const preparation = page.getByRole('region', { name: 'Préparation' });
    await preparation.getByRole('button', { name: 'Préparer un réassort' }).click();
    const dialog = page.getByRole('dialog', { name: 'Préparer un réassort' });
    await expect(dialog.getByRole('radio', { name: 'Une date' })).toHaveCount(0);
    await expect(dialog.getByRole('combobox', { name: 'Événement' })).toHaveCount(0);
    await choose(page, dialog.getByRole('combobox', { name: 'Fournisseur' }), supplier.name);
    await expect(dialog.getByRole('spinbutton', { name: 'Délai de commande (jours)' })).toHaveValue('7');
    await dialog.getByRole('button', { name: 'Préparer', exact: true }).click();
    await expect(page.getByTestId('toast').last()).toContainText(`Réassort chez ${supplier.name} préparé.`);

    const restock = preparation.getByRole('listitem').filter({ hasText: supplier.name });
    await expect(restock).toContainText(`Commander chez ${supplier.name}`);
    await expect(restock).toContainText(`À commander avant le ${dayBefore(event.startDate, 9)}`);
    await expect(restock).not.toContainText('Pour le');
    await expect(restock).not.toContainText(event.name);
    await expect(restock.getByRole('link', { name: 'Passer la commande' })).toBeVisible();

    await restock.getByRole('checkbox', { name: 'Commandé' }).click();
    await expect(restock).toContainText(`Commandé le ${dayBefore(today, 0)}`);
    await expect(restock.getByRole('checkbox', { name: 'Commandé' })).toBeChecked();
});
