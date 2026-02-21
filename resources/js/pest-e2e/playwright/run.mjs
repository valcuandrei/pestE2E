import { spawn } from 'child_process';
import { mkdir } from 'fs/promises';
import { dirname } from 'path';
import { Convert } from './convert.mjs';

/**
 * Run Playwright tests and convert the report to canonical format.
 *
 * Environment variables:
 * - PEST_E2E_TARGET: Target name (required)
 * - PEST_E2E_RUN_ID: Run ID (required)
 * - PEST_E2E_REPORT_PATH: Path for canonical report (optional, defaults to .pest-e2e/<runId>/report.json)
 */
async function main() {
    const target = process.env.PEST_E2E_TARGET;
    const runId = process.env.PEST_E2E_RUN_ID;

    if (!target) {
        console.error('Error: PEST_E2E_TARGET environment variable is required');
        process.exit(1);
    }

    if (!runId) {
        console.error('Error: PEST_E2E_RUN_ID environment variable is required');
        process.exit(1);
    }

    const rawReportPath = `.pest-e2e/${runId}/playwright-report.json`;
    const canonicalReportPath = process.env.PEST_E2E_REPORT_PATH || `.pest-e2e/${runId}/report.json`;
    const convert = new Convert(rawReportPath, canonicalReportPath, target, runId);

    await mkdir(dirname(rawReportPath), { recursive: true });
    await mkdir(dirname(canonicalReportPath), { recursive: true });

    let exitCode = 1;

    try {
        exitCode = await runPlaywright(rawReportPath);
        await convert.init();

        try {
            await convert.convert();
        } catch (convertError) {
            await convert.writeSyntheticFailureReport(
                'Playwright exited with code ' + exitCode + '. Additionally, report conversion failed: ' + (convertError?.message ?? String(convertError))
            );
        }
    } catch (error) {
        const message = error?.stack || error?.message || String(error);

        try {
            await convert.writeSyntheticFailureReport(message);
        } catch {
        }

        exitCode = 1;
    }

    process.exit(exitCode);
}

/**
 * Execute Playwright tests with JSON reporter output written to a file.
 *
 * @param {string} rawReportPath - Path where Playwright writes its JSON report
 * @returns {Promise<number>} Exit code from Playwright process
 */
function runPlaywright(rawReportPath) {
    return new Promise((resolve, reject) => {
        const args = [
            'playwright',
            'test',
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

        child.on('close', (code) => {
            resolve(typeof code === 'number' ? code : 1);
        });

        child.on('error', (error) => {
            reject(new Error(`Failed to start Playwright process: ${error.message}`));
        });
    });
}

main().catch((error) => {
    console.error('Unhandled error:', error);
    process.exit(1);
});
