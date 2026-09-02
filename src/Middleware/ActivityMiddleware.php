<?php

declare(strict_types=1);

namespace Workflow\Middleware;

use Illuminate\Support\Str;
use LimitIterator;
use SplFileObject;
use Workflow\Events\ActivityCompleted;
use Workflow\Events\ActivityFailed;
use Workflow\Events\ActivityStarted;
use Workflow\Exceptions\TransitionNotFound;

final class ActivityMiddleware
{
    private $job;

    private $result;

    private string $uuid;

    public function handle($job, $next): void
    {
        $this->job = $job;
        $this->uuid = (string) Str::uuid();

        ActivityStarted::dispatch(
            $job->storedWorkflow->id,
            $this->uuid,
            $job::class,
            $job->index,
            json_encode($job->arguments),
            now()
                ->format('Y-m-d\TH:i:s.u\Z')
        );

        try {
            $this->result = $next($job);

            $job->onUnlock = fn (bool $shouldSignal) => $this->onUnlock($shouldSignal);
        } catch (\Throwable $throwable) {
            $file = new SplFileObject($throwable->getFile());
            $iterator = new LimitIterator($file, max(0, $throwable->getLine() - 4), 7);

            ActivityFailed::dispatch(
                $job->storedWorkflow->id,
                $this->uuid,
                json_encode([
                    'class' => get_class($throwable),
                    'message' => $throwable->getMessage(),
                    'code' => $throwable->getCode(),
                    'line' => $throwable->getLine(),
                    'file' => $throwable->getFile(),
                    'trace' => $throwable->getTrace(),
                    'snippet' => array_slice(iterator_to_array($iterator), 0, 7),
                ]),
                now()
                    ->format('Y-m-d\TH:i:s.u\Z'),
                $job::class,
                $job->index
            );

            throw $throwable;
        }
    }

    public function onUnlock(bool $shouldSignal): void
    {
        try {
            $this->job->storedWorkflow->toWorkflow()
                ->next($this->job->index, $this->job->now, $this->job::class, $this->result, $shouldSignal);

            ActivityCompleted::dispatch(
                $this->job->storedWorkflow->id,
                $this->uuid,
                json_encode($this->result),
                now()
                    ->format('Y-m-d\TH:i:s.u\Z'),
                $this->job::class,
                $this->job->index
            );
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $throwable) {
            $this->job->storedWorkflow->toWorkflow()
                ->fail($throwable);
        } catch (TransitionNotFound) {
            if ($this->job->storedWorkflow->toWorkflow()->running()) {
                $this->job->release();
            }
        }
    }
}
