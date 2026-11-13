<?php

declare(strict_types=1);

namespace Tests\Unit\V2\Compensation;

use Fiber;
use LogicException;
use Tests\TestCase;
use Workflow\V2\Compensation\Definition\Obligation;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\Persistence\ScopeRepository;
use Workflow\V2\Support\ConfiguredV2Models;
use Workflow\V2\Support\WorkflowFiberContext;

final class ScopeRepositoryTest extends TestCase
{
    public function testRegistrationIsRetainedAndExactlyAdoptedAfterRepositoryRestart(): void
    {
        $scope = $this->scope();
        $repository = new ScopeRepository();
        $repository->open($scope, 'original-run');
        $first = $repository->register($scope, new Obligation('book', 'carrier.cancel', '1', [
            'reference' => 'booking-1',
        ]));
        $again = (new ScopeRepository())->register($scope, new Obligation('book', 'carrier.cancel', '1', [
            'reference' => 'booking-1',
        ]));
        $this->assertSame($first, $again);
        $this->assertCount(1, $repository->inspect($scope)['obligations']);
        $this->assertSame(['opened', 'registered'], array_column($repository->journal($scope), 'kind'));
    }

    public function testConflictingDuplicateDoesNotReplaceOriginalDescriptor(): void
    {
        $repository = new ScopeRepository();
        $repository->open($this->scope(), 'original-run');
        $repository->register($this->scope(), new Obligation('book', 'carrier.cancel', '1', []));
        try {
            $repository->register($this->scope(), new Obligation('book', 'carrier.cancel', '2', []));
            $this->fail('Conflicting descriptor was adopted.');
        } catch (LogicException) {
            $this->assertSame(
                '1',
                $repository->inspect($this->scope())['obligations'][0]['descriptor']['handlerVersion']
            );
        }
    }

    public function testScopeGenerationAndNamespaceCannotShareObligations(): void
    {
        $repository = new ScopeRepository();
        $repository->open($this->scope(), 'run-a');
        $repository->register($this->scope(), new Obligation('book', 'carrier.cancel', '1', []));
        foreach ([
            new ScopeIdentity('shop', 'client-b', 'order-42', '1'),
            new ScopeIdentity('shop', 'client-a', 'order-42', '2'),
        ] as $other) {
            $repository->open($other, 'run-b');
            $this->assertSame([], $repository->inspect($other)['obligations']);
        }
    }

    public function testRegistrationAndItsJournalRollBackTogether(): void
    {
        $repository = new ScopeRepository();
        $repository->open($this->scope(), 'original-run');
        $connection = ConfiguredV2Models::connection();
        $connection->beginTransaction();
        $repository->register($this->scope(), new Obligation('book', 'carrier.cancel', '1', []));
        $connection->rollBack();
        $this->assertSame([], $repository->inspect($this->scope())['obligations']);
        $this->assertSame(['opened'], array_column($repository->journal($this->scope()), 'kind'));
    }

    public function testOriginalRunCannotBeSilentlyReplaced(): void
    {
        $repository = new ScopeRepository();
        $repository->open($this->scope(), 'original-run');
        $this->expectException(LogicException::class);
        $repository->open($this->scope(), 'replacement-run');
    }

    public function testJournalWriteFailureCannotLeaveAnUnjournaledObligation(): void
    {
        $repository = new ScopeRepository();
        $repository->open($this->scope(), 'original-run');
        $fail = true;
        ConfiguredV2Models::connection()->beforeExecuting(static function (string $sql) use (&$fail): void {
            if ($fail && str_starts_with($sql, 'insert') && str_contains($sql, 'workflow_compensation_journal')) {
                throw new \RuntimeException('injected journal failure');
            }
        });
        try {
            $repository->register($this->scope(), new Obligation('book', 'carrier.cancel', '1', []));
            $this->fail('Expected the injected failure.');
        } catch (\RuntimeException $error) {
            $this->assertSame('injected journal failure', $error->getMessage());
        } finally {
            $fail = false;
        }
        $this->assertSame([], $repository->inspect($this->scope())['obligations']);
        $this->assertCount(1, $repository->journal($this->scope()));
        $repository->register($this->scope(), new Obligation('book', 'carrier.cancel', '1', []));
        $this->assertSame(1, $repository->inspect($this->scope())['obligations'][0]['position']);
    }

    public function testOpeningLiveStorageFromWorkflowReplayIsRefusedBeforeWriting(): void
    {
        $scope = $this->scope();
        $fiber = new Fiber(static function () use ($scope): void {
            WorkflowFiberContext::enter();
            try {
                (new ScopeRepository())->open($scope, 'original-run');
            } finally {
                WorkflowFiberContext::leave();
            }
        });
        try {
            $fiber->start();
            $this->fail('Workflow replay opened live compensation storage.');
        } catch (LogicException $error) {
            $this->assertStringContainsString('never in workflow replay', $error->getMessage());
        }
        $this->assertSame(0, ConfiguredV2Models::connection()->table('workflow_compensation_scopes')->count());
        $this->assertSame(0, ConfiguredV2Models::connection()->table('workflow_compensation_journal')->count());
    }

    public function testObligationLimitCannotLeaveAPartialRegistration(): void
    {
        $repository = new ScopeRepository();
        $scope = $this->scope();
        $repository->open($scope, 'original-run');
        for ($index = 0; $index < ScopeRepository::MAX_OBLIGATIONS; ++$index) {
            $repository->register($scope, new Obligation('work-' . $index, 'carrier.cancel', '1', []));
        }
        $journal = $repository->journal($scope);
        try {
            $repository->register($scope, new Obligation('overflow', 'carrier.cancel', '1', []));
            $this->fail('The obligation limit was exceeded.');
        } catch (LogicException) {
            $this->assertCount(ScopeRepository::MAX_OBLIGATIONS, $repository->inspect($scope)['obligations']);
            $this->assertSame($journal, $repository->journal($scope));
        }
    }

    private function scope(): ScopeIdentity
    {
        return new ScopeIdentity('shop', 'client-a', 'order-42', '1');
    }
}
