import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test, type APIResponse } from '@playwright/test';
import { authenticateUnauthorizedThroughFortify, loginThroughUi, syntheticUsers } from '../support/auth-helpers';
import { installBrowserGuards } from '../support/browser-guards';

type ApprovedMenuItem = {
    label_zh: string;
    url: string;
};

const repositoryRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..');
const approvedMenu = JSON.parse(
    readFileSync(path.join(repositoryRoot, 'menu/APPROVED_MENU.json'), 'utf8'),
) as { items: ApprovedMenuItem[] };
const SENSITIVE_ERROR_MARKERS = [
    /SQLSTATE/iu,
    /(?:Exception|Error)\s+in\s+\/[^\s]+/iu,
    /\/Users\//u,
    /\/var\/www\//u,
    /APP_KEY/iu,
    /DB_PASSWORD/iu,
    /stack trace/iu,
];
const APP_SHELL_MARKERS = [
    'ERP主导航',
    'shellNavigation',
    'authenticated-shell',
    'shell-primary-nav',
    'shell-account-dock',
];
const SUPER_ADMIN_MARKERS = ['super_admin', 'super-admin', 'Super Admin'];
const APPROVED_MENU_MARKERS = approvedMenu.items.flatMap((item) => [item.label_zh, item.url]);

function assertBodyExcludes(body: string, markers: Array<RegExp | string>, failureMessage: string): void {
    for (const marker of markers) {
        const matched = typeof marker === 'string' ? body.includes(marker) : marker.test(body);

        if (matched) throw new Error(failureMessage);
    }
}

async function expectSafeErrorResponse(response: APIResponse, status: 403 | 404): Promise<string> {
    expect(response.status()).toBe(status);
    const text = await response.text();

    assertBodyExcludes(text, SENSITIVE_ERROR_MARKERS, 'Error response disclosed a prohibited sensitive marker.');

    return text;
}

test('super admin can access the permission-protected dashboard', async ({ context, page }) => {
    const guard = await installBrowserGuards(context, page);

    await loginThroughUi(page, syntheticUsers().authorized);

    await expect(page).toHaveURL(/\/dashboard$/u);
    await expect(page.getByRole('navigation', { name: 'ERP主导航' })).toBeVisible();
    await guard.assertClean();
});

test('active unauthorized user authenticates but receives real 403 responses', async ({ context, page }) => {
    const guard = await installBrowserGuards(context, page);
    const loginStatus = await authenticateUnauthorizedThroughFortify(context, syntheticUsers().unauthorized);

    expect(loginStatus).toBe(302);
    const dashboardBody = await expectSafeErrorResponse(await context.request.get('/dashboard'), 403);
    const settingsBody = await expectSafeErrorResponse(await context.request.get('/settings'), 403);

    for (const body of [dashboardBody, settingsBody]) {
        assertBodyExcludes(body, APP_SHELL_MARKERS, 'Unauthorized response exposed an App Shell marker.');
        assertBodyExcludes(body, APPROVED_MENU_MARKERS, 'Unauthorized response exposed an approved menu marker.');
        assertBodyExcludes(body, SUPER_ADMIN_MARKERS, 'Unauthorized response exposed a Super Admin marker.');
    }
    await guard.assertClean();
});

test('an unknown route returns a safe real 404 response', async ({ context, page }) => {
    const guard = await installBrowserGuards(context, page);

    await loginThroughUi(page, syntheticUsers().authorized);
    await expectSafeErrorResponse(await context.request.get('/__e2e-not-found__'), 404);
    await guard.assertClean();
});
