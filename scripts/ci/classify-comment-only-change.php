<?php

declare(strict_types=1);

/*
 * Decides whether a change to a replay- or codec-guarded PHP file is comment-only and safe to exempt
 * from the regression-corpus growth requirement.
 *
 * Input (stdin, JSON): {"path": "<repository-relative path>", "base": "<base source>", "current": "<current source>"}
 * Output (stdout, JSON): {"exempt": bool, "reason": string}
 *
 * A file is exempt only when every condition holds; anything unexpected fails closed (not exempt):
 *  1. Its token stream, without T_COMMENT, T_DOC_COMMENT and T_WHITESPACE, is identical at base and head.
 *     Attributes, strings, heredocs and declare statements are ordinary tokens and still count.
 *  2. It declares no trait. Workflow definition fingerprints include the source of traits a workflow uses.
 *  3. It contains no closure, arrow function or anonymous class. Condition-wait fingerprints hash the
 *     raw source lines of closures, comments included.
 *  4. It declares at least one class, interface or enum; each one loads from this exact file through
 *     the repository autoloader; and no declared class is a Workflow\V2\Workflow or Workflow\Workflow
 *     subclass. Workflow definition fingerprints hash the raw source of workflow classes, comments
 *     included, and that fingerprint selects legacy version defaults and the pinned class on replay.
 *     Subclassing is checked by loading the class, never inferred from tokens.
 */

const WORKFLOW_BASE_CLASSES = ['Workflow\\V2\\Workflow', 'Workflow\\Workflow'];

function result(bool $exempt, string $reason): never
{
    echo json_encode([
        'exempt' => $exempt,
        'reason' => $reason,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    exit(0);
}

/**
 * @return list<array|string>|null
 */
function tokens(string $source): ?array
{
    try {
        return token_get_all($source, TOKEN_PARSE);
    } catch (Throwable) {
        return null;
    }
}

/**
 * @param list<array|string> $tokens
 *
 * @return list<string>
 */
function significant(array $tokens): array
{
    $stream = [];
    foreach ($tokens as $token) {
        if (is_array($token)) {
            if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true)) {
                continue;
            }
            $stream[] = token_name($token[0]) . ':' . $token[1];
        } else {
            $stream[] = $token;
        }
    }
    return $stream;
}

/**
 * @param list<array|string> $tokens
 */
function nextSignificantIndex(array $tokens, int $index): ?int
{
    for ($next = $index + 1; isset($tokens[$next]); ++$next) {
        $token = $tokens[$next];
        if (! is_array($token) || ! in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true)) {
            return $next;
        }
    }
    return null;
}

/**
 * @param list<array|string> $tokens
 */
function nextSignificant(array $tokens, int $index): array|string|null
{
    $next = nextSignificantIndex($tokens, $index);
    return $next === null ? null : $tokens[$next];
}

/**
 * @param list<array|string> $tokens
 */
function previousSignificant(array $tokens, int $index): array|string|null
{
    for ($previous = $index - 1; $previous >= 0; --$previous) {
        $token = $tokens[$previous];
        if (! is_array($token) || ! in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true)) {
            return $token;
        }
    }
    return null;
}

/** PHP 8.1 tokenizes `&` as T_AMPERSAND_(NOT_)FOLLOWED_BY_VAR_OR_VARARG, earlier versions as '&'. */
function isAmpersand(array|string|null $token): bool
{
    if ($token === '&') {
        return true;
    }
    return is_array($token) && in_array(token_name($token[0]), [
        'T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG',
        'T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG',
    ], true);
}

function isToken(array|string|null $token, int|string $kind): bool
{
    return is_array($token) ? $token[0] === $kind : $token === $kind;
}

/**
 * @param list<array|string> $tokens
 *
 * @return array{declared: list<string>, problem: ?string}
 */
