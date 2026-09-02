<?php

declare(strict_types=1);

namespace Workflow\Middleware;

use Illuminate\Container\Container;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\InteractsWithTime;
use Illuminate\Support\Str;

class WithoutOverlappingMiddleware
{
    use InteractsWithTime;

    public const WORKFLOW = 1;

    public const ACTIVITY = 2;

    public string $key;

    public int $type;

    public $releaseAfter;

    public $expiresAfter;

    public $prefix = 'laravel-workflow-overlap:';

    private $cache;

    public function __construct($workflowId, $type, $releaseAfter = 0, $expiresAfter = 0)
    {
        $this->key = "{$workflowId}";
        $this->type = $type;
        $this->releaseAfter = $releaseAfter;
        $this->expiresAfter = $this->secondsUntil($expiresAfter);
        $this->cache = Container::getInstance()->make(Cache::class);
    }

    public function handle($job, $next)
    {
        $locked = $this->lock($job);

        if ($locked) {
            try {
                $next($job);
            } finally {
                $shouldSignal = $this->unlock($job);
                if (isset($job->onUnlock) && is_callable($job->onUnlock)) {
                    ($job->onUnlock)($shouldSignal);
                }
            }
        } elseif ($this->releaseAfter !== null) {
            $job->release($this->releaseAfter);
        }
    }

    public function getLockKey()
    {
        return $this->prefix . $this->key;
    }

    public function getWorkflowSemaphoreKey()
    {
        return $this->getLockKey() . ':workflow';
    }

    public function getActivitySemaphoreKey()
    {
        return $this->getLockKey() . ':activity';
    }

    public function lock($job)
    {
        $workflowSemaphore = (int) $this->cache->get($this->getWorkflowSemaphoreKey(), 0);
        $activitySemaphore = 0;
        $activitySemaphores = $this->cache->get($this->getActivitySemaphoreKey(), []);

        foreach ($activitySemaphores as $semaphore) {
            if ($this->cache->has($semaphore)) {
                $activitySemaphore++;
            }
        }

        switch ($this->type) {
            case self::WORKFLOW:
                $locked = false;
                if ($workflowSemaphore === 0 && $activitySemaphore === 0) {
                    $locked = $this->compareAndSet(
                        $this->getWorkflowSemaphoreKey(),
                        (int) $workflowSemaphore,
                        (int) $workflowSemaphore + 1,
                        $this->expiresAfter
                    );
                }
                break;

            case self::ACTIVITY:
                $locked = false;
                $maxAttempts = 5;
                for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
                    if ($attempt > 0) {
                        $workflowSemaphore = (int) $this->cache->get($this->getWorkflowSemaphoreKey(), 0);
                        if ($workflowSemaphore !== 0) {
                            break;
                        }
                        $activitySemaphores = $this->cache->get($this->getActivitySemaphoreKey(), []);
                    }
                    if ($workflowSemaphore === 0) {
                        $job->key = $this->getActivitySemaphoreKey() . ':' . (string) Str::uuid();
                        $locked = $this->compareAndSet(
                            $this->getActivitySemaphoreKey(),
                            $activitySemaphores,
                            array_merge($activitySemaphores, [$job->key]),
                            $this->expiresAfter
                        );
                        if ($locked) {
                            if ($this->expiresAfter) {
                                $this->cache->put($job->key, 1, $this->expiresAfter);
                            } else {
                                $this->cache->put($job->key, 1);
                            }
                            break;
                        }
                        usleep(500);
                    }
                }
                break;

            default:
                $locked = false;
                break;
        }

        return $locked;
    }

    public function unlock($job): bool
    {
        switch ($this->type) {
            case self::WORKFLOW:
                $unlocked = false;
                while (! $unlocked) {
                    $workflowSemaphore = (int) $this->cache->get($this->getWorkflowSemaphoreKey(), 0);
                    $unlocked = $this->compareAndSet(
                        $this->getWorkflowSemaphoreKey(),
                        (int) $workflowSemaphore,
                        (int) max($workflowSemaphore - 1, 0),
                        $this->expiresAfter
                    );
                }
                return true;

            case self::ACTIVITY:
                return $this->unlockActivity($job);

            default:
                return true;
        }
    }

    private function unlockActivity($job): bool
    {
        $maxRetries = 100;
        $retries = 0;

        while ($retries < $maxRetries) {
            $lock = $this->cache->lock($this->getLockKey(), 5);

            if (! $lock->get()) {
                $retries++;
                usleep(1000);
                continue;
            }

            try {
                $remaining = array_values(
                    array_diff($this->cache->get($this->getActivitySemaphoreKey(), []), [$job->key])
                );
                if ($remaining === []) {
                    $this->cache->forget($this->getActivitySemaphoreKey());
                } elseif ($this->expiresAfter) {
                    $this->cache->put($this->getActivitySemaphoreKey(), $remaining, $this->expiresAfter);
                } else {
                    $this->cache->put($this->getActivitySemaphoreKey(), $remaining);
                }
                $this->cache->forget($job->key);

                foreach ($remaining as $semaphore) {
                    if ($this->cache->has($semaphore)) {
                        return false;
                    }
                }

                return true;
            } finally {
                $lock->release();
            }
        }

        return true;
    }

    private function compareAndSet($key, $expectedValue, $newValue, $expiresAfter = 0)
    {
        $maxRetries = 10;
        $retries = 0;

        while ($retries < $maxRetries) {
            $lock = $this->cache->lock($this->getLockKey(), 5);

            if ($lock->get()) {
                try {
                    $currentValue = $this->cache->get($key, $expectedValue);

                    $currentValue = is_int($expectedValue) ? (int) $currentValue : $currentValue;

                    if ($currentValue === $expectedValue) {
                        if ($newValue === 0 || $newValue === []) {
                            $this->cache->forget($key);
                        } elseif ($expiresAfter) {
                            $this->cache->put($key, $newValue, $expiresAfter);
                        } else {
                            $this->cache->put($key, $newValue);
                        }
                        return true;
                    }
                } finally {
                    $lock->release();
                }

                return false;
            }

            $retries++;
            usleep(1000);
        }

        return false;
    }
}
