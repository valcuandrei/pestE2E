import { mkdir, writeFile } from 'fs/promises';
import { dirname } from 'path';
import { readParams, hasAuthTicket, getAuthEndpoint } from './core.mjs';

export function storageStatePath() {
    const runId = process.env.PEST_E2E_RUN_ID || Date.now().toString();
    return `.pest-e2e/${runId}/storageState.json`;
}

/**
 * POST to the auth endpoint and return { body, cookies }.
 * Uses native fetch — no browser launch needed.
 */
async function callAuthEndpoint(params) {
    const authUrl = getAuthEndpoint(params);

    const res = await fetch(authUrl, {
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

    if (!res.ok && res.status < 300) {
        const body = await res.text().catch(() => '');
        throw new Error(`Auth endpoint failed (${res.status}): ${body}`);
    }

    const setCookieHeaders = res.headers.getSetCookie?.() ?? [];

    let body = {};
    const text = await res.text().catch(() => '');
    if (text.trim()) {
        try { body = JSON.parse(text); } catch { /* ignore */ }
    }

    return { body, setCookieHeaders };
}

export async function globalSetup(_config) {
    const params = await readParams();

    const storagePath = storageStatePath();
    await mkdir(dirname(storagePath), { recursive: true });

    if (!hasAuthTicket(params)) {
        await writeFile(storagePath, JSON.stringify({ cookies: [], origins: [] }));
        return;
    }

    const mode = (params.auth?.mode ?? 'session').toLowerCase();

    if (mode === 'sanctum') {
        const { body } = await callAuthEndpoint(params);
        if (!body.token) {
            throw new Error('Sanctum mode selected but auth endpoint did not return a token.');
        }
        process.env.PEST_E2E_AUTH_TOKEN = body.token;
        await writeFile(storagePath, JSON.stringify({ cookies: [], origins: [] }));
        return;
    }

    // SESSION: use Playwright's request context so cookies are set natively.
    // This fixes auth on Herd/Windows where manual Set-Cookie parsing fails to produce
    // cookies that the browser sends on subsequent requests.
    const appUrl = process.env.APP_URL || params.baseUrl || 'http://localhost';
    const authUrl = `${appUrl.replace(/\/$/, '')}/pest-e2e/auth/login`;

    const { chromium } = await import('@playwright/test');
    const browser = await chromium.launch();
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
    await browser.close();

    process.env.PEST_E2E_STORAGE_STATE = storagePath;
}
