import assert from 'node:assert/strict';
import { spawn, spawnSync } from 'node:child_process';
import { chmod, mkdtemp, mkdir, readFile, realpath, rm, stat, symlink, writeFile } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import test from 'node:test';
import { scanArtifactTree } from './artifact-scan.mjs';
import {
    REPOSITORY_ROOT,
    assertExternalRunRoot,
    assertLoopbackBaseUrl,
    assertRuntimeEnvironment,
    cleanupDisposableRun,
    resolveLocalPlaywrightCli,
    resolveE2eBrowserMode,
    runManagedChild,
} from './e2e-environment.mjs';

const browserCache = path.join(tmpdir(), 'byl-e2e-contract-browser-cache', '1.62.1');
const runner = path.join(REPOSITORY_ROOT, 'tests/e2e/support/run-e2e.mjs');

test.before(async () => {
    await mkdir(browserCache, { recursive: true, mode: 0o700 });
});

test.after(async () => {
    await rm(path.dirname(browserCache), { recursive: true });
});

async function temporaryDirectory(prefix = 'byl-e2e-contract-') {
    const directory = await mkdtemp(path.join(tmpdir(), prefix));
    await chmod(directory, 0o700);

    return await realpath(directory);
}

function disposableEnvironment(runRoot, databasePath) {
    const cacheRoot = path.join(runRoot, 'bootstrap', 'cache');

    return {
        APP_CONFIG_CACHE: path.join(cacheRoot, 'config.php'),
        APP_ENV: 'testing',
        APP_EVENTS_CACHE: path.join(cacheRoot, 'events.php'),
        APP_KEY: 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
        APP_PACKAGES_CACHE: path.join(cacheRoot, 'packages.php'),
        APP_ROUTES_CACHE: path.join(cacheRoot, 'routes-v7.php'),
        APP_SERVICES_CACHE: path.join(cacheRoot, 'services.php'),
        BYL_E2E_ACTIVE_PASSWORD: 'SYNTHETIC-ACTIVE-PASSWORD-123',
        BYL_E2E_DISPOSABLE: 'true',
        BYL_E2E_INACTIVE_PASSWORD: 'SYNTHETIC-INACTIVE-PASSWORD-456',
        BYL_E2E_RUN_ID: 'contract001eb',
        BYL_E2E_RUN_ROOT: runRoot,
        DB_CONNECTION: 'sqlite',
        DB_DATABASE: databasePath,
        DB_URL: '',
        ERP_LOCAL_ADMIN_BYPASS: 'false',
        PLAYWRIGHT_BROWSERS_PATH: browserCache,
    };
}

function storedZipEntries(entries) {
    const localParts = [];
    const centralParts = [];
    let localOffset = 0;

    for (const [entryName, content] of entries) {
        const name = Buffer.from(entryName);
        const data = Buffer.from(content);
        const local = Buffer.alloc(30);
        local.writeUInt32LE(0x04034b50, 0);
        local.writeUInt16LE(20, 4);
        local.writeUInt32LE(data.length, 18);
        local.writeUInt32LE(data.length, 22);
        local.writeUInt16LE(name.length, 26);

        const central = Buffer.alloc(46);
        central.writeUInt32LE(0x02014b50, 0);
        central.writeUInt16LE(20, 4);
        central.writeUInt16LE(20, 6);
        central.writeUInt32LE(data.length, 20);
        central.writeUInt32LE(data.length, 24);
        central.writeUInt16LE(name.length, 28);
        central.writeUInt32LE(localOffset, 42);

        localParts.push(local, name, data);
        centralParts.push(central, name);
        localOffset += local.length + name.length + data.length;
    }

    const centralBuffer = Buffer.concat(centralParts);
    const end = Buffer.alloc(22);
    end.writeUInt32LE(0x06054b50, 0);
    end.writeUInt16LE(entries.length, 8);
    end.writeUInt16LE(entries.length, 10);
    end.writeUInt32LE(centralBuffer.length, 12);
    end.writeUInt32LE(localOffset, 16);

    return Buffer.concat([...localParts, centralBuffer, end]);
}

