import { test } from 'node:test';
import assert from 'node:assert/strict';
import { parseSetCookies } from '../../resources/js/pest-e2e/setCookieParser.mjs';

const AUTH_URL = 'http://127.0.0.1:8801/pest-e2e/auth/login';

test('returns [] for empty/absent input', () => {
    assert.deepEqual(parseSetCookies([], AUTH_URL), []);
    assert.deepEqual(parseSetCookies(undefined, AUTH_URL), []);
    assert.deepEqual(parseSetCookies(null, AUTH_URL), []);
});

test('parses a minimal Laravel session cookie with all Playwright attributes', () => {
    const raw = [
        'laravel_session=eyJpdiI6ImFiYyJ9; Expires=Wed, 15 Oct 2025 07:28:00 GMT; Max-Age=7200; Path=/; Domain=127.0.0.1; HttpOnly; SameSite=Lax',
    ];

    const cookies = parseSetCookies(raw, AUTH_URL);

    assert.equal(cookies.length, 1);
    const c = cookies[0];

    assert.equal(c.name, 'laravel_session');
    assert.equal(c.value, 'eyJpdiI6ImFiYyJ9');
    assert.equal(c.path, '/');
    assert.equal(c.domain, '127.0.0.1');
    assert.equal(c.httpOnly, true);
    assert.equal(c.secure, false);
    assert.equal(c.sameSite, 'Lax');
    // Max-Age wins over Expires. Now-ish, ± a few seconds.
    const nowSec = Math.floor(Date.now() / 1000);
    assert.ok(
        Math.abs(c.expires - (nowSec + 7200)) < 10,
        `expected Max-Age=7200 to resolve to now+7200s (got ${c.expires}, now=${nowSec})`,
    );
});

test('decomposes multiple Set-Cookie headers without comma-splitting Expires dates', () => {
    // This is the trap: naive `header.split(",")` on a comma-joined Set-Cookie
    // will slice the RFC 1123 date ("Wed, 15 Oct 2025 ...") in half. Node's
    // Headers.getSetCookie() returns one entry per header — no comma-split
    // needed. parseSetCookies() must accept that shape correctly.
    const raw = [
        'XSRF-TOKEN=abc; Path=/; SameSite=Lax; Expires=Wed, 15 Oct 2025 07:28:00 GMT',
        'laravel_session=def; Path=/; HttpOnly; SameSite=Lax; Expires=Wed, 15 Oct 2025 07:28:00 GMT',
    ];

    const cookies = parseSetCookies(raw, AUTH_URL);

    assert.equal(cookies.length, 2);
    assert.equal(cookies[0].name, 'XSRF-TOKEN');
    assert.equal(cookies[0].value, 'abc');
    assert.equal(cookies[0].httpOnly, false);
    assert.equal(cookies[1].name, 'laravel_session');
    assert.equal(cookies[1].value, 'def');
    assert.equal(cookies[1].httpOnly, true);
});

test('handles quoted values (RFC 6265 double-quoted form)', () => {
    const raw = ['token="opaque value with spaces"; Path=/'];

    const cookies = parseSetCookies(raw, AUTH_URL);

    assert.equal(cookies.length, 1);
    assert.equal(cookies[0].value, 'opaque value with spaces');
});

test('falls back to the origin URL host when Domain is absent', () => {
    const raw = ['plain=1; Path=/'];

    const cookies = parseSetCookies(raw, 'http://127.0.0.1:8802/pest-e2e/auth/login');

    assert.equal(cookies.length, 1);
    assert.equal(cookies[0].domain, '127.0.0.1');
    assert.equal(cookies[0].path, '/');
});

test('normalises SameSite spelling', () => {
    const strict = parseSetCookies(['a=1; SameSite=strict'], AUTH_URL);
    const lax = parseSetCookies(['b=1; SameSite=lax'], AUTH_URL);
    const none = parseSetCookies(['c=1; SameSite=NONE'], AUTH_URL);
    const missing = parseSetCookies(['d=1'], AUTH_URL);

    assert.equal(strict[0].sameSite, 'Strict');
    assert.equal(lax[0].sameSite, 'Lax');
    assert.equal(none[0].sameSite, 'None');
    assert.equal(missing[0].sameSite, 'Lax');
});

test('records Secure and HttpOnly flags without values', () => {
    const cookies = parseSetCookies(
        ['session=1; HttpOnly; Secure; Path=/'],
        'https://example.test/x',
    );

    assert.equal(cookies[0].httpOnly, true);
    assert.equal(cookies[0].secure, true);
});

test('parses Expires alone (no Max-Age) as Unix seconds', () => {
    const raw = ['e=1; Expires=Wed, 15 Oct 2025 07:28:00 GMT; Path=/'];

    const cookies = parseSetCookies(raw, AUTH_URL);

    // 2025-10-15T07:28:00Z → 1760513280
    assert.equal(cookies[0].expires, 1760513280);
});

test('Max-Age=0 is treated as immediate expiry', () => {
    const raw = ['gone=x; Max-Age=0; Path=/'];

    const cookies = parseSetCookies(raw, AUTH_URL);

    assert.equal(cookies[0].expires, 0);
});

test('emits expires=-1 (session) when neither Expires nor Max-Age is present', () => {
    const cookies = parseSetCookies(['s=1; Path=/'], AUTH_URL);

    assert.equal(cookies[0].expires, -1);
});

test('skips malformed entries without a name', () => {
    const cookies = parseSetCookies(
        ['=novalue; Path=/', '; only-attr'],
        AUTH_URL,
    );

    assert.deepEqual(cookies, []);
});

test('preserves cookie insertion order (matches Playwright storage-state semantics)', () => {
    const raw = [
        'first=1; Path=/',
        'second=2; Path=/',
        'third=3; Path=/',
    ];

    const cookies = parseSetCookies(raw, AUTH_URL);

    assert.deepEqual(
        cookies.map((c) => c.name),
        ['first', 'second', 'third'],
    );
});

test('output shape matches Playwright storage-state cookie schema (all fields present)', () => {
    const cookies = parseSetCookies(['x=y'], AUTH_URL);
    const keys = Object.keys(cookies[0]).sort();

    assert.deepEqual(keys, [
        'domain',
        'expires',
        'httpOnly',
        'name',
        'path',
        'sameSite',
        'secure',
        'value',
    ]);
});
