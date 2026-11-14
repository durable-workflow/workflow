<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use DurableWorkflow\Examples\BusinessStateMachines\SqlBusinessStore;
use Illuminate\Support\Facades\DB;
use LogicException;
use Workflow\V2\StateMachine\Definition\TransitionDefinition;
use Workflow\V2\StateMachine\Execution\ExecutionReference;
use Workflow\V2\StateMachine\Rules\Decision;
use Workflow\V2\StateMachine\Rules\Verdict;
use Workflow\V2\StateMachine\Transition\FactSnapshot;
use Workflow\V2\StateMachine\Transition\SnapshotPhase;
use Workflow\V2\StateMachine\Transition\TransitionContext;

require_once __DIR__ . '/../../../examples/business-state-machines/SqlBusinessStore.php';

/** Closed application policy and immutable assessment fixture, not a production authorizer. */
final class BusinessCommitFixture
{
    public static function context(string $executionId = 'transition-1', string $subject = 'item-1'): TransitionContext
    {
        $execution = new ExecutionReference('tenant-a', $subject, $executionId, str_repeat('a', 64), 'received', '1');
        $transition = new TransitionDefinition('prepare', 'accepted', ['received'], 'ready', 'prepare', '1');
        return new TransitionContext($transition, new FactSnapshot(
            $execution,
            $transition->key,
            SnapshotPhase::After,
            'immutable-assessment-1',
            'received',
            '1',
            new Decision(Verdict::Satisfied, 'effects_verified', ['fact-1']),
            new Decision(Verdict::Satisfied, 'work_permitted', ['grant-1']),
        ));
    }

    public static function seed(ExecutionReference $execution): void
    {
        DB::table('example_machine_subjects')->insert([
            'id' => SqlBusinessStore::subjectId($execution),
            'scope' => $execution->scope,
            'subject' => $execution->subject,
            'principal' => 'alice',
            'authority_version' => 'grant-v1',
            'state' => 'received',
            'revision' => 1,
            'cancellation_requested' => false,
        ]);
    }

    public static function store(string $authorityVersion = 'grant-v1'): SqlBusinessStore
    {
        return new SqlBusinessStore(DB::connection(), static function (
            ExecutionReference $execution,
            string $principal,
            string $operation,
            ?TransitionContext $context,
        ) use ($authorityVersion): string {
            if ($principal !== 'alice' || $execution->scope !== 'tenant-a'
                || $execution->policyFingerprint !== str_repeat('a', 64)
                || ! in_array($operation, ['commit', 'cancel'], true)
                || DB::connection()->transactionLevel() !== 0) {
                throw new LogicException('The application policy refused protected access.');
            }
            if ($operation === 'commit') {
                $canonical = self::context($execution->executionId, $execution->subject);
                if ($context === null || get_object_vars($context->transition) !== get_object_vars(
                    $canonical->transition
                )
                    || json_encode($context->snapshot, JSON_THROW_ON_ERROR)
                        !== json_encode($canonical->snapshot, JSON_THROW_ON_ERROR)) {
                    throw new LogicException('The pinned profile or immutable assessment was substituted.');
                }
            }
            return $authorityVersion;
        });
    }
}
