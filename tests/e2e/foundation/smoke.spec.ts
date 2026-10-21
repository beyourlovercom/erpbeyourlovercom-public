import { expect, test } from '@playwright/test';

test('public login loads built assets through Inertia without external traffic or console errors', async ({
    context,
    page,
}) => {
    const externalRequests: string[] = [];
    const consoleErrors: string[] = [];
    const pageErrors: string[] = [];
    const builtAssets = new Set<string>();

    await context.route('**/*', async (route) => {
        const requestUrl = new URL(route.request().url());

        if (!['127.0.0.1', 'localhost', '::1'].includes(requestUrl.hostname)) {
            externalRequests.push(requestUrl.origin);
            await route.abort('blockedbyclient');

            return;
        }

        await route.continue();
    });

    page.on('console', (message) => {
        if (message.type() === 'error') {
            consoleErrors.push(message.text());
        }
    });
    page.on('pageerror', (error) => pageErrors.push(error.message));
    page.on('response', (response) => {
        const url = new URL(response.url());

        if (url.pathname.startsWith('/build/assets/') && response.ok()) {
            builtAssets.add(url.pathname);
        }
    });

    const response = await page.goto('/login');
    const html = await response?.text();

    expect(response?.ok()).toBe(true);
    await expect(page.getByLabel('邮箱')).toBeVisible();
    await expect(page.locator('input[name="password"]')).toBeVisible();
    await expect(page.getByRole('button', { name: '登录系统' })).toBeVisible();

    expect(html).toContain('data-page=');
    const inertiaPage = await page.locator('script[data-page="app"]').textContent();
    expect(inertiaPage).not.toBeNull();
    expect(JSON.parse(inertiaPage ?? '{}').component).toBe('Auth/Login');
    expect(builtAssets.size).toBeGreaterThan(0);
    expect(externalRequests).toEqual([]);
    expect(consoleErrors).toEqual([]);
    expect(pageErrors).toEqual([]);
    console.log('FOUNDATION_SMOKE_EXTERNAL_REQUESTS=0');
});
