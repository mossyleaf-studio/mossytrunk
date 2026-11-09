import { expect } from '@playwright/test';
import { unique, uniqueDay, uniqueTypeCode } from './unique.js';

/** Arrange helpers: create data through the JSON API to keep UI tests focused. */

export async function createType(request, name = unique('Type'), { variants = [], prefixesNames = true } = {}) {
    const response = await request.post('/api/product-types', { data: { name, code: uniqueTypeCode(), variants, prefixesNames } });
    expect(response.status()).toBe(201);
    return response.json();
}

export async function defineVariants(request, type, variants) {
    const response = await request.put(`/api/product-types/${type.id}`, { data: { name: type.name, color: type.color, variants } });
    expect(response.status()).toBe(204);
    return { ...type, variants };
}

const unprefixedTypes = new WeakMap();

async function typeKeepingNames(request) {
    if (!unprefixedTypes.has(request)) {
        unprefixedTypes.set(request, await createType(request, unique('Divers'), { prefixesNames: false }));
    }
    return unprefixedTypes.get(request);
}

export async function createProduct(request, { name = unique('Produit'), sellingPrice = 400, buyingPrice = 0, variants = [], type = null } = {}) {
    type ??= variants.length ? await createType(request, unique('Divers'), { prefixesNames: false }) : await typeKeepingNames(request);
    const response = await request.post('/api/products', { data: { name, sellingPrice, variants, typeId: type.id } });
    expect(response.status()).toBe(201);
    const id = (await response.json()).id;
    if (buyingPrice > 0) {
        for (const variant of variants.length ? variants : [null]) {
            await restock(request, { id }, { variant, quantity: 100, totalPaid: buyingPrice * 100 });
        }
    }
    const displayName = type.prefixesNames ? `${type.name} ${name}` : name;
    return { id, name, displayName, sellingPrice, buyingPrice, variants, type };
}

export async function createEvent(request, { name = unique('Convention'), startDate = uniqueDay(), endDate = null, readyDaysBefore = null } = {}) {
    const response = await request.post('/api/events', {
        data: { name, location: 'Lyon', startDate, endDate: endDate ?? startDate, readyDaysBefore },
    });
    expect(response.status()).toBe(201);
    return { id: (await response.json()).id, name, startDate, endDate: endDate ?? startDate };
}

export async function createDiscountRule(request, { name = unique('Remise'), conditions, action }) {
    const response = await request.post('/api/discount-rules', { data: { name, conditions, action } });
    expect(response.status()).toBe(201);
    return { id: (await response.json()).id, name };
}

export async function forgetService(request, service) {
    const response = await request.delete(`/api/services/${service}`);
    expect([204, 404]).toContain(response.status());
}

export async function addService(request, service, fields, options = {}) {
    await forgetService(request, service);
    const response = await request.post('/api/services', { data: { service, fields, ...options } });
    expect(response.status()).toBe(201);
}

export const addSumUp = (request) => addService(request, 'sumup', { merchant_code: 'MCODE', api_key: 'sup_sk_e2e_key' });

export async function restock(request, product, { variant = null, quantity, totalPaid }) {
    const response = await request.post('/api/stock/restock', { data: { productId: product.id, variant, quantity, totalPaid } });
    expect(response.status()).toBe(204);
}
