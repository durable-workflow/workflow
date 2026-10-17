<?php

declare(strict_types=1);

namespace Tests\Performance;

use InvalidArgumentException;
use RuntimeException;

final class ReplayProfileProgress
{
    private readonly string $directory;

    private readonly int $started;

    public function __construct(
        private readonly int $rounds,
        private readonly int $repetition
    ) {
        $this->directory = sys_get_temp_dir() . '/dw-replay-' . bin2hex(random_bytes(12));
        $this->started = hrtime(true);
        if (! mkdir($this->directory, 0700)) {
            throw new RuntimeException('Cannot create the private profiling progress directory.');
        }
    }

    public function path(): string
    {
        return $this->directory . '/progress.json';
    }

    public function report(string $phase, int $iteration, int $effects, int $entries, int $visits): void
    {
        if (strlen($phase) > 64) {
            throw new InvalidArgumentException('Profiling phase names must fit in 64 bytes.');
        }
        $json = json_encode([
            'pid' => getmypid(),
            'rounds' => $this->rounds,
            'repetition' => $this->repetition,
            'phase' => $phase,
            'iteration' => $iteration,
            'activity_effects' => $effects,
            'workflow_entries' => $entries,
            'round_visits' => $visits,
            'elapsed_wall_ms' => (hrtime(true) - $this->started) / 1_000_000,
        ], JSON_THROW_ON_ERROR);
        $temporary = tempnam($this->directory, '.write-');
        if ($temporary === false) {
            throw new RuntimeException('Cannot create the profiling progress update.');
        }
        try {
            if (file_put_contents($temporary, $json) !== strlen($json)
                || ! rename($temporary, $this->path())) {
                throw new RuntimeException('Cannot publish the profiling progress update.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    public function close(): void
    {
        if (is_file($this->path())) {
            unlink($this->path());
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }
}
