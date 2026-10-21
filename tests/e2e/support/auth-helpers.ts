import { expect, type BrowserContext, type Page } from '@playwright/test';

type SyntheticCredentials = {
    email: string;
    password: string;
};

type SyntheticUsers = {
    authorized: SyntheticCredentials;
    unauthorized: SyntheticCredentials;
    inactive: SyntheticCredentials;
    missing: SyntheticCredentials;
    kolEmma: SyntheticCredentials;
    kolOther: SyntheticCredentials;
    kolManager: SyntheticCredentials;
};

function requireSecret(name: 'BYL_E2E_ACTIVE_PASSWORD' | 'BYL_E2E_INACTIVE_PASSWORD'): string {
    const value = process.env[name];

    if (!value || value.length < 24) {
        throw new Error(`Required synthetic credential ${name} is unavailable.`);
    }

    return value;
}

export function syntheticUsers(): SyntheticUsers {
    const runId = (process.env.BYL_E2E_RUN_ID ?? '').replace(/[^a-z0-9]/giu, '');

    if (!runId) {
        throw new Error('BYL_E2E_RUN_ID is unavailable.');
    }

    const activePassword = requireSecret('BYL_E2E_ACTIVE_PASSWORD');

    return {
        authorized: {
            email: `authorized-${runId}@byl-e2e.invalid`,
            password: activePassword,
        },
        unauthorized: {
            email: `unauthorized-${runId}@byl-e2e.invalid`,
            password: activePassword,
        },
        inactive: {
            email: `inactive-${runId}@byl-e2e.invalid`,
            password: requireSecret('BYL_E2E_INACTIVE_PASSWORD'),
        },
        missing: {
            email: `missing-${runId}@byl-e2e.invalid`,
            password: activePassword,
        },
        kolEmma: {
            email: `kol-emma-${runId}@byl-e2e.invalid`,
            password: activePassword,
        },
        kolOther: {
            email: `kol-other-${runId}@byl-e2e.invalid`,
            password: activePassword,
        },
        kolManager: {
            email: `kol-manager-${runId}@byl-e2e.invalid`,
            password: activePassword,
        },
    };
}

export async function loginThroughUi(page: Page, credentials: SyntheticCredentials): Promise<void> {
    await page.goto('/login');
    await page.getByLabel('邮箱').fill(credentials.email);
    await page.locator('input[name="password"]').fill(credentials.password);
    await Promise.all([
        page.waitForURL((url) => url.pathname === '/dashboard'),
        page.getByRole('button', { name: '登录系统' }).click(),
    ]);
}

export async function expectGenericLoginFailure(page: Page, credentials: SyntheticCredentials): Promise<void> {
    await page.goto('/login');
    await page.getByLabel('邮箱').fill(credentials.email);
    await page.locator('input[name="password"]').fill(credentials.password);
    await page.getByRole('button', { name: '登录系统' }).click();

    await expect(page.getByRole('alert')).toHaveText('错误：邮箱或密码不正确，请重试。');
    await expect(page).toHaveURL(/\/login$/u);
}

export async function authenticateUnauthorizedThroughFortify(
    context: BrowserContext,
    credentials: SyntheticCredentials,
): Promise<number> {
    await context.request.get('/login');
    const response = await context.request.post('/login', {
        form: {
            email: credentials.email,
            password: credentials.password,
        },
        headers: {
            Origin: process.env.BYL_E2E_BASE_URL ?? '',
            'Sec-Fetch-Site': 'same-origin',
        },
        maxRedirects: 0,
    });

    expect(new URL(response.headers().location).pathname).toBe('/dashboard');

    return response.status();
}
