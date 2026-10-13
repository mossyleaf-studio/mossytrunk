import { test, expect } from './support/test.js';
import { fillDate, fillDateRange } from './support/date.js';
import { unique, uniqueDay } from './support/unique.js';

test('schedule an event and track its expenses', async ({ page }) => {
    const name = unique('Japan Expo');
    const start = uniqueDay();
    const end = uniqueDay(2);

    await page.goto('/events');
    await page.getByRole('button', { name: 'Nouveau marché ou salon' }).click();
    const form = page.getByRole('dialog', { name: 'Nouveau marché ou salon' }).locator('form');
    await form.getByLabel('Nom').fill(name);
    await form.getByLabel('Lieu').fill('Villepinte');
    await fillDateRange(form.getByRole('group', { name: 'Dates', exact: true }), start, end);
    await form.getByRole('button', { name: "Créer l'événement" }).click();

    await expect(page.getByTestId('toast')).toContainText(`Événement « ${name} » créé.`);
    await page.getByRole('link', { name }).click();

    await expect(page.getByRole('heading', { level: 1, name })).toBeVisible();
    for (const [label, amount] of [['Stand', '300'], ['Train', '89,50']]) {
        await page.getByRole('button', { name: 'Ajouter une dépense' }).click();
        const expense = page.getByRole('dialog', { name: 'Nouvelle dépense' });
        await expense.getByLabel('Libellé').fill(label);
        await expense.getByLabel('Montant').fill(amount);
        await expense.getByRole('button', { name: 'Ajouter la dépense' }).click();
        await expect(expense).toHaveCount(0);
    }

    await expect(page.getByRole('row', { name: /Total/ })).toContainText('389,50');

    await page.getByRole('button', { name: 'Modifier Stand' }).click();
    const edit = page.getByRole('dialog', { name: 'Modifier la dépense' });
    await expect(edit.getByLabel('Montant')).toHaveValue(/^300,00/);
    await edit.getByLabel('Montant').fill('320');
    await edit.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(page.getByTestId('toast').last()).toContainText('Dépense « Stand » modifiée.');
    await expect(page.getByRole('row', { name: /Total/ })).toContainText('409,50');

    await page.getByRole('button', { name: 'Supprimer Train' }).click();
    await expect(page.getByRole('row', { name: /Total/ })).toContainText('320,00');
});

test('share an expense with the next event', async ({ page, request }) => {
    const { createEvent } = await import('./support/api.js');
    const first = await createEvent(request, { startDate: uniqueDay() });
    const second = await createEvent(request, { startDate: uniqueDay(3) });

    await page.goto(`/events/${first.id}`);
    await page.getByRole('button', { name: 'Ajouter une dépense' }).click();
    const expense = page.getByRole('dialog', { name: 'Nouvelle dépense' });
    await expense.getByLabel('Libellé').fill('Nappe');
    await expense.getByLabel('Montant').fill('100');
    await expense.getByRole('switch', { name: 'Répartir sur plusieurs événements' }).click();
    await expense.getByRole('spinbutton', { name: "Nombre d'événements" }).fill('10');
    await expense.getByRole('button', { name: 'Ajouter la dépense' }).click();
    await expect(expense).toHaveCount(0);
    await expect(page.getByRole('row', { name: /Nappe/ })).toContainText('partagée sur 2 événements');
    await expect(page.getByRole('row', { name: /Total/ })).toContainText('50,00');

    await page.goto(`/events/${second.id}`);
    const shared = page.getByRole('row', { name: /Nappe/ });
    await expect(shared).toContainText(`Dépense de ${first.name}`);
    await expect(shared).toContainText('50,00');
    await expect(shared.getByRole('button', { name: 'Modifier Nappe' })).toHaveCount(0);
});

test('record donations received at an event', async ({ page, request }) => {
    const { createEvent } = await import('./support/api.js');
    const event = await createEvent(request, { startDate: uniqueDay() });

    await page.goto(`/events/${event.id}`);
    for (const [label, amount] of [['Boîte à dons', '25'], ['', '5']]) {
        await page.getByRole('button', { name: 'Ajouter un don' }).click();
        const donation = page.getByRole('dialog', { name: 'Nouveau don' });
        await donation.getByLabel('Montant').fill(amount);
        await donation.getByLabel('Libellé').fill(label);
        await donation.getByRole('button', { name: 'Ajouter le don' }).click();
        await expect(donation).toHaveCount(0);
    }

    const donations = page.getByRole('region', { name: 'Dons' });
    await expect(donations.getByRole('row', { name: /Total/ })).toContainText('30,00');
    await donations.getByRole('button', { name: 'Supprimer Don', exact: true }).click();
    await expect(page.getByTestId('toast').last()).toContainText('Don de 5,00');
    await expect(donations.getByRole('row', { name: /Total/ })).toContainText('25,00');
});

