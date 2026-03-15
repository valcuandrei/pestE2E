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

/**
 * Parse Set-Cookie headers into Playwright-compatible cookie objects.
 */
function parseSetCookieHeaders(headers, baseUrl) {
    const url = new URL(baseUrl);

    return headers.map(header => {
        const parts = header.split(';').map(p => p.trim());
        const [nameValue, ...attrs] = parts;
        const eqIdx = nameValue.indexOf('=');
        const name = nameValue.substring(0, eqIdx);
        const value = nameValue.substring(eqIdx + 1);

        const cookie = {
            name,
            value,
            domain: url.hostname,
            path: '/',
            expires: -1,
            httpOnly: false,
            secure: false,
            sameSite: 'Lax',
        };

        for (const attr of attrs) {
            const lower = attr.toLowerCase();
            if (lower === 'httponly') { cookie.httpOnly = true; continue; }
            if (lower === 'secure') { cookie.secure = true; continue; }
            if (lower.startsWith('path=')) { cookie.path = attr.split('=')[1]; continue; }
            if (lower.startsWith('domain=')) { cookie.domain = attr.split('=')[1]; continue; }
            if (lower.startsWith('samesite=')) {
                const val = attr.split('=')[1];
                cookie.sameSite = val.charAt(0).toUpperCase() + val.slice(1).toLowerCase();
                continue;
            }
            if (lower.startsWith('expires=')) {
                const ts = Date.parse(attr.substring('expires='.length));
                if (!isNaN(ts)) cookie.expires = ts / 1000;
                continue;
            }
            if (lower.startsWith('max-age=')) {
                const seconds = parseInt(attr.split('=')[1], 10);
                if (!isNaN(seconds)) cookie.expires = (Date.now() / 1000) + seconds;
            }
        }

        return cookie;
    });
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

    const { body, setCookieHeaders } = await callAuthEndpoint(params);

    if (mode === 'sanctum') {
        if (!body.token) {
            throw new Error('Sanctum mode selected but auth endpoint did not return a token.');
        }

        process.env.PEST_E2E_AUTH_TOKEN = body.token;
        await writeFile(storagePath, JSON.stringify({ cookies: [], origins: [] }));
        return;
    }

    // SESSION: parse Set-Cookie headers into Playwright storageState format
    const appUrl = process.env.APP_URL || params.baseUrl || 'http://localhost';
    const cookies = parseSetCookieHeaders(setCookieHeaders, appUrl);

    await writeFile(storagePath, JSON.stringify({ cookies, origins: [] }));
    process.env.PEST_E2E_STORAGE_STATE = storagePath;
}
