<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Throwable;
use Workflow\Serializers\Serializer;

final class CommandPayloadPreview
{
    public static function available(mixed $payload): bool
    {
        return is_string($payload) && $payload !== '';
    }

    public static function preview(mixed $payload): mixed
    {
        if (! self::available($payload)) {
            return null;
        }

        try {
            return Serializer::unserialize($payload);
        } catch (Throwable) {
            return $payload;
        }
    }
}
