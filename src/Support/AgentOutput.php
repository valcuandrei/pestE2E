<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Support;

use Pest\Plugins\Parallel;

/**
 * Detects PAO / AI-agent environments for compact JSON E2E summaries.
 *
 * @internal
 */
final class AgentOutput
{
    public static function explicitlyDisabled(): bool
    {
        if (self::truthyEnv('PAO_DISABLE')) {
            return true;
        }

        return self::truthyEnv('PEST_E2E_AGENT_OUTPUT_DISABLE');
    }

    public static function enabled(): bool
    {
        AgentOutputIntent::hydrateEnvironment();

        if (self::explicitlyDisabled()) {
            return false;
        }

        if (self::truthyEnv('PAO_FORCE') || self::truthyEnv('PEST_E2E_AGENT_OUTPUT')) {
            return true;
        }

        if (class_exists(Parallel::class)) {
            $global = Parallel::getGlobal('agent_output');

            if (self::isTruthy($global)) {
                return true;
            }
        }

        if (self::configEnabled()) {
            return true;
        }

        if (class_exists('Laravel\\AgentDetector\\AgentDetector')) {
            return self::detectUsingAgentDetector();
        }

        return self::detectFromKnownEnvVars();
    }

    private static function detectUsingAgentDetector(): bool
    {
        $agentDetector = 'Laravel\\AgentDetector\\AgentDetector';

        if (! in_array('detect', get_class_methods($agentDetector), true)) {
            return false;
        }

        /** @var callable(): mixed $detect */
        $detect = [$agentDetector, 'detect'];
        $detection = $detect();

        if (! is_object($detection) || ! property_exists($detection, 'isAgent')) {
            return false;
        }

        return self::isTruthy($detection->isAgent);
    }

    /**
     * Silence Pest / Collision human-readable output (PAO-compatible).
     */
    public static function silenceTestRunnerOutput(): void
    {
        foreach ([
            'COLLISION_PRINTER',
            'COLLISION_PRINTER_COMPACT',
            'COLLISION_PRINTER_PROFILE',
        ] as $key) {
            unset($_SERVER[$key], $_ENV[$key]);
            putenv($key);
        }

        $_SERVER['PEST_PARALLEL_NO_OUTPUT'] = '1';
    }

    private static function configEnabled(): bool
    {
        if (! function_exists('config')) {
            return false;
        }

        try {
            return self::isTruthy(config('pest-e2e.agent_output'));
        } catch (\Throwable) {
            return false;
        }
    }

    public static function isTruthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (! is_scalar($value)) {
            return false;
        }

        if ($value === '') {
            return false;
        }

        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    }

    private static function detectFromKnownEnvVars(): bool
    {
        $envVars = [
            'AI_AGENT',
            'CURSOR_AGENT',
            'GEMINI_CLI',
            'CODEX_SANDBOX',
            'CODEX_CI',
            'CODEX_THREAD_ID',
            'AUGMENT_AGENT',
            'OPENCODE_CLIENT',
            'OPENCODE',
            'AMP_CURRENT_THREAD_ID',
            'CLAUDECODE',
            'CLAUDE_CODE',
            'REPL_ID',
            'COPILOT_MODEL',
            'COPILOT_ALLOW_ALL',
            'COPILOT_GITHUB_TOKEN',
            'COPILOT_CLI',
            'ANTIGRAVITY_AGENT',
            'PI_CODING_AGENT',
            'KIRO_AGENT_PATH',
        ];

        foreach ($envVars as $envVar) {
            $value = $_SERVER[$envVar] ?? $_ENV[$envVar] ?? getenv($envVar);

            if (! in_array($value, [false, null, ''], true)) {
                return true;
            }
        }

        return file_exists('/opt/.devin');
    }

    private static function truthyEnv(string $key): bool
    {
        $value = $_SERVER[$key] ?? $_ENV[$key] ?? getenv($key);

        return self::isTruthy($value === false ? null : $value);
    }
}
