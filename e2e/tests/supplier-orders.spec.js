import { test, expect } from './support/test.js';
import { choose } from './support/select.js';
import { unique } from './support/unique.js';
import { createProduct } from './support/api.js';

test('order from a new supplier, receive more than ordered, and stock the real unit cost', async ({ page, request }) => {
    const product = await createProduct(request, { name: unique('Print'), sellingPrice: 1_500, variants: ['A4'] });
    const sleeve = await createProduct(request, { name: unique('Pochette'), sellingPrice: 100 });
    const supplier = unique('Imprimerie');

    await page.goto('/supplier-orders');
    await page.getByRole('button', { name: 'Nouvelle commande' }).click();
    const form = page.getByRole('dialog', { name: 'Nouvelle commande fournisseur' }).locator('form');
    await choose(page, form.getByRole('combobox', { name: 'Fournisseur' }), 'Nouveau fournisseur…');
    await form.getByLabel('Nom du nouveau fournisseur').fill(supplier);
    await form.getByRole('button', { name: 'Créer', exact: true }).click();
    await expect(form.getByRole('combobox', { name: 'Fournisseur' })).toHaveText(supplier);

    await choose(page, form.getByRole('combobox', { name: 'Produit' }), sleeve.name);
    await expect(form.getByRole('group', { name: 'Variante' })).toHaveCount(0);
    await choose(page, form.getByRole('combobox', { name: 'Produit' }), product.name);
    await expect(form.getByRole('group', { name: 'Variante' }).getByRole('button', { name: 'A4' })).toHaveAttribute('data-state', 'on');
    await form.getByRole('spinbutton', { name: 'Quantité commandée' }).fill('20');
    await form.getByLabel('Prix total', { exact: true }).fill('60');
    await form.getByRole('button', { name: 'Ajouter', exact: true }).click();
    await expect(form.getByRole('row').filter({ hasText: `${product.name} — A4` })).toContainText('3,00');
    await form.getByLabel('Frais de livraison').fill('4');
    await form.getByLabel('Frais de livraison').press('Tab');
    await expect(form.getByRole('row').filter({ hasText: `${product.name} — A4` })).toContainText('3,20');
    await expect(form.getByText(/^Total payé/)).toContainText('64,00');
    await form.getByRole('button', { name: 'Passer la commande' }).click();

    await expect(page.getByTestId('toast').last()).toContainText('Commande fournisseur passée.');
    const row = page.getByRole('row').filter({ hasText: supplier });
    await expect(row).toContainText('Commandée');
    await row.getByRole('link').click();

    await expect(page.getByRole('heading', { level: 1, name: /^CMF-/ })).toBeVisible();
    await page.getByRole('button', { name: 'Déballer le colis' }).click();
    await expect(page.getByText('Ligne 1 sur 1')).toBeVisible();
    const received = page.getByRole('spinbutton', { name: 'Quantité reçue' });
    await received.fill('24');
    await received.press('Enter');
    await expect(page.getByRole('region', { name: 'Vérifier et valider' })).toContainText('+4');
    await expect(page.getByRole('region', { name: 'Vérifier et valider' })).toContainText('2,67');
    await page.getByRole('button', { name: 'Valider le déballage' }).click();

    await expect(page.getByTestId('toast').last()).toContainText('déballée');
    await expect(page.getByText('Reçue', { exact: true })).toBeVisible();

    await page.goto('/products');
    await page.getByLabel('Rechercher un produit').fill(product.name);
    const productRow = page.getByRole('row').filter({ hasText: product.name });
    await expect(productRow).toContainText('24');
    await expect(productRow).toContainText('2,67');
});