function storedZip(entryName, content) {
    return storedZipEntries([[entryName, content]]);
}

async function waitForFile(file, timeout = 3_000) {
    const deadline = Date.now() + timeout;
    while (Date.now() < deadline) {
        if (existsSync(file)) return;
        await new Promise((resolve) => setTimeout(resolve, 20));
    }
    throw new Error('Timed out waiting for lifecycle control file.');
}

async function runLifecycleFixture(mode) {
    const controlRoot = await temporaryDirectory('byl-e2e-control-');
    const controlPath = path.join(controlRoot, 'run-root.txt');
    const result = spawnSync(process.execPath, [runner], {
        cwd: REPOSITORY_ROOT,
        encoding: 'utf8',
        env: {
            ...process.env,
            BYL_E2E_LIFECYCLE_CONTROL_PATH: controlPath,
            BYL_E2E_LIFECYCLE_FIXTURE: mode,
            PLAYWRIGHT_BROWSERS_PATH: browserCache,
        },
    });
    const runRoot = await readFile(controlPath, 'utf8');
    await rm(controlRoot, { recursive: true });

    return { result, runRoot };
}

test('external run root and loopback URL contracts fail closed', async () => {
    const external = await temporaryDirectory();
    assert.equal(assertExternalRunRoot(external), await realpath(external));
    assert.equal(assertLoopbackBaseUrl('http://127.0.0.1:48123'), 'http://127.0.0.1:48123');
    assert.throws(() => assertExternalRunRoot(REPOSITORY_ROOT), /outside the Git worktree/);
    assert.throws(() => assertLoopbackBaseUrl('https://example.com'), /127\.0\.0\.1/);
    assert.throws(() => assertLoopbackBaseUrl('http://localhost:48123'), /127\.0\.0\.1/);
    await rm(external, { recursive: true });
});

test('database safety gate accepts only fresh external SQLite and rejects the logical repository path without a file dependency', async () => {
    const runRoot = await temporaryDirectory();
    await mkdir(path.join(runRoot, 'database'));
    const databasePath = path.join(runRoot, 'database', 'e2e.sqlite');
    await writeFile(databasePath, '');
    const environment = disposableEnvironment(runRoot, databasePath);

    assert.equal(assertRuntimeEnvironment(environment, runRoot), path.join(await realpath(runRoot), 'database', 'e2e.sqlite'));
    assert.throws(
        () => assertRuntimeEnvironment({ ...environment, DB_CONNECTION: 'mysql' }, runRoot),
        /Only a direct disposable SQLite/,
    );
    assert.throws(
        () => assertRuntimeEnvironment({ ...environment, APP_ENV: 'local' }, runRoot),
        /APP_ENV/,
    );
    assert.throws(
        () => assertRuntimeEnvironment({ ...environment, DB_DATABASE: path.join(REPOSITORY_ROOT, 'database/database.sqlite') }, runRoot),
        /dedicated SQLite/,
    );
    await writeFile(databasePath, 'shared');
    assert.throws(() => assertRuntimeEnvironment(environment, runRoot), /new empty regular file/);
    await rm(runRoot, { recursive: true });
});

test('PHP user bootstrap rejects an unsafe environment before application boot', () => {
    const result = spawnSync('php', ['tests/e2e/support/provision-users.php'], {
        cwd: REPOSITORY_ROOT,
        encoding: 'utf8',
        env: { ...process.env, APP_ENV: 'production', BYL_E2E_ACTIVE_PASSWORD: 'CANARY-DO-NOT-PRINT' },
    });

    assert.notEqual(result.status, 0);
    assert.match(result.stderr, /safety gate failed/);
    assert.doesNotMatch(result.stderr, /CANARY-DO-NOT-PRINT/);
});

