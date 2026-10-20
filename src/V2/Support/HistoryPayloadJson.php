<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Database\Eloquent\Casts\Json;

/** @internal Inspect the inherited decoder setting without replacing Eloquent's codec. */
final class HistoryPayloadJson extends Json
{
    public static function usesDefaultDecoder(): bool
    {
        return static::$decoder === null;
    }
}
