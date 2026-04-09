<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use RuntimeException;
use Tests\TestCase;
use Workflow\V2\Support\ParallelFailureSelector;

final class ParallelFailureSelectorTest extends TestCase
{
    public function testItPrefersEarliestRecordedFailure(): void
    {
        $current = ParallelFailureSelector::select(null, 0, new RuntimeException('first'), 200);
        $selected = ParallelFailureSelector::select($current, 1, new RuntimeException('second'), 100);

        $this->assertSame(1, $selected['index']);
        $this->assertSame('second', $selected['exception']->getMessage());
        $this->assertSame(100, $selected['recorded_at']);
    }

    public function testItBreaksRecordedAtTiesByBarrierIndex(): void
    {
        $current = ParallelFailureSelector::select(null, 1, new RuntimeException('second'), 100);
        $selected = ParallelFailureSelector::select($current, 0, new RuntimeException('first'), 100);

        $this->assertSame(0, $selected['index']);
        $this->assertSame('first', $selected['exception']->getMessage());
        $this->assertSame(100, $selected['recorded_at']);
    }
}
