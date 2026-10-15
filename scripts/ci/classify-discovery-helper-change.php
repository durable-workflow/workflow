<?php

declare(strict_types=1);

/** The bridge's nonEmptyStrings body serves task discovery, not replay. */
function withoutDiscoveryHelperBody(string $source): ?string
{
    $tokens = token_get_all($source, TOKEN_PARSE);
    $normalized = '';
    $helpers = 0;

    for ($index = 0; $index < count($tokens); ++$index) {
        $token = $tokens[$index];
        $normalized .= is_array($token) ? $token[1] : $token;

        if (! is_array($token) || $token[0] !== T_FUNCTION) {
            continue;
        }

        $nameIndex = $index + 1;
        while (isset($tokens[$nameIndex]) && is_array($tokens[$nameIndex]) && $tokens[$nameIndex][0] === T_WHITESPACE) {
            ++$nameIndex;
        }

        if (! isset($tokens[$nameIndex]) || ! is_array($tokens[$nameIndex])
            || $tokens[$nameIndex][0] !== T_STRING || $tokens[$nameIndex][1] !== 'nonEmptyStrings') {
            continue;
        }

        while (isset($tokens[++$index]) && $tokens[$index] !== '{') {
            $normalized .= is_array($tokens[$index]) ? $tokens[$index][1] : $tokens[$index];
        }

        $normalized .= '{}';
        $depth = 1;
        while (isset($tokens[++$index]) && $depth > 0) {
            $current = $tokens[$index];
            if ($current === '{' || (is_array($current) && in_array(
                $current[0],
                [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES],
                true
            ))) {
                ++$depth;
            } elseif ($current === '}') {
                --$depth;
            }
        }

        // The loop has advanced past the closing brace.
        --$index;
        ++$helpers;
    }

    return $helpers === 1 ? $normalized : null;
}

try {
    $input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
    $base = withoutDiscoveryHelperBody($input['base']);
    $current = withoutDiscoveryHelperBody($input['current']);
    echo json_encode($base !== null && $current !== null && $base === $current, JSON_THROW_ON_ERROR);
} catch (Throwable) {
    echo 'false';
}
