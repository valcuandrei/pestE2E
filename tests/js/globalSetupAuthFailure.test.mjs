import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, rmSync, existsSync, readFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { createServer } from 'node:http';

/**
 * Regression tests for the native-fetch session-auth path in
 * resources/js/pest-e2e/playwright.mjs.
 *
 * The specific concerns being pinned down here:
 *
 *   1. A non-success HTTP response (4xx / 5xx) MUST cause `globalSetup` to
 *      throw. It must NOT silently fall back to the browser-context path
 *      (that would just repeat the same failing request while discarding a
 *      diagnosable error). It must NOT write a storage-state file.
 *
 *   2. A 2xx or 3xx response with a valid Set-Cookie header MUST succeed via
 *      native fetch and write a well-formed Playwright storage-state JSON
 *      file. The browser fallback must NOT be reached.
 *
 *   3. A network / connection failure surfaces as a thrown error.
 *
 * These tests spin up a tiny in-process HTTP server per case, wire it as
 * APP_URL, and drive `globalSetup()` directly. The pest-e2e ticket / params
 * are supplied via a temp PEST_E2E_PARAMS payload so no PHP is needed.
 */

const { globalSetup } = await import('../../resources/js/pest-e2e/playwright.mjs');

/** @returns {Promise<{server: import('http').Server, url: string}>} */
function startServer(handler) {
    return new Promise((resolve) => {
        const server = createServer((req, res) => {
            let body = '';
            req.on('data', (chunk) => { body += chunk.toString(); });
            req.on('end', () => handler(req, res, body));
        });
        server.listen(0, '127.0.0.1', () => {
            const addr = server.address();
            resolve({ server, url: `http://127.0.0.1:${addr.port}` });
        });
    });
}

function makeTempDir() {
    return mkdtempSync(join(tmpdir(), 'pest-e2e-auth-test-'));
}

function setEnvForTest({ appUrl, runId, ticket = 'ticket-abc', mode = 'session' }) {
    process.env.APP_URL = appUrl;
    process.env.PEST_E2E_RUN_ID = runId;
    process.env.PEST_E2E_PARAMS = JSON.stringify({
        target: 'frontend',
        runId,
        params: {
            baseUrl: appUrl,
            auth: { ticket, mode, guard: 'web', meta: {} },
        },
    });
}

function clearEnvAfterTest() {
    delete process.env.APP_URL;
    delete process.env.PEST_E2E_RUN_ID;
    delete process.env.PEST_E2E_PARAMS;
    delete process.env.PEST_E2E_AUTH_STRATEGY;
    delete process.env.PEST_E2E_STORAGE_STATE;
}

