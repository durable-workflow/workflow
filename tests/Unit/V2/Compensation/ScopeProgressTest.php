<?php

declare(strict_types=1);

namespace Tests\Unit\V2\Compensation;

use LogicException;
use Tests\TestCase;
use Workflow\V2\Compensation\Definition\Obligation;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\Execution\ForwardResult;
use Workflow\V2\Compensation\Execution\Resolution;
use Workflow\V2\Compensation\Persistence\ScopeProgress;
use Workflow\V2\Compensation\Persistence\ScopeRepository;

final class ScopeProgressTest extends TestCase
{
    public function testUnknownForwardIsReconciledOnRetryAndCancellationClosesAdmission(): void
    {
        [$scope, $id] = $this->registered();
        $progress = new ScopeProgress();
        $this->assertFalse($progress->admitForward($scope, $id, 'native-attempt-1')['uncertain']);
        $progress->forwardResult($scope, $id, 'native-attempt-1', ForwardResult::unknown('ack-missing'));
        $this->assertTrue($progress->admitForward($scope, $id, 'native-attempt-2')['uncertain']);
        (new ScopeRepository())->request($scope, 'request', 'operator', 'cancel');
        $this->expectException(LogicException::class);
        $progress->admitForward($scope, $id, 'native-attempt-3');
    }

    public function testReverseProgressStopsAtUnknownAndDoesNotRepeatVerifiedWork(): void
    {
        [$scope, $first] = $this->registered();
        $repository = new ScopeRepository();
        $second = $repository->register($scope, new Obligation('second', 'carrier.cancel', '1', []));
        $repository->request($scope, 'request', 'operator', 'cancel');
        $progress = new ScopeProgress();
        $progress->beginCompensation($scope, $second, 'native-undo-1');
        $progress->resolve($scope, $second, 'native-undo-1', Resolution::unknown('provider-unavailable'));
        try {
            $progress->beginCompensation($scope, $first, 'native-undo-2');
            $this->fail('Earlier compensation advanced past unresolved work.');
        } catch (LogicException) {
            $this->assertSame('requested', $repository->inspect($scope)['status']);
        }
        $progress->beginCompensation($scope, $second, 'native-undo-3');
        $progress->resolve($scope, $second, 'native-undo-3', Resolution::verified(['proof-second'], true));
        $this->assertNull($progress->beginCompensation($scope, $second, 'native-undo-4'));
        $progress->beginCompensation($scope, $first, 'native-undo-5');
        $progress->resolve($scope, $first, 'native-undo-5', Resolution::verified(['proof-first'], true));
        $this->assertSame('verified', $progress->finish($scope)['status']);
    }

    public function testUnknownForwardCannotBecomeVerifiedWithoutSettledEvidence(): void
    {
        [$scope, $id] = $this->registered();
        $progress = new ScopeProgress();
        $progress->admitForward($scope, $id, 'native-forward');
        (new ScopeRepository())->request($scope, 'request', 'operator', 'cancel');
        $progress->beginCompensation($scope, $id, 'native-undo');
        $this->expectException(LogicException::class);
        $progress->resolve($scope, $id, 'native-undo', Resolution::verified(['undo-ack'], false));
    }

    public function testCompensationCannotOverwriteAnotherNativeAttempt(): void
    {
        [$scope, $id] = $this->registered();
        (new ScopeRepository())->request($scope, 'request', 'operator', 'cancel');
        $progress = new ScopeProgress();
        $progress->beginCompensation($scope, $id, 'native-undo-1');
        $progress->beginCompensation($scope, $id, 'native-undo-2');
        $this->expectException(LogicException::class);
        $progress->resolve($scope, $id, 'native-undo-1', Resolution::verified(['proof'], true));
    }

    public function testMaximumEvidenceReferencesFitForwardAndCompensationJournals(): void
    {
        [$scope, $id] = $this->registered();
        $references = array_fill(0, 32, 'p' . str_repeat('/', 127));
        $progress = new ScopeProgress();
        $repository = new ScopeRepository();
        $progress->admitForward($scope, $id, str_repeat('f', 26));
        $progress->forwardResult($scope, $id, str_repeat('f', 26), ForwardResult::observed([], $references));
        $repository->request($scope, 'request', 'operator', 'cancel');
        $progress->beginCompensation($scope, $id, str_repeat('c', 26));
        $progress->resolve($scope, $id, str_repeat('c', 26), Resolution::verified($references, true));
        $records = array_values(array_filter(
            $repository->journal($scope),
            static fn (array $row): bool => in_array($row['kind'], ['forward_outcome', 'compensation_outcome'], true)
        ));
        $this->assertCount(2, $records);
        foreach ($records as $row) {
            $this->assertSame($references, json_decode($row['data'], true, flags: JSON_THROW_ON_ERROR)['evidence']);
        }
        $this->assertSame('verified', $progress->finish($scope)['status']);
    }

    private function registered(): array
    {
        $scope = new ScopeIdentity('shop', 'client-a', 'order-42', '1');
        $repository = new ScopeRepository();
        $repository->open($scope, 'original-run');
        return [$scope, $repository->register($scope, new Obligation('book', 'carrier.cancel', '1', []))];
    }
}
