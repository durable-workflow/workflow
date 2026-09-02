<?php

declare(strict_types=1);

namespace Workflow\Traits;

use Carbon\CarbonInterval;
use Illuminate\Database\QueryException;
use React\Promise\PromiseInterface;
use function React\Promise\resolve;
use Workflow\Serializers\Serializer;
use Workflow\Signal;
use Workflow\Timer;

trait AwaitWithTimeouts
{
    public static function awaitWithTimeout(int|string|CarbonInterval $seconds, $condition): PromiseInterface
    {
        $log = self::$context->storedWorkflow->findLogByIndex(self::$context->index);

        if ($log) {
            ++self::$context->index;

            $result = Serializer::unserialize($log->result);

            if ($log->class === Timer::class) {
                return resolve(! $result);
            }

            return resolve($result);
        }

        $result = $condition();

        if ($result === true) {
            if (! self::$context->replaying) {
                try {
                    self::$context->storedWorkflow->createLog([
                        'index' => self::$context->index,
                        'now' => self::$context->now,
                        'class' => Signal::class,
                        'result' => Serializer::serialize($result),
                    ]);
                } catch (QueryException $exception) {
                    $log = self::$context->storedWorkflow->findLogByIndex(self::$context->index, true);

                    if ($log) {
                        ++self::$context->index;
                        return resolve(Serializer::unserialize($log->result));
                    }

                    throw $exception;
                }
            }
            ++self::$context->index;
            return resolve($result);
        }

        return self::timer($seconds)->then(static fn ($completed): bool => ! $completed);
    }
}
