<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Install;

/**
 * Immutable snapshot of user choices resolved before steps run (CLI flags + prompts + probe checks).
 */
final readonly class InstallPlan
{
    /**
     * @param  bool  $addCsrfExclusion  Patch bootstrap for CSRF exclusion on pest-e2e auth.
     * @param  bool  $publishBaseTestCase  Publish `pest-e2e-test-case` tag.
     * @param  bool  $updatePestConfig  Extend Pest.php with E2ETestCase when missing.
     * @param  bool  $publishConfig  Publish `pest-e2e-config` tag.
     * @param  bool  $installPlaywright  Install npm package + `playwright install` when not already present.
     * @param  bool  $publishJsHarness  Publish `pest-e2e-js-harness` tag.
     * @param  bool  $publishJsPlaywright  Publish `pest-e2e-js-playwright` tag (after Playwright available).
     * @param  bool  $publishBrowserTests  Publish `pest-e2e-browser-tests` tag.
     * @param  bool  $publishPlaywrightTests  Publish `pest-e2e-playwright-tests` tag.
     * @param  bool  $setupEnvTesting  Create `.env.testing`.
     * @param  bool  $setupTestingDatabase  Create `database/testing.sqlite`.
     * @param  bool  $configurePhpunit  Comment phpunit env vars for `.env.testing`.
     * @param  bool  $mergeSailWslgHeaded  Merge WSLg settings into Sail compose `laravel.test`.
     */
    public function __construct(
        public bool $addCsrfExclusion,
        public bool $publishBaseTestCase,
        public bool $updatePestConfig,
        public bool $publishConfig,
        public bool $installPlaywright,
        public bool $publishJsHarness,
        public bool $publishJsPlaywright,
        public bool $publishBrowserTests,
        public bool $publishPlaywrightTests,
        public bool $setupEnvTesting,
        public bool $setupTestingDatabase,
        public bool $configurePhpunit,
        public bool $mergeSailWslgHeaded,
    ) {}
}
