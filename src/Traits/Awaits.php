<?php

declare(strict_types=1);

namespace Workflow\Traits;

use Illuminate\Database\QueryException;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use function React\Promise\resolve;
use Workflow\Serializers\Serializer;
use Workflow\Signal;

trait Awaits
{
    public static function await($condition): PromiseInterface
    {
        $log = self::$context->storedWorkflow->findLogByIndex(self::$context->index);

        if ($log) {
            ++self::$context->index;
            return resolve(Serializer::unserialize($log->result));
        }

        if (self::isProbing()) {
            ++self::$context->index;
            $deferred = new Deferred();
            return $deferred->promise();
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

        ++self::$context->index;
        $deferred = new Deferred();
        return $deferred->promise();
    }
}
