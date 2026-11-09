<?php

declare(strict_types=1);

namespace Workflow\V2\StateMachine\Execution;

use InvalidArgumentException;

/** Business identity survives activity retries and does not depend on a workflow run ID. */
final readonly class ExecutionReference
{
    public function __construct(
        public string $scope,
        public string $subject,
        public string $executionId,
        public string $policyFingerprint,
        public string $sourceState,
        public string $sourceRevision,
    ) {
        foreach (get_object_vars($this) as $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException('Execution identity fields must not be empty.');
            }
        }
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    public function equals(self $other): bool
    {
        return $this->toArray() === $other->toArray();
    }
}
