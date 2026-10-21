import { randomBytes, randomUUID } from 'node:crypto';
import { mkdtemp, mkdir, open, realpath, rm, stat } from 'node:fs/promises';
import { existsSync, lstatSync, readFileSync, readdirSync, realpathSync } from 'node:fs';
import { createServer } from 'node:net';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawn } from 'node:child_process';

export const REPOSITORY_ROOT = realpathSync.native(
    path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..'),
);

function isWithin(parent, candidate) {
    const relative = path.relative(parent, candidate);

    return relative === '' || (!relative.startsWith(`..${path.sep}`) && relative !== '..' && !path.isAbsolute(relative));
}

function requireAbsolutePath(value, name) {
    if (typeof value !== 'string' || value.length === 0 || !path.isAbsolute(value)) {
        throw new Error(`${name} must be an absolute path.`);
    }

    return path.resolve(value);
}

export function assertNotAborted(signal) {
    if (signal?.aborted) {
        throw new Error('E2E execution was aborted before starting a child process.');
    }
}

export function resolveLocalPlaywrightCli(repositoryRoot = REPOSITORY_ROOT) {
    const packageRoot = path.join(repositoryRoot, 'node_modules', '@playwright', 'test');
    const packageFile = path.join(packageRoot, 'package.json');
    const cliFile = path.join(packageRoot, 'cli.js');

    if (!existsSync(packageFile) || !existsSync(cliFile)) {
        throw new Error('The exact local Playwright CLI is not installed.');
    }

    const packageEntry = lstatSync(packageFile);
    const cliEntry = lstatSync(cliFile);
    if (!packageEntry.isFile() || packageEntry.isSymbolicLink() || !cliEntry.isFile() || cliEntry.isSymbolicLink()) {
        throw new Error('The local Playwright CLI must use regular repository-installed files.');
    }

    const resolvedPackageRoot = realpathSync.native(packageRoot);
    const resolvedPackageFile = realpathSync.native(packageFile);
    const resolvedCliFile = realpathSync.native(cliFile);
    if (!isWithin(resolvedPackageRoot, resolvedPackageFile) || !isWithin(resolvedPackageRoot, resolvedCliFile)) {
        throw new Error('The local Playwright CLI escaped its installed package.');
    }

    const metadata = JSON.parse(readFileSync(resolvedPackageFile, 'utf8'));
    if (metadata.version !== '1.62.1') {
        throw new Error('The local Playwright CLI version must be exactly 1.62.1.');
    }

    return resolvedCliFile;
}

export function assertExternalRunRoot(value, { requireEmpty = true } = {}) {
    const candidate = requireAbsolutePath(value, 'BYL_E2E_RUN_ROOT');

    if (!existsSync(candidate)) {
        throw new Error('BYL_E2E_RUN_ROOT must already exist.');
    }

    const entry = lstatSync(candidate);

    if (!entry.isDirectory() || entry.isSymbolicLink()) {
        throw new Error('BYL_E2E_RUN_ROOT must be a real directory.');
    }

    const resolved = realpathSync.native(candidate);

    if (isWithin(REPOSITORY_ROOT, resolved)) {
        throw new Error('BYL_E2E_RUN_ROOT must be outside the Git worktree.');
    }

    if ((entry.mode & 0o077) !== 0) {
        throw new Error('BYL_E2E_RUN_ROOT must be owner-only.');
    }

    if (requireEmpty && readdirSync(resolved).length !== 0) {
        throw new Error('BYL_E2E_RUN_ROOT must be empty before initialization.');
    }

    return resolved;
}

export function assertLoopbackBaseUrl(value) {
    let url;

    try {
        url = new URL(value ?? '');
    } catch {
        throw new Error('BYL_E2E_BASE_URL must be a valid URL.');
    }

    if (url.protocol !== 'http:' || url.hostname !== '127.0.0.1' || !url.port || url.username || url.password) {
        throw new Error('BYL_E2E_BASE_URL must use http://127.0.0.1:<port>.');
    }

    if (url.pathname !== '/' || url.search || url.hash) {
        throw new Error('BYL_E2E_BASE_URL must not contain a path, query or fragment.');
    }

    return url.origin;
}

export function assertBrowserCache(value) {
    const candidate = requireAbsolutePath(value, 'PLAYWRIGHT_BROWSERS_PATH');

    if (!existsSync(candidate)) {
        throw new Error('PLAYWRIGHT_BROWSERS_PATH must already exist.');
    }
    const entry = lstatSync(candidate);
    if (!entry.isDirectory() || entry.isSymbolicLink()) {
        throw new Error('PLAYWRIGHT_BROWSERS_PATH must be a real directory.');
    }
    const resolved = realpathSync.native(candidate);

    if (isWithin(REPOSITORY_ROOT, resolved)) {
        throw new Error('PLAYWRIGHT_BROWSERS_PATH must be outside the Git worktree.');
    }

    if (!resolved.endsWith(`${path.sep}1.62.1`)) {
        throw new Error('PLAYWRIGHT_BROWSERS_PATH must be versioned for Playwright 1.62.1.');
    }

    return resolved;
}

