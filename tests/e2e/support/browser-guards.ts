import { expect, type BrowserContext, type Page, type Request } from '@playwright/test';

type BrowserGuardOptions = {
    allowedNavigationAbortPaths?: string[];
};

type BrowserGuard = {
    assertClean: () => Promise<void>;
};

const LOOPBACK_HOSTS = new Set(['127.0.0.1']);

function isAllowedNavigationAbort(request: Request, allowedPaths: Set<string>): boolean {
    const failure = request.failure();

    if (!request.isNavigationRequest() || failure?.errorText !== 'net::ERR_ABORTED') {
        return false;
    }

    try {
        return allowedPaths.has(new URL(request.url()).pathname);
    } catch {
        return false;
    }
}

export async function installBrowserGuards(
    context: BrowserContext,
    page: Page,
    options: BrowserGuardOptions = {},
): Promise<BrowserGuard> {
    const allowedNavigationAbortPaths = new Set(options.allowedNavigationAbortPaths ?? []);
    let consoleErrors = 0;
    let pageErrors = 0;
    let requestFailures = 0;
    let externalRequests = 0;

    await context.route('**/*', async (route) => {
        let url: URL;

        try {
            url = new URL(route.request().url());
        } catch {
            externalRequests += 1;
            await route.abort('blockedbyclient');

            return;
        }

        if (!LOOPBACK_HOSTS.has(url.hostname)) {
            externalRequests += 1;
            await route.abort('blockedbyclient');

            return;
        }

        await route.continue();
    });

    await page.addInitScript(() => {
        const guardedWindow = window as Window & { __bylUnhandledRejectionCount?: number };
        guardedWindow.__bylUnhandledRejectionCount = 0;
        window.addEventListener('unhandledrejection', () => {
            guardedWindow.__bylUnhandledRejectionCount = (guardedWindow.__bylUnhandledRejectionCount ?? 0) + 1;
        });
    });

    page.on('console', (message) => {
        if (message.type() === 'error') consoleErrors += 1;
    });
    page.on('pageerror', () => {
        pageErrors += 1;
    });
    page.on('requestfailed', (request) => {
        if (!isAllowedNavigationAbort(request, allowedNavigationAbortPaths)) requestFailures += 1;
    });

    return {
        assertClean: async () => {
            const unhandledRejections = await page.evaluate(() => (
                (window as Window & { __bylUnhandledRejectionCount?: number }).__bylUnhandledRejectionCount ?? 0
            ));

            expect({
                consoleErrors,
                externalRequests,
                pageErrors,
                requestFailures,
                unhandledRejections,
            }).toEqual({
                consoleErrors: 0,
                externalRequests: 0,
                pageErrors: 0,
                requestFailures: 0,
                unhandledRejections: 0,
            });
        },
    };
}
