<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use LogicException;
use Workflow\V2\Contracts\YieldedCommand;

final class AllCall implements YieldedCommand
{
    /**
     * @var list<ActivityCall|ChildWorkflowCall|AllCall>
     */
    public readonly array $calls;

    public readonly ?string $kind;

    /**
     * @param iterable<int, mixed> $calls
     */
    public function __construct(iterable $calls)
    {
        $normalized = [];

        foreach ($calls as $call) {
            if (! $call instanceof ActivityCall && ! $call instanceof ChildWorkflowCall && ! $call instanceof self) {
                throw new LogicException(sprintf(
                    'Workflow\\V2\\all() currently supports activity() calls, child() calls, or nested all() groups only. Received [%s].',
                    get_debug_type($call),
                ));
            }

            $normalized[] = $call;
        }

        $this->calls = $normalized;
        $this->kind = self::kindForCalls($normalized);
    }

    public function leafCount(): int
    {
        return count($this->leafDescriptors());
    }

    /**
     * @return list<array{
     *     call: ActivityCall|ChildWorkflowCall,
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
        return self::leafDescriptorsForCalls($this->calls, $baseSequence);
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
     * @param list<ActivityCall|ChildWorkflowCall|AllCall> $calls
     */
    private static function kindForCalls(array $calls): ?string
    {
        $kind = null;

        foreach ($calls as $call) {
            $callKind = match (true) {
                $call instanceof ActivityCall => 'activity',
                $call instanceof ChildWorkflowCall => 'child',
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
     * @param list<ActivityCall|ChildWorkflowCall|AllCall> $calls
     * @return list<array{
     *     call: ActivityCall|ChildWorkflowCall,
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
    private static function leafDescriptorsForCalls(array $calls, int $baseSequence): array
    {
        $descriptors = [];
        $cursor = 0;
        $groupSize = self::leafCountForCalls($calls);
        $groupKind = self::kindForCalls($calls) ?? 'activity';

        foreach ($calls as $index => $call) {
            if ($call instanceof ActivityCall || $call instanceof ChildWorkflowCall) {
                $descriptors[] = [
                    'call' => $call,
                    'offset' => $cursor,
                    'result_path' => [$index],
                    'group_path' => [
                        ParallelChildGroup::groupEntry($baseSequence, $groupSize, $cursor, $groupKind),
                    ],
                ];
                ++$cursor;

                continue;
            }

            $nestedDescriptors = self::leafDescriptorsForCalls($call->calls, $baseSequence + $cursor);

            foreach ($nestedDescriptors as $descriptor) {
                $descriptors[] = [
                    'call' => $descriptor['call'],
                    'offset' => $cursor + $descriptor['offset'],
                    'result_path' => array_merge([$index], $descriptor['result_path']),
                    'group_path' => array_merge([
                        ParallelChildGroup::groupEntry(
                            $baseSequence,
                            $groupSize,
                            $cursor + $descriptor['offset'],
                            $groupKind,
                        ),
                    ], $descriptor['group_path']),
                ];
            }

            $cursor += self::leafCountForCalls($call->calls);
        }

        return $descriptors;
    }

    /**
     * @param list<ActivityCall|ChildWorkflowCall|AllCall> $calls
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
}
