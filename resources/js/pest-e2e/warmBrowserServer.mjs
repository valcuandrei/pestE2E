#!/usr/bin/env node
/**
 * Persistent Playwright browser server, one per ParaTest worker.
 *
 * Started by the PHP-side {@see \ValcuAndrei\PestE2E\Support\WarmBrowserManager}
 * on the first `e2e(...)` execution of a Pest worker; subsequent pest-e2e
 * invocations on that worker connect to this server via the wsEndpoint
 * exposed by Playwright's `chromium.launchServer()`. Each connecting
 * Playwright process creates its own browser context (per its config), so
 * per-test isolation for cookies/localStorage/session storage is preserved
 * — only the browser process itself is shared, not the context/page state.
 *
 * Protocol
 * --------
 * On successful launch this process prints ONE line of JSON to stdout:
 *
 *     {"wsEndpoint":"ws://127.0.0.1:PORT/...", "pid": <chromium-main-pid?>, "worker":"<TEST_TOKEN or 'solo'>"}
 *
 * followed by a newline. The PHP manager waits for that line, extracts the
 * endpoint, and injects it into every Playwright child as
 * PEST_E2E_WARM_WS_ENDPOINT. Any additional stdout after the JSON line is
 * routed to the diagnostic channel — this process does not chatter under
 * normal operation.
 *
 * Shutdown
 * --------
 * SIGTERM / SIGINT / stdin close → `browserServer.close()`, then exit(0).
 * The PHP manager registers this in Laravel's shutdown handler and in the
 * `TestSuite\Finished` subscriber. No orphan chromium processes should
 * survive the pest run.
 */

import { chromium } from '@playwright/test';

async function main() {
    const server = await chromium.launchServer({
        // Headless-only for the warm browser; --browse tests spawn their own
        // headed instance and don't share this server.
        headless: true,
    });

    const wsEndpoint = server.wsEndpoint();

    // Handshake: emit exactly one line of JSON. The PHP side parses this;
    // anything else printed before it will confuse the handshake.
    const worker = process.env.TEST_TOKEN || 'solo';
    process.stdout.write(JSON.stringify({ wsEndpoint, worker }) + '\n');

    const shutdown = async (signal) => {
        try {
            await server.close();
        } catch (err) {
            // Ignore — process is exiting.
        }
        process.exit(signal === 'error' ? 1 : 0);
    };

    process.on('SIGTERM', () => shutdown('SIGTERM'));
    process.on('SIGINT', () => shutdown('SIGINT'));
    process.on('SIGHUP', () => shutdown('SIGHUP'));

    // If the parent process disappears (pest exited without cleanup), stdin
    // will close. Use that as an additional shutdown trigger.
    process.stdin.on('end', () => shutdown('stdin-end'));
    process.stdin.on('close', () => shutdown('stdin-close'));
    process.stdin.resume();
}

main().catch((err) => {
    // Diagnostic to stderr; PHP surfaces it in the timeout error.
    process.stderr.write('pest-e2e warm-browser server failed: ' + (err && err.stack ? err.stack : String(err)) + '\n');
    process.exit(1);
});
