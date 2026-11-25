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
 * pinned profile. It performs no I/O and is not an authorization or persistence boundary; business
 * state remains distinct from workflow-run status.
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
     * @param string $state The current business state from the application's authoritative store.
     * @param string $revision Its current revision.
     * @param list<ChildBinding|NeverAdmittedChild|ChildReservation> $children Admitted, permanently
     *        closed or still-reserved child work, in the host's order.
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
        if (! in_array($state, $profile->states, true) || trim($revision) === '') {
            throw new InvalidArgumentException('The inspected business state must be declared by its pinned profile.');
        }
        if ($execution->policyFingerprint !== $profile->fingerprint()) {
            throw new InvalidArgumentException('The inspected execution does not use the pinned profile.');
        }
        if ($transition !== null && ! $transition->execution->equals($execution)) {
            throw new InvalidArgumentException('The inspected transition belongs to another business execution.');
        }
        $this->children = self::children($children, $execution);
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
            'children' => array_map(self::child(...), $this->children),
            'compensation' => array_map(static fn (array $entry): array => [
                'scopeId' => $entry['scope']->id(),
                'scope' => $entry['scope']->toArray(),
                ...$entry['description'],
            ], $this->compensation),
        ];
    }

    /**
     * @return list<ChildBinding|NeverAdmittedChild|ChildReservation>
     */
    private static function children(array $children, ExecutionReference $execution): array
    {
        if (! array_is_list($children) || count($children) > self::MAX_CHILDREN) {
            throw new InvalidArgumentException(sprintf(
                'Inspected child work requires a list of at most %d entries.',
                self::MAX_CHILDREN
            ));
        }
        $keys = [];
        foreach ($children as $child) {
            if (! $child instanceof ChildBinding && ! $child instanceof NeverAdmittedChild
                && ! $child instanceof ChildReservation) {
                throw new InvalidArgumentException('Inspected child work requires typed bindings or reservations.');
            }
            $identity = self::identity($child);
            $parent = $identity->parentExecution;
            if ($parent->scope !== $execution->scope || $parent->subject !== $execution->subject
                || $parent->policyFingerprint !== $execution->policyFingerprint) {
                throw new InvalidArgumentException(
                    'Inspected child work belongs to another business subject or pinned profile.'
                );
            }
            if (in_array($identity->key(), $keys, true)) {
                throw new InvalidArgumentException('Inspected child work repeats a binding generation.');
            }
            $keys[] = $identity->key();
        }
        return array_values($children);
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
                || ! is_string($description['scopeStatus']) || ! is_string($description['nextAction'])
                || ! is_array($description['unresolved']) || ! array_is_list($description['unresolved'])) {
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

    private static function identity(ChildBinding|NeverAdmittedChild|ChildReservation $child): BindingIdentity
    {
        return match (true) {
            $child instanceof ChildBinding => $child->identity,
            $child instanceof NeverAdmittedChild => $child->reservation->identity,
            default => $child->identity,
        };
    }

    /**
     * @return array<string, ?string>
     */
    private static function child(ChildBinding|NeverAdmittedChild|ChildReservation $child): array
    {
        $identity = self::identity($child);
        [$status, $instance, $run, $closure] = match (true) {
            $child instanceof ChildBinding => [
                'admitted',
                $child->admission->childInstanceId,
                $child->admission->childRunId,
                null,
            ],
            $child instanceof NeverAdmittedChild => [
                'never_admitted',
                $child->reservation->childInstanceId(),
                null,
                $child->closureId,
            ],
            default => ['reserved', $child->childInstanceId(), null, null],
        };
        return [
            'status' => $status,
            'binding' => $identity->key(),
            'slot' => $identity->slot,
            'generation' => $identity->generation,
            'hostingState' => $identity->hostingState,
            'childWorkflowType' => $identity->childWorkflowType,
            'requiredOutcome' => $identity->requiredOutcome,
            'parentExecutionId' => $identity->parentExecution->executionId,
            'childInstanceId' => $instance,
            'childRunId' => $run,
            'closureId' => $closure,
        ];
    }
}
