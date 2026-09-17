/**
 * Parse an array of Set-Cookie header strings into Playwright storageState
 * cookie objects.
 *
 * Playwright storageState.cookies schema (from `context.storageState({path})`):
 * {
 *   name: string
 *   value: string
 *   domain: string
 *   path: string
 *   expires: number     // Unix seconds, -1 for session cookies
 *   httpOnly: boolean
 *   secure: boolean
 *   sameSite: 'Strict' | 'Lax' | 'None'
 * }
 *
 * pest-e2e uses this to build a storageState.json from a native `fetch()` auth
 * POST, so the actual test browser launches already authenticated without
 * paying the ~1s cost of a globalSetup chromium.launch().
 *
 * Inputs come from `Headers.getSetCookie()` (Node ≥18.14 / undici-backed
 * fetch) which returns one raw Set-Cookie string per header — never a single
 * comma-joined string. Do NOT re-parse comma-joined Set-Cookie values here.
 *
 * @param {string[]} setCookieHeaders  Raw Set-Cookie header values, one per header.
 * @param {URL|string} originUrl       URL the Set-Cookie came from — supplies
 *                                     default Domain and Secure=protocol=='https:'.
 * @returns {Array<{
 *   name: string, value: string, domain: string, path: string,
 *   expires: number, httpOnly: boolean, secure: boolean,
 *   sameSite: 'Strict'|'Lax'|'None'
 * }>}
 */
export function parseSetCookies(setCookieHeaders, originUrl) {
    if (!Array.isArray(setCookieHeaders) || setCookieHeaders.length === 0) {
        return [];
    }

    const origin = originUrl instanceof URL ? originUrl : new URL(String(originUrl));
    const defaultDomain = origin.hostname;
    const cookies = [];

    for (const raw of setCookieHeaders) {
        const parsed = parseSetCookie(raw, defaultDomain);

        if (parsed !== null) {
            cookies.push(parsed);
        }
    }

    return cookies;
}

/**
 * @param {string} raw
 * @param {string} defaultDomain
 */
function parseSetCookie(raw, defaultDomain) {
    if (typeof raw !== 'string' || raw.trim() === '') {
        return null;
    }

    // Split on `;` but only outside of double-quoted values, which cookie
    // values may legitimately contain. Set-Cookie doesn't quote-escape `;`
    // inside quoted strings per RFC 6265, so a plain split is sufficient
    // provided we trim each part.
    const parts = raw.split(';').map((p) => p.trim());

    if (parts.length === 0) {
        return null;
    }

    const nameValue = parts[0];
    const eqIndex = nameValue.indexOf('=');

    if (eqIndex <= 0) {
        // No name or empty name — not a valid cookie.
        return null;
    }

    const name = nameValue.slice(0, eqIndex).trim();
    const value = unquote(nameValue.slice(eqIndex + 1).trim());

    if (name === '') {
        return null;
    }

    const cookie = {
        name,
        value,
        domain: defaultDomain,
        path: '/',
        expires: -1,
        httpOnly: false,
        secure: false,
        sameSite: 'Lax',
    };

    let maxAgeSeconds = null;
    let expiresSeconds = null;

    for (let i = 1; i < parts.length; i++) {
        const attr = parts[i];

        if (attr === '') {
            continue;
        }

        const attrEq = attr.indexOf('=');
        const attrName = (attrEq === -1 ? attr : attr.slice(0, attrEq)).trim().toLowerCase();
        const attrValue = attrEq === -1 ? '' : attr.slice(attrEq + 1).trim();

        switch (attrName) {
            case 'domain':
                if (attrValue !== '') {
                    // Strip a single leading dot — Playwright accepts both
                    // ".example.com" and "example.com", but the storage-state
                    // canonical form omits the dot for host-only cookies. Keep
                    // it if the original had it, since some browsers treat the
                    // dot-form as suffix-match.
                    cookie.domain = attrValue.toLowerCase();
                }
                break;
            case 'path':
                if (attrValue !== '') {
                    cookie.path = attrValue;
                }
                break;
            case 'expires':
                if (attrValue !== '') {
                    const parsed = Date.parse(attrValue);

                    if (!Number.isNaN(parsed)) {
                        expiresSeconds = Math.floor(parsed / 1000);
                    }
                }
                break;
            case 'max-age':
                if (attrValue !== '' && /^-?\d+$/.test(attrValue)) {
                    const seconds = parseInt(attrValue, 10);

                    // Max-Age=0 or negative → expire immediately. Represent as
                    // a past Unix timestamp so downstream consumers respect it.
                    maxAgeSeconds = seconds <= 0
                        ? 0
                        : Math.floor(Date.now() / 1000) + seconds;
                }
                break;
            case 'httponly':
                cookie.httpOnly = true;
                break;
            case 'secure':
                cookie.secure = true;
                break;
            case 'samesite':
                cookie.sameSite = normalizeSameSite(attrValue);
                break;
            default:
                // Unknown attributes ignored per RFC 6265 §5.2.
                break;
        }
    }

    // Max-Age takes precedence over Expires per RFC 6265 §5.3.
    if (maxAgeSeconds !== null) {
        cookie.expires = maxAgeSeconds;
    } else if (expiresSeconds !== null) {
        cookie.expires = expiresSeconds;
    }

    return cookie;
}

/**
 * @param {string} v
 */
function unquote(v) {
    if (v.length >= 2 && v.startsWith('"') && v.endsWith('"')) {
        return v.slice(1, -1);
    }

    return v;
}

/**
 * @param {string} v
 * @returns {'Strict'|'Lax'|'None'}
 */
function normalizeSameSite(v) {
    switch (v.toLowerCase()) {
        case 'strict':
            return 'Strict';
        case 'none':
            return 'None';
        case 'lax':
        default:
            return 'Lax';
    }
}