test('create a supply from the order form, correct the order once received, then merge another order into it', async ({ page, request }) => {
    const supplier = await (await request.post('/api/suppliers', { data: { name: unique('Emballages') } })).json();
    const type = await (await request.post('/api/product-types', { data: { name: unique('Emballage'), code: `E${Date.now().toString(36).slice(-5)}`.toUpperCase(), variants: [], prefixesNames: false } })).json();
    const sleeve = unique('Pochette');

    await page.goto('/supplier-orders');
    await page.getByRole('button', { name: 'Nouvelle commande' }).click();
    const form = page.getByRole('dialog', { name: 'Nouvelle commande fournisseur' }).locator('form').first();
    await choose(page, form.getByRole('combobox', { name: 'Fournisseur' }), supplier.name);
    await form.getByRole('button', { name: 'Nouveau produit' }).click();
    const productForm = page.getByRole('dialog', { name: 'Nouveau produit' });
    await productForm.getByRole('radio', { name: /^Fourniture/ }).click();
    await choose(page, productForm.getByRole('combobox', { name: 'Type' }), type.name);
    await productForm.getByLabel('Nom').fill(sleeve);
    await productForm.getByRole('button', { name: 'Ajouter le produit' }).click();
    await expect(page.getByTestId('toast').last()).toContainText(`Produit « ${sleeve} » créé`);
    await expect(form.getByRole('combobox', { name: 'Produit' })).toHaveValue(sleeve);
    await form.getByRole('spinbutton', { name: 'Quantité commandée' }).fill('100');
    await form.getByLabel('Prix total', { exact: true }).fill('10');
    await form.getByRole('button', { name: 'Ajouter', exact: true }).click();
    await form.getByRole('button', { name: 'Passer la commande' }).click();
    await expect(page.getByTestId('toast').last()).toContainText('Commande fournisseur passée.');

    const orders = (await (await request.get('/api/supplier-orders')).json()).filter((order) => order.supplier.id === supplier.id);
    const [order] = orders;
    await request.post(`/api/supplier-orders/${order.id}/reception`, { data: { lines: [{ lineId: order.lines[0].id, received: 100 }] } });

    await page.goto(`/supplier-orders/${order.id}`);
    await page.getByRole('button', { name: 'Modifier' }).click();
    const edit = page.getByRole('dialog', { name: 'Modifier la commande fournisseur' });
    await edit.getByRole('spinbutton', { name: `Quantité reçue de ${sleeve}` }).fill('80');
    await edit.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(page.getByTestId('toast').last()).toContainText('mise à jour');
    await expect(edit).toHaveCount(0);
    await expect(page.getByRole('row').filter({ hasText: sleeve })).toContainText('80');

    const other = await (await request.post('/api/supplier-orders', { data: { supplierId: supplier.id, orderedOn: '2026-09-01', lines: [{ productId: order.lines[0].productId, variant: null, quantity: 50, totalPrice: 500 }] } })).json();
    const otherLines = (await (await request.get(`/api/supplier-orders/${other.id}`)).json()).lines;
    await request.post(`/api/supplier-orders/${other.id}/reception`, { data: { lines: [{ lineId: otherLines[0].id, received: 50 }] } });

    await page.reload();
    await page.getByRole('button', { name: 'Fusionner' }).click();
    const merge = page.getByRole('dialog', { name: 'Fusionner avec une autre commande' });
    await merge.getByRole('radio').first().click();
    await merge.getByRole('button', { name: 'Fusionner' }).click();
    await expect(page.getByTestId('toast').last()).toContainText('fusionnée');
    await expect(merge).toHaveCount(0);
    await expect(page.getByRole('row').filter({ hasText: sleeve })).toContainText('130');
});

test('an order being written survives closing the drawer, and its discount can come from the total paid', async ({ page, request }) => {
    const supplier = await (await request.post('/api/suppliers', { data: { name: unique('Atelier') } })).json();
    const product = await createProduct(request, { name: unique('Badge'), sellingPrice: 500 });

    await page.goto('/supplier-orders');
    await page.getByRole('button', { name: 'Nouvelle commande' }).click();
    let form = page.getByRole('dialog', { name: 'Nouvelle commande fournisseur' }).locator('form').first();
    await choose(page, form.getByRole('combobox', { name: 'Fournisseur' }), supplier.name);
    await choose(page, form.getByRole('combobox', { name: 'Produit' }), product.name);
    await form.getByRole('spinbutton', { name: 'Quantité commandée' }).fill('10');
    await form.getByLabel('Prix total', { exact: true }).fill('60');
    await form.getByRole('button', { name: 'Ajouter', exact: true }).click();
    await form.getByRole('button', { name: 'Annuler' }).click();

    await page.getByRole('button', { name: 'Nouvelle commande' }).click();
    form = page.getByRole('dialog', { name: 'Nouvelle commande fournisseur' }).locator('form').first();
    await expect(form.getByRole('status')).toContainText('Commande reprise');
    await expect(form.getByRole('row').filter({ hasText: product.name })).toBeVisible();

    await form.getByRole('button', { name: 'Par le total payé' }).click();
    await form.getByLabel('Total réellement payé').fill('50');
    await form.getByLabel('Total réellement payé').press('Tab');
    await expect(form.getByText('Remise déduite')).toContainText('10,00');
    await form.getByRole('button', { name: 'Passer la commande' }).click();
    await expect(page.getByTestId('toast').last()).toContainText('Commande fournisseur passée.');

    const order = (await (await request.get('/api/supplier-orders')).json()).find((candidate) => candidate.supplier.id === supplier.id);
    expect([order.discount, order.total]).toEqual([1_000, 5_000]);
});
