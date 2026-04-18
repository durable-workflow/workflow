<?php

declare(strict_types=1);

namespace Workflow\V2\Contracts;

/**
 * Cross-node long-poll wake signal coordination.
 *
 * This contract enables worker polling endpoints to detect when new work arrives
 * without constant database probing. When tasks or history events are created/updated,
 * the system signals relevant channels. Pollers snapshot channel versions before probing,
 * then check for changes to decide when to re-probe.
 *
 * ## Multi-node deployment requirements
 *
 * Implementations MUST use a shared backend (Redis, database cache, Memcached, etc.)
 * that is accessible from all server nodes. File-based or in-memory backends will not
 * coordinate wake signals across nodes.
 *
 * ## Channel naming
 *
 * Channels are strings identifying work streams. Common patterns:
 * - Workflow task queues: "workflow-tasks:{namespace}:{queue}"
 * - Activity task queues: "activity-tasks:{namespace}:{queue}"
 * - History waits: "history:{run_id}"
 *
 * The contract does not prescribe a channel format — applications define their own.
 *
 * ## Version stamps
 *
 * Each signal updates the channel's version stamp (e.g., a timestamp or ULID).
 * Pollers capture a snapshot of versions before probing, then check if any version changed.
 * If a version changed, work may have arrived → re-probe immediately.
 *
 * ## Signal TTL
 *
 * Signal versions should expire after a reasonable TTL (e.g., poll timeout + buffer).
 * This prevents unbounded growth and allows garbage collection of stale channels.
 */
interface LongPollWakeStore
{
    /**
     * Capture current version stamps for the given channels.
     *
     * @param  list<string>  $channels
     * @return array<string, string|null>  Map of channel → version stamp
     */
    public function snapshot(array $channels): array;

    /**
     * Check if any channel version has changed since the snapshot.
     *
     * @param  array<string, string|null>  $snapshot
     * @return bool  True if at least one channel version differs from the snapshot
     */
    public function changed(array $snapshot): bool;

    /**
     * Signal that work is available on the given channels.
     *
     * Updates each channel's version stamp and stores it with the configured TTL.
     */
    public function signal(string ...$channels): void;

    /**
     * Build channel names for workflow task polling.
     *
     * Returns the set of channels a workflow task poller should subscribe to
     * for the given namespace and queue.
     *
     * @param  string|null  $connection  Database connection name (null = any)
     * @param  string|null  $queue  Task queue name (null = any)
     * @return list<string>
     */
    public function workflowTaskPollChannels(string $namespace, ?string $connection, ?string $queue): array;

    /**
     * Build channel names for activity task polling.
     *
     * Returns the set of channels an activity task poller should subscribe to
     * for the given namespace and queue.
     *
     * @param  string|null  $connection  Database connection name (null = any)
     * @param  string|null  $queue  Task queue name (null = any)
     * @return list<string>
     */
    public function activityTaskPollChannels(string $namespace, ?string $connection, ?string $queue): array;

    /**
     * Build channel name for a specific workflow run's history waits.
     */
    public function historyRunChannel(string $runId): string;
}
