<?php

declare(strict_types=1);

namespace Workflow\Traits;

use Carbon\CarbonInterval;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use function React\Promise\resolve;
use Workflow\Serializers\Serializer;
use Workflow\Timer;

trait Timers
{
    public static function timer(int|string|CarbonInterval $seconds): PromiseInterface
    {
        if ($seconds instanceof CarbonInterval) {
            $seconds = $seconds->totalSeconds;
        } elseif (is_string($seconds)) {
            $seconds = CarbonInterval::fromString($seconds)->totalSeconds;
        }

        if ($seconds <= 0) {
            ++self::$context->index;
            return resolve(true);
        }

        $log = self::$context->storedWorkflow->findLogByIndex(self::$context->index);

        if ($log) {
            ++self::$context->index;
            return resolve(Serializer::unserialize($log->result));
        }

        self::$context->storedWorkflow->loadMissing('timers');

        $timer = self::$context->storedWorkflow->findTimerByIndex(self::$context->index);

        if ($timer === null) {
            $when = self::$context->now->copy()
                ->addSeconds($seconds);

            if (self::isProbing()) {
                self::markProbePendingBeforeMatch();
                ++self::$context->index;
                return (new Deferred())->promise();
            }

            if (! self::$context->replaying) {
                $timer = self::$context->storedWorkflow->createTimer([
                    'index' => self::$context->index,
                    'stop_at' => $when,
                ]);
            } else {
                ++self::$context->index;
                return (new Deferred())->promise();
            }
        }

        $result = $timer->stop_at
            ->lessThanOrEqualTo(self::$context->now);

        if ($result === true) {
            if (! self::$context->replaying) {
                try {
                    self::$context->storedWorkflow->createLog([
                        'index' => self::$context->index,
                        'now' => self::$context->now,
                        'class' => Timer::class,
                        'result' => Serializer::serialize(true),
                    ]);
                } catch (\Illuminate\Database\UniqueConstraintViolationException $exception) {
                    // already logged
                }
            }
            ++self::$context->index;
            return resolve(true);
        }

        if (! self::$context->replaying) {
            $delay = $timer->stop_at;

            $connection = self::connection() ?? config('queue.default');
            $driver = config('queue.connections.' . $connection . '.driver');

            if ($driver === 'sqs') {
                $maxDelay = self::$context->now->copy()->addSeconds(900);
                if ($timer->stop_at->greaterThan($maxDelay)) {
                    $delay = $maxDelay;
                }
            }

            Timer::dispatch(
                self::$context->storedWorkflow,
                self::$context->index,
                self::connection(),
                self::queue()
            )->delay($delay);
        }

        if (self::isProbing()) {
            self::markProbePendingBeforeMatch();
        }

        ++self::$context->index;
        return (new Deferred())->promise();
    }
}
