<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use LogicException;

final class SelectCall extends AllCall
{
    /**
     * @param iterable<int|string, mixed> $calls
     */
    public function __construct(iterable $calls)
    {
        parent::__construct($calls, 'select');
    }

    /**
     * @param array<string, mixed> $winner
     * @param array<int, mixed> $flatResults
     * @param array<int, \Throwable> $flatFailures
     * @param array<int, string> $operationIdentities
     */
    public function resolved(
        int $baseSequence,
        array $winner,
        array $flatResults,
        array $flatFailures = [],
        array $operationIdentities = [],
    ): SelectionResult {
        $winnerIndex = is_int($winner['member_index'] ?? null) ? $winner['member_index'] : 0;
        $handles = [];
        $cursor = 0;

        foreach ($this->calls as $index => $call) {
            $size = $call instanceof AllCall ? $call->leafCount() : 1;
            $kind = self::callKind($call);
            $key = $this->keys[$index];
            $identity = $index === $winnerIndex && is_string($winner['operation_identity'] ?? null)
                ? $winner['operation_identity']
                : ($kind === 'group'
                    ? sprintf('group:%d:%d', $baseSequence + $cursor, $size)
                    : ($operationIdentities[$baseSequence + $cursor] ?? null));
            if (! is_string($identity) || $identity === '') {
                throw new LogicException(sprintf(
                    'Selection member [%s] is missing its durable scheduled or open operation identity.',
                    (string) $key,
                ));
            }
            $handles[$key] = new DurableOperationHandle(
                $key,
                $index,
                $kind,
                $identity,
                $baseSequence + $cursor,
                $size,
                sprintf('select-calls:%d:%d', $baseSequence, $this->leafCount()),
                $call,
            );
            $cursor += $size;
        }

        $winnerKey = $this->keys[$winnerIndex] ?? $winnerIndex;
        $winnerHandle = $handles[$winnerKey];
        $winnerValue = $this->memberValue($winnerIndex, $flatResults);
        $winnerFailure = $this->memberFailure($baseSequence, $winnerIndex, $winner, $flatFailures);

        return new SelectionResult(
            $winnerKey,
            $winnerIndex,
            $winnerHandle->kind,
            $winnerHandle->identity,
            $winnerValue,
            $winnerFailure,
            $winnerHandle,
            $handles,
        );
    }

    /**
     * @param array<int, mixed> $flatResults
     */
    private function memberValue(int $memberIndex, array $flatResults): mixed
    {
        $cursor = 0;

        foreach ($this->calls as $index => $call) {
            $size = $call instanceof AllCall ? $call->leafCount() : 1;

            if ($index === $memberIndex) {
                if (! $call instanceof AllCall) {
                    return $flatResults[$cursor] ?? null;
                }

                $values = [];
                for ($offset = 0; $offset < $size; ++$offset) {
                    $values[] = $flatResults[$cursor + $offset] ?? null;
                }

                return $call->nestedResults($values);
            }

            $cursor += $size;
        }

        return null;
    }

    /**
     * @param array<int, \Throwable> $flatFailures
     */
    private function memberFailure(
        int $baseSequence,
        int $memberIndex,
        array $winner,
        array $flatFailures,
    ): ?\Throwable {
        $resolutionSequence = is_int($winner['_resolution_sequence'] ?? null)
            ? $winner['_resolution_sequence']
            : null;
        if (($winner['outcome'] ?? null) === 'failed' && $resolutionSequence !== null) {
            $failure = $flatFailures[$resolutionSequence - $baseSequence] ?? null;

            return $failure instanceof \Throwable ? $failure : null;
        }

        $cursor = 0;

        foreach ($this->calls as $index => $call) {
            $size = $call instanceof AllCall ? $call->leafCount() : 1;

            if ($index === $memberIndex) {
                for ($offset = 0; $offset < $size; ++$offset) {
                    if (($flatFailures[$cursor + $offset] ?? null) instanceof \Throwable) {
                        return $flatFailures[$cursor + $offset];
                    }
                }

                return null;
            }

            $cursor += $size;
        }

        return null;
    }

    private static function callKind(
        ActivityCall|ChildWorkflowCall|TimerCall|SignalCall|AwaitCall|AwaitWithTimeoutCall|AllCall $call,
    ): string {
        return match (true) {
            $call instanceof ActivityCall => 'activity',
            $call instanceof ChildWorkflowCall => 'child',
            $call instanceof TimerCall => 'timer',
            $call instanceof SignalCall => 'signal',
            $call instanceof AwaitCall, $call instanceof AwaitWithTimeoutCall => 'condition',
            default => 'group',
        };
    }
}
