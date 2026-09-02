<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Tests\NonDatabaseTestCase;
use Workflow\V2\Enums\StructuralLimitKind;
use Workflow\V2\Exceptions\DurableOperationCancelledException;
use Workflow\V2\Exceptions\RestoredWorkflowException;
use Workflow\V2\Exceptions\StraightLineWorkflowRequiredException;
use Workflow\V2\Exceptions\StructuralLimitExceededException;
use Workflow\V2\Exceptions\UnresolvedWorkflowFailureException;
use Workflow\V2\Exceptions\UnsupportedBackendCapabilitiesException;
use Workflow\V2\Support\DurableOperationHandle;
use Workflow\V2\Support\FailureFactory;
use Workflow\V2\Support\TimerCall;

final class WorkflowExceptionContractsTest extends NonDatabaseTestCase
{
    public function testInvalidTypeMappingFallsBackToAWrapperForMissingRecordedClass(): void
    {
        config()->set('workflows.v2.types.exceptions', [
            'broken.failure' => \stdClass::class,
        ]);

        $restored = FailureFactory::restore([
            'class' => 'App\\Exceptions\\RemovedWorkflowException',
            'type' => 'broken.failure',
            'message' => 'missing recorded failure class',
        ]);

        $this->assertInstanceOf(RestoredWorkflowException::class, $restored);
        $this->assertSame('App\\Exceptions\\RemovedWorkflowException', $restored->originalExceptionClass());
        $this->assertSame('missing recorded failure class', $restored->getMessage());
    }

    public function testUnresolvedFailureExposesItsPortablePayload(): void
    {
        $payload = [
            'message' => 'class metadata unavailable',
            'code' => 19,
        ];

        $exception = UnresolvedWorkflowFailureException::unresolved($payload);

        $this->assertSame($payload, $exception->failurePayload());
        $this->assertSame('unknown', $exception->originalExceptionClass());
        $this->assertNull($exception->exceptionType());
        $this->assertSame('unresolved', $exception->resolutionSource());
    }

    public function testStraightLineCallbackFailureNamesTheCallbackContract(): void
    {
        $exception = StraightLineWorkflowRequiredException::forCallback();

        $this->assertSame(
            'Workflow v2 callbacks must use straight-line helpers and must not yield.',
            $exception->getMessage(),
        );
    }

    public function testUnsupportedBackendCapabilityMessagesHandleEmptyAndMalformedIssues(): void
    {
        $empty = new UnsupportedBackendCapabilitiesException([]);
        $malformed = new UnsupportedBackendCapabilitiesException([
            'issues' => ['not an issue map'],
        ]);

        $this->assertSame('Workflow v2 backend capabilities are unsupported.', $empty->getMessage());
        $this->assertSame('Workflow v2 backend capabilities are unsupported.', $malformed->getMessage());
        $this->assertSame([
            'issues' => ['not an issue map'],
        ], $malformed->snapshot());
    }

    public function testPendingTimerLimitCarriesPortableMetadata(): void
    {
        $exception = StructuralLimitExceededException::pendingTimerCount(81, 80);

        $this->assertSame(StructuralLimitKind::PendingTimerCount, $exception->limitKind);
        $this->assertSame(81, $exception->currentValue);
        $this->assertSame(80, $exception->configuredLimit);
        $this->assertSame('Structural limit exceeded: 81 pending timers (limit 80).', $exception->getMessage());
    }

    public function testDurableCancellationRetainsSelectedHandleIdentity(): void
    {
        $handle = new DurableOperationHandle(
            key: 'timer-key',
            index: 2,
            kind: 'timer',
            identity: 'timer:2',
            baseSequence: 7,
            size: 1,
            selectionGroupId: 'selection-1',
            call: new TimerCall(30),
        );

        $exception = DurableOperationCancelledException::forHandle($handle);

        $this->assertSame('selection-1', $exception->selectionGroupId);
        $this->assertSame('timer-key', $exception->memberKey);
        $this->assertSame(2, $exception->memberIndex);
        $this->assertSame('timer', $exception->operationKind);
        $this->assertSame('timer:2', $exception->operationIdentity);
        $this->assertSame('Durable timer operation [timer:2] was cancelled.', $exception->getMessage());
    }

    public function testDurableCancellationCanRepresentAnUnselectedOperation(): void
    {
        $exception = DurableOperationCancelledException::forOperation('activity', 'activity:9');

        $this->assertNull($exception->selectionGroupId);
        $this->assertNull($exception->memberKey);
        $this->assertNull($exception->memberIndex);
        $this->assertSame('activity', $exception->operationKind);
        $this->assertSame('activity:9', $exception->operationIdentity);
        $this->assertSame('Durable activity operation [activity:9] was cancelled.', $exception->getMessage());
    }
}
