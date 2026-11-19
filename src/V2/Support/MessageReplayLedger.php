<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowMessage;
use Workflow\V2\Models\WorkflowRun;

/**
 * What one execution of workflow code has already matched against the message
 * table. Workflow code runs again on every replay, so a send or receive it
 * already made is matched to the row it wrote or consumed instead of acting a
 * second time (#1010).
 *
 * Matches are counted per send identity and per receive step, so a lookup
 * skips the rows this execution already matched instead of listing them.
 *
 * @internal
 */
final class MessageReplayLedger
{
    /**
     * A redrive successor replays its source's prefix, so the chain can be long
     * only through repeated redrives of one instance.
     */
    private const MAX_REDRIVE_HOPS = 1000;

    /**
     * @var array<string, int>
     */
    private array $sendOffsets = [];

    /**
     * @var array<string, int>
     */
    private array $receiveOffsets = [];

    /**
     * Exactly matched outbound rows per send identity, in send order, loaded
     * once per execution and extended with the rows this execution writes
     * (#1014).
     *
     * @var array<string, list<WorkflowMessage>>
     */
    private array $sendCandidates = [];

    /**
     * @var list<string>|null
     */
    private ?array $runIds = null;

    private ?int $highestRecordedStep = null;

    /**
     * This run and the runs it was redriven from, newest first. A send or
     * receive in a reused prefix was made by one of them.
     *
     * @return list<string>
     */
    public function runIds(WorkflowRun $run): array
    {
        if ($this->runIds !== null) {
            return $this->runIds;
        }

        $runIds = [$run->id];
        $current = $run->id;

        for ($hop = 0; $hop < self::MAX_REDRIVE_HOPS; ++$hop) {
            /** @var WorkflowHistoryEvent|null $started */
            $started = WorkflowHistoryEvent::query()
                ->where('workflow_run_id', $current)
                ->where('event_type', HistoryEventType::WorkflowStarted->value)
                ->first();
            $payload = is_array($started?->payload) ? $started->payload : [];
            $source = $payload['continued_from_run_id'] ?? null;

            if (
                ($payload['recovery_kind'] ?? null) !== 'redrive'
                || ! is_string($source)
                || $source === ''
                || in_array($source, $runIds, true)
                || ! WorkflowRun::query()
                    ->whereKey($source)
                    ->where('workflow_instance_id', $run->workflow_instance_id)
                    ->exists()
            ) {
                break;
            }

            $runIds[] = $source;
            $current = $source;
        }

        return $this->runIds = $runIds;
    }

    /**
     * The highest step sequence this run's history recorded when this
     * execution first asked. Steps this execution records later are at or
     * past the step it runs, so a stale answer only ever errs toward "not
     * passed yet" for them.
     */
    public function highestRecordedStep(WorkflowRun $run): int
    {
        if ($this->highestRecordedStep !== null) {
            return $this->highestRecordedStep;
        }

        $highest = 0;
        // The executor usually has the run's history loaded already.
        $payloads = $run->relationLoaded('historyEvents')
            ? $run->historyEvents->pluck('payload')
            : WorkflowHistoryEvent::query()
                ->where('workflow_run_id', $run->id)
                ->pluck('payload');

        foreach ($payloads as $payload) {
            if (is_string($payload)) {
                $payload = json_decode($payload, true);
            }
            $sequence = is_array($payload) ? ($payload['sequence'] ?? null) : null;

            // Imported histories can carry the step as a numeric string.
            if (is_string($sequence) && ctype_digit($sequence)) {
                $sequence = (int) $sequence;
            }

            if (is_int($sequence) && $sequence > $highest) {
                $highest = $sequence;
            }
        }

        return $this->highestRecordedStep = $highest;
    }

    /**
     * The rows already sent under this identity, loaded once per execution.
     *
     * @param callable(): list<WorkflowMessage> $load
     * @return list<WorkflowMessage>
     */
    public function sendCandidates(string $identity, callable $load): array
    {
        return $this->sendCandidates[$identity] ??= $load();
    }

    /**
     * Record a row this execution just wrote, so the next identical send
     * finds it without reloading.
     */
    public function rememberSend(string $identity, WorkflowMessage $message): void
    {
        if (array_key_exists($identity, $this->sendCandidates)) {
            $this->sendCandidates[$identity][] = $message;
        }
    }

    public function sendOffset(string $identity): int
    {
        return $this->sendOffsets[$identity] ?? 0;
    }

    public function advanceSend(string $identity): void
    {
        $this->sendOffsets[$identity] = ($this->sendOffsets[$identity] ?? 0) + 1;
    }

    public function receiveOffset(string $streamKey, int $consumedBySequence): int
    {
        return $this->receiveOffsets[$streamKey . "\0" . $consumedBySequence] ?? 0;
    }

    public function advanceReceive(string $streamKey, int $consumedBySequence, int $count): void
    {
        $key = $streamKey . "\0" . $consumedBySequence;
        $this->receiveOffsets[$key] = ($this->receiveOffsets[$key] ?? 0) + $count;
    }
}
