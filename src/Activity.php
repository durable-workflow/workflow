<?php

declare(strict_types=1);

namespace Workflow;

use BadMethodCallException;
use Illuminate\Bus\Queueable;
use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use LimitIterator;
use SplFileObject;
use Throwable;
use Workflow\Exceptions\NonRetryableExceptionContract;
use Workflow\Middleware\ActivityMiddleware;
use Workflow\Middleware\WithoutOverlappingMiddleware;
use Workflow\Models\StoredWorkflow;
use Workflow\Serializers\Serializer;
use Workflow\Traits\ResolvesMethodDependencies;
use Workflow\Traits\SerializesModels;

class Activity implements ShouldBeEncrypted, ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use ResolvesMethodDependencies;
    use SerializesModels;

    public $tries = PHP_INT_MAX;

    public $maxExceptions = PHP_INT_MAX;

    public $timeout = 0;

    public $arguments;

    public $key = '';

    private Container $container;

    // Set while handling only; an unset typed property is not serialized.
    private bool $awaitingRecordedFailure;

    public function __construct(
        public int $index,
        public string $now,
        public StoredWorkflow $storedWorkflow,
        ...$arguments
    ) {
        $this->arguments = $arguments;

        $options = $this->storedWorkflow->workflowOptions();
        $connection = $options->connection;

        if ($connection !== null) {
            $this->onConnection($connection);
        } elseif (property_exists($this, 'connection')) {
            $this->onConnection($this->connection);
        }

        $queue = $options->queue;

        if ($queue !== null) {
            $this->onQueue($queue);
        } elseif (property_exists($this, 'queue')) {
            $this->onQueue($this->queue);
        }

        $this->afterCommit = true;
    }

    public function backoff()
    {
        return [1, 2, 5, 10, 15, 30, 60, 120];
    }

    public function uniqueId()
    {
        return $this->storedWorkflow->id . ':' . $this->index;
    }

    public function workflowId()
    {
        return $this->storedWorkflow->id;
    }

    public function webhookUrl(string $signalMethod = ''): string
    {
        $workflow = Str::kebab(class_basename($this->storedWorkflow->class));

        if ($signalMethod === '') {
            return route("workflows.start.{$workflow}");
        }

        $signal = Str::kebab($signalMethod);
        return route("workflows.signal.{$workflow}.{$signal}", [
            'workflowId' => $this->storedWorkflow->id,
        ]);
    }

    public function handle()
    {
        if (! method_exists($this, 'execute')) {
            throw new BadMethodCallException('Execute method not implemented.');
        }

        $this->container = App::make(Container::class);

        // A failed job's outcome is recorded later by a queued Exception job,
        // after this activity's unique lock is gone. A replay in between
        // dispatches the activity again; that copy must not run it again.
        $failedJob = Cache::get(self::failureKey($this->storedWorkflow->id, $this->index));

        if ($this->storedWorkflow->hasLogByIndex($this->index)) {
            return;
        }

        if ($failedJob !== null && $failedJob !== $this->queueJobUuid()) {
            $this->awaitingRecordedFailure = true;

            return;
        }

        // The Exception job forgets the failure only after recording it, so
        // logs loaded with this job's payload may predate that record.
        if ($failedJob === null && $this->storedWorkflow->relationLoaded('logs') && $this->storedWorkflow->logs()
            ->whereIndex($this->index)
            ->exists()) {
            return;
        }

        try {
            $result = $this->{'execute'}(
                ...$this->resolveClassMethodDependencies($this->arguments, $this, 'execute')
            );

            if ($failedJob !== null) {
                Cache::forget(self::failureKey($this->storedWorkflow->id, $this->index));
            }

            return $result;
        } catch (\Throwable $throwable) {
            $this->storedWorkflow->exceptions()
                ->create([
                    'class' => $this::class,
                    'exception' => Serializer::serialize($throwable),
                ]);

            // Recorded before the job can fail and release its unique lock.
            $uuid = $this->queueJobUuid();
            if ($uuid !== null) {
                Cache::forever(self::failureKey($this->storedWorkflow->id, $this->index), $uuid);
            }

            if ($throwable instanceof NonRetryableExceptionContract) {
                $this->fail($throwable);
            }

            throw $throwable;
        }
    }

    /**
     * @internal
     */
    public static function failureKey(int|string $workflowId, int $index): string
    {
        return 'laravel-workflow-activity-failure:' . $workflowId . ':' . $index;
    }

    /**
     * @internal True when another job of this activity failed and its outcome is not yet recorded.
     */
    public function awaitsRecordedFailure(): bool
    {
        return $this->awaitingRecordedFailure ?? false;
    }

    public function middleware()
    {
        return [
            new WithoutOverlappingMiddleware(
                $this->storedWorkflow->id,
                WithoutOverlappingMiddleware::ACTIVITY,
                $this->storedWorkflow->workflowOptions()
->overlapReleaseAfter,
                $this->timeout
            ),
            new ActivityMiddleware(),
        ];
    }

    public function failed(Throwable $throwable): void
    {
        $workflow = $this->storedWorkflow->toWorkflow();

        $file = new SplFileObject($throwable->getFile());
        $iterator = new LimitIterator($file, max(0, $throwable->getLine() - 4), 7);

        $throwable = [
            'class' => get_class($throwable),
            'message' => $throwable->getMessage(),
            'code' => $throwable->getCode(),
            'line' => $throwable->getLine(),
            'file' => $throwable->getFile(),
            'trace' => collect($throwable->getTrace())
                ->filter(static fn ($trace) => Serializer::serializable($trace))
                ->toArray(),
            'snippet' => array_slice(iterator_to_array($iterator), 0, 7),
        ];

        Exception::dispatch(
            $this->index,
            $this->now,
            $this->storedWorkflow,
            $throwable,
            $workflow->connection(),
            $workflow->queue(),
            $this::class
        );
    }

    public function heartbeat(): void
    {
        pcntl_alarm(max($this->timeout, 0));
        if ($this->timeout) {
            Cache::put($this->key, 1, $this->timeout);
        }
    }

    private function queueJobUuid(): ?string
    {
        $uuid = $this->job?->uuid();

        return is_string($uuid) && $uuid !== '' ? $uuid : null;
    }
}
