<?php

declare(strict_types=1);

/** Recognize only the closed read-only version-usage observer surface. */
function versionUsageObserver(string $path, string $source): bool
{
    $class = pathinfo($path, PATHINFO_FILENAME);
    if (! in_array($class, ['VersionUsageQuery', 'VersionUsageDecision', 'VersionUsageCursor'], true)) {
        return false;
    }
    $tokens = array_values(array_filter(token_get_all($source, TOKEN_PARSE), static fn ($token): bool =>
        ! is_array($token) || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
    $imports = [
        'DateTimeImmutable', 'Generator', 'Illuminate\\Database\\Query\\Builder',
        'Illuminate\\Database\\Eloquent\\Builder', 'Illuminate\\Support\\Collection',
        'InvalidArgumentException', 'JsonException', 'LogicException',
        'Workflow\\V2\\Enums\\HistoryEventType', 'Workflow\\V2\\Enums\\RunStatus',
        'Workflow\\V2\\Models\\WorkflowHistoryEvent', 'Workflow\\V2\\Models\\WorkflowInstance',
        'Workflow\\V2\\Models\\WorkflowRun',
    ];
    $functions = [
        'config', 'strlen', 'preg_match', 'hash', 'hash_hmac', 'hash_equals', 'json_encode', 'json_decode',
        'str_contains', 'is_string', 'is_int', 'is_array', 'in_array', 'strcmp', 'count', 'sort', 'array_slice',
        'array_key_last', 'array_keys', 'array_key_exists', 'array_diff', 'array_is_list', 'array_fill',
        'implode', 'rtrim', 'strtr', 'base64_encode', 'base64_decode',
    ];
    $methods = [
        'getQuery', 'getConnection', 'getDriverName', 'getGrammar', 'wrap', 'getModel', 'qualifyColumn',
        'setEagerLoads', 'where', 'whereIn', 'whereRaw', 'orderByRaw', 'select', 'selectRaw', 'limit',
        'groupByRaw', 'groupBy', 'toBase', 'get', 'pluck', 'value', 'keyBy', 'distinct', 'lazyById', 'isEmpty', 'last',
        'count', 'all', 'setConnection', 'getConnectionName', 'newQuery',
        'runs', 'idExpression', 'batches', 'rows', 'identities', 'decisions', 'observe', 'result', 'unavailable',
    ];
    $static = [
        'ConfiguredV2Models' => ['resolve'], 'NamespaceIdentity' => ['constrain'],
        'VersionUsageDecision' => ['integer'], 'VersionUsageCursor' => ['encode', 'decode'],
        'DateTimeImmutable' => ['createFromFormat', 'getLastErrors'],
        'self' => ['text', 'binary', 'runId', 'encode', 'signature', 'integer', 'validReuse'],
    ];
    if ($class !== 'VersionUsageQuery') {
        $methods = [];
        $static = ['self' => $static['self']] + ($class === 'VersionUsageDecision'
            ? ['DateTimeImmutable' => $static['DateTimeImmutable']] : []);
    }
    $namespaces = 0;
    $classes = 0;
    foreach ($tokens as $index => $token) {
        $previous = $tokens[$index - 1] ?? null;
        $next = $tokens[$index + 1] ?? null;
        $text = is_array($token) ? $token[1] : $token;
        $previousKind = is_array($previous) ? $previous[0] : null;
        if (is_array($token) && in_array($token[0], [
            T_EVAL, T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE, T_EXIT,
            T_GLOBAL, T_GOTO, T_PRINT, T_ECHO, T_HALT_COMPILER, T_EXTENDS, T_IMPLEMENTS,
            T_INTERFACE, T_TRAIT, T_ENUM,
        ], true)) {
            return false;
        }
        if (($class !== 'VersionUsageCursor' && $text === '.') || $text === '`'
            || (in_array($text, [')', ']', '}'], true) && $next === '(')
            || (is_array($token) && $token[0] === T_VARIABLE && $next === '('
            && $previousKind !== T_NEW)) {
            return false;
        }
        if (is_array($token) && $token[0] === T_NAMESPACE) {
            ++$namespaces;
            if (! is_array($next) || $next[1] !== 'Workflow\\V2\\Support') {
                return false;
            }
        }
        if (is_array($token) && $token[0] === T_CLASS) {
            // ::class is metadata, not a class declaration.
            if ($previousKind === T_DOUBLE_COLON) {
                continue;
            }
            ++$classes;
            if (! is_array($next) || $next[1] !== $class) {
                return false;
            }
        }
        if (is_array($token) && $token[0] === T_USE) {
            $alias = $tokens[$index + 2] ?? null;
            $aliasName = $tokens[$index + 3] ?? null;
            $queryAlias = is_array($next) && $next[1] === 'Illuminate\\Database\\Query\\Builder'
                && is_array($alias) && $alias[0] === T_AS
                && is_array($aliasName) && $aliasName[1] === 'QueryBuilder'
                && ($tokens[$index + 4] ?? null) === ';';
            if (! is_array($next) || ! in_array($next[1], $imports, true)
                || (($tokens[$index + 2] ?? null) !== ';' && ! $queryAlias)) {
                return false;
            }
        }
        if ($previousKind === T_OBJECT_OPERATOR || $previousKind === T_NULLSAFE_OBJECT_OPERATOR) {
            if (! is_array($token) || $token[0] !== T_STRING || ! in_array($text, $methods, true)) {
                // Reading a field is safe; a variable selector/call is not.
                if (! is_array($token) || $token[0] !== T_STRING || $next === '(') {
                    return false;
                }
            }
        }
        if ($previousKind === T_DOUBLE_COLON && $next === '(') {
            $owner = $tokens[$index - 2] ?? null;
            $owner = is_array($owner) ? $owner[1] : $owner;
            if (! in_array($text, $static[$owner] ?? [], true)
                && ! ($class === 'VersionUsageQuery' && $owner === '$model' && $text === 'query')) {
                return false;
            }
        }
        if (is_array($token) && in_array($token[0], [T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED], true)
            && $next === '(' && ! in_array($previousKind, [T_FUNCTION, T_NEW, T_OBJECT_OPERATOR,
                T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true)
            && ! in_array($text, $functions, true)) {
            return false;
        }
        if (is_array($token) && $token[0] === T_NEW) {
            $type = is_array($next) ? $next[1] : null;
            $types = ['InvalidArgumentException', 'LogicException'];
            if ($class === 'VersionUsageQuery') {
                $types = array_merge($types, ['VersionUsageDecision', '$instanceModel', '$eventModel']);
            }
            if (! in_array($type, $types, true)) {
                return false;
            }
        }
        if (is_array($token) && in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)
            && preg_match('/\b(INSERT|UPDATE|DELETE|REPLACE|ALTER|DROP|CREATE|TRUNCATE|CALL|EXECUTE|INTO|OUTFILE|DUMPFILE|COPY|PRAGMA|ATTACH|DETACH|VACUUM|GRANT|REVOKE)\b/i', $text)) {
            return false;
        }
    }
    return $namespaces === 1 && $classes === 1;
}

try {
    $input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
    echo json_encode(versionUsageObserver($input['path'], $input['source']), JSON_THROW_ON_ERROR);
} catch (Throwable) {
    echo 'false';
}
