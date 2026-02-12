import { readFile, writeFile } from 'fs/promises';

/**
 * Convert Playwright JSON report to PestE2E canonical JSON schema (v1).
 * 
 * @param {string} rawReportPath - Path to the raw Playwright JSON report
 * @param {string} canonicalReportPath - Path where to write the canonical report
 * @param {string} target - Target name (from PEST_E2E_TARGET)
 * @param {string} runId - Run ID (from PEST_E2E_RUN_ID)
 * @returns {Promise<void>}
 */
export async function convertPlaywrightReport(rawReportPath, canonicalReportPath, target, runId) {
    let rawReport;
    
    try {
        const rawContent = await readFile(rawReportPath, 'utf8');
        rawReport = JSON.parse(rawContent);
    } catch (error) {
        throw new Error(`Failed to read or parse raw Playwright report from ${rawReportPath}: ${error.message}`);
    }

    const canonicalReport = transformToCanonical(rawReport, target, runId);
    
    try {
        await writeFile(canonicalReportPath, JSON.stringify(canonicalReport, null, 2));
    } catch (error) {
        throw new Error(`Failed to write canonical report to ${canonicalReportPath}: ${error.message}`);
    }
}

/**
 * Transform a Playwright JSON report to canonical format.
 * 
 * @param {Object} rawReport - Raw Playwright JSON report
 * @param {string} target - Target name
 * @param {string} runId - Run ID
 * @returns {Object} Canonical report object
 */
function transformToCanonical(rawReport, target, runId) {
    const stats = {
        passed: 0,
        failed: 0,
        skipped: 0,
        durationMs: 0
    };
    
    const tests = [];
    const suites = rawReport.suites || [];
    
    // Extract tests from suites recursively
    extractTests(suites, tests, stats, rawReport.config?.projects);
    
    return {
        schema: "pest-e2e.v1",
        target,
        runId,
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
 * @param {Array} projects - Playwright projects configuration
 */
function extractTests(suites, tests, stats, projects = [], parentFile = null) {
    for (const suite of suites) {
        const file = suite.file || parentFile;
        
        // Process nested suites recursively
        if (suite.suites?.length > 0) {
            extractTests(suite.suites, tests, stats, projects, file);
        }
        
        // Process tests in this suite
        if (suite.specs?.length > 0) {
            for (const spec of suite.specs) {
                processSpec(spec, tests, stats, projects, file);
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
 * @param {Array} projects - Playwright projects configuration
 */
function processSpec(spec, tests, stats, projects = [], file = null) {
    for (const test of spec.tests || []) {
        for (const result of test.results || []) {
            const canonicalTest = transformTest(spec, test, result, projects, file);
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
 * @param {Array} projects - Playwright projects configuration
 * @returns {Object} Canonical test object
 */
function transformTest(spec, test, result, projects = [], file = null) {
    // The title lives on the spec, not on the test
    let name = spec.title || test.title || 'unknown test';
    
    // Add project prefix if multiple projects exist
    if (projects.length > 1 && test.projectName) {
        name = `[${test.projectName}] ${name}`;
    }
    
    // Map status
    const status = mapPlaywrightStatus(result.status);
    
    // Extract error if present
    let error = null;
    if (status === 'failed' && result.errors?.length > 0) {
        error = extractError(result.errors[0]);
    }
    
    // Calculate duration
    const durationMs = result.duration || calculateDurationFromTimestamps(result);
    
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
    
    return canonicalTest;
}

/**
 * Map Playwright test status to canonical status.
 * 
 * @param {string} playwrightStatus - Playwright test status
 * @returns {string} Canonical status
 */
function mapPlaywrightStatus(playwrightStatus) {
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
function extractError(playwrightError) {
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
function calculateDurationFromTimestamps(result) {
    if (result.startTime && result.endTime) {
        return new Date(result.endTime).getTime() - new Date(result.startTime).getTime();
    }
    
    return 0;
}