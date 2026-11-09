import { test, expect } from './support/test.js';
import { choose } from './support/select.js';
import { fillDate } from './support/date.js';
import { unique, uniqueDay } from './support/unique.js';
import { createEvent } from './support/api.js';

const shortDate = new Intl.DateTimeFormat('fr-FR', { dateStyle: 'medium', timeZone: 'UTC' });
const dayBefore = (iso, days) => shortDate.format(new Date(Date.parse(iso) - days * 86_400_000));
const span = (iso, from, to) => (from === to ? dayBefore(iso, from) : `${dayBefore(iso, from)} → ${dayBefore(iso, to)}`);
const today = new Intl.DateTimeFormat('en-CA', { timeZone: 'Europe/Paris' }).format(new Date());
const inDays = (days) => new Date(Date.parse(today) + days * 86_400_000).toISOString().slice(0, 10);
const dayIn = (days) => dayBefore(inDays(days), 0);
const between = (from, to) => `${dayIn(from)} → ${dayIn(to)}`;

async function createCollection(request) {
    const name = unique('Sous-bois');
    const response = await request.post('/api/design-collections', { data: { name, description: '' } });
    expect(response.status()).toBe(201);
    return { id: (await response.json()).id, name };
}

async function createDesign(request, collectionId = null) {
    const name = unique('Renard');
    const response = await request.post('/api/designs', { data: { name, collectionId, notes: '' } });
    expect(response.status()).toBe(201);
    return { id: (await response.json()).id, name };
}

async function planRelease(request, { collectionId, designId, ...data }) {
    const url = collectionId ? `/api/design-collections/${collectionId}/release` : `/api/designs/${designId}/release`;
    const response = await request.post(url, { data });
    expect(response.status()).toBe(201);
}

async function defineTemplate(request, steps) {
    const response = await request.put('/api/planning-settings', { data: { steps, readyDaysBefore: 3 } });
    expect(response.status()).toBe(204);
}

async function setDays(scope, step, days) {
    const field = scope.getByRole('spinbutton', { name: `Jours de l'étape ${step}` });
    await field.fill(String(days));
    await field.press('Tab');
}

