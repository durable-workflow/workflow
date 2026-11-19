<?php

declare(strict_types=1);

namespace Tests\Unit\V2\StateMachine;

use DurableWorkflow\Examples\BusinessStateMachines\SqlBusinessStore;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LogicException;
use Orchestra\Testbench\TestCase;
use Tests\Fixtures\V2\BusinessCommitFixture;
use Tests\Fixtures\V2\TestDefinedMachineWorkflow;
use Workflow\V2\StateMachine\Definition\TransitionDefinition;
use Workflow\V2\StateMachine\Execution\ExecutionReference;
use Workflow\V2\StateMachine\Execution\TransitionStatus;
use Workflow\V2\StateMachine\Transition\FactSnapshot;
use Workflow\V2\StateMachine\Transition\TransitionContext;

final class BusinessCommitTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Load the example without changing package autoload or installing application tables in DW.
        class_exists(BusinessCommitFixture::class);
        $schema = require __DIR__ . '/../../../../examples/business-state-machines/schema.php';
        $schema(DB::connection()->getSchemaBuilder());
        BusinessCommitFixture::seed(BusinessCommitFixture::context()->execution());
    }

    public function testLostCommitAcknowledgementReturnsTheOriginalDecisionAndOnePublication(): void
    {
        $context = BusinessCommitFixture::context();
        BusinessCommitFixture::store()->commit($context, 'alice'); // Simulate loss of the returned acknowledgement.
        $recovered = BusinessCommitFixture::store()->commit($context, 'alice');
        $this->assertSame(TransitionStatus::Committed, $recovered->status);
        $this->assertTrue($context->execution()->equals($recovered->execution));
        $this->assertSame(['ready', 2], $this->subjectState());
        $this->assertSame(1, DB::table('example_machine_decisions')->count());
        $this->assertSame(1, DB::table('example_machine_publications')->count());
        $publication = json_decode(
            DB::table('example_machine_publications')->value('payload'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $this->assertSame($context->execution()->toArray(), $publication['execution']);
        $this->assertSame('2', $publication['revision']);
    }

    public function testUnsatisfiedMachineInvariantCannotReachTheProtectedStateWrite(): void
    {
        $original = BusinessCommitFixture::context();
        $s = $original->snapshot;
        $context = new TransitionContext($original->transition, new FactSnapshot(
            $s->execution,
            $s->transitionKey,
            $s->phase,
            $s->snapshotId,
            $s->state,
            $s->revision,
            $s->target,
            $s->permission,
            TestDefinedMachineWorkflow::assessment($s->snapshotId, 'unsatisfied')
        ));
        $store = new SqlBusinessStore(DB::connection(), static function (
            ExecutionReference $execution,
            string $principal,
            string $operation,
            ?TransitionContext $submitted
        ) use ($context): string {
            if (! $execution->equals($context->execution()) || $principal !== 'alice'
                || $operation !== 'commit' || $submitted !== $context) {
                throw new LogicException('This test grants only the exact original commit owner.');
            }
            return 'grant-v1';
        });
        try {
            $store->commit($context, 'alice');
            $this->fail('A satisfied target cannot bypass the shared machine invariant.');
        } catch (LogicException $error) {
            $this->assertStringContainsString('matching satisfied assessment', $error->getMessage());
        }
        $this->assertSame(['received', 1], $this->subjectState());
        $this->assertSame(0, DB::table('example_machine_decisions')->count());
        $this->assertSame(0, DB::table('example_machine_publications')->count());
    }

    public function testAcceptedCancellationFencesCompletionWithoutClaimingCompensation(): void
    {
        $context = BusinessCommitFixture::context();
        $store = BusinessCommitFixture::store();
        $first = $store->requestCancellation($context->execution(), 'cancel-1', 'alice');
        $duplicate = $store->requestCancellation($context->execution(), 'cancel-1', 'alice');
        $commit = $store->commit($context, 'alice');
        $this->assertSame(TransitionStatus::CancellationRequested, $first->status);
        $this->assertSame($first->reason, $duplicate->reason);
        $this->assertSame(TransitionStatus::CancellationRequested, $commit->status);
        $this->assertSame(['received', 1], $this->subjectState());
        $this->assertSame(2, DB::table('example_machine_decisions')->count());
        $this->assertSame(0, DB::table('example_machine_publications')->count());
    }

    public function testLateCancellationCannotReplaceAnAlreadyCommittedDecision(): void
    {
        $context = BusinessCommitFixture::context();
        $store = BusinessCommitFixture::store();
        $store->commit($context, 'alice');
        $cancel = $store->requestCancellation($context->execution(), 'cancel-1', 'alice');
        $this->assertSame(TransitionStatus::Conflict, $cancel->status);
        $this->assertSame(TransitionStatus::Committed, $store->commit($context, 'alice')->status);
        $this->assertFalse((bool) DB::table('example_machine_subjects')->value('cancellation_requested'));
        $this->assertSame(['ready', 2], $this->subjectState());
        $this->assertSame(1, DB::table('example_machine_publications')->count());
    }

    public function testLostAcknowledgementSurvivesAcceptedCancellationOfTheNextRevision(): void
    {
        $context = BusinessCommitFixture::context();
        $store = BusinessCommitFixture::store();
        $store->commit($context, 'alice');
        $next = new ExecutionReference('tenant-a', 'item-1', 'next-execution', str_repeat('a', 64), 'ready', '2');
        $this->assertSame(
            TransitionStatus::CancellationRequested,
            $store->requestCancellation($next, 'cancel-next-revision', 'alice')
->status,
        );
        $this->assertSame(TransitionStatus::Committed, $store->commit($context, 'alice')->status);
        $this->assertTrue((bool) DB::table('example_machine_subjects')->value('cancellation_requested'));
        $this->assertSame(['ready', 2], $this->subjectState());
        $this->assertSame(1, DB::table('example_machine_publications')->count());
    }

    public function testPublicationFailureRollsBackStateAndDecisionTogether(): void
    {
        $context = BusinessCommitFixture::context();
        $id = SqlBusinessStore::decisionId($context->execution(), 'transition', $context->execution()->executionId);
        DB::table('example_machine_publications')->insert([
            'id' => $id,
            'subject_id' => 'existing-intent',
            'payload' => '{}',
        ]);
        try {
            BusinessCommitFixture::store()->commit($context, 'alice');
            $this->fail('The conflicting publication must fail the entire commit.');
        } catch (QueryException) {
            $this->assertSame(['received', 1], $this->subjectState());
            $this->assertSame(0, DB::table('example_machine_decisions')->count());
            $this->assertSame('{}', DB::table('example_machine_publications')->value('payload'));
        }
    }

    public function testACompetingTransitionGetsAnExplicitRevisionConflict(): void
    {
        $store = BusinessCommitFixture::store();
        $store->commit(BusinessCommitFixture::context(), 'alice');
        $this->assertSame(
            TransitionStatus::Conflict,
            $store->commit(BusinessCommitFixture::context('transition-2'), 'alice')->status
        );
        $this->assertSame(['ready', 2], $this->subjectState());
        $this->assertSame(1, DB::table('example_machine_publications')->count());
    }

    public function testAReusedDecisionCannotChangeItsSubject(): void
    {
        $store = BusinessCommitFixture::store();
        $store->commit(BusinessCommitFixture::context(), 'alice');
        $other = BusinessCommitFixture::context(subject: 'item-2');
        BusinessCommitFixture::seed($other->execution());
        $this->expectExceptionMessage('The decision identity was reused with different content.');
        $store->commit($other, 'alice');
    }

    public function testLiveAuthorityRevocationRefusesAnOldAssessment(): void
    {
        DB::table('example_machine_subjects')->update([
            'authority_version' => 'grant-v2',
        ]);
        $this->expectExceptionMessage('The original subject authority is not current.');
        BusinessCommitFixture::store()->commit(BusinessCommitFixture::context(), 'alice');
    }

    public function testForeignPrincipalIsRefusedBeforeAnyProtectedQuery(): void
    {
        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        try {
            BusinessCommitFixture::store()->commit(BusinessCommitFixture::context(), 'mallory');
            $this->fail('A foreign principal must be refused.');
        } catch (LogicException $error) {
            $this->assertSame('The application policy refused protected access.', $error->getMessage());
            $this->assertSame([], $queries);
        }
    }

    public function testCallerOwnedTransactionIsRefusedBeforeTheHostPolicyRuns(): void
    {
        $calls = 0;
        $store = new SqlBusinessStore(DB::connection(), static function () use (&$calls): string {
            ++$calls;
            return 'grant-v1';
        });
        DB::beginTransaction();
        try {
            $store->commit(BusinessCommitFixture::context(), 'alice');
            $this->fail('A caller-owned transaction must be refused.');
        } catch (LogicException $error) {
            $this->assertSame('The business boundary must own its transaction.', $error->getMessage());
            $this->assertSame(0, $calls);
        } finally {
            DB::rollBack();
        }
    }

    public function testAProfileFingerprintDoesNotAuthorizeAChangedTargetState(): void
    {
        $original = BusinessCommitFixture::context();
        $forged = new TransitionContext(new TransitionDefinition(
            'prepare',
            'accepted',
            ['received'],
            'cancelled',
            'prepare',
            '1',
        ), $original->snapshot);
        $this->expectExceptionMessage('The pinned profile or immutable assessment was substituted.');
        BusinessCommitFixture::store()->commit($forged, 'alice');
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'example');
        $app['config']->set('database.connections.example', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    private function subjectState(): array
    {
        $row = DB::table('example_machine_subjects')->first();
        return [$row->state, (int) $row->revision];
    }
}
