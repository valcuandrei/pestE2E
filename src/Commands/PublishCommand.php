<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Commands;

use Illuminate\Console\Command;

final class PublishCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'pest-e2e:publish {--force : Overwrite existing files}';

    /**
     * The console command description.
     */
    protected $description = 'Publish Pest E2E JavaScript assets to resources/js/pest-e2e';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Publishing Pest E2E JavaScript assets...');

        $options = [];

        if ($this->option('force')) {
            $options['--force'] = true;
        }

        $result = $this->call('vendor:publish', array_merge([
            '--tag' => 'pest-e2e-js',
        ], $options));

        if ($result === 0) {
            $this->line('Next steps:');
            $this->line('1. Import globalSetup in your Playwright config:');
            $this->line("   <fg=yellow>import { globalSetup } from './resources/js/pest-e2e/playwright.mjs';</>");
            $this->line('   <fg=red>Note: If importing with .ts fails, remove the extension.</>');
            $this->line('');
            $this->line('2. Add globalSetup to your playwright.config.ts:');
            $this->line('   <fg=yellow>export default defineConfig({ globalSetup });</>');
            $this->line('');
            $this->line('3. Session mode:');
            $this->line('   <fg=yellow>use: { storageState: process.env.PEST_E2E_STORAGE_STATE }</>');
            $this->line('');
            $this->line('4. Sanctum mode:');
            $this->line("   <fg=yellow>use: { extraHTTPHeaders: process.env.PEST_E2E_AUTH_TOKEN ? { Authorization: 'Bearer ' + process.env.PEST_E2E_AUTH_TOKEN } : {} }</>");

            return self::SUCCESS;
        }

        $this->error('Failed to publish assets');

        return self::FAILURE;
    }
}