test('define the release steps in the settings: add, reorder, remove, save', async ({ page, request }) => {
    await defineTemplate(request, []);
    await page.goto('/settings');
    const card = page.getByRole('region', { name: 'Planning', exact: true });

    for (const [index, [label, days]] of [['Dessins', 3], ['Impression', 2], ['Photos', 1]].entries()) {
        await card.getByRole('button', { name: 'Ajouter une étape' }).click();
        await card.getByRole('textbox', { name: `Nom de l'étape ${index + 1}` }).fill(label);
        await setDays(card, index + 1, days);
    }
    await card.getByRole('button', { name: "Monter l'étape 3" }).click();
    await expect(card.getByRole('textbox', { name: "Nom de l'étape 2" })).toHaveValue('Photos');
    await card.getByRole('button', { name: "Retirer l'étape 3" }).click();
    await card.getByRole('button', { name: 'Enregistrer', exact: true }).click();
    await expect(page.getByTestId('toast')).toContainText('Réglages du planning enregistrés.');

    await page.reload();
    await expect(card.getByRole('textbox', { name: /^Nom de l'étape/ })).toHaveCount(2);
    await expect(card.getByRole('textbox', { name: "Nom de l'étape 1" })).toHaveValue('Dessins');
    await expect(card.getByRole('spinbutton', { name: "Jours de l'étape 1" })).toHaveValue('3');
    await expect(card.getByRole('textbox', { name: "Nom de l'étape 2" })).toHaveValue('Photos');
    await expect(card.getByRole('spinbutton', { name: "Jours de l'étape 2" })).toHaveValue('1');
});

test('plan a collection release on an event, tick a step, move it to a date, then cancel it', async ({ page, request }) => {
    await defineTemplate(request, [{ label: 'Dessins', days: 3 }, { label: 'Impression', days: 2 }, { label: 'Photos', days: 1 }]);
    const { id, name: collection } = await createCollection(request);
    const event = await createEvent(request);

    await page.goto(`/designs?collection=${id}`);
    const shelf = page.getByRole('region', { name: collection });
    await shelf.getByRole('button', { name: 'Planifier la sortie' }).click();
    const dialog = page.getByRole('dialog', { name: 'Planifier la sortie' });
    await dialog.getByRole('radio', { name: 'Un événement' }).click();
    await choose(page, dialog.getByRole('combobox', { name: 'Événement' }), event.name);
    await expect(dialog.getByRole('textbox', { name: "Nom de l'étape 1" })).toHaveValue('Dessins');
    await expect(dialog.getByRole('textbox', { name: "Nom de l'étape 3" })).toHaveValue('Photos');
    await dialog.getByRole('button', { name: 'Planifier', exact: true }).click();
    await expect(page.getByTestId('toast')).toContainText(`Sortie de « ${collection} » planifiée.`);

    const timeline = shelf.getByRole('region', { name: 'Planning de sortie' });
    await expect(timeline).toContainText(dayBefore(event.startDate, 0));
    await expect(timeline).toContainText(event.name);
    const step = (label) => timeline.getByRole('listitem').filter({ hasText: label });
    await expect(step('Dessins')).toContainText(span(event.startDate, 8, 6));
    await expect(step('Impression')).toContainText(span(event.startDate, 5, 4));
    await expect(step('Photos')).toContainText(span(event.startDate, 3, 3));
    await expect(step('Photos')).toContainText('À venir');

    await step('Dessins').getByRole('checkbox', { name: 'Dessins' }).click();
    await expect(step('Dessins')).toContainText('Fait');

    const day = uniqueDay();
    await timeline.getByRole('button', { name: 'Modifier la sortie' }).click();
    const edit = page.getByRole('dialog', { name: 'Modifier la sortie' });
    await edit.getByRole('radio', { name: 'Une date' }).click();
    await fillDate(edit.getByRole('group', { name: 'Date de sortie' }), day);
    await setDays(edit, 3, 2);
    await edit.getByRole('button', { name: 'Enregistrer', exact: true }).click();
    await expect(page.getByTestId('toast').last()).toContainText(`Sortie de « ${collection} » modifiée.`);

    await expect(timeline).toContainText(dayBefore(day, 0));
    await expect(timeline).not.toContainText(event.name);
    await expect(step('Photos')).toContainText(span(day, 2, 1));
    await expect(step('Impression')).toContainText(span(day, 4, 3));
    await expect(step('Dessins')).toContainText(span(day, 7, 5));
    await expect(step('Dessins')).toContainText('Fait');

    await shelf.getByRole('button', { name: 'Annuler la sortie' }).click();
    await page.getByRole('alertdialog').getByRole('button', { name: 'Annuler la sortie' }).click();
    await expect(page.getByTestId('toast').last()).toContainText(`Sortie de « ${collection} » annulée.`);
    await expect(timeline).toHaveCount(0);
    await expect(shelf.getByRole('button', { name: 'Planifier la sortie' })).toBeVisible();
});

test('a design follows its collection release until it is planned on its own', async ({ page, request }) => {
    await defineTemplate(request, [{ label: 'Croquis', days: 2 }]);
    const collection = await createCollection(request);
    const collectionDay = uniqueDay();
    await planRelease(request, { collectionId: collection.id, day: collectionDay, steps: [{ label: 'Dessins', days: 3 }] });
    const design = await createDesign(request, collection.id);

    await page.goto(`/designs/${design.id}`);
    await expect(page.getByText(`Sortie de la collection ${collection.name}`)).toBeVisible();
    await expect(page.getByRole('link', { name: collection.name })).toHaveAttribute('href', `/designs?collection=${collection.id}`);
    const timeline = page.getByRole('region', { name: 'Planning de sortie' });
    await expect(timeline).toContainText(dayBefore(collectionDay, 0));
    await expect(timeline.getByRole('listitem').filter({ hasText: 'Dessins' })).toContainText(span(collectionDay, 3, 1));
    await expect(timeline.getByRole('button', { name: 'Modifier la sortie' })).toHaveCount(0);
    await expect(timeline.getByRole('checkbox', { name: 'Dessins' })).toBeDisabled();

    const day = uniqueDay();
    await page.getByRole('button', { name: 'Planifier ce design à part' }).click();
    const dialog = page.getByRole('dialog', { name: 'Planifier la sortie' });
    await expect(dialog.getByRole('textbox', { name: "Nom de l'étape 1" })).toHaveValue('Croquis');
    await fillDate(dialog.getByRole('group', { name: 'Date de sortie' }), day);
    await dialog.getByRole('button', { name: 'Planifier', exact: true }).click();
    await expect(page.getByTestId('toast')).toContainText(`Sortie de « ${design.name} » planifiée.`);

    await expect(page.getByText(`Sortie de la collection ${collection.name}`)).toHaveCount(0);
    await expect(timeline).toContainText(dayBefore(day, 0));
    await expect(timeline.getByRole('listitem').filter({ hasText: 'Croquis' })).toContainText(span(day, 2, 1));
    await expect(timeline.getByRole('button', { name: 'Modifier la sortie' })).toBeVisible();
});

test('a design without collection plans its own release', async ({ page, request }) => {
    await defineTemplate(request, [{ label: 'Croquis', days: 1 }]);
    const design = await createDesign(request);

    await page.goto(`/designs/${design.id}`);
    await expect(page.getByRole('button', { name: 'Planifier ce design à part' })).toHaveCount(0);
    await page.getByRole('button', { name: 'Planifier la sortie' }).click();
    const dialog = page.getByRole('dialog', { name: 'Planifier la sortie' });
    const day = uniqueDay();
    await fillDate(dialog.getByRole('group', { name: 'Date de sortie' }), day);
    await dialog.getByRole('button', { name: 'Planifier', exact: true }).click();
    await expect(page.getByTestId('toast')).toContainText(`Sortie de « ${design.name} » planifiée.`);

    const timeline = page.getByRole('region', { name: 'Planning de sortie' });
    await expect(timeline.getByRole('listitem').filter({ hasText: 'Croquis' })).toContainText(span(day, 1, 1));
});

async function arrangeLateNearAndFarSteps(request) {
    const collection = await createCollection(request);
    await planRelease(request, { collectionId: collection.id, day: inDays(1), steps: [{ label: 'Encrage', days: 3 }, { label: 'Vernis', days: 1 }] });
    const design = await createDesign(request);
    await planRelease(request, { designId: design.id, day: inDays(40), steps: [{ label: 'Annonce', days: 1 }] });
    return { collection, design };
}

test('the planning page sums up, lists the steps left to do by block and draws them on the overview', async ({ page, request }) => {
    const { collection, design } = await arrangeLateNearAndFarSteps(request);

    await page.goto('/dashboard');
    await page.getByRole('navigation').getByRole('link', { name: 'Planning' }).click();
    await expect(page).toHaveURL(/\/planning$/);

    const summary = page.getByRole('group', { name: 'Résumé du planning' });
    await expect(summary.getByRole('link', { name: 'En retard · 1' })).toBeVisible();
    await expect(summary).toContainText(`Prochaine échéance : Vernis (${collection.name}) · ${dayIn(0)} · aujourd'hui`);
    await summary.getByRole('link', { name: 'Cette semaine · 1' }).click();
    await expect(page).toHaveURL(/#planning-this-week$/);

    const late = page.getByRole('region', { name: 'En retard' });
    const thisWeek = page.getByRole('region', { name: 'Cette semaine' });
    const later = page.getByRole('region', { name: 'Plus tard' });
    const encrage = late.getByRole('listitem').filter({ hasText: 'Encrage' });
    await expect(encrage).toContainText('il y a 1 j');
    await expect(encrage).toContainText(dayIn(-1));
    await expect(encrage).toContainText('3 jours');
    await expect(encrage).toContainText('En retard');
    await expect(encrage.getByRole('link', { name: collection.name })).toHaveAttribute('href', `/designs?collection=${collection.id}`);
    const vernis = thisWeek.getByRole('listitem').filter({ hasText: 'Vernis' });
    await expect(vernis).toContainText("Aujourd'hui");
    await expect(vernis).toContainText(dayIn(0));
    await expect(vernis).toContainText(`Sortie le ${dayIn(1)}`);

    const laterToggle = later.getByRole('button', { name: /^Plus tard/ });
    await expect(laterToggle).toContainText('1');
    await expect(laterToggle).toHaveAttribute('aria-expanded', 'false');
    await expect(later.getByRole('listitem')).toHaveCount(0);
    await laterToggle.click();
    const annonce = later.getByRole('listitem').filter({ hasText: 'Annonce' });
    await expect(annonce).toContainText('dans 39 j');
    await expect(annonce).toContainText(dayIn(39));
    await expect(annonce.getByRole('link', { name: design.name })).toHaveAttribute('href', `/designs/${design.id}`);

    const overview = page.getByRole('region', { name: "Vue d'ensemble" });
    const collectionLane = overview.getByRole('group', { name: collection.name });
    await expect(collectionLane.getByRole('img', { name: `Encrage · ${collection.name} · ${between(-3, -1)} · En retard` })).toBeVisible();
    await expect(collectionLane.getByRole('img', { name: `Vernis · ${collection.name} · ${dayIn(0)} · En cours` })).toBeVisible();
    await expect(overview.getByRole('group', { name: design.name }).getByRole('img', { name: `Annonce · ${design.name} · ${dayIn(39)} · À venir` })).toBeAttached();

    await encrage.getByRole('checkbox', { name: 'Encrage' }).click();
    await expect(late).toHaveCount(0);
    await expect(summary.getByRole('link', { name: /^En retard/ })).toHaveCount(0);
    await expect(collectionLane.getByRole('img', { name: `Encrage · ${collection.name} · ${between(-3, -1)} · Fait` })).toBeVisible();
});

test('the overview draws a lane per upcoming event with its ready-by flag, its releases and its restocks', async ({ page, request }) => {
    const event = await createEvent(request, { startDate: inDays(10) });
    const empty = await createEvent(request, { startDate: inDays(20), endDate: inDays(21) });
    const collection = await createCollection(request);
    await planRelease(request, { collectionId: collection.id, eventId: event.id, steps: [{ label: 'Dessins', days: 2 }, { label: 'Photos', days: 1 }] });
    const design = await createDesign(request);
    await planRelease(request, { designId: design.id, day: inDays(2), steps: [{ label: 'Annonce', days: 1 }] });
    const supplier = await (await request.post('/api/suppliers', { data: { name: unique('Imprimerie'), leadDays: 5 } })).json();
    const supplierName = supplier.name;
    const other = await (await request.post('/api/suppliers', { data: { name: unique('Cartonnerie'), leadDays: 5 } })).json();
    for (const { id } of [supplier, other]) {
        const planned = await request.post('/api/restocks', { data: { supplierId: id, note: null, eventId: event.id, leadDays: 5 } });
        expect(planned.status()).toBe(201);
    }

    await page.goto('/planning');
    const summary = page.getByRole('group', { name: 'Résumé du planning' });
    await expect(summary.getByRole('link', { name: /^En retard/ })).toHaveCount(0);
    await expect(summary.getByRole('link', { name: 'Cette semaine · 4' })).toBeVisible();
    await expect(summary).toContainText(`Prochaine échéance : Annonce (${design.name}) · ${dayIn(1)} · demain`);

    const overview = page.getByRole('region', { name: "Vue d'ensemble" });
    const lane = overview.getByRole('group', { name: event.name });
    await expect(lane.getByRole('img', { name: `Prêt le ${dayIn(7)}` })).toBeVisible();
    await expect(lane.getByRole('img', { name: `${event.name} · ${dayIn(10)}` })).toBeVisible();
    await expect(lane.getByRole('img', { name: `Dessins · ${collection.name} · ${between(5, 6)} · À venir` })).toBeVisible();
    await expect(lane.getByRole('img', { name: `Photos · ${collection.name} · ${dayIn(7)} · À venir` })).toBeVisible();
    const diamond = lane.getByRole('img', { name: `Commander chez ${supplierName} · ${dayIn(2)} · À commander` });
    const otherDiamond = lane.getByRole('img', { name: `Commander chez ${other.name} · ${dayIn(2)} · À commander` });
    await expect(diamond).toBeVisible();
    await expect(otherDiamond).toBeVisible();
    expect((await diamond.boundingBox()).y).not.toBe((await otherDiamond.boundingBox()).y);
    await expect(overview.getByRole('group', { name: empty.name }).getByRole('img', { name: `Prêt le ${dayIn(17)}` })).toBeVisible();
    await expect(overview.getByRole('group', { name: design.name }).getByRole('img', { name: `Annonce · ${design.name} · ${dayIn(1)} · À venir` })).toBeVisible();

    await lane.getByRole('img', { name: /^Dessins/ }).focus();
    await expect(page.getByTestId('timeline-tooltip')).toContainText('Dessins');
    await expect(page.getByTestId('timeline-tooltip')).toContainText(between(5, 6));

    const thisWeek = page.getByRole('region', { name: 'Cette semaine' });
    await expect(thisWeek.getByRole('listitem').filter({ hasText: 'Annonce' })).toContainText('Demain');
    await expect(thisWeek.getByRole('listitem').filter({ hasText: supplierName })).toContainText('dans 2 j');
    const dessins = thisWeek.getByRole('listitem').filter({ hasText: 'Dessins' });
    await expect(dessins).toContainText('dans 5 j');
    await expect(dessins).toContainText(dayIn(5));
    await expect(dessins).toContainText(event.name);
});

test('the overview keeps a late step that ended before its first week, on its left edge', async ({ page, request }) => {
    const collection = await createCollection(request);
    await planRelease(request, { collectionId: collection.id, day: inDays(12), steps: [{ label: 'Croquis', days: 2 }, { label: 'Encrage', days: 30 }] });

    await page.goto('/planning');
    await expect(page.getByRole('group', { name: 'Résumé du planning' }).getByRole('link', { name: 'En retard · 1' })).toBeVisible();
    const lane = page.getByRole('region', { name: "Vue d'ensemble" }).getByRole('group', { name: collection.name });
    await expect(lane.getByRole('img', { name: `Croquis · ${collection.name} · ${between(-20, -19)} · En retard` })).toBeVisible();
});

test('on a phone the overview opens scrolled to today', async ({ page, request }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    const event = await createEvent(request, { startDate: inDays(10) });

    await page.goto('/planning');
    const lane = page.getByRole('region', { name: "Vue d'ensemble" }).getByRole('group', { name: event.name });
    await expect(lane.getByRole('img', { name: `Prêt le ${dayIn(7)}` })).toBeInViewport();
});

test('the dashboard shows the late and this week steps, and hides the card when there are none', async ({ page, request }) => {
    const design = await createDesign(request);
    await planRelease(request, { designId: design.id, day: inDays(40), steps: [{ label: 'Annonce', days: 1 }] });
    await page.goto('/dashboard');
    await expect(page.getByRole('heading', { level: 2 }).first()).toBeVisible();
    await expect(page.getByRole('region', { name: 'Planning', exact: true })).toHaveCount(0);

    const { collection } = await arrangeLateNearAndFarSteps(request);
    await page.reload();
    const card = page.getByRole('region', { name: 'Planning', exact: true });
    const encrage = card.getByRole('listitem').filter({ hasText: 'Encrage' });
    await expect(encrage).toContainText('En retard');
    await expect(encrage).toContainText('il y a 1 j');
    const vernis = card.getByRole('listitem').filter({ hasText: 'Vernis' });
    await expect(vernis).toContainText(collection.name);
    await expect(vernis).toContainText("Aujourd'hui");
    await expect(card.getByRole('listitem').filter({ hasText: 'Annonce' })).toHaveCount(0);
    await expect(card.getByRole('link', { name: 'Voir le planning' })).toHaveAttribute('href', '/planning');

    await encrage.getByRole('checkbox', { name: 'Encrage' }).click();
    await expect(card.getByRole('listitem').filter({ hasText: 'Encrage' })).toHaveCount(0);
});

test('a design moved out of its collection stops following its release', async ({ page, request }) => {
    const collection = await createCollection(request);
    await planRelease(request, { collectionId: collection.id, day: uniqueDay(), steps: [{ label: 'Dessins', days: 3 }] });
    const design = await createDesign(request, collection.id);

    await page.goto(`/designs/${design.id}`);
    await expect(page.getByText(`Sortie de la collection ${collection.name}`)).toBeVisible();
    await page.getByRole('button', { name: 'Modifier', exact: true }).click();
    const dialog = page.getByRole('dialog', { name: 'Modifier le design' });
    await choose(page, dialog.getByRole('combobox', { name: 'Collection' }), 'Sans collection');
    await dialog.getByRole('button', { name: 'Enregistrer', exact: true }).click();

    await expect(page.getByText(`Sortie de la collection ${collection.name}`)).toHaveCount(0);
    await expect(page.getByRole('region', { name: 'Planning de sortie' })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Planifier la sortie' })).toBeVisible();
});

test('the release form shows a domain error and keeps a past event when editing', async ({ page, request }) => {
    const event = await createEvent(request, { startDate: inDays(-30) });
    const design = await createDesign(request);
    await planRelease(request, { designId: design.id, eventId: event.id, steps: [{ label: 'Croquis', days: 1 }] });

    await page.goto(`/designs/${design.id}`);
    await page.getByRole('region', { name: 'Planning de sortie' }).getByRole('button', { name: 'Modifier la sortie' }).click();
    const dialog = page.getByRole('dialog', { name: 'Modifier la sortie' });
    await expect(dialog.getByRole('combobox', { name: 'Événement' })).toContainText(event.name);

    await dialog.getByRole('button', { name: 'Ajouter une étape' }).click();
    await dialog.getByRole('textbox', { name: "Nom de l'étape 2" }).fill('Croquis');
    await dialog.getByRole('button', { name: 'Enregistrer', exact: true }).click();
    await expect(dialog).toContainText('L\'étape « Croquis » est en double.');
});

test('the planning page says when there is nothing to do', async ({ page }) => {
    await page.goto('/planning');

    const summary = page.getByRole('group', { name: 'Résumé du planning' });
    await expect(summary.getByRole('link', { name: 'Cette semaine · 0' })).toBeVisible();
    await expect(summary.getByRole('link', { name: /^En retard/ })).toHaveCount(0);
    await expect(summary).not.toContainText('Prochaine échéance');
    await expect(page.getByRole('region', { name: 'Cette semaine' })).toContainText('Rien à faire cette semaine.');
    await expect(page.getByRole('region', { name: 'En retard' })).toHaveCount(0);
    await expect(page.getByRole('region', { name: 'Plus tard' })).toHaveCount(0);
    await expect(page.getByRole('region', { name: "Vue d'ensemble" })).toContainText('Aucun événement ni sortie à venir.');
});