export function resolveE2eBrowserMode(value) {
    if (value === undefined || value === '') return 'bundled';
    if (value === 'chrome') return 'chrome';

    throw new Error('BYL_E2E_BROWSER must be "chrome" when explicitly set.');
}

export function assertRuntimeEnvironment(env, runRoot, { allowPopulatedDatabase = false } = {}) {
    const resolvedRoot = assertExternalRunRoot(runRoot, { requireEmpty: false });

    if (env.APP_ENV !== 'testing') {
        throw new Error('APP_ENV must be exactly testing.');
    }
    if (env.BYL_E2E_DISPOSABLE !== 'true') {
        throw new Error('BYL_E2E_DISPOSABLE must be exactly true.');
    }
    if (env.ERP_LOCAL_ADMIN_BYPASS !== 'false') {
        throw new Error('ERP_LOCAL_ADMIN_BYPASS must be exactly false.');
    }
    if (env.DB_CONNECTION !== 'sqlite' || (env.DB_URL ?? '') !== '') {
        throw new Error('Only a direct disposable SQLite connection is allowed.');
    }

    const cacheRoot = path.join(resolvedRoot, 'bootstrap', 'cache');
    const expectedCachePaths = {
        APP_CONFIG_CACHE: path.join(cacheRoot, 'config.php'),
        APP_EVENTS_CACHE: path.join(cacheRoot, 'events.php'),
        APP_PACKAGES_CACHE: path.join(cacheRoot, 'packages.php'),
        APP_ROUTES_CACHE: path.join(cacheRoot, 'routes-v7.php'),
        APP_SERVICES_CACHE: path.join(cacheRoot, 'services.php'),
    };
    for (const [name, expected] of Object.entries(expectedCachePaths)) {
        if (env[name] !== expected) {
            throw new Error(`${name} must be isolated inside the external run root.`);
        }
    }

    const requestedDatabasePath = requireAbsolutePath(env.DB_DATABASE, 'DB_DATABASE');
    const databasePath = path.join(realpathSync.native(path.dirname(requestedDatabasePath)), path.basename(requestedDatabasePath));
    const expectedPath = path.join(resolvedRoot, 'database', 'e2e.sqlite');

    if (databasePath !== expectedPath || isWithin(REPOSITORY_ROOT, databasePath)) {
        throw new Error('DB_DATABASE must be the dedicated SQLite file inside the external run root.');
    }

    const parent = path.dirname(databasePath);

    if (!isWithin(resolvedRoot, parent)) {
        throw new Error('The SQLite parent directory escaped the run root.');
    }

    if (existsSync(databasePath)) {
        const entry = lstatSync(databasePath);

        if (!entry.isFile() || entry.isSymbolicLink() || (!allowPopulatedDatabase && entry.size !== 0)) {
            throw new Error('The disposable SQLite target must be a new empty regular file.');
        }
    } else if (allowPopulatedDatabase) {
        throw new Error('The migrated disposable SQLite database is missing.');
    }

    return databasePath;
}

export async function findAvailableLoopbackPort() {
    return await new Promise((resolve, reject) => {
        const server = createServer();
        server.unref();
        server.once('error', reject);
        server.listen(0, '127.0.0.1', () => {
            const address = server.address();
            const port = typeof address === 'object' && address ? address.port : null;
            server.close((error) => (error ? reject(error) : resolve(port)));
        });
    });
}

