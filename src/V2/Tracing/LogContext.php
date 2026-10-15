<?php

declare(strict_types=1);

namespace Workflow\V2\Tracing;

use Illuminate\Log\Logger;
use Monolog\LogRecord;
use Throwable;
use Workflow\V2\Support\ExecutionTracing;

/** Laravel logging tap for activity execution correlation. */
final class LogContext
{
    public function __invoke(Logger $logger): void
    {
        $target = $logger->getLogger();
        if (method_exists($target, 'pushProcessor')) {
            $target->pushProcessor($this->process(...));
        }
    }

    public function process(array|LogRecord $record): array|LogRecord
    {
        try {
            $fields = ExecutionTracing::current();
            if ($fields === []) {
                return $record;
            }
            $context = $record instanceof LogRecord ? $record->context : $record['context'];
            if (array_key_exists('request_id', $context)) {
                unset($fields['request_id']);
            }
            $context = $fields + $context;

            if ($record instanceof LogRecord) {
                return $record->with(context: $context);
            }
            $record['context'] = $context;
        } catch (Throwable) {
            // A logging adapter must not disrupt the application's logging path.
        }

        return $record;
    }
}
