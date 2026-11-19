<?php

declare(strict_types=1);

namespace Workflow\V2\StateMachine\Rules;

use InvalidArgumentException;
use Workflow\V2\Compensation\Definition\PortableDescriptor;
use Workflow\V2\StateMachine\Definition\MachineDefinition;

/**
 * Recorded invariant outcome; the host retains the complete correlated ConditionEvaluation for inspection.
 *
 * @experimental Part of the business state-machine API, which may change in a minor release.
 * See docs/api-stability.md#state-machines.
 */
final readonly class MachineAssessment
{
    private function __construct(
        public string $definitionFingerprint,
        public string $invariantFingerprint,
        public string $snapshotId,
        public Decision $decision
    ) {
        PortableDescriptor::identifier($snapshotId, 'machine fact snapshot');
        PortableDescriptor::encode($this->toArray());
    }

    public static function fromEvaluation(MachineDefinition $definition, ConditionEvaluation $evaluation): self
    {
        if ($evaluation->condition->fingerprint() !== $definition->invariants->fingerprint()) {
            throw new InvalidArgumentException('The invariant assessment uses another machine condition.');
        }
        return new self(
            $definition->fingerprint(),
            $definition->invariants->fingerprint(),
            $evaluation->snapshotId,
            $evaluation->decision
        );
    }

    public function toArray(): array
    {
        return [
            'version' => 1,
            'definitionFingerprint' => $this->definitionFingerprint,
            'invariantFingerprint' => $this->invariantFingerprint,
            'snapshotId' => $this->snapshotId,
            'decision' => $this->decision->toArray(),
        ];
    }

    public function matches(MachineDefinition $definition, string $snapshotId): bool
    {
        return $this->definitionFingerprint === $definition->fingerprint()
            && $this->invariantFingerprint === $definition->invariants->fingerprint()
            && $this->snapshotId === $snapshotId;
    }

    public static function fromArray(array $value, MachineDefinition $definition): self
    {
        PortableDescriptor::encode($value);
        $keys = array_keys($value);
        sort($keys);
        if ($keys !== ['decision', 'definitionFingerprint', 'invariantFingerprint', 'snapshotId', 'version']
            || $value['version'] !== 1 || $value['definitionFingerprint'] !== $definition->fingerprint()
            || $value['invariantFingerprint'] !== $definition->invariants->fingerprint()
            || ! is_string($value['snapshotId']) || ! is_array($value['decision'])) {
            throw new InvalidArgumentException(
                'The recorded invariant assessment belongs to another definition or format.'
            );
        }
        $decision = $value['decision'];
        $keys = array_keys($decision);
        sort($keys);
        if ($keys !== ['evidence', 'reason', 'verdict'] || ! is_string($decision['verdict'])
            || ! is_string($decision['reason']) || ! is_array($decision['evidence'])
            || Verdict::tryFrom($decision['verdict']) === null) {
            throw new InvalidArgumentException('The recorded invariant decision is malformed.');
        }
        return new self(
            $value['definitionFingerprint'],
            $value['invariantFingerprint'],
            $value['snapshotId'],
            new Decision(Verdict::from($decision['verdict']), $decision['reason'], $decision['evidence'])
        );
    }
}
