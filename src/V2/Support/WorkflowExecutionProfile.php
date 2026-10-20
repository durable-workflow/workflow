<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

/**
 * @internal Explicit, process-local qualification observer. Never stored in a workflow or Fiber.
 */
final class WorkflowExecutionProfile
{
    private static ?self $active = null;

    /**
     * @var list<array{phase: string, cpu: int, wall: int, child_cpu: int, child_wall: int}>
     */
    private array $stack = [];

    /**
     * @var array<string, array{calls: int, cpu_us: int, wall_ns: int, exclusive_cpu_us: int, exclusive_wall_ns: int}>
     */
    private array $phases = [];

    private bool $balanced = true;

    public function collect(callable $operation): mixed
    {
        $previous = self::$active;
        self::$active = $this;
        try {
            return $operation();
        } finally {
            $this->balanced = $this->balanced && $this->stack === [];
            self::$active = $previous;
        }
    }

    public static function begin(string $phase): ?int
    {
        $profile = self::$active;
        if ($profile === null) {
            return null;
        }
        $profile->stack[] = [
            'phase' => $phase,
            'cpu' => self::cpu(),
            'wall' => hrtime(true),
            'child_cpu' => 0,
            'child_wall' => 0,
        ];

        return count($profile->stack);
    }

    public static function finish(?int $depth): void
    {
        $profile = self::$active;
        if ($depth === null || $profile === null) {
            return;
        }
        $cpu = self::cpu();
        $wall = hrtime(true);
        if ($depth !== count($profile->stack)) {
            $profile->balanced = false;
            return;
        }
        $sample = array_pop($profile->stack);
        $cpu -= $sample['cpu'];
        $wall -= $sample['wall'];
        $phase = &$profile->phases[$sample['phase']];
        $phase ??= [
            'calls' => 0,
            'cpu_us' => 0,
            'wall_ns' => 0,
            'exclusive_cpu_us' => 0,
            'exclusive_wall_ns' => 0,
        ];
        ++$phase['calls'];
        $phase['cpu_us'] += $cpu;
        $phase['wall_ns'] += $wall;
        $phase['exclusive_cpu_us'] += max(0, $cpu - $sample['child_cpu']);
        $phase['exclusive_wall_ns'] += max(0, $wall - $sample['child_wall']);
        $parent = count($profile->stack) - 1;
        if ($parent >= 0) {
            $profile->stack[$parent]['child_cpu'] += $cpu;
            $profile->stack[$parent]['child_wall'] += $wall;
        }
    }

    /**
     * @return array{balanced: bool, phases: array<string, array{calls: int, cpu_us: int, wall_ns: int,
     *     exclusive_cpu_us: int, exclusive_wall_ns: int}>}
     */
    public function snapshot(): array
    {
        return [
            'balanced' => $this->balanced && $this->stack === [],
            'phases' => $this->phases,
        ];
    }

    private static function cpu(): int
    {
        $usage = getrusage();

        return ($usage['ru_utime.tv_sec'] + $usage['ru_stime.tv_sec']) * 1_000_000
            + $usage['ru_utime.tv_usec'] + $usage['ru_stime.tv_usec'];
    }
}
