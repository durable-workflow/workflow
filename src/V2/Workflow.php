<?php

declare(strict_types=1);

namespace Workflow\V2;

use Carbon\CarbonInterval;
use Throwable;
use Workflow\Traits\ResolvesMethodDependencies;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\ChildWorkflowHandles;
use Workflow\V2\Support\HistoryBudget;

/**
 * Base class for v2 workflows.
 *
 * Application workflows extend this class and implement a `handle` entry
 * method. The class also exposes the v2 authoring API as a static facade so
 * workflow code can read like:
 *
 *     use Workflow\V2\Workflow;
 *
 *     class MyWorkflow extends Workflow
 *     {
 *         public function handle(): mixed
 *         {
 *             $result = Workflow::activity(MyActivity::class, 'arg');
 *             Workflow::timer('5 seconds');
 *             Workflow::upsertMemo(['stage' => 'finalizing']);
 *
 *             return $result;
 *         }
 *     }
 *
 * Each static method is a thin delegate to the equivalent namespaced
 * helper under `Workflow\V2\*` — authors can pick either style.
 *
 * @api Stable v2 authoring API. Adding new static methods is an additive
 *      (non-breaking) change; removing or renaming a documented method
 *      requires a major version bump. See docs/api-stability.md.
 */
abstract class Workflow
{
    use ResolvesMethodDependencies;

    public ?string $connection = null;

    public ?string $queue = null;

    private int $visibleSequence = 1;

    private bool $commandDispatchEnabled = true;

    /**
     * @var list<callable>
     */
    private array $compensations = [];

    private bool $parallelCompensation = false;

    private bool $continueWithError = false;

    final public function __construct(
        public readonly WorkflowRun $run,
    ) {
    }

    public function workflowId(): string
    {
        return $this->run->workflow_instance_id;
    }

    public function runId(): string
    {
        return $this->run->id;
    }

    /**
     * The handle for the most recently spawned child workflow, or null if
     * this workflow has not spawned any children yet.
     *
     * Renamed from `child()` in v2 so the `Workflow::child(...)` static
     * facade can spawn child workflows. Use `lastChild()` for the handle
     * lookup and `Workflow::child(...)` / `Workflow::executeChildWorkflow(...)`
     * to spawn a new child.
     */
    public function lastChild(): ?ChildWorkflowHandle
    {
        $children = $this->children();

        if ($children === []) {
            return null;
        }

        return $children[array_key_last($children)];
    }

    /**
     * @return list<ChildWorkflowHandle>
     */
    public function children(): array
    {
        return ChildWorkflowHandles::forRun($this->run, $this->visibleSequence, $this->commandDispatchEnabled);
    }

    public function historyLength(): int
    {
        return HistoryBudget::forRun($this->run)['history_event_count'];
    }

    public function historySize(): int
    {
        return HistoryBudget::forRun($this->run)['history_size_bytes'];
    }

    public function shouldContinueAsNew(): bool
    {
        return HistoryBudget::forRun($this->run)['continue_as_new_recommended'];
    }

    public function addCompensation(callable $compensation): static
    {
        $this->compensations[] = $compensation;

        return $this;
    }

    public function setParallelCompensation(bool $parallel): static
    {
        $this->parallelCompensation = $parallel;

        return $this;
    }

    public function setContinueWithError(bool $continueWithError): static
    {
        $this->continueWithError = $continueWithError;

        return $this;
    }

    public function compensate(): void
    {
        $reversed = array_reverse($this->compensations);

        if ($this->parallelCompensation) {
            // Pass each compensation closure directly to all(): it uses
            // whileInactive() to capture the ActivityCall/ChildWorkflowCall the
            // closure produces without executing it serially in-fiber. Calling
            // $compensation() here would resolve the activity synchronously and
            // hand all() plain string results, which it rejects (AllCall only
            // accepts the call objects).
            if ($this->continueWithError) {
                try {
                    all($reversed);
                } catch (Throwable) {
                    // continueWithError applies uniformly: swallow parallel compensation failures
                }
            } else {
                all($reversed);
            }
        } else {
            foreach ($reversed as $compensation) {
                try {
                    $compensation();
                } catch (Throwable $e) {
                    if (! $this->continueWithError) {
                        throw $e;
                    }
                }
            }
        }
    }

    public function syncExecutionCursor(int $visibleSequence): void
    {
        $this->visibleSequence = max(1, $visibleSequence);
    }

    public function setCommandDispatchEnabled(bool $enabled): void
    {
        $this->commandDispatchEnabled = $enabled;
    }

    // ── Static authoring facade ─────────────────────────────────────────────
    //
    // The static methods below delegate to the namespaced helpers under
    // `Workflow\V2\*` (see src/V2/functions.php). They exist so workflow
    // authors can use the more conventional `Workflow::timer(...)` form
    // without an extra import. See the class docblock for example usage.
    //
    // These are pure delegates. Behaviour, determinism guarantees, and
    // operand/return types come from the underlying helper functions.

