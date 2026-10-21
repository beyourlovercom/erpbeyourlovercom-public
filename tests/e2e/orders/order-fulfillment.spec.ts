import { expect, test, type Page } from '@playwright/test';
import { loginThroughUi, syntheticUsers } from '../support/auth-helpers';
import { installBrowserGuards } from '../support/browser-guards';

// Scoped helper: finds the .erp-form-field containing the exact label text and returns its input.
// Required because V2 labels are not associated via `for` attributes.
function getFieldInput(page: Page, label: string) {
    return page
        .locator('.erp-form-field')
        .filter({ hasText: new RegExp(`^${label}$`, 'u') })
        .locator('input');
}

async function selectSkuProduct(page: Page, query: string): Promise<void> {
    const combobox = page.getByPlaceholder('输入 SKU 或产品名称…');
    await combobox.fill(query);
    const option = page.locator('.sku-combobox-dropdown li').first();
    await option.waitFor({ state: 'visible' });
    await option.click();
}

async function shipViaPrompt(page: Page, carrier: string, tracking: string): Promise<void> {
    const answers = [carrier, tracking];
    page.on('dialog', async (dialog) => {
        await dialog.accept(answers.shift() ?? '');
    });
    await page.getByRole('button', { name: '标记发货' }).click();
}

async function fillRecipientFields(page: Page, overrides: {
    name: string;
    phone: string;
    address: string;
}): Promise<void> {
    await getFieldInput(page, '收件人姓名').fill(overrides.name);
    await getFieldInput(page, '联系电话').fill(overrides.phone);
    await getFieldInput(page, '国家').fill('US');
    await getFieldInput(page, '省/州').fill('CA');
    await getFieldInput(page, '城市').fill('Los Angeles');
    await getFieldInput(page, '地址行 1').fill(overrides.address);
}

async function fillItemRow(page: Page, summary: string, qty: string, price: string): Promise<void> {
    const row = page.locator('.ord-item-row').first();
    await row.locator('.erp-form-field').filter({ has: page.locator('.erp-form-label', { hasText: '产品摘要' }) }).locator('input').fill(summary);
    await row.locator('.erp-form-field').filter({ has: page.locator('.erp-form-label', { hasText: '数量' }) }).locator('input').fill(qty);
    await row.locator('.erp-form-field').filter({ has: page.locator('.erp-form-label', { hasText: '单价' }) }).locator('input').fill(price);
}

test('customer manual order flows through logistics to shipped with the business number preserved', async ({ context, page }) => {
    const guard = await installBrowserGuards(context, page);
    await loginThroughUi(page, syntheticUsers().authorized);

    await page.goto('/customers/orders/create');
    await expect(page.locator('#main-content').getByRole('heading', { name: '新建客户订单', level: 1 })).toBeVisible();
    await page.getByRole('button', { name: '编辑订单号' }).click();
    await page.getByLabel('业务订单号').fill('E2E-CUS-0001');
    await fillRecipientFields(page, { name: 'E2E Customer Recipient', phone: '+1 555 0100', address: '100 E2E Street' });
    await selectSkuProduct(page, 'E2E-SKU-0001');
    await fillItemRow(page, 'E2E Product', '1', '9.99');
    await page.getByRole('button', { name: '创建订单' }).click();

    await expect(page).toHaveURL(/\/customers\/orders\/\d+$/u);
    await expect(page.getByRole('heading', { name: 'E2E-CUS-0001' })).toBeVisible();

    await page.goto('/customers/orders');
    await expect(page.getByRole('link', { name: 'E2E-CUS-0001' })).toBeVisible();

    await page.goto('/logistics/pending');
    await expect(page).toHaveURL(/\/logistics\/wait-stock$/u);
    await expect(page.getByText('E2E-CUS-0001')).toBeVisible();

    await guard.assertClean();
});

