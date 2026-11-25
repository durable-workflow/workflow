<?php

declare(strict_types=1);

namespace Workflow\V2\StateMachine\Inspection;

use InvalidArgumentException;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\StateMachine\Binding\BindingIdentity;
use Workflow\V2\StateMachine\Binding\ChildBinding;
use Workflow\V2\StateMachine\Binding\ChildReservation;
use Workflow\V2\StateMachine\Binding\NeverAdmittedChild;
use Workflow\V2\StateMachine\Definition\PolicyProfile;
use Workflow\V2\StateMachine\Execution\ExecutionReference;
use Workflow\V2\StateMachine\Execution\TransitionResult;

/**
 * One business subject's correlated diagnostic view: pinned profile, current business state, the
 * business execution and its transition explanation, child work and retained compensation.
 *
 * The host assembles it outside workflow code from values it has already authorized for this
 * subject. Construction refuses a transition or child binding that belongs to another subject or
 * pinned profile. A child's retained participation links it to its parent and child scope IDs;
 * the host pairs the listed compensation scopes with the subject, because scope keys are
 * application-defined. It performs no I/O and is not an authorization or persistence boundary;
 * business state remains distinct from workflow-run status.
 *
 * @experimental Part of the business state-machine API, which may change in a minor release.
 * See docs/api-stability.md#state-machines.
 */
