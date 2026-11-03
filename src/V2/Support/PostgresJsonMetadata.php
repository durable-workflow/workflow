<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

final class PostgresJsonMetadata
{
    public static function textExpression(string $payload): string
    {
        // PostgreSQL reparses every string, even when extracting a different
        // key from json. Mask actual NUL escapes only in metadata expressions.
        // Paired backslashes remain literal, and stored history is untouched.
        $nullEscape = <<<'REGEX'
            (?<!\\)((?:\\\\)*)\\u0000
            REGEX;
        $pattern = bin2hex($nullEscape);
        $replacement = bin2hex('\\1 ');

        return "regexp_replace({$payload}::text, convert_from(decode('{$pattern}', 'hex'), 'UTF8'), "
            . "convert_from(decode('{$replacement}', 'hex'), 'UTF8'), 'g')";
    }
}
