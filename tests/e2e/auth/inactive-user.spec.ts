import { expect, test } from '@playwright/test';
import { expectGenericLoginFailure, syntheticUsers } from '../support/auth-helpers';
import { installBrowserGuards } from '../support/browser-guards';

test('inactive account returns the generic error without exposing account state', async ({ context, page }) => {
    const guard = await installBrowserGuards(context, page);

    await expectGenericLoginFailure(page, syntheticUsers().inactive);

    await expect(page.getByText(/inactive|停用|disabled/iu)).toHaveCount(0);
    await guard.assertClean();
});
