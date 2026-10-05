<?php

declare(strict_types=1);

namespace Workflow\V2;

use Carbon\CarbonImmutable;
use Fiber;
use InvalidArgumentException;
use LogicException;
use WeakMap;
use WeakReference;
use Workflow\V2\Support\WorkflowFiberContext;

/** Immutable request metadata recorded in canonical workflow history. */
final class CancellationContext
{
    /**
     * @var WeakMap<self, WeakReference<Fiber>>|null
     */
    private static ?WeakMap $replayFibers = null;

    /**
     * @param array<string, string> $requester
     * @param list<array{request_id: string, workflow_instance_id: string, workflow_run_id: string}> $lineage
     */
    private function __construct(
        public readonly string $requestId,
        public readonly string $rootRequestId,
        public readonly string $rootWorkflowInstanceId,
        public readonly string $rootWorkflowRunId,
        public readonly ?string $parentRequestId,
        public readonly ?string $reason,
        public readonly array $requester,
        public readonly string $source,
        private readonly CarbonImmutable $originalRequestedAt,
        private readonly CarbonImmutable $cleanupDeadline,
        public readonly array $lineage,
        public readonly ?ScopedCancellationContext $scopeOrigin = null,
    ) {
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    public static function fromArray(array $snapshot): self
    {
        $schema = $snapshot['schema'] ?? null;
        if (! in_array($schema, ['durable-workflow.cancellation-context/v1',
            'durable-workflow.cancellation-context/v2'], true)) {
            throw new InvalidArgumentException('Unsupported cancellation context schema.');
        }
        $scopeOrigin = null;
        if ($schema === 'durable-workflow.cancellation-context/v2') {
            if (! is_array($snapshot['scope_origin'] ?? null)) {
                throw new InvalidArgumentException('Scoped run cancellation requires its original scope context.');
            }
            $scopeOrigin = ScopedCancellationContext::fromArray($snapshot['scope_origin']);
        } elseif (array_key_exists('scope_origin', $snapshot) || array_key_exists(
            'scope_authority_deadline_at',
            $snapshot
        )) {
            throw new InvalidArgumentException('Legacy cancellation context cannot discard a scoped origin.');
        }
        $requester = $snapshot['requester'] ?? null;
        if (! is_array($requester) || array_is_list($requester) || $requester === []) {
            throw new InvalidArgumentException('Cancellation requester must identify its caller.');
        }
        foreach ($requester as $key => $value) {
            if (! in_array($key, ['type', 'id', 'label'], true) || ! is_string($value) || $value === '') {
                throw new InvalidArgumentException('Cancellation requester contains unsupported metadata.');
            }
        }
        // JSON databases may reorder object keys. Identity comparisons must
        // preserve the values without depending on their storage order.
        $normalizedRequester = [];
        foreach (['type', 'id', 'label'] as $key) {
            if (isset($requester[$key])) {
                $normalizedRequester[$key] = $requester[$key];
            }
        }
        $lineage = $snapshot['lineage'] ?? null;
        if (! is_array($lineage) || ! array_is_list($lineage) || $lineage === []) {
            throw new InvalidArgumentException('Cancellation lineage must contain the root request.');
        }
        $normalizedLineage = [];
        foreach ($lineage as $entry) {
            if (! is_array($entry)) {
                throw new InvalidArgumentException('Cancellation lineage entry is invalid.');
            }
            $normalizedLineage[] = [
                'request_id' => self::text($entry, 'request_id'),
                'workflow_instance_id' => self::text($entry, 'workflow_instance_id'),
                'workflow_run_id' => self::text($entry, 'workflow_run_id'),
            ];
        }
        $requestId = self::text($snapshot, 'request_id');
        $rootRequestId = self::text($snapshot, 'root_request_id');
        $rootInstanceId = self::text($snapshot, 'root_workflow_instance_id');
        $rootRunId = self::text($snapshot, 'root_workflow_run_id');
        $parentRequestId = $snapshot['parent_request_id'] ?? null;
        $reason = $snapshot['reason'] ?? null;
        if (count(array_unique(array_column($normalizedLineage, 'request_id'))) !== count($normalizedLineage)
            || count(array_unique(array_column($normalizedLineage, 'workflow_run_id'))) !== count($normalizedLineage)) {
            throw new InvalidArgumentException('Cancellation lineage cannot contain a cycle.');
        }
        if (($parentRequestId !== null && (! is_string($parentRequestId) || $parentRequestId === ''))
            || ($reason !== null && ! is_string($reason))) {
            throw new InvalidArgumentException('Cancellation parent identity or reason is invalid.');
        }
        if ($normalizedLineage[0] !== [
            'request_id' => $rootRequestId,
            'workflow_instance_id' => $rootInstanceId,
            'workflow_run_id' => $rootRunId,
        ] || $normalizedLineage[count($normalizedLineage) - 1]['request_id'] !== $requestId
            || ($scopeOrigin === null && (count($normalizedLineage) === 1
                ? $parentRequestId !== null
                : $parentRequestId !== $normalizedLineage[count($normalizedLineage) - 2]['request_id']))) {
            throw new InvalidArgumentException('Cancellation lineage does not match its request identities.');
        }
        $requestedAt = self::timestamp(self::text($snapshot, 'requested_at'));
        $deadline = self::timestamp(self::text($snapshot, 'cleanup_deadline_at'));
        if ($deadline->lessThanOrEqualTo($requestedAt)) {
            throw new InvalidArgumentException('Cancellation deadline must follow the original request.');
        }
        if ($scopeOrigin !== null) {
            $last = $normalizedLineage[count($normalizedLineage) - 1];
            $expected = self::scopedDescendantSnapshot(
                $scopeOrigin,
                $requestId,
                $last['workflow_instance_id'],
                $last['workflow_run_id'],
                self::timestamp(self::text($snapshot, 'scope_authority_deadline_at')),
            );
            $normalized = [
                ...$snapshot,
                'lineage' => $normalizedLineage,
                'requested_at' => $requestedAt->toISOString(),
                'cleanup_deadline_at' => $deadline->toISOString(),
                'scope_authority_deadline_at' => self::timestamp(
                    self::text($snapshot, 'scope_authority_deadline_at')
                )->toISOString(),
            ];
            foreach ($expected as $key => $value) {
                $actual = $normalized[$key] ?? null;
                if ($key === 'requester') {
                    ksort($actual);
                    ksort($value);
                }
                if ($key !== 'scope_origin' && $actual !== $value) {
                    throw new InvalidArgumentException(
                        'Run cancellation does not preserve its original scope context.'
                    );
                }
            }
        }

        return new self(
            $requestId,
            $rootRequestId,
            $rootInstanceId,
            $rootRunId,
            $parentRequestId,
            $reason,
            $normalizedRequester,
            self::text($snapshot, 'source'),
            $requestedAt,
            $deadline,
            $normalizedLineage,
            $scopeOrigin,
        );
    }

    public function requestedAt(): CarbonImmutable
    {
        return $this->originalRequestedAt;
    }

    public function deadline(): CarbonImmutable
    {
        return $this->cleanupDeadline;
    }

    /**
     * Derive a local delivery identity without granting another cleanup budget.
     */
    public function forDescendant(string $requestId, string $workflowInstanceId, string $workflowRunId): self
    {
        if ($this->scopeOrigin !== null) {
            return self::fromScopeContext(
                ScopedCancellationContext::fromRunContext($this),
                $requestId,
                $workflowInstanceId,
                $workflowRunId,
            );
        }
        $snapshot = $this->toArray();
        $snapshot['request_id'] = $requestId;
        $snapshot['parent_request_id'] = $this->requestId;
        $snapshot['lineage'][] = [
            'request_id' => $requestId,
            'workflow_instance_id' => $workflowInstanceId,
            'workflow_run_id' => $workflowRunId,
        ];

        return self::fromArray($snapshot);
    }

    /**
     * Preserve every scope address when cooperative cancellation enters a child run.
     */
    public static function fromScopeContext(
        ScopedCancellationContext $origin,
        string $requestId,
        string $workflowInstanceId,
        string $workflowRunId,
        ?CarbonImmutable $authorityDeadline = null,
    ): self {
        return self::fromArray(self::scopedDescendantSnapshot(
            $origin,
            $requestId,
            $workflowInstanceId,
            $workflowRunId,
            $authorityDeadline,
        ));
    }

    /**
     * Remaining seconds at the consumed blocking boundary, never the host clock.
     */
    public function remaining(): float
    {
        $fiber = Fiber::getCurrent();
        $binding = self::$replayFibers === null ? null : (self::$replayFibers[$this] ?? null);
        if (! $fiber instanceof Fiber || $binding?->get() !== $fiber || ! WorkflowFiberContext::active()) {
            throw new LogicException('Cancellation remaining() requires its active workflow replay.');
        }

        $time = WorkflowFiberContext::getCancellationTime();
        $seconds = $this->cleanupDeadline->getTimestamp() - $time->getTimestamp();
        $microseconds = (int) $this->cleanupDeadline->format('u') - (int) $time->format('u');

        return max(0.0, $seconds + $microseconds / 1_000_000);
    }

    /**
     * @internal Bind only at canonical executor delivery, without retaining the Fiber.
     */
    public function bindToReplayFiber(Fiber $fiber): void
    {
        self::$replayFibers ??= new WeakMap();
        self::$replayFibers[$this] = WeakReference::create($fiber);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'schema' => $this->scopeOrigin === null ? 'durable-workflow.cancellation-context/v1'
                : 'durable-workflow.cancellation-context/v2',
            'request_id' => $this->requestId,
            'root_request_id' => $this->rootRequestId,
            'root_workflow_instance_id' => $this->rootWorkflowInstanceId,
            'root_workflow_run_id' => $this->rootWorkflowRunId,
            'parent_request_id' => $this->parentRequestId,
            'reason' => $this->reason,
            'requester' => $this->requester,
            'source' => $this->source,
            'requested_at' => $this->originalRequestedAt->toISOString(),
            'cleanup_deadline_at' => $this->cleanupDeadline->toISOString(),
            'lineage' => $this->lineage,
            ...($this->scopeOrigin === null ? [] : [
                'scope_origin' => $this->scopeOrigin->toArray(),
                'scope_authority_deadline_at' => $this->cleanupDeadline->toISOString(),
            ]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function scopedDescendantSnapshot(
        ScopedCancellationContext $origin,
        string $requestId,
        string $workflowInstanceId,
        string $workflowRunId,
        ?CarbonImmutable $authorityDeadline = null,
    ): array {
        if (in_array($workflowRunId, array_column($origin->lineage, 'workflow_run_id'), true)
            || in_array($requestId, array_column($origin->lineage, 'request_id'), true)
            || trim($requestId) === '' || trim($workflowInstanceId) === '' || trim($workflowRunId) === '') {
            throw new InvalidArgumentException('Scoped run cancellation cannot repeat a request or run.');
        }
        $root = $origin->rootContext->toArray();
        $deadline = $authorityDeadline ?? $origin->deadline();
        if ($deadline->greaterThan($origin->deadline()) || $deadline->lessThanOrEqualTo($origin->requestedAt())) {
            throw new InvalidArgumentException('Child cancellation authority cannot extend its original scope budget.');
        }
        $lineage = [$root['lineage'][0]];
        foreach ($origin->lineage as $entry) {
            if ($entry['workflow_run_id'] === $origin->rootContext->rootWorkflowRunId) {
                continue;
            }
            $address = array_intersect_key($entry, array_flip([
                'request_id', 'workflow_instance_id', 'workflow_run_id',
            ]));
            $index = count($lineage) - 1;
            if ($lineage[$index]['workflow_run_id'] === $entry['workflow_run_id']) {
                $lineage[$index] = $address;
            } else {
                $lineage[] = $address;
            }
        }
        return [
            ...$root,
            'schema' => 'durable-workflow.cancellation-context/v2',
            'request_id' => $requestId,
            'parent_request_id' => $origin->requestId,
            'cleanup_deadline_at' => $deadline
                ->toISOString(),
            'lineage' => [...$lineage, [
                'request_id' => $requestId,
                'workflow_instance_id' => $workflowInstanceId,
                'workflow_run_id' => $workflowRunId,
            ]],
            'scope_origin' => $origin->toArray(),
            'scope_authority_deadline_at' => $deadline->toISOString(),
        ];
    }

    /**
     * @param array<string, mixed> $value
     */
    private static function text(array $value, string $key): string
    {
        $text = $value[$key] ?? null;
        if (! is_string($text) || trim($text) === '') {
            throw new InvalidArgumentException(sprintf('Cancellation %s must be a non-empty string.', $key));
        }

        return $text;
    }

    private static function timestamp(string $value): CarbonImmutable
    {
        if (preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})\z/', $value) !== 1) {
            throw new InvalidArgumentException('Cancellation timestamp requires an ISO date, time and timezone.');
        }
        $time = CarbonImmutable::parse($value);
        $errors = CarbonImmutable::getLastErrors();
        if ($errors !== false && ($errors['warning_count'] !== 0 || $errors['error_count'] !== 0)) {
            throw new InvalidArgumentException('Cancellation timestamp is invalid.');
        }

        return $time;
    }
}
