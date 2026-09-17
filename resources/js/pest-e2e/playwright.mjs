import { mkdir, writeFile } from 'fs/promises';
import { dirname } from 'path';
import { readParams, hasAuthTicket, getAuthEndpoint } from './core.mjs';
import { parseSetCookies } from './setCookieParser.mjs';

export function storageStatePath() {
    const runId = process.env.PEST_E2E_RUN_ID || Date.now().toString();
    return `.pest-e2e/${runId}/storageState.json`;
}

/**
 * POST to the auth endpoint via native fetch.
 * Returns { body, setCookieHeaders, response } — the raw Set-Cookie header
 * array (Node's undici returns one entry per header, never comma-collapsed).
 *
 * Callers must not comma-split Set-Cookie themselves — that is unsafe.
 */
async function callAuthEndpoint(params) {
    const authUrl = getAuthEndpoint(params);

    const response = await fetch(authUrl, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-Pest-E2E': '1',
        },
        redirect: 'manual',
        body: JSON.stringify({
            ticket: params.auth?.ticket,
            mode: params.auth?.mode,
            guard: params.auth?.guard,
            meta: params.auth?.meta ?? {},
        }),
    });

    if (response.status < 200 || response.status >= 400) {
        const errBody = await response.text().catch(() => '');
        throw new Error(
            `pest-e2e auth endpoint failed (${response.status} ${response.statusText || ''}): `
            + (errBody.length > 500 ? errBody.slice(0, 500) + ' […truncated]' : errBody)
        );
    }

    // Node ≥ 18.14 (undici-backed fetch) exposes getSetCookie() which returns
    // one raw Set-Cookie string per header. Older Node builds miss this — we
    // fall back to iterating raw headers where available. We never comma-split
    // a joined Set-Cookie string: that is not reversible for cookies whose
    // Expires attribute contains commas.
    let setCookieHeaders = [];

    if (typeof response.headers.getSetCookie === 'function') {
        setCookieHeaders = response.headers.getSetCookie();
    }

    let body = {};
    const text = await response.text().catch(() => '');

    if (text.trim()) {
        try { body = JSON.parse(text); } catch { /* ignore */ }
    }

    return { body, setCookieHeaders, response, authUrl };
}

async function writeEmptyStorageState(storagePath) {
    await writeFile(storagePath, JSON.stringify({ cookies: [], origins: [] }));
}

async function writeStorageStateWithCookies(storagePath, cookies) {
    // Playwright's canonical storage-state format written by
    // `context.storageState({path})`. Cookies is a plain array; origins is
    // empty because we didn't run a browser to populate localStorage.
    await writeFile(storagePath, JSON.stringify({ cookies, origins: [] }));
}

/**
 * Session-auth via native fetch.
 *
 * Return values:
 *   - `true`  → auth succeeded; storage-state file written with cookies.
 *   - `false` → environment cannot decompose Set-Cookie (missing
 *               `Headers.getSetCookie`, empty raw list, or parser produced
 *               zero cookies). Caller may fall back to the browser-context
 *               path — a real Playwright browser can capture cookies that
 *               native fetch cannot expose in this runtime.
 *
 * Throws on any authentication or transport failure:
 *   - non-2xx / non-3xx HTTP status (4xx client error, 5xx server error);
 *   - network / connection failure (propagated from `fetch`).
 *
 * A non-success HTTP response is treated as an explicit auth failure and
 * MUST NOT be masked by falling back to a browser: the browser would
 * make the exact same HTTP request and fail the same way, while
 * discarding a diagnosable error along the way. No storage-state file
 * is written on failure.
 */