test('Laravel runtime preflight verifies effective SQLite identity and rejects stale caches or unsafe databases before Migration', async () => {
    const runRoot = await temporaryDirectory();
    await mkdir(path.join(runRoot, 'database'));
    await mkdir(path.join(runRoot, 'bootstrap', 'cache'), { recursive: true });
    const databasePath = path.join(runRoot, 'database', 'e2e.sqlite');
    const configCache = path.join(runRoot, 'bootstrap', 'cache', 'config.php');
    await writeFile(databasePath, '');
    const environment = { ...process.env, ...disposableEnvironment(runRoot, databasePath) };
    const command = ['tests/e2e/support/provision-users.php', '--runtime-preflight'];

    const valid = spawnSync('php', command, { cwd: REPOSITORY_ROOT, encoding: 'utf8', env: environment });
    assert.equal(valid.status, 0, valid.stderr);
    assert.match(valid.stdout, /"runtime_preflight":"pass"/);
    assert.equal((await stat(databasePath)).size, 0);

    await writeFile(configCache, "<?php return ['database' => ['default' => 'mysql']];\n");
    const staleCache = spawnSync('php', command, { cwd: REPOSITORY_ROOT, encoding: 'utf8', env: environment });
    assert.notEqual(staleCache.status, 0);
    assert.match(staleCache.stderr, /active bootstrap cache/);
    assert.equal((await stat(databasePath)).size, 0);
    await rm(configCache);

    const mysql = spawnSync('php', command, {
        cwd: REPOSITORY_ROOT,
        encoding: 'utf8',
        env: { ...environment, DB_CONNECTION: 'mysql', DB_HOST: '203.0.113.1' },
    });
    assert.notEqual(mysql.status, 0);
    assert.match(mysql.stderr, /safety gate failed: connection/);

    const trackedRepositoryFixture = path.join(REPOSITORY_ROOT, 'composer.json');
    const trackedRepositoryFixtureBefore = await stat(trackedRepositoryFixture);
    const repositoryPath = spawnSync('php', command, {
        cwd: REPOSITORY_ROOT,
        encoding: 'utf8',
        env: { ...environment, DB_DATABASE: trackedRepositoryFixture },
    });
    const trackedRepositoryFixtureAfter = await stat(trackedRepositoryFixture);
    assert.notEqual(repositoryPath.status, 0);
    assert.match(repositoryPath.stderr, /safety gate failed: database path/);
    assert.equal(trackedRepositoryFixtureAfter.size, trackedRepositoryFixtureBefore.size);
    assert.equal(trackedRepositoryFixtureAfter.mtimeMs, trackedRepositoryFixtureBefore.mtimeMs);

    await writeFile(databasePath, 'shared-database-canary');
    const shared = spawnSync('php', command, { cwd: REPOSITORY_ROOT, encoding: 'utf8', env: environment });
    assert.notEqual(shared.status, 0);
    assert.match(shared.stderr, /database is not fresh/);
    await rm(runRoot, { recursive: true });
});

test('local Playwright CLI rejects unsafe input and lists only the Chromium project for a valid contract', async () => {
    const runRoot = await temporaryDirectory();
    for (const directory of ['database', 'artifacts', 'report', 'bootstrap/cache']) await mkdir(path.join(runRoot, directory), { recursive: true });
    const databasePath = path.join(runRoot, 'database', 'e2e.sqlite');
    await writeFile(databasePath, '');
    const environment = {
        ...process.env,
        ...disposableEnvironment(runRoot, databasePath),
        BYL_E2E_BASE_URL: 'https://example.com',
        BYL_E2E_RUN_ROOT: runRoot,
        PLAYWRIGHT_BROWSERS_PATH: browserCache,
    };
    const playwrightCli = resolveLocalPlaywrightCli();
    const rejected = spawnSync(process.execPath, [playwrightCli, 'test', '--list'], {
        cwd: REPOSITORY_ROOT,
        encoding: 'utf8',
        env: environment,
    });
    assert.notEqual(rejected.status, 0);
    assert.match(`${rejected.stdout}${rejected.stderr}`, /127\.0\.0\.1/);

    const listed = spawnSync(process.execPath, [playwrightCli, 'test', '--list'], {
        cwd: REPOSITORY_ROOT,
        encoding: 'utf8',
        env: { ...environment, BYL_E2E_BASE_URL: 'http://127.0.0.1:48123' },
    });
    assert.equal(listed.status, 0, listed.stderr);
    assert.match(listed.stdout, /\[chromium\]/);
    assert.doesNotMatch(listed.stdout, /\[(?:firefox|webkit)\]/);
    await rm(runRoot, { recursive: true });
});

