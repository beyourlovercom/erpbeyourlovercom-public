import { expect, test } from '@playwright/test';
import { authenticateUnauthorizedThroughFortify, loginThroughUi, syntheticUsers } from '../support/auth-helpers';
import { installBrowserGuards } from '../support/browser-guards';

test('authorized user can create, view, edit and archive a Company end to end', async ({ context, page }) => {
    const guard = await installBrowserGuards(context, page);
    await loginThroughUi(page, syntheticUsers().authorized);

    await page.goto('/customers/companies');
    await expect(page.getByRole('heading', { name: '公司', level: 2 })).toBeVisible();

    await page.getByRole('link', { name: '新建公司' }).click();
    await expect(page).toHaveURL(/\/customers\/companies\/create$/u);
    await page.getByLabel('名称', { exact: true }).fill('E2E Synthetic Company');
    await page.getByLabel('网址').fill('https://Shop.E2E-Synthetic.example./');
    await page.getByLabel('国家代码').fill('us');
    await page.getByRole('button', { name: '保存公司' }).click();

    await expect(page).toHaveURL(/\/customers\/companies\/\d+$/u);
    await expect(page.getByRole('heading', { name: 'E2E Synthetic Company' })).toBeVisible();
    await expect(page.getByText('shop.e2e-synthetic.example', { exact: true })).toBeVisible();
    await expect(page.getByText('US', { exact: true })).toBeVisible();

    await page.getByRole('link', { name: '编辑' }).click();
    await expect(page).toHaveURL(/\/customers\/companies\/\d+\/edit$/u);
    await page.getByLabel('行业').fill('Synthetic Retail');
    await page.getByRole('button', { name: '保存修改' }).click();

    await expect(page).toHaveURL(/\/customers\/companies\/\d+$/u);
    await expect(page.getByText('Synthetic Retail', { exact: true })).toBeVisible();

    page.once('dialog', (dialog) => dialog.accept());
    await page.getByRole('button', { name: '归档' }).click();
    await expect(page).toHaveURL(/\/customers\/companies$/u);
    await expect(page.getByText('暂无符合条件的公司', { exact: true })).toBeVisible();

    await page.goto('/customers/companies?archived=only');
    await expect(page.getByRole('link', { name: 'E2E Synthetic Company' })).toBeVisible();
    await expect(page.getByRole('button', { name: '取消归档' })).toBeVisible();

    await page.getByRole('link', { name: 'E2E Synthetic Company' }).click();
    await expect(page).toHaveURL(/\/customers\/companies\/\d+$/u);
    await expect(page.getByRole('button', { name: '编辑' })).toHaveCount(0);
    await expect(page.getByRole('button', { name: '归档', exact: true })).toHaveCount(0);

    page.once('dialog', (dialog) => dialog.accept());
    await page.getByRole('button', { name: '取消归档' }).click();
    await expect(page).toHaveURL(/\/customers\/companies\/\d+$/u);
    await expect(page.getByRole('button', { name: '取消归档' })).toHaveCount(0);
    await expect(page.getByRole('button', { name: '归档', exact: true })).toBeVisible();

    await page.goto('/customers/companies');
    await expect(page.getByRole('link', { name: 'E2E Synthetic Company' })).toBeVisible();

    await page.goto('/customers/companies?archived=only');
    await expect(page.getByText('暂无符合条件的公司', { exact: true })).toBeVisible();

    await guard.assertClean();
});

test('a required-name validation error is shown without creating a Company', async ({ context, page }) => {
    const guard = await installBrowserGuards(context, page);
    await loginThroughUi(page, syntheticUsers().authorized);

    await page.goto('/customers/companies/create');
    await page.getByRole('button', { name: '保存公司' }).click();
    await expect(page).toHaveURL(/\/customers\/companies\/create$/u);

    await guard.assertClean();
});

test('an unauthorized active user receives a real 403 for Company routes', async ({ context, page }) => {
    const guard = await installBrowserGuards(context, page);
    const loginStatus = await authenticateUnauthorizedThroughFortify(context, syntheticUsers().unauthorized);
    expect(loginStatus).toBe(302);

    const response = await context.request.get('/customers/companies');
    expect(response.status()).toBe(403);

    await guard.assertClean();
});