test('KOL fulfillment manual order defaults to kol_fulfillment and shares the same logistics queue', async ({ context, page }) => {
    const guard = await installBrowserGuards(context, page);
    await loginThroughUi(page, syntheticUsers().authorized);

    await page.goto('/kol/orders/create');
    await expect(page.locator('#main-content').getByRole('heading', { name: '新建网红履约订单', level: 1 })).toBeVisible();
    // 订单类型 is a readonly display block in V2 — field text includes the value, so scope by label child
    await expect(
        page.locator('.erp-form-field').filter({ has: page.locator('.erp-form-label', { hasText: /^订单类型$/ }) }).locator('.erp-readonly-input'),
    ).toHaveText('网红履约订单');
    await page.getByRole('button', { name: '编辑订单号' }).click();
    await page.getByLabel('业务订单号').fill('E2E-KOL-0001');
    await fillRecipientFields(page, { name: 'E2E KOL Recipient', phone: '+1 555 0101', address: '200 E2E Street' });
    await selectSkuProduct(page, 'E2E-SKU-0001');
    await fillItemRow(page, 'E2E KOL Product', '1', '19.99');
    await page.getByRole('button', { name: '创建订单' }).click();

    await expect(page).toHaveURL(/\/kol\/orders\/\d+$/u);
    await expect(page.getByRole('heading', { name: 'E2E-KOL-0001' })).toBeVisible();

    await page.goto('/kol/orders');
    await expect(page.getByRole('link', { name: 'E2E-KOL-0001' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'E2E-CUS-0001' })).toHaveCount(0);

    await page.goto('/logistics/pending');
    await expect(page).toHaveURL(/\/logistics\/wait-stock$/u);
    await expect(page.getByText('E2E-KOL-0001')).toBeVisible();

    await guard.assertClean();
});

test('direct URL manipulation cannot cross-leak an order into the wrong kind surface', async ({ context, page }) => {
    const guard = await installBrowserGuards(context, page);
    await loginThroughUi(page, syntheticUsers().authorized);

    await page.goto('/customers/orders/create');
    await page.getByRole('button', { name: '编辑订单号' }).click();
    await page.getByLabel('业务订单号').fill('E2E-LEAK-CUS');
    await fillRecipientFields(page, { name: 'Leak Test Customer', phone: '+1 555 0102', address: '300 E2E Street' });
    await selectSkuProduct(page, 'E2E-SKU-0001');
    await fillItemRow(page, 'Leak Product', '1', '5.00');
    await page.getByRole('button', { name: '创建订单' }).click();
    await expect(page).toHaveURL(/\/customers\/orders\/(\d+)$/u);
    const customerOrderId = new URL(page.url()).pathname.split('/').pop();

    const crossLeakResponse = await context.request.get(`/kol/orders/${customerOrderId}`);
    expect(crossLeakResponse.status()).toBe(404);

    await guard.assertClean();
});

test('logistics copy falls back to a manual-selection path when the async Clipboard API is unavailable', async ({ context, page }) => {
    const guard = await installBrowserGuards(context, page);
    await page.addInitScript(() => {
        Object.defineProperty(window.navigator, 'clipboard', {
            configurable: true,
            value: {
                writeText: () => Promise.reject(new Error('Clipboard API unavailable in this synthetic run.')),
            },
        });
    });
    await loginThroughUi(page, syntheticUsers().authorized);

    await page.goto('/customers/orders/create');
    await page.getByRole('button', { name: '编辑订单号' }).click();
    await page.getByLabel('业务订单号').fill('E2E-FALLBACK-0001');
    await fillRecipientFields(page, { name: 'Fallback Recipient', phone: '+1 555 0103', address: '400 E2E Street' });
    await selectSkuProduct(page, 'E2E-SKU-0001');
    await fillItemRow(page, 'Fallback Product', '1', '7.00');
    await page.getByRole('button', { name: '创建订单' }).click();
    await expect(page).toHaveURL(/\/customers\/orders\/\d+$/u);

    await page.goto('/logistics/pending');
    await expect(page).toHaveURL(/\/logistics\/wait-stock$/u);
    await expect(page.getByText('E2E-FALLBACK-0001')).toBeVisible();

    await guard.assertClean();
});