final readonly class MachineInspection
{
    public const MAX_CHILDREN = 256;

    public const MAX_SCOPES = 256;

    private const SCOPE_DESCRIPTION = [
        'scopeStatus',
        'originStatus',
        'recoveryRunId',
        'recoveryStatus',
        'automaticRecovery',
        'refusal',
        'nextAction',
        'unresolved',
    ];

    /**
     * @var list<ChildBinding|NeverAdmittedChild|ChildReservation>
     */
    public array $children;

    /**
     * @var list<array{scope: ScopeIdentity, description: array<string, mixed>}>
     */
    public array $compensation;

    /**
     * Child work grouped by binding generation, in first-appearance order.
     *
     * @var list<array{identity: BindingIdentity, binding: ?ChildBinding, closed: ?NeverAdmittedChild, reservation: ?ChildReservation}>
     */
    private array $work;

    /**
     * @param string $state The current business state from the application's authoritative store.
     * @param string $revision Its current revision.
     * @param list<ChildBinding|NeverAdmittedChild|ChildReservation> $children Admitted, permanently
     *        closed or still-reserved child work, in the host's order. Pass an admitted child's
     *        ChildReservation next to its ChildBinding to include its retained participation.
     * @param list<array{scope: ScopeIdentity, description: array<string, mixed>}> $compensation
     *        Retained scopes with their ScopeRecovery::describe() result.
     */
    public function __construct(
        public PolicyProfile $profile,
        public string $state,
        public string $revision,
        public ExecutionReference $execution,
        public ?TransitionResult $transition = null,
        array $children = [],
        array $compensation = [],
    ) {
        if (! in_array($state, $profile->states, true)) {
            throw new InvalidArgumentException('The inspected business state must be declared by its pinned profile.');
        }
        if (trim($revision) === '') {
            throw new InvalidArgumentException('The inspected business revision must not be empty.');
        }
        if ($execution->policyFingerprint !== $profile->fingerprint()) {
            throw new InvalidArgumentException('The inspected execution does not use the pinned profile.');
        }
        if ($transition !== null && ! $transition->execution->equals($execution)) {
            throw new InvalidArgumentException('The inspected transition belongs to another business execution.');
        }
        $this->work = self::children($children, $execution);
        $this->children = array_values($children);
        $this->compensation = self::compensation($compensation);
    }

    /**
     * Portable, versioned view for operator tooling. Child and scope entries keep the host's order.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'version' => 1,
            'machine' => [
                'machine' => $this->profile->machine,
                'definitionVersion' => $this->profile->definitionVersion,
                'profile' => $this->profile->profile,
                'profileVersion' => $this->profile->profileVersion,
                'policyFingerprint' => $this->profile->fingerprint(),
                'definitionFingerprint' => $this->profile->definition?->fingerprint(),
            ],
            'business' => [
                'state' => $this->state,
                'revision' => $this->revision,
            ],
            'execution' => $this->execution->toArray(),
            'transition' => $this->transition?->explain(),
            'children' => array_map(self::child(...), $this->work),
            'compensation' => array_map(static fn (array $entry): array => [
                'scopeId' => $entry['scope']->id(),
                'scope' => $entry['scope']->toArray(),
                ...$entry['description'],
            ], $this->compensation),
        ];
    }

    /**
     * @return list<array{identity: BindingIdentity, binding: ?ChildBinding, closed: ?NeverAdmittedChild, reservation: ?ChildReservation}>
     */
    private static function children(array $children, ExecutionReference $execution): array
    {
        if (! array_is_list($children) || count($children) > self::MAX_CHILDREN) {
            throw new InvalidArgumentException(sprintf(
                'Inspected child work requires a list of at most %d entries.',
                self::MAX_CHILDREN
            ));
        }
        $work = [];
        foreach ($children as $child) {
            [$identity, $kind] = match (true) {
                $child instanceof ChildBinding => [$child->identity, 'binding'],
                $child instanceof NeverAdmittedChild => [$child->reservation->identity, 'closed'],
                $child instanceof ChildReservation => [$child->identity, 'reservation'],
                default => throw new InvalidArgumentException(
                    'Inspected child work requires typed bindings or reservations.'
                ),
            };
            $parent = $identity->parentExecution;
            if ($parent->scope !== $execution->scope || $parent->subject !== $execution->subject
                || $parent->policyFingerprint !== $execution->policyFingerprint) {
                throw new InvalidArgumentException(
                    'Inspected child work belongs to another business subject or pinned profile.'
                );
            }
            $key = $identity->key();
            $entry = $work[$key] ?? [
                'identity' => $identity,
                'binding' => null,
                'closed' => null,
                'reservation' => null,
            ];
            // One generation is admitted or permanently closed, never both; a closure already
            // carries its reservation, and an admitted child may be paired with its own reservation.
            if ($entry[$kind] !== null || $entry['identity']->fingerprint() !== $identity->fingerprint()
                || ($kind === 'closed' && ($entry['binding'] !== null || $entry['reservation'] !== null))
                || ($kind !== 'closed' && $entry['closed'] !== null)) {
                throw new InvalidArgumentException('Inspected child work repeats or contradicts a binding generation.');
            }
            $entry[$kind] = $child;
            $work[$key] = $entry;
        }
        return array_values($work);
    }

    /**
     * @return list<array{scope: ScopeIdentity, description: array<string, mixed>}>
     */
    private static function compensation(array $entries): array
    {
        if (! array_is_list($entries) || count($entries) > self::MAX_SCOPES) {
            throw new InvalidArgumentException(sprintf(
                'Inspected compensation requires a list of at most %d scopes.',
                self::MAX_SCOPES
            ));
        }
        $ids = [];
        $valid = [];
        foreach ($entries as $entry) {
            if (! is_array($entry) || count($entry) !== 2 || ! ($entry['scope'] ?? null) instanceof ScopeIdentity
                || ! is_array($entry['description'] ?? null)) {
                throw new InvalidArgumentException('Inspected compensation requires a scope and its description.');
            }
            $description = $entry['description'];
            $keys = array_keys($description);
            if (count($keys) !== count(self::SCOPE_DESCRIPTION) || array_diff(self::SCOPE_DESCRIPTION, $keys) !== []
                || ! self::describedScope($description)) {
                throw new InvalidArgumentException('Inspected compensation requires a ScopeRecovery description.');
            }
            if (in_array($entry['scope']->id(), $ids, true)) {
                throw new InvalidArgumentException('Inspected compensation repeats a retained scope.');
            }
            $ids[] = $entry['scope']->id();
            $valid[] = [
                'scope' => $entry['scope'],
                'description' => $description,
            ];
        }
        return $valid;
    }

    /**
     * The documented ScopeRecovery::describe() value types, so the view stays portable.
     *
     * @param array<string, mixed> $description
     */
    private static function describedScope(array $description): bool
    {
        foreach (['scopeStatus', 'automaticRecovery', 'nextAction'] as $field) {
            if (! is_string($description[$field])) {
                return false;
            }
        }
        foreach (['originStatus', 'recoveryRunId', 'recoveryStatus'] as $field) {
            if ($description[$field] !== null && ! is_string($description[$field])) {
                return false;
            }
        }
        $refusal = $description['refusal'];
        if ($refusal !== null && (! is_array($refusal) || array_keys($refusal) !== ['code', 'message']
            || ! is_string($refusal['code']) || ($refusal['message'] !== null && ! is_string($refusal['message'])))) {
            return false;
        }
        if (! is_array($description['unresolved']) || ! array_is_list($description['unresolved'])) {
            return false;
        }
        foreach ($description['unresolved'] as $obligation) {
            if (! is_array($obligation) || array_keys($obligation) !== ['key', 'status', 'reason']
                || ! is_string($obligation['key']) || ! is_string($obligation['status'])
                || ($obligation['reason'] !== null && ! is_string($obligation['reason']))) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param array{identity: BindingIdentity, binding: ?ChildBinding, closed: ?NeverAdmittedChild, reservation: ?ChildReservation} $work
     * @return array<string, mixed>
     */
    private static function child(array $work): array
    {
        $identity = $work['identity'];
        $reservation = $work['closed']?->reservation ?? $work['reservation'];
        $participation = $reservation?->participation;
        return [
            'status' => match (true) {
                $work['binding'] !== null => 'admitted',
                $work['closed'] !== null => 'never_admitted',
                default => 'reserved',
            },
            'binding' => $identity->key(),
            'slot' => $identity->slot,
            'generation' => $identity->generation,
            'hostingState' => $identity->hostingState,
            'childWorkflowType' => $identity->childWorkflowType,
            'requiredOutcome' => $identity->requiredOutcome,
            'parentExecutionId' => $identity->parentExecution->executionId,
            'childInstanceId' => $work['binding']?->admission->childInstanceId ?? $reservation?->childInstanceId(),
            'childRunId' => $work['binding']?->admission->childRunId,
            'closureId' => $work['closed']?->closureId,
            'participation' => $participation === null ? null : [
                'parentScopeId' => $participation->parent->id(),
                'childScopeId' => $participation->child->id(),
                'obligationKey' => $participation->obligationKey,
            ],
        ];
    }
}
