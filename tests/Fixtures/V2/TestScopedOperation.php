<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Illuminate\Support\Facades\Cache;
use Workflow\V2\Compensation\Execution\ForwardResult;
use Workflow\V2\Compensation\Execution\OperationContext;
use Workflow\V2\Compensation\Execution\Resolution;
use Workflow\V2\Compensation\Execution\ScopedOperation;

final class TestScopedOperation implements ScopedOperation
{
    public function assertInput(array $input): void
    {
    }

    public function execute(OperationContext $context): ForwardResult
    {
        if (($context->obligation->input['mode'] ?? null) === 'rejected') {
            return ForwardResult::rejected('provider-refused', ['refusal:' . $context->operationId]);
        }
        $key = 'test:scope:effect:' . $context->operationId;
        if (! Cache::has($key)) {
            Cache::forever($key, $context->obligation->key);
            Cache::increment('test:scope:effect-count');
        }
        if (($context->obligation->input['mode'] ?? null) === 'lost-ack') {
            return ForwardResult::unknown('ack-lost');
        }
        if (($context->obligation->input['mode'] ?? null) === 'oversized') {
            // Above the 11,264-byte caller value limit, within the envelope limit of earlier releases.
            return ForwardResult::observed([
                'receipt' => str_repeat('r', 12_000),
            ], ['provider:' . $context->operationId]);
        }
        return ForwardResult::observed([
            'reference' => $context->operationId,
        ], ['provider:' . $context->operationId]);
    }

    public function compensate(OperationContext $context): Resolution
    {
        $scopedLog = Cache::get('test:scope:undo-scoped-log', []);
        $scopedLog[] = $context->scope->key . '/' . $context->obligation->key;
        Cache::forever('test:scope:undo-scoped-log', $scopedLog);
        $log = Cache::get('test:scope:undo-log', []);
        $log[] = $context->obligation->key;
        Cache::forever('test:scope:undo-log', $log);
        if ($context->obligation->key === 'first' && Cache::get('test:scope:failed-first', false)) {
            return Resolution::failed('provider-refused-undo');
        }
        if ($context->obligation->key === 'first' && Cache::get('test:scope:unknown-first', false)) {
            return Resolution::unknown('provider-unavailable');
        }
        if (Cache::pull('test:scope:expire-native', false)) {
            \Illuminate\Support\Carbon::setTestNow(now()->addMinutes(10));
        }
        Cache::forget('test:scope:effect:' . $context->operationId);
        if (Cache::get('test:scope:late-forward-possible', false)) {
            return Resolution::unknown('late-forward-possible');
        }
        if ($context->obligation->key === 'first' && Cache::get('test:scope:invalid-resolution', false)) {
            return Resolution::verified([]);
        }
        if (Cache::get('test:scope:legacy-verified', false)) {
            // A handler written for earlier releases passes the removed forwardSettled flag.
            return call_user_func([Resolution::class, 'verified'], ['provider-undo:' . $context->operationId], true);
        }
        return Resolution::verified(['provider-undo:' . $context->operationId]);
    }
}
