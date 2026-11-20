import { test, expect } from './support/test.js';
import { createProduct, createType, forgetService } from './support/api.js';

test('add the website in the connected services and import the orders of its shop', async ({ page, request }) => {
    const type = await createType(request, 'Papeterie du site', { prefixesNames: false });
    for (const product of [{ name: 'Sticker Mousse du site', reference: 'WEB-STICKER', variants: [] }, { name: 'Print Forêt du site', reference: 'WEB-PRINT', variants: ['A4', 'A3'] }]) {
        const response = await request.post('/api/products', { data: { ...product, sellingPrice: 400, typeId: type.id } });
        expect(response.status()).toBe(201);
    }

    await forgetService(request, 'website');
    await page.goto('/settings');
    await expect(page.getByText('Aucun service. Ajoutez Etsy, SumUp ou Site web pour importer vos ventes.')).toBeVisible();
    await page.getByRole('button', { name: 'Ajouter un service' }).click();
    await page.getByRole('dialog', { name: 'Ajouter un service' }).getByRole('button', { name: /^Site web/ }).click();
    const form = page.getByRole('dialog', { name: 'Ajouter Site web' });
    await expect(form.getByRole('radio', { name: /En ligne/ })).toBeChecked();
    await expect(form.getByRole('radio', { name: /Me demander/ })).toBeChecked();

    await form.getByRole('textbox', { name: 'Adresse du site' }).fill('https://boutique.exemple.fr/admin');
    await form.locator('input[type="password"]').fill('jeton-du-site-0123456789abcdef');
    await form.getByRole('button', { name: 'Ajouter Site web' }).click();
    await expect(form).toContainText("Indiquez seulement l'adresse https du site, comme https://exemple.fr.");

    await form.getByRole('textbox', { name: 'Adresse du site' }).fill('https://boutique.exemple.fr');
    await form.getByRole('button', { name: 'Ajouter Site web' }).click();
    await expect(page.getByTestId('toast').last()).toContainText('Site web ajouté.');

    const services = page.getByRole('region', { name: 'Services connectés' });
    await expect(services).toContainText('Prêt');
    await services.getByRole('button', { name: 'Importer les ventes' }).click();
    await expect(page.getByTestId('toast').last()).toContainText('2 commandes importées');
    await services.getByRole('button', { name: 'Importer les ventes' }).click();
    await expect(page.getByTestId('toast').last()).toContainText('0 commande importée, 2 déjà importées');

    await page.goto('/orders');
    await page.getByLabel('Rechercher une commande').fill('ML-3D8H-4TNC');
    await expect(page.getByRole('row').filter({ hasText: 'ML-3D8H-4TNC' })).toContainText('Annulée');
    await page.getByLabel('Rechercher une commande').fill('ML-7K2P-9QXA');
    await page.getByRole('row').filter({ hasText: 'ML-7K2P-9QXA' }).getByRole('link', { name: /^CMD-/ }).click();
    await expect(page.getByText('Boutique Site web · importée depuis Site web')).toBeVisible();
    await expect(page.getByRole('row').filter({ hasText: 'Print Forêt du site — A4' })).toContainText('18,00');
    await expect(page.getByText('Frais de port')).toBeVisible();
    await expect(page.getByTestId('order-total')).toContainText('30,50');
});