test('missing local Playwright CLI fails closed without an npm network fallback', async () => {
    const fakeRepository = await temporaryDirectory();
    assert.throws(() => resolveLocalPlaywrightCli(fakeRepository), /local Playwright CLI is not installed/);
    await rm(fakeRepository, { recursive: true });
});

test('explicit system Chrome mode bypasses only the bundled browser cache contract', () => {
    assert.equal(resolveE2eBrowserMode('chrome'), 'chrome');
    assert.equal(resolveE2eBrowserMode(undefined), 'bundled');
    assert.throws(() => resolveE2eBrowserMode('firefox'), /BYL_E2E_BROWSER/);
});

test('an already-aborted signal starts no child process and the disposable run root is cleaned', async () => {
    const controlRoot = await temporaryDirectory('byl-e2e-abort-control-');
    const runRoot = await temporaryDirectory();
    const marker = path.join(controlRoot, 'child-started.txt');
    const controller = new AbortController();
    controller.abort();

    try {
        await assert.rejects(
            runManagedChild(process.execPath, ['-e', `require('node:fs').writeFileSync(${JSON.stringify(marker)}, 'started')`], {
                signal: controller.signal,
            }),
            /aborted before starting a child process/,
        );
    } finally {
        await cleanupDisposableRun(runRoot);
    }

    assert.equal(existsSync(marker), false);
    assert.equal(existsSync(runRoot), false);
    await rm(controlRoot, { recursive: true });
});

test('artifact scanner accepts bounded synthetic text and returns only count and hash', async () => {
    const root = await temporaryDirectory();
    await writeFile(path.join(root, 'result.json'), JSON.stringify({ email: 'runner@byl-e2e.invalid', status: 'ok' }));
    const result = await scanArtifactTree(root);

    assert.equal(result.files, 1);
    assert.match(result.manifestHash, /^[a-f0-9]{64}$/);
    assert.deepEqual(Object.keys(result).sort(), ['files', 'manifestHash']);
    await rm(root, { recursive: true });
});

test('artifact scanner rejects sensitive text, configured secrets and real email addresses without echoing them', async () => {
    for (const [name, content, options] of [
        ['plain.txt', 'authorization: bearer REDACT-ME', {}],
        ['result.json', '{"password":"REDACT-ME"}', {}],
        ['fixture-a.json', '{"cookie":"CANARY"}', {}],
        ['fixture-b.json', '{"setCookie":"CANARY"}', {}],
        ['fixture-c.json', '{"sessionCookie":"CANARY"}', {}],
        ['report.html', '<p>owner@example.com</p>', {}],
        ['canary.txt', 'CANARY-SECRET-987', { secretValues: ['CANARY-SECRET-987'] }],
    ]) {
        const root = await temporaryDirectory();
        await writeFile(path.join(root, name), content);
        await assert.rejects(scanArtifactTree(root, options), (error) => {
            assert.doesNotMatch(error.message, /REDACT-ME|owner@example\.com|CANARY/);
            return true;
        });
        await rm(root, { recursive: true });
    }
});

test('artifact scanner inspects ZIP entries and rejects embedded sensitive content', async () => {
    const root = await temporaryDirectory();
    await writeFile(path.join(root, 'results.zip'), storedZip('nested/result.json', '{"csrf":"REDACT-ME"}'));
    await assert.rejects(scanArtifactTree(root), /blocked sensitive-data marker/);
    await rm(root, { recursive: true });
});