async function trySessionAuthViaFetch(params, storagePath) {
    const appUrl = process.env.APP_URL || params.baseUrl || 'http://localhost';
    const authUrl = `${appUrl.replace(/\/$/, '')}/pest-e2e/auth/login`;

    const response = await fetch(authUrl, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-Pest-E2E': '1',
        },
        redirect: 'manual',
        body: JSON.stringify({
            ticket: params.auth?.ticket,
            mode: params.auth?.mode,
            guard: params.auth?.guard,
            meta: params.auth?.meta ?? {},
        }),
    });

    // Success is 2xx OR 3xx (session auth commonly answers with a 302 to the
    // authenticated landing page; `redirect: 'manual'` surfaces that as
    // status=302 rather than following it, and the Set-Cookie header on the
    // 302 response is what carries the session cookie). 4xx and 5xx are
    // real authentication or server failures — throw so the failure is
    // surfaced immediately, do not silently fall back to a browser.
    if (response.status < 200 || response.status >= 400) {
        const errBody = await response.text().catch(() => '');
        throw new Error(
            `pest-e2e auth endpoint failed (${response.status} ${response.statusText || ''}): `
            + (errBody.length > 500 ? errBody.slice(0, 500) + ' […truncated]' : errBody)
        );
    }

    if (typeof response.headers.getSetCookie !== 'function') {
        // Older Node build (< 18.14) — cannot reliably decompose multiple
        // Set-Cookie headers via native fetch. Legitimate environment
        // limitation; the browser-context path can still capture cookies.
        return false;
    }

    const rawSetCookies = response.headers.getSetCookie();

    if (!Array.isArray(rawSetCookies) || rawSetCookies.length === 0) {
        // The auth endpoint returned success but this runtime observes no
        // Set-Cookie headers — some proxies / edge environments strip them
        // before fetch sees them. The browser-context path may still capture
        // them via a lower-level socket read. Legitimate fallback trigger.
        return false;
    }

    const cookies = parseSetCookies(rawSetCookies, authUrl);

    if (cookies.length === 0) {
        // Parser could not derive any usable cookies from the raw headers.
        // Also a legitimate fallback trigger.
        return false;
    }

    await writeStorageStateWithCookies(storagePath, cookies);

    return true;
}

/**
 * Session-auth via Playwright browser context — the historical path.
 *
 * Retained ONLY as a fallback for supported environments where
 * `Headers.getSetCookie()` is unavailable or produces no cookies (rare —
 * observed on some pre-18.14 Node builds and some Herd/Windows setups that
 * proxy Set-Cookie through an intermediate layer that strips it).
 *
 * If you find yourself here on a normal run, the JS runtime is not compliant
 * with Node ≥18.14 fetch semantics. Native fetch is the intended path.
 */
async function sessionAuthViaBrowserContext(params, storagePath) {
    const appUrl = process.env.APP_URL || params.baseUrl || 'http://localhost';
    const authUrl = `${appUrl.replace(/\/$/, '')}/pest-e2e/auth/login`;

    const { chromium } = await import('@playwright/test');
    const browser = await chromium.launch();

    try {
        const context = await browser.newContext({ baseURL: appUrl });
        const res = await context.request.post(authUrl, {
            headers: { 'Content-Type': 'application/json', 'X-Pest-E2E': '1' },
            data: {
                ticket: params.auth?.ticket,
                mode: params.auth?.mode,
                guard: params.auth?.guard,
                meta: params.auth?.meta ?? {},
            },
        });

        if (!res.ok()) {
            throw new Error(`Auth endpoint failed (${res.status()}): ${await res.text()}`);
        }

        await context.storageState({ path: storagePath });
    } finally {
        await browser.close();
    }
}

export async function globalSetup(_config) {
    const params = await readParams();

    const storagePath = storageStatePath();
    await mkdir(dirname(storagePath), { recursive: true });

    if (!hasAuthTicket(params)) {
        await writeEmptyStorageState(storagePath);

        return;
    }

    const mode = (params.auth?.mode ?? 'session').toLowerCase();

    if (mode === 'sanctum') {
        const { body } = await callAuthEndpoint(params);

        if (!body.token) {
            throw new Error('Sanctum mode selected but auth endpoint did not return a token.');
        }

        process.env.PEST_E2E_AUTH_TOKEN = body.token;
        await writeEmptyStorageState(storagePath);
        // Instrumentation: prove no browser was launched on the normal sanctum
        // path. Consumers can grep this in their harness output.
        process.env.PEST_E2E_AUTH_STRATEGY = 'fetch';

        return;
    }

    // SESSION mode. Native fetch is the normal path — no browser launch. The
    // browser-context fallback below only fires when Node's Headers API cannot
    // give us decomposed Set-Cookie values, which is not expected on any
    // supported Playwright environment (Playwright requires Node ≥18).
    const succeededViaFetch = await trySessionAuthViaFetch(params, storagePath);

    if (succeededViaFetch) {
        process.env.PEST_E2E_STORAGE_STATE = storagePath;
        process.env.PEST_E2E_AUTH_STRATEGY = 'fetch';

        return;
    }

    // Narrow fallback: legacy browser-context path. Instrumented so verification
    // runs can prove the normal path did NOT hit this branch.
    await sessionAuthViaBrowserContext(params, storagePath);
    process.env.PEST_E2E_STORAGE_STATE = storagePath;
    process.env.PEST_E2E_AUTH_STRATEGY = 'browser-fallback';
}
