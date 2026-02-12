import { spawn } from 'child_process';
import { mkdir } from 'fs/promises';
import { dirname } from 'path';
import { convertPlaywrightReport } from './convert.mjs';

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
    
    // Determine paths
    const rawReportPath = `.pest-e2e/${runId}/playwright-report.json`;
    const canonicalReportPath = process.env.PEST_E2E_REPORT_PATH || `.pest-e2e/${runId}/report.json`;
    
    try {
        // Ensure output directories exist
        await mkdir(dirname(rawReportPath), { recursive: true });
        await mkdir(dirname(canonicalReportPath), { recursive: true });
        
        // Run Playwright with JSON reporter, writing to rawReportPath via env var
        const exitCode = await runPlaywright(rawReportPath);
        
        // Convert the raw Playwright JSON report to canonical pest-e2e format
        await convertPlaywrightReport(rawReportPath, canonicalReportPath, target, runId);
        
        // Exit with 0 so the PHP side reads the report and handles pass/fail itself
        process.exit(0);
        
    } catch (error) {
        console.error('Error running Playwright tests:', error.message);
        process.exit(1);
    }
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
        
        // Add any additional arguments passed to this script (e.g. --grep)
        const additionalArgs = process.argv.slice(2);
        args.push(...additionalArgs);
        
        // Spawn Playwright process.
        // PLAYWRIGHT_JSON_OUTPUT_FILE tells the built-in json reporter where to write.
        const child = spawn('npx', args, {
            stdio: 'inherit',
            cwd: process.cwd(),
            env: {
                ...process.env,
                PLAYWRIGHT_JSON_OUTPUT_FILE: rawReportPath,
            }
        });
        
        child.on('close', (code) => {
            resolve(code || 0);
        });
        
        child.on('error', (error) => {
            reject(new Error(`Failed to start Playwright process: ${error.message}`));
        });
    });
}

// Run the main function
main().catch((error) => {
    console.error('Unhandled error:', error);
    process.exit(1);
});