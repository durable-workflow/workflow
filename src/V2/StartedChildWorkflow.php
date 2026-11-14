<?php

declare(strict_types=1);

namespace Workflow\V2;

use InvalidArgumentException;
use Workflow\V2\Support\AwaitStartedChildWorkflowCall;
use Workflow\V2\Support\WorkflowFiberContext;

/** A confirmed native child admission, independent of its eventual result. */
final readonly class StartedChildWorkflow
{
    public function __construct(
        public string $namespace,
        public string $parentInstanceId,
        public string $parentRunId,
        public int $startSequence,
        public string $childInstanceId,
        public string $childRunId,
        public string $childCallId,
        public string $workflowType,
    ) {
        foreach (get_object_vars($this) as $value) {
            if (is_string($value) && trim($value) === '') {
                throw new InvalidArgumentException('Child admission identifiers must not be empty.');
            }
        }
        if ($startSequence < 1) {
            throw new InvalidArgumentException('A child admission requires its positive owning step.');
        }
    }

    public function await(): mixed
    {
        return WorkflowFiberContext::suspend(new AwaitStartedChildWorkflowCall($this));
    }

    /**
     * @return array<string, int|string>
     */
    public function toArray(): array
    {
        return [
            'version' => 1,
            ...get_object_vars($this),
        ];
    }

    /**
     * @param array<string, mixed> $value
     */
    public static function fromArray(array $value): self
    {
        $strings = ['namespace', 'parentInstanceId', 'parentRunId', 'childInstanceId',
            'childRunId', 'childCallId', 'workflowType'];
        if (($value['version'] ?? null) !== 1 || ! is_int($value['startSequence'] ?? null)
            || count($value) !== count($strings) + 2) {
            throw new InvalidArgumentException('Unsupported or malformed child admission.');
        }
        foreach ($strings as $key) {
            if (! is_string($value[$key] ?? null)) {
                throw new InvalidArgumentException('Child admission identifiers must be strings.');
            }
        }
        return new self(
            $value['namespace'],
            $value['parentInstanceId'],
            $value['parentRunId'],
            $value['startSequence'],
            $value['childInstanceId'],
            $value['childRunId'],
            $value['childCallId'],
            $value['workflowType']
        );
    }
}
