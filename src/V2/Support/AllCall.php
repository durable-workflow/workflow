<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use LogicException;
use Workflow\V2\Contracts\YieldedCommand;

class AllCall implements YieldedCommand
{
    /**
     * @var list<ActivityCall|ChildWorkflowCall|TimerCall|SignalCall|AwaitCall|AwaitWithTimeoutCall|AllCall>
     */
    public readonly array $calls;

    /**
     * @var list<int|string>
     */
    public readonly array $keys;

    public readonly ?string $kind;

    public readonly string $mode;

    /**
     * @param iterable<int|string, mixed> $calls
     */
    public function __construct(iterable $calls, string $mode = 'all')
    {
        if (! in_array($mode, ['all', 'select'], true)) {
            throw new LogicException(sprintf('Unsupported durable group mode [%s].', $mode));
        }

        $normalized = [];
        $keys = [];
        $seenSelectionKeys = [];

        foreach ($calls as $key => $call) {
            if (! is_int($key) && ! is_string($key)) {
                throw new LogicException(sprintf(
                    'Workflow\\V2\\select() member keys must be integers or strings. Received [%s].',
                    get_debug_type($key),
                ));
            }

            if ($mode === 'select' && ((is_string($key) && $key === '') || (is_int($key) && $key < 0))) {
                throw new LogicException(sprintf(
                    'Workflow\\V2\\select() member key [%s] must be a non-empty string or non-negative integer.',
                    (string) $key,
                ));
            }

            if ($mode === 'select' && array_key_exists($key, $seenSelectionKeys)) {
                throw new LogicException(sprintf(
                    'Workflow\\V2\\select() member key [%s] is duplicated.',
                    (string) $key,
                ));
            }

            if (! self::supports($call)) {
                throw new LogicException(sprintf(
                    'Workflow\\V2\\%s() supports activity(), child(), timer(), await(), signal waits, or nested all()/parallel() groups only. Received [%s].',
                    $mode === 'select' ? 'select' : 'all/parallel',
                    get_debug_type($call),
                ));
            }

            if ($mode === 'select' && $call instanceof SelectCall) {
                throw new LogicException(
                    'Nested select() groups are not supported; select a nested all()/parallel() barrier instead.'
                );
            }

            $normalized[] = $call;
            $keys[] = $key;
            $seenSelectionKeys[$key] = true;
        }

        $this->calls = $normalized;
        $this->keys = $keys;
        $this->kind = self::kindForCalls($normalized);
        $this->mode = $mode;

        if ($mode === 'select' && $normalized === []) {
            throw new LogicException('Workflow\\V2\\select() requires at least one durable operation.');
        }
    }

    public function leafCount(): int
    {
        return count($this->leafDescriptors());
    }

    /**
     * @return list<array{
     *     call: ActivityCall|ChildWorkflowCall|TimerCall|SignalCall|AwaitCall|AwaitWithTimeoutCall,
     *     offset: int,
     *     result_path: list<int>,
     *     group_path: list<array{
     *         parallel_group_id: string,
     *         parallel_group_kind: string,
     *         parallel_group_base_sequence: int,
     *         parallel_group_size: int,
     *         parallel_group_index: int
     *     }>
     * }>
     */
    public function leafDescriptors(int $baseSequence = 0): array
    {
        return $this->descriptors($baseSequence);
    }

    /**
     * @param list<mixed> $flatResults
     * @return list<mixed>
     */
    public function nestedResults(array $flatResults): array
    {
        $offset = 0;

        return $this->consumeNestedResults($flatResults, $offset);
    }

    /**
     * @param list<mixed> $flatResults
     * @return list<mixed>
     */
    private function consumeNestedResults(array $flatResults, int &$offset): array
    {
        $results = [];

        foreach ($this->calls as $call) {
            if ($call instanceof self) {
                $results[] = $call->consumeNestedResults($flatResults, $offset);

                continue;
            }

            $results[] = $flatResults[$offset] ?? null;
            ++$offset;
        }

        return $results;
    }

