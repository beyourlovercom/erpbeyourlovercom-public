import { expect, test, type BrowserContext } from '@playwright/test';
import { syntheticUsers } from '../support/auth-helpers';
import { installBrowserGuards } from '../support/browser-guards';

async function loginWithDisposableSession(context: BrowserContext, credentials: { email: string; password: string }): Promise<void> {
    // Exercise Fortify's real HTTP session without relying on Mac Chrome's
    // flaky button-animation click stability in the legacy full E2E run.
    await context.request.get('/login');
    const result = await context.request.post('/login', {
        form: { email: credentials.email, password: credentials.password },
        headers: {
            Origin: process.env.BYL_E2E_BASE_URL ?? '',
            'Sec-Fetch-Site': 'same-origin',
        },
        maxRedirects: 0,
    });
    expect(result.status()).toBe(302);
    expect(new URL(result.headers().location).pathname).toBe('/dashboard');
}

test('Emma specialist SKU and owner-only logistics on 390px Chrome', async ({ context, page }) => {
    const guard = await installBrowserGuards(context, page);
    await page.setViewportSize({ width: 390, height: 844 });
    await loginWithDisposableSession(context, syntheticUsers().kolEmma);

    const sku = await context.request.get('/products/sku-search?q=E2E-SKU-0001', {
        headers: { Accept: 'application/json' },
    });
    expect(sku.status()).toBe(200);
    expect(JSON.stringify(await sku.json())).toContain('E2E-SKU-0001');

    await page.goto('/kol/logistics', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('.kol-logistics h2')).toHaveText('物流进度');
    await expect(page.locator('.shipment-card')).toHaveCount(1);
    await expect(page.locator('.shipment-card')).toContainText('E2E-KOL-EMMA');
    await expect(page.locator('.shipment-card')).toContainText('E2E-TRACK-EMMA');
    await expect(page.getByText('E2E-KOL-OTHER')).toHaveCount(0);
    await expect(page.getByText('E2E-KOL-MANAGER')).toHaveCount(0);
    await expect(page.getByText('E2E-CUSTOMER-PRIVATE')).toHaveCount(0);

    const width = await page.evaluate(() => ({
        viewport: window.innerWidth,
        page: document.documentElement.scrollWidth,
    }));
    expect(width.page).toBeLessThanOrEqual(width.viewport + 1);

    await page.goto('/kol/logistics?search=E2E-KOL-OTHER', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('.shipment-card')).toHaveCount(0);
    const forbidden = await context.request.get('/logistics/wait-stock', { maxRedirects: 0 });
    expect(forbidden.status()).toBe(403);
    await guard.assertClean();
});

test('manager sees all and only KOL shipments, never customer', async ({ context, page }) => {
    const guard = await installBrowserGuards(context, page);
    await page.setViewportSize({ width: 390, height: 844 });
    await loginWithDisposableSession(context, syntheticUsers().kolManager);

    await page.goto('/kol/logistics', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('.shipment-card')).toHaveCount(3);
    await expect(page.getByText('E2E-KOL-EMMA')).toHaveCount(1);
    await expect(page.getByText('E2E-KOL-OTHER')).toHaveCount(1);
    await expect(page.getByText('E2E-KOL-MANAGER')).toHaveCount(1);
    await expect(page.getByText('E2E-CUSTOMER-PRIVATE')).toHaveCount(0);
    await guard.assertClean();
});

test('another KOL specialist never inherits Emma or manager orders', async ({ context, page }) => {
    const guard = await installBrowserGuards(context, page);
    await loginWithDisposableSession(context, syntheticUsers().kolOther);
    await page.goto('/kol/logistics', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('.shipment-card')).toHaveCount(1);
    await expect(page.locator('.shipment-card')).toContainText('E2E-KOL-OTHER');
    await expect(page.getByText('E2E-KOL-EMMA')).toHaveCount(0);
    await expect(page.getByText('E2E-KOL-MANAGER')).toHaveCount(0);
    await guard.assertClean();
});
