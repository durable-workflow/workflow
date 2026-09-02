<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Event\TestSuite\Started;
use PHPUnit\Event\TestSuite\StartedSubscriber;

class TestSuiteSubscriber implements StartedSubscriber
{
    private static string $currentSuite = '';

    public function notify(Started $event): void
    {
        $suiteName = $event->testSuite()
            ->name();

        if (in_array($suiteName, ['unit', 'feature'], true)) {
            self::$currentSuite = $suiteName;
        }
    }

    public static function getCurrentSuite(): string
    {
        if (self::$currentSuite !== '') {
            return self::$currentSuite;
        }

        return self::inferCurrentSuiteFromArguments();
    }

    private static function inferCurrentSuiteFromArguments(): string
    {
        $arguments = $_SERVER['argv'] ?? $GLOBALS['argv'] ?? [];

        foreach ($arguments as $index => $argument) {
            if (! is_string($argument)) {
                continue;
            }

            if (str_starts_with($argument, '--testsuite=')) {
                $suite = substr($argument, strlen('--testsuite='));

                if (in_array($suite, ['unit', 'feature'], true)) {
                    return $suite;
                }
            }

            if ($argument === '--testsuite') {
                $suite = $arguments[$index + 1] ?? null;

                if (is_string($suite) && in_array($suite, ['unit', 'feature'], true)) {
                    return $suite;
                }
            }
        }

        foreach ($arguments as $argument) {
            if (! is_string($argument)) {
                continue;
            }

            $normalized = str_replace('\\', '/', $argument);

            if (str_contains($normalized, 'tests/Feature/')) {
                return 'feature';
            }

            if (str_contains($normalized, 'tests/Unit/')) {
                return 'unit';
            }
        }

        return '';
    }
}
