<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\Exceptions\RuntimeProfileRefusal;

/** @internal Exact quoted bytes are part of the guard's storage authority. */
final class RuntimeProfileSqlDefinition
{
    public static function normalize(string $sql): string
    {
        $result = '';
        $quote = null;
        $space = false;
        $length = strlen($sql);
        for ($index = 0; $index < $length; $index++) {
            $character = $sql[$index];
            if ($quote !== null) {
                $result .= $character;
                if ($character === $quote) {
                    if (($sql[$index + 1] ?? null) === $quote) {
                        $result .= $sql[++$index];
                    } else {
                        $quote = null;
                    }
                }
                continue;
            }
            if (in_array($character, [' ', "\t", "\n", "\r", "\v", "\f"], true)) {
                $space = $result !== '';
                continue;
            }
            if ($space) {
                $result .= ' ';
                $space = false;
            }
            $result .= $character;
            if (in_array($character, ["'", '"', '`', '['], true)) {
                $quote = $character === '[' ? ']' : $character;
            }
        }
        if ($quote !== null) {
            throw new RuntimeProfileRefusal('runtime_profile_storage');
        }

        return $result;
    }
}