    /**
     * Read the deterministic workflow time.
     *
     * Inside a workflow fiber, returns the timestamp of the last history
     * event the executor replayed. Outside a workflow, returns wall-clock.
     *
     * @see \Workflow\V2\now()
     */
    public static function now(): \Carbon\CarbonInterface
    {
        return \Workflow\V2\Support\WorkflowFiberContext::getTime();
    }

    /**
     * Invoke an activity and wait for its result.
     *
     * @see activity()
     */
    public static function activity(string $activity, mixed ...$arguments): mixed
    {
        return activity($activity, ...$arguments);
    }

    /**
     * Alias for {@see activity()} matching Temporal's `executeActivity` name.
     */
    public static function executeActivity(string $activity, mixed ...$arguments): mixed
    {
        return activity($activity, ...$arguments);
    }

    /**
     * Invoke a child workflow and wait for its result.
     *
     * @see child()
     */
    public static function child(string $workflow, mixed ...$arguments): mixed
    {
        return child($workflow, ...$arguments);
    }

    /**
     * Alias for {@see child()} matching Temporal's `executeChildWorkflow` name.
     */
    public static function executeChildWorkflow(string $workflow, mixed ...$arguments): mixed
    {
        return child($workflow, ...$arguments);
    }

    /**
     * Run a callable as an auto-generated child workflow.
     *
     * @see async()
     */
    public static function async(callable $callback): mixed
    {
        return async($callback);
    }

    /**
     * Await a list of concurrent calls and return their resolved results in
     * iteration order.
     *
     * @see all()
     */
    public static function all(iterable $calls): mixed
    {
        return all($calls);
    }

    /**
     * Alias for {@see all()} matching the "run these in parallel" intent.
     */
    public static function parallel(iterable $calls): mixed
    {
        return all($calls);
    }

    /**
     * Wait for a condition closure to become truthy, for a signal by name,
     * or for either plus a timeout.
     *
     * @see await()
     */
    public static function await(
        callable|string $condition,
        int|string|CarbonInterval|null $timeout = null,
        ?string $conditionKey = null,
    ): mixed {
        return await($condition, $timeout, $conditionKey);
    }

    /**
     * Wait for a condition or signal with an explicit timeout, failing the
     * await when the timeout elapses.
     */
    public static function awaitWithTimeout(
        int|string|CarbonInterval $timeout,
        callable|string $condition,
        ?string $conditionKey = null,
    ): mixed {
        return await($condition, $timeout, $conditionKey);
    }

    /**
     * Wait for a named signal. Equivalent to `await(<signal name>)`.
     */
    public static function awaitSignal(string $name): mixed
    {
        return await($name);
    }

    /**
     * Suspend until a timer fires.
     *
     * @see timer()
     */
    public static function timer(int|string|CarbonInterval $duration): mixed
    {
        return timer($duration);
    }

    /**
     * Capture the result of a side-effect closure in history so replay
     * returns the same value on subsequent attempts.
     *
     * @see sideEffect()
     */
    public static function sideEffect(callable $callback): mixed
    {
        return sideEffect($callback);
    }

    /**
     * Terminate the current run by starting a new one with the provided
     * arguments, preserving workflow instance identity.
     *
     * @see continueAsNew()
     */
    public static function continueAsNew(mixed ...$arguments): mixed
    {
        return continueAsNew(...$arguments);
    }

    /**
     * Declare a workflow-code versioning change and receive the negotiated
     * version for the current run.
     *
     * @see getVersion()
     */
    public static function getVersion(
        string $changeId,
        int $minSupported = WorkflowStub::DEFAULT_VERSION,
        int $maxSupported = 1,
    ): mixed {
        return getVersion($changeId, $minSupported, $maxSupported);
    }

    /**
     * Return whether this workflow run has crossed a replay-safe code patch.
     *
     * @see patched()
     */
    public static function patched(string $changeId): mixed
    {
        return patched($changeId);
    }

    /**
     * Keep a patch marker alive after the old workflow branch is removed.
     *
     * @see deprecatePatch()
     */
    public static function deprecatePatch(string $changeId): mixed
    {
        return deprecatePatch($changeId);
    }

    /**
     * Upsert non-indexed memo metadata on the current workflow run.
     *
     * @param array<string, mixed> $entries
     *
     * @see upsertMemo()
     */
    public static function upsertMemo(array $entries): void
    {
        upsertMemo($entries);
    }

    /**
     * Upsert indexed search attributes on the current workflow run.
     *
     * @param array<string, scalar|null> $attributes
     *
     * @see upsertSearchAttributes()
     */
    public static function upsertSearchAttributes(array $attributes): void
    {
        upsertSearchAttributes($attributes);
    }

    // Timer sugar --------------------------------------------------------

    public static function seconds(int $seconds): mixed
    {
        return seconds($seconds);
    }

    public static function minutes(int $minutes): mixed
    {
        return minutes($minutes);
    }

    public static function hours(int $hours): mixed
    {
        return hours($hours);
    }

    public static function days(int $days): mixed
    {
        return days($days);
    }

    public static function weeks(int $weeks): mixed
    {
        return weeks($weeks);
    }

    public static function months(int $months): mixed
    {
        return months($months);
    }

    public static function years(int $years): mixed
    {
        return years($years);
    }
}
