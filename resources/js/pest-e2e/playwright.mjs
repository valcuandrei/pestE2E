import { mkdir, writeFile } from 'fs/promises';
import { dirname } from 'path';
import { chromium } from '@playwright/test';
import { readParams, hasAuthTicket, getAuthEndpoint } from './core.mjs';

/**
 * Get the storage state path.
 */
export function storageStatePath() {
    const runId = process.env.PEST_E2E_RUN_ID || Date.now().toString();
    return `.pest-e2e/${runId}/storageState.json`;
}

/**
 * Call the Sanctum auth endpoint.
 */
async function callSanctumAuthEndpoint(params) {
    const authUrl = getAuthEndpoint(params);

    const res = await fetch(authUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Pest-E2E': '1' },
        body: JSON.stringify({
            ticket: params.auth?.ticket,
            mode: params.auth?.mode,
            guard: params.auth?.guard,
            meta: params.auth?.meta ?? {},
        }),
    });

    if (!res.ok) {
        const body = await res.text().catch(() => '');
        throw new Error(`Auth endpoint failed (${res.status}): ${body}`);
    }

    // session mode returns {}
    // sanctum returns { token: string }
    const text = await res.text();

    if (!text.trim()) return {};

    try {
        return JSON.parse(text);
    } catch {
        return {};
    }
}

export async function globalSetup(_config) {
    const params = await readParams();

    // Always create the storageState file so the config can reference it
    const storagePath = storageStatePath();
    await mkdir(dirname(storagePath), { recursive: true });

    if (!hasAuthTicket(params)) {
        // Write empty storage state so Playwright doesn't error on missing file
        await writeFile(storagePath, JSON.stringify({ cookies: [], origins: [] }));
        return;
    }

    const mode = (params.auth?.mode ?? 'session').toLowerCase();

    // SANCTUM: just fetch token and expose it
    if (mode === 'sanctum') {
        const { token } = await callSanctumAuthEndpoint(params);

        if (!token) {
            throw new Error('Sanctum mode selected but auth endpoint did not return a token.');
        }

        process.env.PEST_E2E_AUTH_TOKEN = token;
        return;
    }

    // SESSION: use Playwright to capture cookies and persist storageState
    const browser = await chromium.launch();
    const context = await browser.newContext();

    const authUrl = getAuthEndpoint(params);

    const res = await context.request.post(authUrl, {
        data: {
            ticket: params.auth?.ticket,
            mode: params.auth?.mode,
            guard: params.auth?.guard,
            meta: params.auth?.meta ?? {},
        },
        headers: {
            'X-Pest-E2E': '1',
        },
    });

    if (!res.ok()) {
        throw new Error(`Auth endpoint failed (${res.status()}): ${await res.text()}`);
    }

    await context.storageState({ path: storagePath });
    await context.close();
    await browser.close();

    process.env.PEST_E2E_STORAGE_STATE = storagePath;
}
