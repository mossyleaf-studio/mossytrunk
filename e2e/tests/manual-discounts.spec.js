import { test, expect } from './support/test.js';
import { fillDate } from './support/date.js';
import { choose } from './support/select.js';
import { unique } from './support/unique.js';
import { createEvent, createProduct } from './support/api.js';

test('a manual gift discount is chosen on a new order, then withdrawn and added again on its page', async ({ page, request }) => {
    const event = await createEvent(request);
    const sticker = await createProduct(request, { name: unique('Sticker'), sellingPrice: 400 });
    const gift = unique('Cadeau');

    await page.goto('/discounts');
    await page.getByRole('button', { name: 'Nouvelle remise' }).click();
    const ruleForm = page.getByRole('dialog', { name: 'Nouvelle remise' }).locator('form');
    await ruleForm.getByLabel('Nom').fill(gift);
    await ruleForm.getByRole('switch', { name: 'Remise manuelle' }).click();
    await expect(ruleForm.getByText('Conditions', { exact: true })).toHaveCount(0);
    await ruleForm.getByRole('button', { name: 'Remise en %' }).click();
    await ruleForm.getByRole('spinbutton', { name: 'Pourcentage' }).fill('100');
    await ruleForm.getByRole('button', { name: 'Créer la remise' }).click();
    await expect(page.getByTestId(`discount-rule-${gift}`)).toContainText('Manuelle · toute la commande');

    await page.goto('/orders');
    await page.getByRole('button', { name: 'Nouvelle commande' }).click();
    const form = page.getByRole('dialog', { name: 'Nouvelle commande' }).locator('form');
    await fillDate(form.getByRole('group', { name: 'Date', exact: true }), `${event.startDate}T14:30`);
    await choose(page, form.getByRole('combobox', { name: 'Produit' }), `${sticker.name} — 4,00 €`);
    await form.getByLabel('Quantité', { exact: true }).fill('2');
    await form.getByRole('button', { name: 'Ajouter', exact: true }).click();
    await expect(form.getByTestId('order-total')).toHaveText(/8,00/);
    await form.getByRole('button', { name: gift }).click();
    await expect(form.getByTestId('order-total')).toHaveText(/0,00/);
    await form.getByRole('button', { name: 'Enregistrer la commande' }).click();

    const toast = page.getByTestId('toast');
    await expect(toast).toContainText(/Commande CMD-\d{8}-\w{6} enregistrée\./);
    const reference = (await toast.textContent()).match(/CMD-\d{8}-\w{6}/)[0];
    await page.getByRole('dialog').getByRole('button', { name: 'Fermer' }).click();
    await page.getByRole('row').filter({ hasText: reference }).getByRole('link', { name: reference }).click();

    await expect(page.getByTestId('order-total')).toHaveText(/0,00/);
    await page.getByRole('button', { name: `Retirer ${gift}` }).click();
    await expect(page.getByTestId('order-total')).toHaveText(/8,00/);

    await page.getByRole('button', { name: 'Ajouter une remise' }).click();
    await page.getByRole('dialog', { name: 'Ajouter une remise' }).getByRole('button', { name: 'Appliquer' }).click();
    await expect(page.getByTestId('toast').last()).toContainText(`Remise « ${gift} » appliquée.`);
    await expect(page.getByTestId('order-total')).toHaveText(/0,00/);
    await expect(page.getByRole('button', { name: 'Ajouter une remise' })).toHaveCount(0);
});
