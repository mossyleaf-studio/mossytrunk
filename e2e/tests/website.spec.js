import { test, expect } from './support/test.js';
import { addService, createProduct, createType, forgetService, restock } from './support/api.js';

async function createShopProducts(request) {
    const type = await createType(request, 'Papeterie du site', { prefixesNames: false });
    const ids = [];
    for (const product of [{ name: 'Sticker Mousse du site', reference: 'WEB-STICKER', variants: [] }, { name: 'Print Forêt du site', reference: 'WEB-PRINT', variants: ['A4', 'A3'] }]) {
        const response = await request.post('/api/products', { data: { ...product, sellingPrice: 400, typeId: type.id } });
        expect(response.status()).toBe(201);
        ids.push((await response.json()).id);
    }
    return { sticker: { id: ids[0] }, print: { id: ids[1] } };
}

test('add the website in the connected services and import the orders of its shop', async ({ page, request }) => {
    await createShopProducts(request);

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

test('publish the catalogue to the website after importing the sales of its shop', async ({ page, request }) => {
    const { sticker } = await createShopProducts(request);
    await restock(request, sticker, { quantity: 10, totalPaid: 1000 });
    await addService(request, 'website', { address: 'https://boutique.exemple.fr', token: 'jeton-du-site-0123456789abcdef' });

    await page.goto('/settings');
    const services = page.getByRole('region', { name: 'Services connectés' });
    await services.getByRole('button', { name: 'Publier le catalogue' }).click();
    const confirmation = page.getByRole('alertdialog', { name: 'Publier le catalogue' });
    await expect(confirmation).toContainText('Le catalogue de Site web est remplacé par celui de MossyTrunk');
    await expect(confirmation).toContainText('retirés de la boutique');
    await expect(confirmation).toContainText('arrivent masqués');
    await expect(confirmation).toContainText('Les ventes du site sont importées juste avant');
    await confirmation.getByRole('button', { name: 'Publier le catalogue' }).click();
    await expect(page.getByTestId('toast').last()).toContainText('Catalogue publié sur Site web : 2 nouveaux produits');
    await expect(page.getByTestId('toast').last()).toContainText('2 commandes du site importées avant l\'envoi');
    await expect(page.getByTestId('toast').last()).not.toContainText('Descriptions');

    await services.getByRole('button', { name: 'Publier le catalogue' }).click();
    await page.getByRole('alertdialog').getByRole('button', { name: 'Publier le catalogue' }).click();
    await expect(page.getByTestId('toast').last()).toContainText('Catalogue publié sur Site web : 0 nouveau produit, 2 mis à jour, 0 retiré de la boutique.');

    const stock = await (await request.get(`/api/products/${sticker.id}/stock`)).json();
    expect(stock.map((item) => item.onHand)).toEqual([8]);
});

test('products created from the orders of the site are announced when the catalogue is published', async ({ page, request }) => {
    await addService(request, 'website', { address: 'https://boutique.exemple.fr', token: 'jeton-du-site-0123456789abcdef' }, { unknownItems: 'create_product' });

    await page.goto('/settings');
    const services = page.getByRole('region', { name: 'Services connectés' });
    await services.getByRole('button', { name: 'Publier le catalogue' }).click();
    await page.getByRole('alertdialog').getByRole('button', { name: 'Publier le catalogue' }).click();

    await expect(page.getByTestId('toast').last()).toContainText('2 produits ont été créés à partir des commandes du site et envoyés avec le catalogue.');
    await expect(services.getByRole('button', { name: 'Publier le catalogue' })).toBeEnabled();
    await expect(services.getByRole('button', { name: 'Importer les ventes' })).toBeEnabled();
});

async function connectEtsy(request, keystring) {
    await addService(request, 'etsy', { keystring, shared_secret: 'shared-secret' });
    expect((await request.get('/settings/etsy/connect')).ok()).toBe(true);
}

async function publishCatalogue(page) {
    await page.goto('/settings');
    await page.getByRole('region', { name: 'Services connectés' }).getByRole('button', { name: 'Publier le catalogue' }).click();
    await page.getByRole('alertdialog').getByRole('button', { name: 'Publier le catalogue' }).click();
    return page.getByTestId('toast').last();
}

test('the publication says what happened to the Etsy descriptions', async ({ page, request }) => {
    await createProduct(request, { name: 'Sticker Mousse', sellingPrice: 400 });
    await addService(request, 'website', { address: 'https://boutique.exemple.fr', token: 'jeton-du-site-0123456789abcdef' });

    await connectEtsy(request, 'unreadable');
    let toast = await publishCatalogue(page);
    await expect(toast).toContainText('Catalogue publié sur Site web : 1 nouveau produit');
    await expect(toast).toContainText("Etsy n'a pas pu être lu : le catalogue est parti sans description.");

    await connectEtsy(request, 'keystring123');
    toast = await publishCatalogue(page);
    await expect(toast).toContainText("Descriptions Etsy : 2 annonces lues, 0 produit décrit sur 1. Pour les autres : 1 produit qu'aucune annonce n'atteint, 2 annonces associées à aucun produit.");

    await page.getByRole('region', { name: 'Services connectés' }).getByRole('button', { name: 'Importer le catalogue' }).click();
    await expect(page.getByTestId('toast').last()).toContainText('Catalogue Etsy importé');
    toast = await publishCatalogue(page);
    await expect(toast).toContainText('Descriptions Etsy : 2 annonces lues, 1 produit décrit sur 1.');
    await expect(toast).not.toContainText('Pour les autres');
});
