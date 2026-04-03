<?php

declare(strict_types=1);

namespace Workflow\Traits;

use Illuminate\Database\QueryException;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use function React\Promise\resolve;
use Workflow\Serializers\Serializer;

trait SideEffects
{
    public static function sideEffect($callable): PromiseInterface
    {
        $log = self::$context->storedWorkflow->findLogByIndex(self::$context->index);

        if ($log) {
            ++self::$context->index;
            return resolve(Serializer::unserialize($log->result));
        }

        if (self::isProbing()) {
            ++self::$context->index;
            return (new Deferred())->promise();
        }

        $result = $callable();

        if (! self::$context->replaying) {
            try {
                self::$context->storedWorkflow->createLog([
                    'index' => self::$context->index,
                    'now' => self::$context->now,
                    'class' => self::$context->storedWorkflow->class,
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
}
