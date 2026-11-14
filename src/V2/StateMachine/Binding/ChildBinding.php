<?php

declare(strict_types=1);

namespace Workflow\V2\StateMachine\Binding;

use InvalidArgumentException;
use Workflow\V2\StartedChildWorkflow;

/**
 * A confirmed native receipt tied to one immutable business/state-entry generation.
 *
 * @experimental Persistence and live authorization are supplied by the binding runtime.
 */
final readonly class ChildBinding
{
    public function __construct(
        public BindingIdentity $identity,
        public StartedChildWorkflow $admission,
    ) {
        if ($identity->namespace !== $admission->namespace
            || $identity->parentInstanceId !== $admission->parentInstanceId
            || $identity->parentRunId !== $admission->parentRunId
            || $identity->childWorkflowType !== $admission->workflowType) {
            throw new InvalidArgumentException('The child admission belongs to a different binding owner or type.');
        }
    }

    public function matches(BindingIdentity $expected): bool
    {
        return $this->identity->toArray() === $expected->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'version' => 1,
            'identity' => $this->identity->toArray(),
            'admission' => $this->admission->toArray(),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        if (count($payload) !== 3 || ($payload['version'] ?? null) !== 1
            || ! is_array($payload['identity'] ?? null) || ! is_array($payload['admission'] ?? null)) {
            throw new InvalidArgumentException('Unsupported or malformed child binding.');
        }
        return new self(BindingIdentity::fromArray($payload['identity']), StartedChildWorkflow::fromArray(
            $payload['admission']
        ));
    }
}