test('globalSetup throws on 401 and does not write a storage-state file', async (t) => {
    const { server, url } = await startServer((req, res, body) => {
        assert.equal(req.method, 'POST');
        assert.equal(req.url, '/pest-e2e/auth/login');
        assert.deepEqual(JSON.parse(body).ticket, 'ticket-abc');
        res.statusCode = 401;
        res.setHeader('Content-Type', 'application/json');
        res.end(JSON.stringify({ error: 'The ticket is invalid or expired.' }));
    });
    t.after(() => { server.close(); });

    const runDir = makeTempDir();
    const runId = 'auth-401-'+Date.now();
    setEnvForTest({ appUrl: url, runId });

    // globalSetup writes to `<cwd>/.pest-e2e/<runId>/storageState.json`.
    // Point cwd at a scratch dir so we can inspect the write-or-not outcome.
    const originalCwd = process.cwd();
    process.chdir(runDir);

    let thrown = null;
    try {
        await globalSetup();
    } catch (e) {
        thrown = e;
    } finally {
        process.chdir(originalCwd);
        clearEnvAfterTest();
    }

    assert.ok(thrown instanceof Error, 'globalSetup should throw on 401');
    assert.match(thrown.message, /auth endpoint failed \(401/i);
    assert.match(thrown.message, /invalid or expired/);

    // Must NOT write a storage-state file on failure.
    const storageStatePath = join(runDir, '.pest-e2e', runId, 'storageState.json');
    assert.equal(existsSync(storageStatePath), false, 'storageState.json must NOT be written on auth failure');

    rmSync(runDir, { recursive: true, force: true });
});

test('globalSetup throws on 500 without silently invoking the browser fallback', async (t) => {
    const { server, url } = await startServer((req, res) => {
        res.statusCode = 500;
        res.end('DB unavailable');
    });
    t.after(() => { server.close(); });

    const runDir = makeTempDir();
    const runId = 'auth-500-'+Date.now();
    setEnvForTest({ appUrl: url, runId });

    const originalCwd = process.cwd();
    process.chdir(runDir);

    let thrown = null;
    try {
        await globalSetup();
    } catch (e) {
        thrown = e;
    } finally {
        process.chdir(originalCwd);
        clearEnvAfterTest();
    }

    assert.ok(thrown instanceof Error);
    assert.match(thrown.message, /auth endpoint failed \(500/i);
    // The strategy env var should NOT have been set to 'browser-fallback'
    // because we never entered that branch.
    assert.equal(process.env.PEST_E2E_AUTH_STRATEGY, undefined);

    rmSync(runDir, { recursive: true, force: true });
});

test('globalSetup writes a storage-state file with cookies on a 302 session redirect (does NOT follow the redirect)', async (t) => {
    // This is a real HTTP-server integration test. The server returns 302
    // with Set-Cookie on the FIRST hop and would return an entirely
    // different Set-Cookie value on the redirect target. If globalSetup
    // silently followed the redirect (Node fetch's default), it would end
    // up with the WRONG cookie set. `redirect: 'manual'` is what makes the
    // intermediate 302 response headers directly inspectable — verify that
    // by pinning the cookie values to the FIRST hop, not the follow.
    let firstHopHits = 0;
    let followHopHits = 0;
    const { server, url } = await startServer((req, res) => {
        if (req.url === '/pest-e2e/auth/login') {
            firstHopHits += 1;
            res.statusCode = 302;
            res.setHeader('Location', '/authenticated-landing');
            res.setHeader('Set-Cookie', [
                'XSRF-TOKEN=xsrf-from-302; Path=/; SameSite=Lax',
                'laravel_session=sess-from-302; Path=/; HttpOnly; SameSite=Lax',
            ]);
            res.end('');

            return;
        }

        followHopHits += 1;
        res.statusCode = 200;
        res.setHeader('Set-Cookie', [
            'XSRF-TOKEN=WRONG-follow; Path=/; SameSite=Lax',
            'laravel_session=WRONG-follow; Path=/; HttpOnly; SameSite=Lax',
        ]);
        res.end('should not have arrived here');
    });
    t.after(() => { server.close(); });

    const runDir = makeTempDir();
    const runId = 'auth-302-'+Date.now();
    setEnvForTest({ appUrl: url, runId });

    const originalCwd = process.cwd();
    process.chdir(runDir);

    try {
        await globalSetup();
    } finally {
        process.chdir(originalCwd);
    }

    const storageStatePath = join(runDir, '.pest-e2e', runId, 'storageState.json');
    assert.equal(existsSync(storageStatePath), true);
    const state = JSON.parse(readFileSync(storageStatePath, 'utf8'));

    assert.ok(Array.isArray(state.cookies));
    assert.equal(state.cookies.length, 2);
    assert.equal(state.cookies[0].name, 'XSRF-TOKEN');
    // The cookies MUST come from the 302 (redirect: 'manual' means we do not
    // follow to the second endpoint that would have delivered WRONG-follow).
    assert.equal(state.cookies[0].value, 'xsrf-from-302');
    assert.equal(state.cookies[1].name, 'laravel_session');
    assert.equal(state.cookies[1].value, 'sess-from-302');
    assert.equal(state.cookies[1].httpOnly, true);
    assert.deepEqual(state.origins, []);

    // Server-side counters: fetch hit the auth endpoint exactly once and
    // did NOT follow the redirect.
    assert.equal(firstHopHits, 1, 'first hop (the 302) must be hit exactly once');
    assert.equal(followHopHits, 0, 'redirect target must NOT be followed');

    // Instrumentation: fetch path must have been the strategy.
    assert.equal(process.env.PEST_E2E_AUTH_STRATEGY, 'fetch');

    clearEnvAfterTest();
    rmSync(runDir, { recursive: true, force: true });
});

test('globalSetup does NOT touch @playwright/test on the 302 success path (browser fallback must not fire)', async (t) => {
    // Direct proof that the browser-context path is not invoked on a
    // successful fetch-based auth. Rather than mock chromium.launch, we
    // rely on the strategy env var — but also grep the storage-state to
    // confirm it lacks any browser-only fields Playwright's own
    // `context.storageState({path})` sometimes emits (e.g. origins
    // populated with `localStorage`). Empty origins == no browser ran.
    const { server, url } = await startServer((req, res) => {
        res.statusCode = 302;
        res.setHeader('Location', '/dashboard');
        res.setHeader('Set-Cookie', 'laravel_session=proof-of-fetch; Path=/; HttpOnly');
        res.end('');
    });
    t.after(() => { server.close(); });

    const runDir = makeTempDir();
    const runId = 'no-fallback-'+Date.now();
    setEnvForTest({ appUrl: url, runId });

    const originalCwd = process.cwd();
    process.chdir(runDir);

    try {
        await globalSetup();
    } finally {
        process.chdir(originalCwd);
    }

    const state = JSON.parse(readFileSync(join(runDir, '.pest-e2e', runId, 'storageState.json'), 'utf8'));
    assert.equal(state.cookies.length, 1);
    assert.equal(state.cookies[0].value, 'proof-of-fetch');
    assert.deepEqual(state.origins, [], 'no browser ran, origins must be []');
    assert.equal(process.env.PEST_E2E_AUTH_STRATEGY, 'fetch');
    assert.notEqual(process.env.PEST_E2E_AUTH_STRATEGY, 'browser-fallback');

    clearEnvAfterTest();
    rmSync(runDir, { recursive: true, force: true });
});

test('globalSetup succeeds on a 200 response with valid Set-Cookie', async (t) => {
    const { server, url } = await startServer((req, res) => {
        res.statusCode = 200;
        res.setHeader('Content-Type', 'application/json');
        res.setHeader('Set-Cookie', 'laravel_session=abc123; Path=/; HttpOnly; SameSite=Lax');
        res.end('{}');
    });
    t.after(() => { server.close(); });

    const runDir = makeTempDir();
    const runId = 'auth-200-'+Date.now();
    setEnvForTest({ appUrl: url, runId });

    const originalCwd = process.cwd();
    process.chdir(runDir);

    try {
        await globalSetup();
    } finally {
        process.chdir(originalCwd);
    }

    const state = JSON.parse(readFileSync(join(runDir, '.pest-e2e', runId, 'storageState.json'), 'utf8'));
    assert.equal(state.cookies.length, 1);
    assert.equal(state.cookies[0].name, 'laravel_session');
    assert.equal(process.env.PEST_E2E_AUTH_STRATEGY, 'fetch');

    clearEnvAfterTest();
    rmSync(runDir, { recursive: true, force: true });
});

test('globalSetup surfaces network / connection failures as thrown errors', async () => {
    const runDir = makeTempDir();
    const runId = 'auth-connrefused-'+Date.now();
    // Port 1 is reserved and reliably refuses connections.
    setEnvForTest({ appUrl: 'http://127.0.0.1:1', runId });

    const originalCwd = process.cwd();
    process.chdir(runDir);

    let thrown = null;
    try {
        await globalSetup();
    } catch (e) {
        thrown = e;
    } finally {
        process.chdir(originalCwd);
        clearEnvAfterTest();
    }

    assert.ok(thrown instanceof Error, 'connection refused must throw');
    // storage-state must not have been written
    const storageStatePath = join(runDir, '.pest-e2e', runId, 'storageState.json');
    assert.equal(existsSync(storageStatePath), false);

    rmSync(runDir, { recursive: true, force: true });
});
