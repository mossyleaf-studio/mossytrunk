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
    const response = await request.put('/api/release-template', { data: { steps } });
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
    const card = page.getByRole('region', { name: 'Étapes de sortie' });

    for (const [index, [label, days]] of [['Dessins', 3], ['Impression', 2], ['Photos', 1]].entries()) {
        await card.getByRole('button', { name: 'Ajouter une étape' }).click();
        await card.getByRole('textbox', { name: `Nom de l'étape ${index + 1}` }).fill(label);
        await setDays(card, index + 1, days);
    }
    await card.getByRole('button', { name: "Monter l'étape 3" }).click();
    await expect(card.getByRole('textbox', { name: "Nom de l'étape 2" })).toHaveValue('Photos');
    await card.getByRole('button', { name: "Retirer l'étape 3" }).click();
    await card.getByRole('button', { name: 'Enregistrer les étapes' }).click();
    await expect(page.getByTestId('toast')).toContainText('Étapes de sortie enregistrées.');

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
    await expect(step('Dessins')).toContainText(span(event.startDate, 6, 4));
    await expect(step('Impression')).toContainText(span(event.startDate, 3, 2));
    await expect(step('Photos')).toContainText(span(event.startDate, 1, 1));
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

test('the planning page groups the steps left to do and lists the releases to come', async ({ page, request }) => {
    const { collection, design } = await arrangeLateNearAndFarSteps(request);

    await page.goto('/dashboard');
    await page.getByRole('navigation').getByRole('link', { name: 'Planning' }).click();
    await expect(page).toHaveURL(/\/planning$/);

    const late = page.getByRole('region', { name: 'En retard' });
    const thisWeek = page.getByRole('region', { name: 'Cette semaine' });
    const later = page.getByRole('region', { name: 'Plus tard' });
    const encrage = late.getByRole('listitem').filter({ hasText: 'Encrage' });
    await expect(encrage).toContainText(span(today, 3, 1));
    await expect(encrage).toContainText(dayBefore(inDays(1), 0));
    await expect(encrage.getByRole('link', { name: collection.name })).toHaveAttribute('href', `/designs?collection=${collection.id}`);
    await expect(thisWeek.getByRole('listitem').filter({ hasText: 'Vernis' })).toContainText(dayBefore(today, 0));
    const annonce = later.getByRole('listitem').filter({ hasText: 'Annonce' });
    await expect(annonce).toContainText(dayBefore(inDays(39), 0));
    await expect(annonce.getByRole('link', { name: design.name })).toHaveAttribute('href', `/designs/${design.id}`);

    await encrage.getByRole('checkbox', { name: 'Encrage' }).click();
    await expect(late.getByRole('listitem').filter({ hasText: 'Encrage' })).toHaveCount(0);

    const releases = page.getByRole('region', { name: 'Sorties à venir' });
    const collectionRelease = releases.getByRole('article').filter({ hasText: collection.name });
    await expect(collectionRelease.getByRole('region', { name: 'Planning de sortie' }).getByRole('listitem').filter({ hasText: 'Encrage' })).toContainText('Fait');
    await expect(releases.getByRole('article').filter({ hasText: design.name })).toContainText(dayBefore(inDays(40), 0));
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
    await expect(card.getByRole('listitem').filter({ hasText: 'Encrage' })).toContainText('En retard');
    await expect(card.getByRole('listitem').filter({ hasText: 'Vernis' })).toContainText(collection.name);
    await expect(card.getByRole('listitem').filter({ hasText: 'Annonce' })).toHaveCount(0);
    await expect(card.getByRole('link', { name: 'Voir le planning' })).toHaveAttribute('href', '/planning');
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

    await expect(page.getByRole('region', { name: 'En retard' })).toContainText('Rien en retard.');
    await expect(page.getByRole('region', { name: 'Cette semaine' })).toContainText('Rien à faire cette semaine.');
    await expect(page.getByRole('region', { name: 'Plus tard' })).toContainText('Rien de prévu plus tard.');
    await expect(page.getByRole('region', { name: 'Sorties à venir' })).toContainText('Aucune sortie à venir.');
});
