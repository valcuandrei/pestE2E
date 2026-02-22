import { readFile, writeFile } from 'fs/promises';

export class Convert {
    #rawReportPath;
    #rawReport;
    #canonicalReportPath;
    #target;
    #runId;

    constructor(rawReportPath, canonicalReportPath, target, runId) {
        this.#rawReportPath = rawReportPath;
        this.#canonicalReportPath = canonicalReportPath;
        this.#target = target;
        this.#runId = runId;
    }

    async init() {
        this.#rawReport = await this.#readReport(this.#rawReportPath);
    }


    /**
     * Convert Playwright JSON report to PestE2E canonical JSON schema (v1).
     *
     * @returns {Promise<void>}
     */
    async convert() {
        const canonicalReport = this.#transformToCanonical();

        try {
            await writeFile(this.#canonicalReportPath, JSON.stringify(canonicalReport, null, 2));
        } catch (error) {
            throw new Error(`Failed to write canonical report to ${this.#canonicalReportPath}: ${error.message}`);
        }
    }

    /**
     * Write a minimal canonical failure report so the PHP side can display a useful error.
     *
     * @param {string} message - Error message
     * @returns {Promise<void>}
     */
    async writeSyntheticFailureReport(message, name = 'E2E harness failed') {
        const report = {
            schema: 'pest-e2e.v1',
            target: this.#target,
            runId: this.#runId,
            stats: { passed: 0, failed: 1, skipped: 0, durationMs: 0 },
            tests: [
                {
                    name: name,
                    status: 'failed',
                    file: null,
                    durationMs: null,
                    id: null,
                    error: { message: String(message), stack: null },
                    artifacts: { trace: null, video: null, screenshots: [] },
                },
            ],
        };

        await writeFile(this.#canonicalReportPath, JSON.stringify(report, null, 2), 'utf8');
    }

    /**
     * Read a report from a file.
     *
     * @param {string} reportPath - Path where to read the report
     * @returns {Promise<Object>} Report
     */
    async #readReport(reportPath) {
        try {
            const content = await readFile(reportPath, 'utf8');
            return JSON.parse(content);
        } catch (error) {
            throw new Error(`Failed to read report from ${reportPath}: ${error.message}`);
        }
    }

    /**
     * Transform a Playwright JSON report to canonical format.
     *
     * @returns {Object} Canonical report object
     */
    #transformToCanonical() {
        const stats = {
            passed: 0,
            failed: 0,
            skipped: 0,
            durationMs: 0
        };

        const tests = [];
        const suites = this.#rawReport.suites || [];
        this.#extractTests(suites, tests, stats);

        return {
            schema: "pest-e2e.v1",
            target: this.#target,
            runId: this.#runId,
            stats,
            tests
        };
    }

    /**
     * Recursively extract tests from Playwright suites.
     *
     * @param {Array} suites - Playwright test suites
     * @param {Array} tests - Accumulator for canonical test objects
     * @param {Object} stats - Stats accumulator
     */
    #extractTests(suites, tests, stats) {
        for (const suite of suites) {
            const file = suite.file;
            // Process nested suites recursively
            if (suite.suites?.length > 0) {
                this.#extractTests(suite.suites, tests, stats, file);
            }

            // Process tests in this suite
            if (suite.specs?.length > 0) {
                for (const spec of suite.specs) {
                    this.#processSpec(spec, tests, stats, file);
                }
            }
        }
    }

    /**
     * Process a single Playwright test spec.
     *
     * @param {Object} spec - Playwright test spec
     * @param {Array} tests - Accumulator for canonical test objects
     * @param {Object} stats - Stats accumulator
     * @param {string} file - File path
     */
    #processSpec(spec, tests, stats, file = null) {
        for (const test of spec.tests || []) {
            for (const result of test.results || []) {
                const canonicalTest = this.#transformTest(spec, test, result, file);
                tests.push(canonicalTest);

                // Update stats
                stats[canonicalTest.status]++;
                stats.durationMs += canonicalTest.durationMs || 0;
            }
        }
    }

    /**
     * Transform a single Playwright test to canonical format.
     *
     * @param {Object} spec - Playwright spec object (has the title)
     * @param {Object} test - Playwright test object (has projectName, results)
     * @param {Object} result - Playwright test result
     * @param {string} file - File path
     * @returns {Object} Canonical test object or null if the test is not found in the standard output
     */
    #transformTest(spec, test, result, file = null) {
        // The title lives on the spec, not on the test
        let name = spec.title || test.title || 'unknown test';

        // Add project prefix if multiple projects exist
        if (this.#rawReport.config?.projects.length > 1 && test.projectName) {
            name = `[${test.projectName}] ${name}`;
        }

        // Map status
        const status = this.#mapPlaywrightStatus(result.status);

        // Extract error if present
        let error = null;
        if (status === 'failed' && result.errors?.length > 0) {
            error = this.#extractError(result.errors[0]);
        }

        // Calculate duration
        const durationMs = result.duration || this.#calculateDurationFromTimestamps(result);

        const canonicalTest = {
            name,
            status,
            durationMs,
        };

        if (file) {
            canonicalTest.file = file;
        }

        if (error) {
            canonicalTest.error = error;
        }

        if (result.stdout.length > 0 || result.stderr.length > 0) {
            canonicalTest.extraLines = [
                ...(result.stdout || []).map(line => (line.text || '').trim().replace(/\r?\n$/, '')),
                ...(result.stderr || []).map(line => (line.text || '').trim().replace(/\r?\n$/, ''))
            ];
        }

        return canonicalTest;
    }

    /**
     * Map Playwright test status to canonical status.
     *
     * @param {string} playwrightStatus - Playwright test status
     * @returns {string} Canonical status
     */
    #mapPlaywrightStatus(playwrightStatus) {
        switch (playwrightStatus) {
            case 'passed':
                return 'passed';
            case 'skipped':
                return 'skipped';
            case 'failed':
            case 'timedOut':
            case 'interrupted':
                return 'failed';
            default:
                return 'failed';
        }
    }

    /**
     * Extract readable error message from Playwright error.
     *
     * @param {Object} playwrightError - Playwright error object
     * @returns {Object} Canonical error object
     */
    #extractError(playwrightError) {
        let message = playwrightError.message || 'Test failed';

        // Append stack trace if available for better debugging
        if (playwrightError.stack) {
            // Clean up stack trace - keep it readable but concise
            const cleanStack = playwrightError.stack
                .split('\n')
                .slice(0, 10) // Limit to first 10 lines
                .join('\n');

            message = `${message}\n\nStack trace:\n${cleanStack}`;
        }

        return {
            message: message.trim()
        };
    }

    /**
     * Calculate duration from timestamps if duration is not provided.
     *
     * @param {Object} result - Playwright test result
     * @returns {number} Duration in milliseconds
     */
    #calculateDurationFromTimestamps(result) {
        if (result.startTime && result.endTime) {
            return new Date(result.endTime).getTime() - new Date(result.startTime).getTime();
        }

        return 0;
    }
}
