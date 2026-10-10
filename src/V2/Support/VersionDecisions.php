<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use LogicException;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;

/** One logical decision per change ID within an execution or cold replay. */
final class VersionDecisions
{
    /**
     * @var array<string, array{call: VersionCall, version: int}>
     */
    private array $decisions = [];

    /**
     * @var array<int, WorkflowHistoryEvent>
     */
    private array $markers = [];

    /**
     * @param WorkflowRun|array<int, WorkflowHistoryEvent> $history
     */
    public function __construct(WorkflowRun|array $history)
    {
        $this->replaceMarkers($history);
    }

    /**
     * @param WorkflowRun|array<int, WorkflowHistoryEvent> $history
     */
    public function replaceMarkers(WorkflowRun|array $history): void
    {
        $markers = [];
        $versions = [];
        foreach ($history instanceof WorkflowRun ? $history->historyEvents : $history as $event) {
            if ($event->event_type !== HistoryEventType::VersionMarkerRecorded) {
                continue;
            }

            $sequence = $event->payload['sequence'] ?? null;
            if (! is_int($sequence) || $sequence < 1 || isset($markers[$sequence])) {
                throw new LogicException('Workflow version markers require distinct positive integer sequences.');
            }
            $changeId = $event->payload['change_id'] ?? null;
            $version = $event->payload['version'] ?? null;
            if (! is_string($changeId) || $changeId === '' || ! is_int($version)) {
                throw new LogicException('Workflow version markers require a change ID and integer version.');
            }
            $original = $this->decisions[$changeId]['version'] ?? $versions[$changeId] ?? $version;
            if ($version !== $original) {
                throw new LogicException(sprintf(
                    'Workflow version marker [%s] conflicts with its original decision [%d].',
                    $changeId,
                    $original
                ));
            }
            $versions[$changeId] = $version;
            $markers[$sequence] = $event;
        }
        $this->markers = $markers;
    }

    public function markerAt(int $sequence): ?WorkflowHistoryEvent
    {
        return $this->markers[$sequence] ?? null;
    }

    public function resolve(?WorkflowRun $run, VersionCall $call, int $sequence): VersionResolution
    {
        if (isset($this->decisions[$call->changeId])) {
            return VersionResolver::reuse($this->decisions[$call->changeId]['version'], $call);
        }

        $event = $this->markerAt($sequence);
        $resolution = $event === null && $run === null
            ? VersionResolution::fresh($call->maxSupported)
            : VersionResolver::resolve($run ?? new WorkflowRun(), $event, $call, $sequence);
        $this->decisions[$call->changeId] = [
            'call' => $call,
            'version' => $resolution->version,
        ];

        return $resolution;
    }

    public function duplicateAt(int $sequence): ?WorkflowHistoryEvent
    {
        $event = $this->markerAt($sequence);
        $changeId = $event?->payload['change_id'] ?? null;

        return is_string($changeId) && isset($this->decisions[$changeId]) ? $event : null;
    }

    public function assertDuplicate(?WorkflowRun $run, WorkflowHistoryEvent $event, int $sequence): void
    {
        $decision = $this->decisions[$event->payload['change_id']];
        $recorded = VersionResolver::resolve($run ?? new WorkflowRun(), $event, $decision['call'], $sequence);
        if ($recorded->version !== $decision['version']) {
            throw new LogicException(sprintf(
                'Workflow version marker [%s] at workflow sequence [%d] conflicts with its original decision [%d].',
                $decision['call']->changeId,
                $sequence,
                $decision['version'],
            ));
        }
    }
}
