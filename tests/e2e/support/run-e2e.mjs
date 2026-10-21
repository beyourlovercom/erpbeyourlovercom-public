import { writeFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import {
    assertNotAborted,
    assertRuntimeEnvironment,
    cleanupDisposableRun,
    createDisposableRun,
    resolveLocalPlaywrightCli,
    runManagedChild,
} from './e2e-environment.mjs';

let activeRun;
const abortController = new AbortController();

for (const signal of ['SIGINT', 'SIGTERM', 'SIGHUP']) {
    process.once(signal, () => abortController.abort(new Error('Process signal received.')));
}

async function runLifecycleFixture(mode, controlPath) {
    activeRun = await createDisposableRun();
    await writeFile(controlPath, activeRun.runRoot, { mode: 0o600 });

    if (mode === 'failure') throw new Error('Requested lifecycle failure fixture.');
    if (mode === 'timeout') {
        await runManagedChild(process.execPath, ['-e', 'setTimeout(() => {}, 10000)'], {
            signal: abortController.signal,
            timeout: 50,
            stdio: 'ignore',
        });
    }
    if (mode === 'signal') {
        await new Promise((resolve, reject) => {
            const keepAlive = setInterval(() => {}, 1_000);
            abortController.signal.addEventListener('abort', () => {
                clearInterval(keepAlive);
                reject(new Error('Signal fixture stopped.'));
            }, { once: true });
        });
    }
}

async function runFoundation() {
    const playwrightCli = resolveLocalPlaywrightCli();
    activeRun = await createDisposableRun();
    const { browserCache, databasePath, env, runRoot } = activeRun;

    process.stdout.write(`E2E_RUN_ROOT=${runRoot}\n`);
    process.stdout.write(`E2E_DATABASE_PATH=${databasePath}\n`);
    process.stdout.write(`E2E_BROWSER_CACHE=${browserCache}\n`);
    process.stdout.write('E2E_RUN_ROOT_OUTSIDE_REPOSITORY=true\n');

    assertRuntimeEnvironment(env, runRoot);
    assertNotAborted(abortController.signal);
    await runManagedChild('npm', ['run', 'build'], { env, signal: abortController.signal });
    assertNotAborted(abortController.signal);
    await runManagedChild('php', ['tests/e2e/support/provision-users.php', '--runtime-preflight'], {
        env,
        signal: abortController.signal,
    });
    assertNotAborted(abortController.signal);
    await runManagedChild('php', ['artisan', 'migrate', '--force'], { env, signal: abortController.signal });
    assertRuntimeEnvironment(env, runRoot, { allowPopulatedDatabase: true });
    assertNotAborted(abortController.signal);
    await runManagedChild('php', ['tests/e2e/support/provision-users.php'], { env, signal: abortController.signal });
    assertNotAborted(abortController.signal);
    const suite = process.env.BYL_E2E_SUITE ?? 'all';
    if (suite !== 'all' && suite !== 'kol-role') {
        throw new Error('BYL_E2E_SUITE must be either all or kol-role.');
    }
    const testArgs = suite === 'kol-role'
        ? [playwrightCli, 'test', 'tests/e2e/orders/kol-role-isolation.spec.ts', '--project=chromium', '--timeout=90000']
        : [playwrightCli, 'test', '--project=chromium'];
    await runManagedChild(process.execPath, testArgs, {
        env,
        signal: abortController.signal,
        timeout: suite === 'kol-role' ? 360_000 : 120_000,
    });
}

async function main() {
    const fixture = process.env.BYL_E2E_LIFECYCLE_FIXTURE;
    try {
        if (fixture) {
            const controlPath = process.env.BYL_E2E_LIFECYCLE_CONTROL_PATH;
            if (!controlPath || !path.isAbsolute(controlPath)) throw new Error('Lifecycle fixture control path is required.');
            await runLifecycleFixture(fixture, controlPath);
        } else {
            await runFoundation();
        }
    } finally {
        if (activeRun?.runRoot) {
            await cleanupDisposableRun(activeRun.runRoot);
            process.stdout.write('E2E_CLEANUP=PASS\n');
        }
    }
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
    main().catch(() => {
        process.stderr.write('E2E run failed safely; external runtime cleanup was attempted.\n');
        process.exitCode = 1;
    });
}

export { main };