export async function createDisposableRun() {
    const browserMode = resolveE2eBrowserMode(process.env.BYL_E2E_BROWSER);
    const browserCache = browserMode === 'bundled'
        ? assertBrowserCache(process.env.PLAYWRIGHT_BROWSERS_PATH)
        : undefined;
    const root = await mkdtemp(path.join(tmpdir(), 'byl-erp-e2e-'));

    try {
        await stat(root).then(async (entry) => {
            if ((entry.mode & 0o077) !== 0) {
                const { chmod } = await import('node:fs/promises');
                await chmod(root, 0o700);
            }
        });
        const resolvedRoot = await realpath(root);

        if (isWithin(REPOSITORY_ROOT, resolvedRoot)) {
            throw new Error('Generated run root is inside the Git worktree.');
        }

        for (const directory of ['database', 'auth', 'artifacts', 'report', 'logs', 'pids', 'secrets', 'bootstrap/cache']) {
            await mkdir(path.join(resolvedRoot, directory), { mode: 0o700, recursive: true });
        }

        const databasePath = path.join(resolvedRoot, 'database', 'e2e.sqlite');
        const handle = await open(databasePath, 'wx', 0o600);
        await handle.close();
        const port = await findAvailableLoopbackPort();
        const runId = randomUUID();
        const env = {
            ...process.env,
            APP_ENV: 'testing',
            APP_DEBUG: 'false',
            APP_KEY: `base64:${randomBytes(32).toString('base64')}`,
            APP_CONFIG_CACHE: path.join(resolvedRoot, 'bootstrap', 'cache', 'config.php'),
            APP_EVENTS_CACHE: path.join(resolvedRoot, 'bootstrap', 'cache', 'events.php'),
            APP_PACKAGES_CACHE: path.join(resolvedRoot, 'bootstrap', 'cache', 'packages.php'),
            APP_ROUTES_CACHE: path.join(resolvedRoot, 'bootstrap', 'cache', 'routes-v7.php'),
            APP_SERVICES_CACHE: path.join(resolvedRoot, 'bootstrap', 'cache', 'services.php'),
            APP_URL: `http://127.0.0.1:${port}`,
            BYL_E2E_BASE_URL: `http://127.0.0.1:${port}`,
            BYL_E2E_DISPOSABLE: 'true',
            BYL_E2E_RUN_ID: runId,
            BYL_E2E_RUN_ROOT: resolvedRoot,
            BYL_E2E_ACTIVE_PASSWORD: randomBytes(24).toString('base64url'),
            BYL_E2E_INACTIVE_PASSWORD: randomBytes(24).toString('base64url'),
            CACHE_STORE: 'array',
            DB_CONNECTION: 'sqlite',
            DB_DATABASE: databasePath,
            DB_URL: '',
            ERP_LOCAL_ADMIN_BYPASS: 'false',
            LOG_CHANNEL: 'stderr',
            MAIL_MAILER: 'array',
            ...(browserCache ? { PLAYWRIGHT_BROWSERS_PATH: browserCache } : {}),
            QUEUE_CONNECTION: 'sync',
            SESSION_DRIVER: 'database',
        };

        assertRuntimeEnvironment(env, resolvedRoot);
        assertLoopbackBaseUrl(env.BYL_E2E_BASE_URL);

        return { browserCache, browserMode, databasePath, env, port, runId, runRoot: resolvedRoot };
    } catch (error) {
        if (existsSync(root)) await rm(root, { recursive: true, force: false, maxRetries: 2 });
        throw error;
    }
}

export async function cleanupDisposableRun(runRoot) {
    const resolved = assertExternalRunRoot(runRoot, { requireEmpty: false });
    await rm(resolved, { recursive: true, force: false, maxRetries: 2 });
}

export async function runManagedChild(command, args, options = {}) {
    const { env = process.env, signal, timeout = 0 } = options;
    assertNotAborted(signal);

    const child = spawn(command, args, {
        cwd: REPOSITORY_ROOT,
        detached: process.platform !== 'win32',
        env,
        stdio: options.stdio ?? 'inherit',
    });

    let timeoutId;
    let killId;
    let aborted = false;
    const terminate = () => {
        aborted = true;
        if (child.pid && child.exitCode === null) {
            try {
                process.kill(process.platform === 'win32' ? child.pid : -child.pid, 'SIGTERM');
            } catch (error) {
                if (error.code !== 'ESRCH') throw error;
            }
            killId = setTimeout(() => {
                try {
                    process.kill(process.platform === 'win32' ? child.pid : -child.pid, 'SIGKILL');
                } catch (error) {
                    if (error.code !== 'ESRCH') throw error;
                }
            }, 5_000);
            killId.unref();
        }
    };
    const abortListener = () => terminate();
    signal?.addEventListener('abort', abortListener, { once: true });

    if (timeout > 0) {
        timeoutId = setTimeout(terminate, timeout);
    }

    const result = await new Promise((resolve, reject) => {
        child.once('error', reject);
        child.once('exit', (code, exitSignal) => resolve({ code, signal: exitSignal }));
    }).finally(() => {
        if (timeoutId) clearTimeout(timeoutId);
        if (killId) clearTimeout(killId);
        signal?.removeEventListener('abort', abortListener);
    });

    if (aborted) {
        throw new Error('Managed child was terminated by timeout or signal.');
    }
    if (result.code !== 0) {
        throw new Error(`Command failed without exposing child payload: ${command}.`);
    }

    return result;
}
