<?php

declare(strict_types=1);

namespace Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TypeError;
use Workflow\WorkflowMetadata;
use Workflow\WorkflowOptions;

final class WorkflowOptionsTest extends TestCase
{
    public function testOptionsRoundTripWithTheOverlapDelay(): void
    {
        $options = new WorkflowOptions(overlapReleaseAfter: 5);
        $metadata = new WorkflowMetadata(['argument'], $options);
        $restored = WorkflowMetadata::fromSerializedArguments($metadata->toArray());
        $this->assertSame(['argument'], $restored->arguments);
        $this->assertSame(5, $restored->options->overlapReleaseAfter);
        $this->assertEquals($options, unserialize(serialize($options)));
    }

    public function testExistingSerializedOptionsKeepTheZeroDefault(): void
    {
        $options = unserialize('O:24:"Workflow\\WorkflowOptions":2:{s:10:"connection";N;s:5:"queue";N;}');
        $this->assertInstanceOf(WorkflowOptions::class, $options);
        $this->assertSame(0, $options->overlapReleaseAfter);
        $this->assertSame(0, WorkflowOptions::set([])->overlapReleaseAfter);
        $metadata = WorkflowMetadata::fromSerializedArguments([
            'arguments' => [],
            'options' => [
                'connection' => 'database',
                'queue' => 'default',
            ],
        ]);
        $this->assertSame(0, $metadata->options->overlapReleaseAfter);
        $this->assertSame([
            'connection' => 'database',
            'queue' => 'default',
        ], $metadata->toArray()['options'],);
    }

    public function testConstructorRejectsNegativeDelay(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new WorkflowOptions(overlapReleaseAfter: -1);
    }

    public function testExplicitOptionsReplaceTheInheritedDelay(): void
    {
        $inherited = new WorkflowOptions(overlapReleaseAfter: 7);
        $metadata = WorkflowMetadata::fromStartArguments(['argument'], $inherited);
        $this->assertSame(7, $metadata->options->overlapReleaseAfter);
        $metadata = WorkflowMetadata::fromStartArguments(['argument', new WorkflowOptions()], $inherited);
        $this->assertSame(0, $metadata->options->overlapReleaseAfter);
        $this->assertSame(['argument'], $metadata->arguments);
    }

    public static function invalidDelays(): array
    {
        return [
            'negative' => [-1, InvalidArgumentException::class],
            'fraction' => [0.5, TypeError::class],
            'string' => ['5', TypeError::class],
            'boolean' => [true, TypeError::class],
            'null' => [null, TypeError::class],
        ];
    }

    #[DataProvider('invalidDelays')]
    public function testRejectsInvalidPersistedDelay(mixed $delay, string $exception): void
    {
        $this->expectException($exception);
        WorkflowOptions::set([
            'overlapReleaseAfter' => $delay,
        ]);
    }
}
