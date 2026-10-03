<?php

declare(strict_types=1);

namespace Workflow\V2;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Workflow\V2\Support\CancellationScopeHistory;

/**
 * @internal Immutable scope-address metadata. It grants no admission authority.
 * Scoped delivery, authority ceilings and replay-clock binding are separate gates.
 */
final class ScopedCancellationContext
{
    public const SCHEMA = 'durable-workflow.scoped-cancellation-context/v1';

    public readonly string $requestId;

    public readonly ?string $parentRequestId;

    public readonly string $workflowInstanceId;

    public readonly string $workflowRunId;

    public readonly string $scopeId;

    public readonly string $rootScopeId;

    /**
     * @param list<array{request_id: string, workflow_instance_id: string, workflow_run_id: string, scope_id: string, cleanup_deadline_at: string}> $lineage
     */
    private function __construct(
        public readonly CancellationContext $rootContext,
        public readonly array $lineage,
        private readonly CarbonImmutable $cleanupDeadline,
    ) {
        $last = $lineage[count($lineage) - 1];
        $this->requestId = $last['request_id'];
        $this->parentRequestId = count($lineage) === 1 ? null : $lineage[count($lineage) - 2]['request_id'];
        $this->workflowInstanceId = $last['workflow_instance_id'];
        $this->workflowRunId = $last['workflow_run_id'];
        $this->scopeId = $last['scope_id'];
        $this->rootScopeId = $lineage[0]['scope_id'];
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    public static function fromArray(array $snapshot): self
    {
        self::assertKeys($snapshot, ['schema', 'root_context', 'lineage']);
        if ($snapshot['schema'] !== self::SCHEMA || ! is_array($snapshot['root_context'])) {
            throw new InvalidArgumentException('Unsupported scoped cancellation context schema or root.');
        }
        $root = CancellationContext::fromArray($snapshot['root_context']);
        self::assertKeys($snapshot['root_context'], array_keys($root->toArray()));
        if ($root->requestId !== $root->rootRequestId || $root->parentRequestId !== null || count(
            $root->lineage
        ) !== 1) {
            throw new InvalidArgumentException('Scoped cancellation root must contain the original root request.');
        }
        $lineage = $snapshot['lineage'];
        if (! is_array($lineage) || ! array_is_list($lineage) || $lineage === []) {
            throw new InvalidArgumentException('Scoped cancellation lineage must contain its root address.');
        }
        $requests = [];
        $addresses = [];
        $instancesByRun = [];
        $normalized = [];
        $deadline = $root->deadline();
        foreach ($lineage as $index => $entry) {
            if (! is_array($entry)) {
                throw new InvalidArgumentException('Scoped cancellation lineage entry is invalid.');
            }
            self::assertKeys($entry, [
                'request_id', 'workflow_instance_id', 'workflow_run_id', 'scope_id', 'cleanup_deadline_at',
            ]);
            $entry = [
                'request_id' => self::text($entry, 'request_id'),
                'workflow_instance_id' => self::text($entry, 'workflow_instance_id'),
                'workflow_run_id' => self::text($entry, 'workflow_run_id'),
                'scope_id' => self::text($entry, 'scope_id'),
                'cleanup_deadline_at' => self::text($entry, 'cleanup_deadline_at'),
            ];
            if ($index === 0 && ($entry['request_id'] !== $root->requestId
                || $entry['workflow_instance_id'] !== $root->rootWorkflowInstanceId
                || $entry['workflow_run_id'] !== $root->rootWorkflowRunId)) {
                throw new InvalidArgumentException('Scoped cancellation root address does not match its request.');
            }
            $address = json_encode([$entry['workflow_run_id'], $entry['scope_id']], JSON_THROW_ON_ERROR);
            if (isset($requests[$entry['request_id']]) || isset($addresses[$address])) {
                throw new InvalidArgumentException('Scoped cancellation lineage cannot repeat a request or address.');
            }
            if (isset($instancesByRun[$entry['workflow_run_id']])
                && $instancesByRun[$entry['workflow_run_id']] !== $entry['workflow_instance_id']) {
                throw new InvalidArgumentException(
                    'Scoped cancellation lineage assigns conflicting instances to one run.'
                );
            }
            $deadlineSnapshot = $root->toArray();
            $deadlineSnapshot['cleanup_deadline_at'] = $entry['cleanup_deadline_at'];
            $nextDeadline = CancellationContext::fromArray($deadlineSnapshot)->deadline();
            if (($index === 0 && ! $nextDeadline->equalTo($root->deadline())) || $nextDeadline->greaterThan(
                $deadline
            )) {
                throw new InvalidArgumentException(
                    'Scoped cancellation lineage cannot change its root or extend a descendant budget.'
                );
            }
            $entry['cleanup_deadline_at'] = $nextDeadline->toISOString();
            $normalized[] = $entry;
            $requests[$entry['request_id']] = true;
            $addresses[$address] = true;
            $instancesByRun[$entry['workflow_run_id']] = $entry['workflow_instance_id'];
            $deadline = $nextDeadline;
        }
        return new self($root, $normalized, $deadline);
    }

    public static function fromRunContext(CancellationContext $context): self
    {
        $root = $context->toArray();
        $root['request_id'] = $context->rootRequestId;
        $root['parent_request_id'] = null;
        $root['lineage'] = [$context->lineage[0]];
        return self::fromArray([
            'schema' => self::SCHEMA,
            'root_context' => $root,
            'lineage' => array_map(static fn (array $entry): array => [
                ...$entry,
                'scope_id' => CancellationScopeHistory::ROOT_SCOPE_ID,
                'cleanup_deadline_at' => $context->deadline()
                    ->toISOString(),
            ], $context->lineage),
        ]);
    }

    public function forDescendant(
        string $requestId,
        string $workflowInstanceId,
        string $workflowRunId,
        string $scopeId,
        ?CarbonImmutable $deadline = null,
    ): self {
        $snapshot = $this->toArray();
        $snapshot['lineage'][] = [
            'request_id' => $requestId,
            'workflow_instance_id' => $workflowInstanceId,
            'workflow_run_id' => $workflowRunId,
            'scope_id' => $scopeId,
            'cleanup_deadline_at' => ($deadline ?? $this->cleanupDeadline)
                ->toISOString(),
        ];
        return self::fromArray($snapshot);
    }

    public function requestedAt(): CarbonImmutable
    {
        return $this->rootContext->requestedAt();
    }

    public function rootDeadline(): CarbonImmutable
    {
        return $this->rootContext->deadline();
    }

    public function deadline(): CarbonImmutable
    {
        return $this->cleanupDeadline;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'schema' => self::SCHEMA,
            'root_context' => $this->rootContext->toArray(),
            'lineage' => $this->lineage,
        ];
    }

    /**
     * @param array<string, mixed> $value
     */
    private static function text(array $value, string $key): string
    {
        $text = $value[$key];
        if (! is_string($text) || trim($text) === '') {
            throw new InvalidArgumentException('Scoped cancellation address and deadline must be nonempty strings.');
        }
        return $text;
    }

    /** @param array<string, mixed> $value
     * @param list<string> $keys
     */
    private static function assertKeys(array $value, array $keys): void
    {
        $actual = array_keys($value);
        sort($actual);
        sort($keys);
        if ($actual !== $keys) {
            throw new InvalidArgumentException('Scoped cancellation context has missing or unsupported fields.');
        }
    }
}
