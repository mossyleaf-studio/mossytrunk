import { test, expect } from './support/test.js';
import { choose } from './support/select.js';
import { fillDate } from './support/date.js';
import { unique } from './support/unique.js';
import { createEvent, createType } from './support/api.js';

test('a design drops A4 from its Print declination, and a discount on A3 prints only applies to A3 lines', async ({ page, request }) => {
    const printType = await createType(request, unique('Print'), { variants: ['A5', 'A4', 'A3'] });
    const gabarit = unique('Tirage');
    const design = unique('Clairière');
    const rule = unique('10 % sur les prints A3');
    const event = await createEvent(request);

    await page.goto('/designs');
    await page.getByRole('button', { name: 'Gabarits' }).click();
    const gabarits = page.getByRole('dialog', { name: 'Gabarits' });
    await gabarits.getByLabel('Nom du gabarit').fill(gabarit);
    await choose(page, gabarits.getByRole('combobox', { name: 'Type', exact: true }), printType.name);
    await gabarits.getByLabel('Prix de vente').fill('20');
    const offered = gabarits.getByRole('group', { name: 'Variantes proposées' });
    for (const variant of ['A5', 'A4', 'A3']) {
        await offered.getByRole('button', { name: variant, exact: true }).click();
    }
    await gabarits.getByRole('button', { name: 'Ajouter le gabarit' }).click();
    await expect(gabarits.getByRole('listitem').filter({ hasText: gabarit })).toContainText('A5, A4, A3');
    await gabarits.getByRole('button', { name: 'Fermer' }).click();

    await page.getByRole('button', { name: 'Nouveau design' }).click();
    const designForm = page.getByRole('dialog', { name: 'Nouveau design' }).locator('form');
    await designForm.getByLabel('Nom du design').fill(design);
    await designForm.getByRole('button', { name: gabarit }).click();
    await designForm.getByRole('button', { name: 'Commencer le design' }).click();

    await expect(page.getByRole('heading', { level: 1, name: design })).toBeVisible();
    const card = page.getByRole('article', { name: gabarit });
    const a4 = card.getByRole('button', { name: 'A4', exact: true });
    await expect(a4).toHaveAttribute('aria-pressed', 'true');
    await a4.click();
    await expect(a4).toHaveAttribute('aria-pressed', 'false');
    await card.getByRole('button', { name: 'Enregistrer la déclinaison' }).click();
    await expect(page.getByTestId('toast').last()).toContainText(`Déclinaison « ${gabarit} » enregistrée.`);

    await page.getByRole('button', { name: "Sortir de l'atelier" }).click();
    await page.getByRole('alertdialog').getByRole('button', { name: 'Créer les produits' }).click();
    await expect(page.getByTestId('toast').last()).toContainText('1 produit créé');
    await expect(card).toContainText('A5 / A3');

    const product = (await (await request.get('/api/products')).json()).find((candidate) => candidate.name === design);
    expect(product.typeId).toBe(printType.id);
    expect(product.variants).toEqual(['A5', 'A3']);

    await page.goto('/discounts');
    await page.getByRole('button', { name: 'Nouvelle remise' }).click();
    const ruleForm = page.getByRole('dialog', { name: 'Nouvelle remise' }).locator('form');
    await ruleForm.getByLabel('Nom').fill(rule);
    await choose(page, ruleForm.getByRole('combobox', { name: 'Type de la condition 1' }), printType.name);
    await ruleForm.getByRole('group', { name: 'Variante de la condition 1' }).getByRole('button', { name: 'A3', exact: true }).click();
    await ruleForm.getByRole('group', { name: 'Type d\'action' }).getByRole('button', { name: 'Remise en %' }).click();
    await ruleForm.getByRole('spinbutton', { name: 'Pourcentage' }).fill('10');
    await ruleForm.getByRole('spinbutton', { name: 'Pourcentage' }).press('Tab');
    await expect(ruleForm.getByTestId('discount-rule-pricing')).toContainText('20,00');
    await expect(ruleForm.getByTestId('discount-rule-pricing')).toContainText('18,00');
    await ruleForm.getByRole('button', { name: 'Créer la remise' }).click();
    await expect(page.getByTestId(`discount-rule-${rule}`)).toContainText(`1 × ${printType.name} A3 −10 %`);

    await page.goto('/orders');
    await page.getByRole('button', { name: 'Nouvelle commande' }).click();
    const orderForm = page.getByRole('dialog', { name: 'Nouvelle commande' }).locator('form');
    await fillDate(orderForm.getByRole('group', { name: 'Date', exact: true }), `${event.startDate}T14:30`);
    for (const variant of ['A3', 'A5']) {
        await choose(page, orderForm.getByRole('combobox', { name: 'Produit' }), `${product.displayName} — 20,00 €`);
        await orderForm.getByRole('group', { name: 'Variante' }).getByRole('button', { name: variant, exact: true }).click();
        await orderForm.getByRole('button', { name: 'Ajouter', exact: true }).click();
    }

    await expect(orderForm.getByText(rule)).toBeVisible();
    await expect(orderForm.getByTestId('order-total')).toHaveText(/38,00/);
});
