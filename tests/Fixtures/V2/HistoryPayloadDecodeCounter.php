<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2 {
    final class HistoryPayloadDecodeCounter
    {
        public static int $decodes = 0;
    }
}

namespace Illuminate\Database\Eloquent\Casts {
    // Loaded only in isolated test processes. Count the actual native decoder
    // while leaving Eloquent's custom-decoder configuration untouched.
    function json_decode(string $json, ?bool $associative = null, int $depth = 512, int $flags = 0): mixed
    {
        ++\Tests\Fixtures\V2\HistoryPayloadDecodeCounter::$decodes;

        return \json_decode($json, $associative, $depth, $flags);
    }
}

namespace Illuminate\Database\Eloquent\Concerns {
    // Laravel 9 decodes directly in HasAttributes, before the configurable
    // Casts\Json API was introduced.
    function json_decode(string $json, ?bool $associative = null, int $depth = 512, int $flags = 0): mixed
    {
        ++\Tests\Fixtures\V2\HistoryPayloadDecodeCounter::$decodes;

        return \json_decode($json, $associative, $depth, $flags);
    }
}
