import { expect, test, type Cookie } from '@playwright/test';
import { loginThroughUi, syntheticUsers } from '../support/auth-helpers';
import { installBrowserGuards } from '../support/browser-guards';

function authenticationCookie(cookies: Cookie[]): Cookie {
    const cookie = cookies.find((candidate) => candidate.httpOnly);

    if (!cookie) {
        throw new Error('The authenticated session cookie was not created.');
    }

    return cookie;
}

test('POST logout invalidates the session and rejects replay of the old cookie', async ({ browser, context, page }) => {
    const guard = await installBrowserGuards(context, page);

    await loginThroughUi(page, syntheticUsers().authorized);
    const oldCookie = authenticationCookie(await context.cookies());
    await page.getByRole('button', { name: '打开账户菜单' }).click();
    const logoutRequest = page.waitForRequest((request) => (
        new URL(request.url()).pathname === '/logout' && request.method() === 'POST'
    ));
    await Promise.all([
        page.waitForURL((url) => url.pathname === '/'),
        page.getByRole('menuitem', { name: '退出登录' }).click(),
    ]);
    await logoutRequest;
    await page.goto('/dashboard');
    await expect(page).toHaveURL(/\/login$/u);
    await guard.assertClean();

    const replayContext = await browser.newContext();
    const replayPage = await replayContext.newPage();
    const replayGuard = await installBrowserGuards(replayContext, replayPage);

    try {
        await replayContext.addCookies([oldCookie]);
        await replayPage.goto('/dashboard');
        await expect(replayPage).toHaveURL(/\/login$/u);
        await replayGuard.assertClean();
    } finally {
        await replayContext.clearCookies();
        await replayContext.close();
    }
});

test('a fresh test context does not inherit authentication', async ({ context, page }) => {
    const guard = await installBrowserGuards(context, page);

    await page.goto('/dashboard');

    await expect(page).toHaveURL(/\/login$/u);
    await guard.assertClean();
});
