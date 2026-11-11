<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use DateTime;
use DateTimeImmutable;
use Workflow\V2\Workflow;

/**
 * Replay-unsafe forms the determinism scan should flag (#888), next to
 * fixed-time forms it must leave alone. The scan is lexical, so the unsafe
 * branch only runs when asked; replaying the fixture stays deterministic.
 */
final class TestUnsafeImplicitTimeWorkflow extends Workflow
{
    /**
     * @param list<string> $items
     * @return array<string, mixed>|string
     */
    public function handle(string $startsAt, array $items, bool $runUnsafe = false): array|string
    {
        if (! $runUnsafe) {
            return 'scanned';
        }

        $unsafe = [
            new DateTime(),
            new DateTimeImmutable('now'),
            new Carbon(),
            new CarbonImmutable('+1 day'),
            new DateTime(),
            today(),
            array_rand($items),
            str_shuffle('abc'),
        ];
        shuffle($items);

        $fixed = [
            new DateTime('2026-01-01 00:00:00'),
            new DateTimeImmutable($startsAt),
            new Carbon('2026-01-01'),
        ];

        return [
            'unsafe' => $unsafe,
            'fixed' => $fixed,
            'items' => $items,
        ];
    }
}