test('artifact scanner rejects multi-entry ZIPs whose declared uncompressed total exceeds the shared budget', async () => {
    const root = await temporaryDirectory();
    const archive = storedZipEntries([
        ['first.txt', Buffer.alloc(4 * 1024 * 1024, 'a')],
        ['second.txt', Buffer.alloc(4 * 1024 * 1024, 'b')],
    ]);
    await writeFile(path.join(root, 'aggregate.zip'), archive);

    await assert.rejects(scanArtifactTree(root), (error) => {
        assert.match(error.message, /cumulative uncompressed size exceeds/);
        assert.doesNotMatch(error.message, /aaaa|bbbb/);
        return true;
    });
    await rm(root, { recursive: true });
});

test('artifact scanner shares the cumulative uncompressed budget with nested ZIP entries', async () => {
    const root = await temporaryDirectory();
    const nested = storedZip('payload.txt', Buffer.alloc(4 * 1024 * 1024, 'n'));
    await writeFile(path.join(root, 'nested.zip'), storedZip('inner.zip', nested));

    await assert.rejects(scanArtifactTree(root), /cumulative uncompressed size exceeds/);
    await rm(root, { recursive: true });
});

test('artifact scanner rejects symlinks and unknown formats', async () => {
    const targetRoot = await temporaryDirectory();
    const symlinkRoot = await temporaryDirectory();
    await writeFile(path.join(targetRoot, 'safe.txt'), 'safe');
    await symlink(path.join(targetRoot, 'safe.txt'), path.join(symlinkRoot, 'linked.txt'));
    await assert.rejects(scanArtifactTree(symlinkRoot), /Symlinks/);
    await rm(symlinkRoot, { recursive: true });

    const unknownRoot = await temporaryDirectory();
    await writeFile(path.join(unknownRoot, 'result.bin'), Buffer.from([0, 1, 2]));
    await assert.rejects(scanArtifactTree(unknownRoot), /Unknown artifact format/);
    await rm(unknownRoot, { recursive: true });

    const disguisedBinaryRoot = await temporaryDirectory();
    await writeFile(path.join(disguisedBinaryRoot, 'result.txt'), Buffer.from([0, 1, 2]));
    await assert.rejects(scanArtifactTree(disguisedBinaryRoot), /not valid UTF-8/);
    await rm(disguisedBinaryRoot, { recursive: true });
    await rm(targetRoot, { recursive: true });
});

test('success, failure and timeout paths remove the disposable run root', async () => {
    for (const [mode, expectedStatus] of [['success', 0], ['failure', 1], ['timeout', 1]]) {
        const { result, runRoot } = await runLifecycleFixture(mode);
        assert.equal(result.status, expectedStatus);
        assert.equal(existsSync(runRoot), false);
    }
});

test('signal exit terminates the runner and removes the disposable run root', async () => {
    const controlRoot = await temporaryDirectory('byl-e2e-control-');
    const controlPath = path.join(controlRoot, 'run-root.txt');
    const child = spawn(process.execPath, [runner], {
        cwd: REPOSITORY_ROOT,
        env: {
            ...process.env,
            BYL_E2E_LIFECYCLE_CONTROL_PATH: controlPath,
            BYL_E2E_LIFECYCLE_FIXTURE: 'signal',
            PLAYWRIGHT_BROWSERS_PATH: browserCache,
        },
        stdio: 'ignore',
    });

    await waitForFile(controlPath);
    const runRoot = await readFile(controlPath, 'utf8');
    const exited = new Promise((resolve, reject) => {
        child.once('exit', resolve);
        child.once('error', reject);
    });
    child.kill('SIGTERM');
    await exited;
    assert.equal(existsSync(runRoot), false);
    await rm(controlRoot, { recursive: true });
});
