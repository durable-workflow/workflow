<?php

declare(strict_types=1);

namespace Workflow\V2\StateMachine\Binding;

use InvalidArgumentException;
use Workflow\V2\Enums\CancellationPolicy;
use Workflow\V2\Enums\ParentClosePolicy;
use Workflow\V2\StateMachine\Execution\ExecutionReference;
use Workflow\V2\Support\ChildWorkflowOptions;

/**
 * Original business/state-entry identity, independent of any later current-state pointer.
 *
 * @experimental This value is not authentication or a persistence/concurrency fence.
 */
final readonly class BindingIdentity
{
    public function __construct(
        public ExecutionReference $parentExecution,
        public string $namespace,
        public string $parentInstanceId,
        public string $parentRunId,
        public string $slot,
        public string $generation,
        public string $hostingState,
        public string $childWorkflowType,
        public string $childPolicyFingerprint,
        public string $requiredOutcome,
        public string $inputFingerprint,
        public ChildWorkflowOptions $childOptions = new ChildWorkflowOptions(),
    ) {
        foreach ([$namespace, $parentInstanceId, $parentRunId, $slot, $generation,
            $hostingState, $childWorkflowType, $requiredOutcome] as $value) {
            if (trim($value) === '' || strlen($value) > 512) {
                throw new InvalidArgumentException('Binding identity fields must contain between 1 and 512 bytes.');
            }
        }
        foreach ([$parentExecution->policyFingerprint, $childPolicyFingerprint, $inputFingerprint] as $fingerprint) {
            if (preg_match('/\A[0-9a-f]{64}\z/D', $fingerprint) !== 1) {
                throw new InvalidArgumentException('Binding policy and input fingerprints must be SHA-256 values.');
            }
        }
    }

    /**
     * Exact original generation lookup; never substitute a latest/current binding.
     */
    public function key(): string
    {
        return hash('sha256', json_encode([
            'version' => 1,
            'scope' => $this->parentExecution->scope,
            'subject' => $this->parentExecution->subject,
            'executionId' => $this->parentExecution->executionId,
            'namespace' => $this->namespace,
            'parentInstanceId' => $this->parentInstanceId,
            'parentRunId' => $this->parentRunId,
            'slot' => $this->slot,
            'generation' => $this->generation,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * The immutable descriptor must also match when an existing key is reused.
     */
    public function fingerprint(): string
    {
        return hash('sha256', json_encode($this->toArray(), JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'version' => 1,
            'parentExecution' => [
                'scope' => $this->parentExecution->scope,
                'subject' => $this->parentExecution->subject,
                'executionId' => $this->parentExecution->executionId,
                'policyFingerprint' => $this->parentExecution->policyFingerprint,
                'sourceState' => $this->parentExecution->sourceState,
                'sourceRevision' => $this->parentExecution->sourceRevision,
            ],
            'namespace' => $this->namespace,
            'parentInstanceId' => $this->parentInstanceId,
            'parentRunId' => $this->parentRunId,
            'slot' => $this->slot,
            'generation' => $this->generation,
            'hostingState' => $this->hostingState,
            'childWorkflowType' => $this->childWorkflowType,
            'childPolicyFingerprint' => $this->childPolicyFingerprint,
            'requiredOutcome' => $this->requiredOutcome,
            'inputFingerprint' => $this->inputFingerprint,
            'childOptions' => $this->childOptions->toSnapshot(),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        $strings = ['namespace', 'parentInstanceId', 'parentRunId', 'slot', 'generation',
            'hostingState', 'childWorkflowType', 'childPolicyFingerprint', 'requiredOutcome', 'inputFingerprint'];
        self::strings($payload, $strings, ['version', 'parentExecution', 'childOptions']);
        if ($payload['version'] !== 1 || ! is_array($payload['parentExecution']) || ! is_array(
            $payload['childOptions']
        )) {
            throw new InvalidArgumentException('Unsupported or malformed binding identity.');
        }
        $parent = $payload['parentExecution'];
        self::strings(
            $parent,
            ['scope', 'subject', 'executionId', 'policyFingerprint', 'sourceState', 'sourceRevision']
        );
        $options = $payload['childOptions'];
        self::strings($options, ['parent_close_policy', 'cancellation_policy'], ['connection', 'queue']);
        foreach (['connection', 'queue'] as $key) {
            if ($options[$key] !== null && ! is_string($options[$key])) {
                throw new InvalidArgumentException('Binding child routing must be a string or null.');
            }
        }
        return new self(
            new ExecutionReference(
                $parent['scope'],
                $parent['subject'],
                $parent['executionId'],
                $parent['policyFingerprint'],
                $parent['sourceState'],
                $parent['sourceRevision']
            ),
            $payload['namespace'],
            $payload['parentInstanceId'],
            $payload['parentRunId'],
            $payload['slot'],
            $payload['generation'],
            $payload['hostingState'],
            $payload['childWorkflowType'],
            $payload['childPolicyFingerprint'],
            $payload['requiredOutcome'],
            $payload['inputFingerprint'],
            new ChildWorkflowOptions(
                ParentClosePolicy::from($options['parent_close_policy']),
                $options['connection'],
                $options['queue'],
                CancellationPolicy::from($options['cancellation_policy']),
            ),
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string> $strings
     * @param list<string> $other
     */
    private static function strings(array $payload, array $strings, array $other = []): void
    {
        $expected = [...$strings, ...$other];
        if (count($payload) !== count($expected) || array_diff($expected, array_keys($payload)) !== []) {
            throw new InvalidArgumentException('Unexpected or missing binding identity field.');
        }
        foreach ($strings as $key) {
            if (! is_string($payload[$key])) {
                throw new InvalidArgumentException('Binding identity fields must be strings.');
            }
        }
    }
}
