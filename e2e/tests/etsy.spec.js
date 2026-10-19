import { test, expect } from './support/test.js';
import { choose } from './support/select.js';
import { createProduct, forgetService } from './support/api.js';

test('connect the Etsy shop, import its orders and link an unknown listing to a bundle of products', async ({ page, request }) => {
    await createProduct(request, { name: 'Sticker Mousse', sellingPrice: 400 });
    const print = await createProduct(request, { name: 'Print Forêt Etsy', sellingPrice: 1_800, variants: ['A4', 'A3'] });

    await forgetService(request, 'etsy');
    await page.goto('/settings');
    await page.getByRole('button', { name: 'Ajouter un service' }).click();
    await page.getByRole('dialog', { name: 'Ajouter un service' }).getByRole('button', { name: /^Etsy/ }).click();
    const form = page.getByRole('dialog', { name: 'Ajouter Etsy' });
    await expect(form.locator('code')).toContainText('/settings/etsy/callback');
    await form.getByRole('textbox', { name: 'Keystring', exact: true }).fill('keystring123');
    await form.locator('input[type="password"]').fill('shared-secret');
    await form.getByRole('button', { name: 'Ajouter Etsy' }).click();
    await expect(page.getByTestId('toast').last()).toContainText('Etsy ajouté.');

    const services = page.getByRole('region', { name: 'Services connectés' });
    await expect(services).toContainText('À connecter');
    await services.getByRole('link', { name: 'Connecter la boutique' }).click();
    await expect(page.getByTestId('toast').last()).toContainText('Etsy connecté');
    await expect(services).toContainText('Atelier Mousse sur Etsy');

    await services.getByRole('button', { name: 'Importer le catalogue' }).click();
    await expect(page.getByTestId('toast').last()).toContainText('Catalogue Etsy importé');
    await services.getByRole('button', { name: 'Écrire les références sur Etsy' }).click();
    await page.getByRole('alertdialog').getByRole('button', { name: 'Écrire les références' }).click();
    await expect(page.getByTestId('toast').last()).toContainText('Etsy :');

    await services.getByRole('button', { name: 'Importer les ventes' }).click();
    await expect(page.getByTestId('toast').last()).toContainText('1 commande importée');
    await services.getByRole('button', { name: /articles? à associer/ }).click();

    const linker = page.getByRole('dialog', { name: 'Articles Etsy' });
    const a4 = linker.getByRole('listitem').filter({ hasText: /^Illustration forêt/ }).filter({ has: page.getByText('A4', { exact: true }) });
    await a4.getByRole('button', { name: 'Associer', exact: true }).click();
    await choose(page, a4.getByRole('combobox', { name: 'Produit' }), `${print.name} — 18,00 €`);
    await expect(a4.getByRole('group', { name: 'Variante' }).getByRole('button', { name: 'A4' })).toHaveAttribute('data-state', 'on');
    await a4.getByRole('button', { name: 'Ajouter', exact: true }).click();
    await choose(page, a4.getByRole('combobox', { name: 'Produit' }), 'Sticker Mousse — 4,00 €');
    await a4.getByLabel('Quantité', { exact: true }).fill('2');
    await a4.getByRole('button', { name: 'Ajouter', exact: true }).click();
    await a4.getByRole('button', { name: "Enregistrer l'association" }).click();
    await expect(page.getByTestId('toast').last()).toContainText('associé');

    await linker.getByRole('button', { name: "Relancer l'import Etsy" }).click();
    await expect(page.getByTestId('toast').last()).toContainText('1 commande importée, 1 déjà importée');
    await page.keyboard.press('Escape');
    await expect(page.getByRole('dialog')).toHaveCount(0);

    await page.goto('/orders');
    await page.getByRole('button', { name: 'Synchroniser' }).click();
    await expect(page.getByTestId('toast').last()).toContainText('0 commande importée, 2 déjà importées');

    await page.getByLabel('Rechercher une commande').fill('ETSY-3100000001');
    await page.getByRole('row').filter({ hasText: 'ETSY-3100000001' }).getByRole('link', { name: /^CMD-/ }).click();
    await expect(page.getByText('Boutique Etsy · importée depuis Etsy')).toBeVisible();
    await expect(page.getByText('Frais de port')).toBeVisible();
    await expect(page.getByTestId('order-total')).toContainText('27,50');
});
