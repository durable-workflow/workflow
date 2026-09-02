<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use RuntimeException;

final class LaravelEmbeddedUpgradeContract
{
    public const SCHEMA = 'durable-workflow.laravel-embedded-upgrade.contract';

    public const VERSION = 1;

    /**
     * @return array<string, mixed>
     */
    public static function manifest(): array
    {
        $path = dirname(__DIR__, 3) . '/resources/laravel-embedded-upgrade-contract.json';
        $json = file_get_contents($path);

        if (! is_string($json)) {
            throw new RuntimeException(sprintf('Unable to read Laravel embedded upgrade contract [%s].', $path));
        }

        $manifest = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($manifest)) {
            throw new RuntimeException('Laravel embedded upgrade contract must decode to an object.');
        }

        if (($manifest['schema'] ?? null) !== self::SCHEMA || ($manifest['version'] ?? null) !== self::VERSION) {
            throw new RuntimeException('Laravel embedded upgrade contract identity does not match the runtime reader.');
        }

        return $manifest;
    }
}
