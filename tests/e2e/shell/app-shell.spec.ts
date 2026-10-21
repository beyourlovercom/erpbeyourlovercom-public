import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test } from '@playwright/test';
import { loginThroughUi, syntheticUsers } from '../support/auth-helpers';
import { installBrowserGuards } from '../support/browser-guards';

type ApprovedMenuItem = {
    emoji: string | null;
    label_zh: string;
    level: number;
    sort: number;
    url: string;
};

const repositoryRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..');
const approvedMenu = JSON.parse(
    readFileSync(path.join(repositoryRoot, 'menu/APPROVED_MENU.json'), 'utf8'),
) as { items: ApprovedMenuItem[] };
const levelOneMenu = approvedMenu.items.filter((item) => item.level === 1).sort((a, b) => a.sort - b.sort);
const levelTwoMenu = approvedMenu.items.filter((item) => item.level === 2);

test('authenticated shell matches the approved level-one menu baseline', async ({ context, page }) => {
    const guard = await installBrowserGuards(context, page);

    await loginThroughUi(page, syntheticUsers().authorized);
    const navigation = page.getByRole('navigation', { name: 'ERP主导航' });
    const links = navigation.locator('.shell-primary-links .shell-primary-link');

    await expect(navigation).toBeVisible();
    await expect(links).toHaveCount(levelOneMenu.length);
    expect(levelOneMenu).toHaveLength(13);
    expect(levelTwoMenu).toHaveLength(57);

    for (const [index, expected] of levelOneMenu.entries()) {
        const link = links.nth(index);
        await expect(link).toHaveAttribute('href', expected.url);
        await expect(link).toContainText(expected.label_zh);
        await expect(link.locator('.shell-primary-emoji')).toHaveText(expected.emoji ?? '');
        await expect(link.locator('.shell-primary-emoji')).toHaveAttribute('aria-hidden', 'true');
        await expect(link).toHaveAccessibleName(new RegExp(expected.label_zh, 'u'));
    }

    await expect(page.locator('.shell-module-sidebar')).toHaveCount(0);
    await guard.assertClean();
});

test('sidebar collapse persists and keeps authorized child routes in the shell', async ({ context, page }) => {
    const guard = await installBrowserGuards(context, page);

    await loginThroughUi(page, syntheticUsers().authorized);
    const navigation = page.getByRole('navigation', { name: 'ERP主导航' });
    await navigation.getByRole('button', { name: '收起导航' }).click();
    await expect(navigation.getByRole('button', { name: '展开导航' })).toBeVisible();
    await expect(navigation.locator('.shell-primary-label')).toHaveCount(levelOneMenu.length);
    await expect(navigation.locator('.shell-child-links')).toHaveCount(0);
    await page.reload();
    await expect(navigation.getByRole('button', { name: '展开导航' })).toBeVisible();
    await guard.assertClean();
});

test('dashboard identity and authentication survive a page refresh', async ({ context, page }) => {
    const guard = await installBrowserGuards(context, page);
    const user = syntheticUsers().authorized;

    await loginThroughUi(page, user);
    await expect(page.getByRole('heading', { name: 'BYL ERP 应用工作台' })).toBeVisible();
    await page.getByRole('button', { name: '打开账户菜单' }).click();
    await expect(page.getByText('E2E Authorized', { exact: true })).toBeVisible();
    await expect(page.getByText(user.email, { exact: true })).toBeVisible();
    await page.reload();
    await expect(page).toHaveURL(/\/dashboard$/u);
    await expect(page.getByRole('navigation', { name: 'ERP主导航' })).toBeVisible();
    await guard.assertClean();
});

test('account dock supports mouse toggle and keyboard Escape focus restoration', async ({ context, page }) => {
    const guard = await installBrowserGuards(context, page);

    await loginThroughUi(page, syntheticUsers().authorized);
    const trigger = page.getByRole('button', { name: '打开账户菜单' });

    await trigger.click();
    await expect(page.getByRole('menu', { name: '用户账户' })).toBeVisible();
    await expect(page.getByRole('button', { name: '关闭账户菜单' })).toHaveAttribute('aria-expanded', 'true');
    await page.getByRole('button', { name: '关闭账户菜单' }).click();
    await expect(page.getByRole('menu', { name: '用户账户' })).toHaveCount(0);

    await trigger.focus();
    await page.keyboard.press('Enter');
    await expect(page.getByRole('menuitem', { name: '退出登录' })).toBeFocused();
    await page.keyboard.press('Escape');
    await expect(page.getByRole('menu', { name: '用户账户' })).toHaveCount(0);
    await expect(trigger).toBeFocused();
    await expect(trigger).toHaveAttribute('aria-expanded', 'false');
    await guard.assertClean();
});
