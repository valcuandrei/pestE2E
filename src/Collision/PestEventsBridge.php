<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Collision;

use NunoMaduro\Collision\Adapters\Phpunit\TestResult;
use Pest\Configuration\Project;

/**
 * Loads Pest's original Collision Events (with a different class name) and
 * delegates to it, avoiding the ambiguous class resolution when both
 * pest-e2e and pest define Pest\Collision\Events.
 *
 * @internal
 */
final class PestEventsBridge
{
    private const PEST_EVENTS_CLASS = 'ValcuAndrei\\PestE2E\\Collision\\PestEvents';

    public static function beforeTestMethodDescription(TestResult $result, string $description): string
    {
        $events = self::loadPestEvents();

        if ($events === null) {
            return $description;
        }

        $modified = $events::beforeTestMethodDescription($result, $description);

        return is_string($modified) ? $modified : $description;
    }

    public static function afterTestMethodDescription(TestResult $result): void
    {
        $events = self::loadPestEvents();

        if ($events !== null) {
            try {
                $events::afterTestMethodDescription($result);
            } catch (\Throwable) {
                // Pest's Events expects $result->context to be initialized; skip when it is not
            }
        }
    }

    private static function loadPestEvents(): ?string
    {
        if (class_exists(self::PEST_EVENTS_CLASS, false)) {
            return self::PEST_EVENTS_CLASS;
        }

        $path = self::locatePestEventsFile();

        if ($path === null || ! is_file($path)) {
            return null;
        }

        $code = file_get_contents($path);

        if ($code === false) {
            return null;
        }

        $code = (string) preg_replace('/^<\?php\s*/', '', $code);
        $code = str_replace('namespace Pest\\Collision;', 'namespace '.__NAMESPACE__.';', $code);
        $code = str_replace('final class Events', 'final class PestEvents', $code);

        eval($code);

        return self::PEST_EVENTS_CLASS;
    }

    private static function locatePestEventsFile(): ?string
    {
        $candidates = [
            dirname(__DIR__, 4).'/pestphp/pest/src/Collision/Events.php',
            dirname(__DIR__, 3).'/vendor/pestphp/pest/src/Collision/Events.php',
        ];

        if (class_exists(Project::class, false)) {
            $projectFile = (new \ReflectionClass(Project::class))->getFileName();
            if ($projectFile !== false) {
                $candidates[] = dirname($projectFile, 2).'/Collision/Events.php';
            }
        }

        foreach ($candidates as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }
}