test('refuses overlapping events', async ({ page }) => {
    const day = uniqueDay();
    await page.goto('/events');
    const form = page.getByRole('dialog').locator('form');

    for (const name of [unique('Premier'), unique('Second')]) {
        await page.getByRole('button', { name: 'Nouveau marché ou salon' }).click();
        await form.getByLabel('Nom').fill(name);
        await form.getByLabel('Lieu').fill('Lyon');
        await fillDateRange(form.getByRole('group', { name: 'Dates', exact: true }), day, day);
        await form.getByRole('button', { name: "Créer l'événement" }).click();
        await expect(page.getByTestId('toast').or(form.getByRole('alert')).first()).toBeVisible();
    }

    await expect(form.getByRole('alert')).toContainText('chevauchent');
});

test('event report details expenses, orders, URSSAF and the result', async ({ page, request }) => {
    const { createEvent, createProduct } = await import('./support/api.js');
    const event = await createEvent(request);
    const print = await createProduct(request, { name: unique('Print'), sellingPrice: 1_500, buyingPrice: 500 });
    const mystery = await createProduct(request, { name: unique('Mystère'), sellingPrice: 1_000, buyingPrice: 0 });
    await request.post(`/api/events/${event.id}/expenses`, { data: { label: 'Stand', amount: 10_000 } });
    for (const lines of [
        [{ productId: print.id, variant: null, quantity: 10 }],
        [{ productId: mystery.id, variant: null, quantity: 1 }],
    ]) {
        const response = await request.post('/api/orders', { data: { placedAt: `${event.startDate}T11:00`, lines } });
        expect(response.status()).toBe(201);
    }

    await page.goto(`/events/${event.id}`);
    const report = page.locator('.event-report');
    await expect(report.locator('.receipt__line')).toHaveText([
        /Chiffre d'affaires\s*160,00/,
        /Coût d'achat\s*−\s*50,00/,
        /Dépenses\s*−\s*100,00/,
        /URSSAF.*−\s*20,48/,
        /Résultat\s*=\s*−10,48/,
    ]);

    // CA 160 − coût 50 − dépenses 100 − URSSAF 20,48 = −10,48
    await expect(report.getByTestId('event-result')).toHaveText(/−10,48|-10,48/);

    const recap = report.getByTestId('order-recap');
    await recap.getByText(mystery.type.name, { exact: true }).click();
    await expect(recap.locator('.order-recap__row', { hasText: mystery.name }).getByRole('img', { name: "Coût d'achat inconnu (0 €)" })).toBeVisible();

    // The article list can be hidden, and the choice is remembered.
    await report.getByLabel('Afficher le détail des articles').uncheck();
    await expect(recap).toHaveCount(0);
    await page.reload();
    await expect(report.getByLabel('Afficher le détail des articles')).not.toBeChecked();
    await report.getByLabel('Afficher le détail des articles').check();

    await report.getByRole('link', { name: 'Voir les commandes' }).click();
    await expect(page.getByRole('combobox', { name: 'Événement' })).toHaveText(event.name);
    await expect(page.getByRole('row')).toHaveCount(4); // header + day + 2 orders
});

test('order recap groups sales by type, product and variant', async ({ page, request }) => {
    const { createEvent, createProduct, createType } = await import('./support/api.js');
    const event = await createEvent(request);
    const printType = await createType(request, unique('Print'));
    const foret = await createProduct(request, { name: 'Forêt', sellingPrice: 1_500, variants: ['A4', 'A3'], type: printType });
    const response = await request.post('/api/orders', {
        data: {
            placedAt: `${event.startDate}T11:00`,
            lines: [
                { productId: foret.id, variant: 'A4', quantity: 2 },
                { productId: foret.id, variant: 'A3', quantity: 1 },
            ],
        },
    });
    expect(response.status()).toBe(201);

    await page.goto(`/events/${event.id}`);
    const recap = page.getByTestId('order-recap');
    const group = recap.locator('.order-recap__group', { hasText: printType.name });
    await expect(group.locator('.order-recap__row--group')).toContainText('3 art.');
    await group.locator('.order-recap__row--group').click();
    const product = group.locator('.order-recap__product', { hasText: foret.displayName });
    await product.locator('.order-recap__row--product').click();
    await expect(product.locator('.order-recap__row--variant', { hasText: 'A4' })).toContainText('30,00');
    await expect(product.locator('.order-recap__row--variant', { hasText: 'A3' })).toContainText('15,00');
});

test('pick a multi-day range from the calendar', async ({ page }) => {
    let start = uniqueDay();
    while (Number(start.slice(8)) > 25) {
        start = uniqueDay();
    }
    const end = uniqueDay(2);
    const name = unique('Festival');

    await page.goto('/events');
    await page.getByRole('button', { name: 'Nouveau marché ou salon' }).click();
    const form = page.getByRole('dialog', { name: 'Nouveau marché ou salon' }).locator('form');
    await form.getByLabel('Nom').fill(name);
    await form.getByLabel('Lieu').fill('Nantes');
    const dates = form.getByRole('group', { name: 'Dates', exact: true });
    await fillDate(dates, start);
    await dates.getByRole('button', { name: 'Ouvrir le calendrier' }).click();
    await page.locator(`.date-picker__content [data-value="${end}"]`).click();
    await page.keyboard.press('Escape');
    await form.getByRole('button', { name: "Créer l'événement" }).click();

    await expect(page.getByTestId('toast')).toContainText(`Événement « ${name} » créé.`);
    await expect(page.getByRole('link', { name: new RegExp(name) })).toContainText('→');
});