    /**
     * @param list<ActivityCall|ChildWorkflowCall|TimerCall|SignalCall|AwaitCall|AwaitWithTimeoutCall|AllCall> $calls
     */
    private static function kindForCalls(array $calls): ?string
    {
        $kind = null;

        foreach ($calls as $call) {
            $callKind = match (true) {
                $call instanceof ActivityCall => 'activity',
                $call instanceof ChildWorkflowCall => 'child',
                $call instanceof TimerCall => 'timer',
                $call instanceof SignalCall => 'signal',
                $call instanceof AwaitCall, $call instanceof AwaitWithTimeoutCall => 'condition',
                $call instanceof self => $call->kind,
                default => null,
            };

            if ($callKind === null) {
                continue;
            }

            if ($kind === null) {
                $kind = $callKind;

                continue;
            }

            if ($kind !== $callKind) {
                return 'mixed';
            }
        }

        return $kind;
    }

    /**
     * @return list<array{
     *     call: ActivityCall|ChildWorkflowCall|TimerCall|SignalCall|AwaitCall|AwaitWithTimeoutCall,
     *     offset: int,
     *     result_path: list<int>,
     *     group_path: list<array{
     *         parallel_group_id: string,
     *         parallel_group_kind: string,
     *         parallel_group_base_sequence: int,
     *         parallel_group_size: int,
     *         parallel_group_index: int
     *     }>
     * }>
     */
    private function descriptors(int $baseSequence): array
    {
        $descriptors = [];
        $cursor = 0;
        $groupSize = self::leafCountForCalls($this->calls);
        $groupKind = self::kindForCalls($this->calls) ?? 'activity';

        foreach ($this->calls as $index => $call) {
            $memberSize = $call instanceof self ? $call->leafCount() : 1;
            $memberKind = $call instanceof self ? 'group' : self::callKind($call);
            $groupEntry = ParallelChildGroup::groupEntry(
                $baseSequence,
                $groupSize,
                $cursor,
                $groupKind,
                $this->mode,
                $this->mode === 'select' ? $this->keys[$index] : null,
                $this->mode === 'select' ? $index : null,
                $this->mode === 'select' ? $baseSequence + $cursor : null,
                $this->mode === 'select' ? $memberSize : null,
                $this->mode === 'select' ? $memberKind : null,
            );

            if (! $call instanceof self) {
                $descriptors[] = [
                    'call' => $call,
                    'offset' => $cursor,
                    'result_path' => [$index],
                    'group_path' => [$groupEntry],
                ];
                ++$cursor;

                continue;
            }

            $nestedDescriptors = $call->descriptors($baseSequence + $cursor);

            foreach ($nestedDescriptors as $descriptor) {
                $leafGroupEntry = ParallelChildGroup::groupEntry(
                    $baseSequence,
                    $groupSize,
                    $cursor + $descriptor['offset'],
                    $groupKind,
                    $this->mode,
                    $this->mode === 'select' ? $this->keys[$index] : null,
                    $this->mode === 'select' ? $index : null,
                    $this->mode === 'select' ? $baseSequence + $cursor : null,
                    $this->mode === 'select' ? $memberSize : null,
                    $this->mode === 'select' ? $memberKind : null,
                );
                $descriptors[] = [
                    'call' => $descriptor['call'],
                    'offset' => $cursor + $descriptor['offset'],
                    'result_path' => array_merge([$index], $descriptor['result_path']),
                    'group_path' => array_merge([$leafGroupEntry], $descriptor['group_path']),
                ];
            }

            $cursor += $memberSize;
        }

        return $descriptors;
    }

    /**
     * @param list<ActivityCall|ChildWorkflowCall|TimerCall|SignalCall|AwaitCall|AwaitWithTimeoutCall|AllCall> $calls
     */
    private static function leafCountForCalls(array $calls): int
    {
        $count = 0;

        foreach ($calls as $call) {
            $count += $call instanceof self
                ? self::leafCountForCalls($call->calls)
                : 1;
        }

        return $count;
    }

    private static function supports(mixed $call): bool
    {
        return $call instanceof ActivityCall
            || $call instanceof ChildWorkflowCall
            || $call instanceof TimerCall
            || $call instanceof SignalCall
            || $call instanceof AwaitCall
            || $call instanceof AwaitWithTimeoutCall
            || $call instanceof self;
    }

    private static function callKind(
        ActivityCall|ChildWorkflowCall|TimerCall|SignalCall|AwaitCall|AwaitWithTimeoutCall $call,
    ): string {
        return match (true) {
            $call instanceof ActivityCall => 'activity',
            $call instanceof ChildWorkflowCall => 'child',
            $call instanceof TimerCall => 'timer',
            $call instanceof SignalCall => 'signal',
            default => 'condition',
        };
    }
}
