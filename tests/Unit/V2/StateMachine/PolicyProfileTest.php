<?php

declare(strict_types=1);

namespace Tests\Unit\V2\StateMachine;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\V2\TestBusinessStateMachineWorkflow;
use Workflow\Serializers\Serializer;
use Workflow\V2\StateMachine\Definition\PolicyProfile;
use Workflow\V2\StateMachine\Definition\TransitionDefinition;
use Workflow\V2\StateMachine\Execution\ExecutionReceipt;
use Workflow\V2\StateMachine\Execution\ExecutionReference;
use Workflow\V2\StateMachine\Execution\TransitionResult;
use Workflow\V2\StateMachine\Execution\TransitionStatus;
use Workflow\V2\StateMachine\Persistence\CommitResult;
use Workflow\V2\StateMachine\Rules\Decision;
use Workflow\V2\StateMachine\Rules\Verdict;

final class PolicyProfileTest extends TestCase
{
    public function testChangedHandlerVersionChangesThePinnedFingerprint(): void
    {
        $original = TestBusinessStateMachineWorkflow::profile();
        $changed = new PolicyProfile('example', '1', 'prepare', '1', ['Received', 'Processing'], [
            new TransitionDefinition('process', 'received', ['Received'], 'Processing', 'prepare', '2'),
        ]);
        $this->assertNotSame($original->fingerprint(), $changed->fingerprint());
        $this->assertSame($original->fingerprint(), TestBusinessStateMachineWorkflow::profile()->fingerprint());
    }

    public function testProfilesCanUseDifferentEdgesWithinTheSameStateCatalogue(): void
    {
        $original = TestBusinessStateMachineWorkflow::profile();
        $other = new PolicyProfile('example', '1', 'imported', '1', $original->states, [
            new TransitionDefinition('import', 'imported', ['Received'], 'Processing', 'import', '1', false),
        ]);
        $this->assertSame([], $other->candidates('received', 'Received'));
        $this->assertSame('import', $other->candidates('imported', 'Received')[0]->key);
    }

    public function testUndeclaredTargetStateIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new PolicyProfile('machine', '1', 'profile', '1', ['Received'], [
            new TransitionDefinition('process', 'received', ['Received'], 'Processing', 'handler', '1'),
        ]);
    }

    public function testDuplicateTransitionIdentityIsRejected(): void
    {
        $transition = new TransitionDefinition('process', 'received', ['Received'], 'Processing', 'handler', '1');
        $this->expectException(InvalidArgumentException::class);
        new PolicyProfile('machine', '1', 'profile', '1', ['Received', 'Processing'], [$transition, $transition]);
    }

    public function testAStateCannotBeAMutableObject(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new PolicyProfile('machine', '1', 'profile', '1', [new \stdClass()], []);
    }

    public function testEvidenceMustBeImmutableReferencesRatherThanObjects(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Decision(Verdict::Unknown, 'missing_fact', [new \stdClass()]);
    }

    public function testUnknownCommitCannotManufactureSuccess(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new CommitResult($this->execution(), TransitionStatus::Unknown, 'acknowledgement_missing');
    }

    public function testReceiptRejectsDuplicateOperationReferences(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ExecutionReceipt($this->execution(), ['booking', 'booking']);
    }

    public function testPublicResultHasAPortableAvroRepresentation(): void
    {
        $result = new TransitionResult(
            $this->execution(),
            TransitionStatus::Pending,
            'delivery_pending',
            new Decision(Verdict::Unknown, 'evidence_missing', ['snapshot-1']),
            'complete'
        );
        $portable = $result->toArray();
        $this->assertSame(
            $portable,
            Serializer::unserializeWithCodec('avro', Serializer::serializeWithCodec('avro', $portable))
        );
        $this->assertSame('unknown', $portable['assessment']['verdict']);
    }

    private function execution(): ExecutionReference
    {
        return new ExecutionReference('scope', 'subject', 'operation', 'fingerprint', 'Received', '1');
    }
}