function declarations(array $tokens): array
{
    $namespace = '';
    $declared = [];
    foreach ($tokens as $index => $token) {
        if (! is_array($token)) {
            continue;
        }
        if ($token[0] === T_NAMESPACE) {
            $name = nextSignificant($tokens, $index);
            if (is_array($name) && in_array($name[0], [T_NAME_QUALIFIED, T_STRING], true)) {
                $namespace = $name[1];
            } elseif (isToken($name, '{')) {
                $namespace = '';
            } else {
                // namespace\Foo relative names and other forms are expressions, not declarations.
                continue;
            }
            continue;
        }
        if ($token[0] === T_TRAIT) {
            return [
                'declared' => $declared,
                'problem' => 'it declares a trait',
            ];
        }
        if ($token[0] === T_FN) {
            return [
                'declared' => $declared,
                'problem' => 'it contains an arrow function',
            ];
        }
        if ($token[0] === T_FUNCTION) {
            $next = nextSignificantIndex($tokens, $index);
            // A by-reference closure is `function &(`; a by-reference method is `function &name(`.
            if ($next !== null && isAmpersand($tokens[$next])) {
                $next = nextSignificantIndex($tokens, $next);
            }
            if ($next !== null && isToken($tokens[$next], '(')) {
                return [
                    'declared' => $declared,
                    'problem' => 'it contains a closure',
                ];
            }
            continue;
        }
        if (in_array($token[0], [T_CLASS, T_INTERFACE, T_ENUM], true)) {
            $previous = previousSignificant($tokens, $index);
            if ($token[0] === T_CLASS && isToken($previous, T_DOUBLE_COLON)) {
                continue;
            }
            if ($token[0] === T_CLASS && isToken($previous, T_NEW)) {
                return [
                    'declared' => $declared,
                    'problem' => 'it contains an anonymous class',
                ];
            }
            $name = nextSignificant($tokens, $index);
            if (! is_array($name) || $name[0] !== T_STRING) {
                return [
                    'declared' => $declared,
                    'problem' => 'a declared type name could not be resolved',
                ];
            }
            $declared[] = ($namespace === '' ? '' : $namespace . '\\') . $name[1];
        }
    }
    return [
        'declared' => $declared,
        'problem' => null,
    ];
}

$input = json_decode((string) stream_get_contents(STDIN), true);
if (! is_array($input) || ! is_string($input['path'] ?? null)
    || ! is_string($input['base'] ?? null) || ! is_string($input['current'] ?? null)) {
    result(false, 'the classifier input was invalid');
}

$baseTokens = tokens($input['base']);
$currentTokens = tokens($input['current']);
if ($baseTokens === null || $currentTokens === null) {
    result(false, 'the file does not parse at base or head');
}
if (significant($baseTokens) !== significant($currentTokens)) {
    result(false, 'code tokens changed');
}

['declared' => $declared, 'problem' => $problem] = declarations($currentTokens);
if ($problem !== null) {
    result(false, 'comment-only change, but ' . $problem);
}
if ($declared === []) {
    result(false, 'comment-only change, but the file declares no class, interface or enum');
}

$autoload = getcwd() . '/vendor/autoload.php';
if (! is_file($autoload)) {
    result(false, 'comment-only change, but the repository autoloader is unavailable');
}
$expectedFile = realpath(getcwd() . '/' . $input['path']);

try {
    require $autoload;
    foreach ($declared as $type) {
        if (! class_exists($type) && ! interface_exists($type) && ! enum_exists($type)) {
            result(false, "comment-only change, but {$type} could not be loaded");
        }
        $file = (new ReflectionClass($type))->getFileName();
        if ($expectedFile === false || $file === false || realpath($file) !== $expectedFile) {
            result(false, "comment-only change, but {$type} does not load from this file");
        }
        foreach (WORKFLOW_BASE_CLASSES as $workflow) {
            if (is_subclass_of($type, $workflow)) {
                result(false, "comment-only change, but {$type} is a {$workflow} subclass");
            }
        }
    }
} catch (Throwable $error) {
    result(false, 'comment-only change, but loading its classes failed: ' . $error::class);
}

result(true, 'comment-only change in ' . implode(', ', $declared)
    . ': no trait, closure or workflow class, so no workflow or condition fingerprint includes it');
