import { spawn } from 'child_process';
import { mkdir } from 'fs/promises';
import { dirname } from 'path';
import { Convert } from './convert.mjs';
import { getConfig } from '../core.mjs';

/**
 * Run Playwright tests and convert the report to canonical format.
 */
async function main() {
    const { rawReportPath, canonicalReportPath, target, runId, testFilter, browse, debug } = getConfig();
    const convert = new Convert(rawReportPath, canonicalReportPath, target, runId);

    await mkdir(dirname(rawReportPath), { recursive: true });
    await mkdir(dirname(canonicalReportPath), { recursive: true });

    let exitCode = 1;

    try {
        const { code, stdout, stderr } = await runPlaywright(rawReportPath, testFilter, browse, debug);
        exitCode = code;

        await convert.init();

        if (exitCode !== 0 && (stdout.trim().startsWith('Error:') || stderr.trim().startsWith('Error:'))) {
            let msg = stripAnsi(stdout.trim().startsWith('Error:') ? stdout : stderr).trim();
            const isTestNotFound = msg.includes('No tests found');
            msg = isTestNotFound ? 'The test [' + testFilter + '] was not found' : msg;

            await convert.writeSyntheticFailureReport(msg, isTestNotFound ? 'Test not found' : 'E2E harness failed');
            process.exit(exitCode || 1);
            return;
        }

        try {
            await convert.convert();
        } catch (convertError) {
            await convert.writeSyntheticFailureReport(
                `Playwright exited with code ${exitCode}.\n\nSTDERR:\n${tail(stripAnsi(stderr))}\n\nSTDOUT:\n${tail(stripAnsi(stdout))}`
            );
        }
    } catch (error) {
        const message = error?.stack || error?.message || String(error);

        try {
            await convert.writeSyntheticFailureReport(message);
        } catch (error) {
            throw error;
        }

        exitCode = 1;
    }

    process.exit(exitCode);
}

/**
 * Execute Playwright tests with JSON reporter output written to a file.
 *
 * @param {string} rawReportPath - Path where Playwright writes its JSON report
 * @param {string} testFilter - Test filter
 * @param {boolean} browse - Whether to run in browse mode
 * @param {boolean} debug - Whether to run in debug mode
 * @returns {Promise<number>} Exit code from Playwright process
 */
function runPlaywright(rawReportPath, testFilter, browse, debug) {
    return new Promise((resolve, reject) => {
        const args = [
            'playwright',
            'test',
            ...(testFilter ? ['--grep', escapeRegex(testFilter)] : []),
            ...(browse || debug ? ['--headed'] : []),
            ...(debug ? ['--debug'] : []),
        ];

        const additionalArgs = process.argv.slice(2);
        args.push(...additionalArgs);

        const child = spawn('npx', args, {
            stdio: ['ignore', 'pipe', 'pipe'],
            cwd: process.cwd(),
            env: {
                ...process.env,
                PLAYWRIGHT_JSON_OUTPUT_FILE: rawReportPath,
            }
        });


        let stdout = '';
        let stderr = '';

        child.stdout.on('data', (d) => { stdout += d.toString('utf8'); });
        child.stderr.on('data', (d) => { stderr += d.toString('utf8'); });

        child.on('close', (code) => {
            resolve({
                code: typeof code === 'number' ? code : 1,
                stdout,
                stderr,
            });
        });

        child.on('error', (error) => {
            reject(new Error(`Failed to start Playwright process: ${error.message}`));
        });
    });
}

/**
 * Escape a string for use in a regular expression.
 *
 * @param {string} s - String to escape
 * @returns {string} Escaped string
 */
function escapeRegex(s) {
    return String(s).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

/**
 * Strip ANSI escape sequences (e.g. cursor movement, line erase) from a string.
 * Playwright emits these for progress display; when captured they corrupt output.
 *
 * @param {string} s - String that may contain ANSI escape sequences
 * @returns {string} String with escape sequences removed
 */
function stripAnsi(s) {
    return (s ?? '').replace(/\x1b\[[0-9;]*[a-zA-Z]/g, '');
}

/**
 * Get the last part of a string.
 *
 * @param {string} s - String to get the last part of
 * @param {number} max - Maximum length of the string
 * @returns {string} Last part of the string
 */
function tail(s, max = 4000) {
    s = (s ?? '').trim();
    if (!s) return '(no output)';
    return s.length > max ? `[truncated]\n${s.slice(-max)}` : s;
}

main().catch((error) => {
    console.error('Unhandled error:', error);
    process.exit(1);
});
