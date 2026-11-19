<?php

declare(strict_types=1);

/*
 * Decides whether a change to a replay- or codec-guarded PHP file is comment-only and safe to exempt
 * from the regression-corpus growth requirement.
 *
 * Input (stdin, JSON): {"path": "<repository-relative path>", "base": "<base source>", "current": "<current source>"}
 * Output (stdout, JSON): {"exempt": bool, "reason": string}
 * With --closure-spans, it prints the closure line spans of the source on stdin instead (used by tests).
 *
 * A file is exempt only when every condition holds; anything unexpected fails closed (not exempt):
 *  1. Its token stream, without T_COMMENT, T_DOC_COMMENT and T_WHITESPACE, is identical at base and head.
 *     Attributes, strings, heredocs and declare statements are ordinary tokens and still count.
 *  2. It declares no trait. Workflow definition fingerprints include the source of traits a workflow uses.
 *  3. It contains no anonymous class, and every closure and arrow function spans byte-identical raw
 *     source lines at base and head, paired in token order. ConditionWaitDefinition::fingerprint()
 *     hashes exactly the raw text of the whole lines a closure spans (no line numbers, offsets or
 *     path), so comments outside those lines cannot change it, and any edit on them can. Each span is
 *     widened to the neighbouring code tokens, so it always covers the lines the fingerprint reads.
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
 * A trait or anonymous class at this token, which a fingerprint may read in full.
 *
 * @param list<array|string> $tokens
 */
function fingerprintedConstructAt(array $tokens, int $index): ?string
{
    $token = $tokens[$index];
    $kind = is_array($token) ? $token[0] : null;
    if ($kind === T_TRAIT) {
        return 'it declares a trait';
    }
    if ($kind === T_CLASS && isToken(previousSignificant($tokens, $index), T_NEW)) {
        return 'it contains an anonymous class';
    }
    return null;
}

/**
 * @param list<array|string> $tokens
 */
function isClosureAt(array $tokens, int $index): bool
{
    if (isToken($tokens[$index], T_FN)) {
        return true;
    }
    if (! isToken($tokens[$index], T_FUNCTION)) {
        return false;
    }
    $next = nextSignificantIndex($tokens, $index);
    // A by-reference closure is `function &(`; a by-reference method is `function &name(`.
    if ($next !== null && isAmpersand($tokens[$next])) {
        $next = nextSignificantIndex($tokens, $next);
    }
    return $next !== null && isToken($tokens[$next], '(');
}

/**
 * The 1-based line each token starts on.
 *
 * @param list<array|string> $tokens
 *
 * @return list<int>
 */
function tokenLines(array $tokens): array
{
    $lines = [];
    $line = 1;
    foreach ($tokens as $token) {
        $lines[] = $line;
        $line += substr_count(is_array($token) ? $token[1] : $token, "\n");
    }
    return $lines;
}

function opensGroup(array|string $token): bool
{
    return in_array($token, ['(', '[', '{'], true)
        || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES, T_ATTRIBUTE], true));
}

/**
 * Index of the token that closes the group opened at $open, or null.
 *
 * @param list<array|string> $tokens
 */
function groupEnd(array $tokens, int $open): ?int
{
    $depth = 0;
    for ($index = $open; isset($tokens[$index]); ++$index) {
        if (opensGroup($tokens[$index])) {
            ++$depth;
        } elseif (in_array($tokens[$index], [')', ']', '}'], true) && --$depth === 0) {
            return $index;
        }
    }
    return null;
}

/**
 * Index of the last token of the closure or arrow function at $index, or null when unknown.
 *
 * @param list<array|string> $tokens
 */
function closureEnd(array $tokens, int $index): ?int
{
    $open = $index;
    while (isset($tokens[++$open]) && $tokens[$open] !== '(') {
    }
    $parameters = isset($tokens[$open]) ? groupEnd($tokens, $open) : null;
    if ($parameters === null) {
        return null;
    }
    if (isToken($tokens[$index], T_FUNCTION)) {
        // Neither parameter defaults, `use` lists nor return types contain `{`: the first one opens the body.
        $body = $parameters;
        while (isset($tokens[++$body]) && $tokens[$body] !== '{') {
        }
        return isset($tokens[$body]) ? groupEnd($tokens, $body) : null;
    }
    $arrow = $parameters;
    while (isset($tokens[++$arrow]) && ! isToken($tokens[$arrow], T_DOUBLE_ARROW)) {
    }
    // The arrow function's expression ends before the first `;`, `,` or closing bracket at its own depth.
    $depth = 0;
    for ($end = $arrow + 1; isset($tokens[$end]); ++$end) {
        $token = $tokens[$end];
        if (opensGroup($token)) {
            ++$depth;
        } elseif (in_array($token, [')', ']', '}'], true) && $depth-- === 0) {
            return $end;
        } elseif ($depth === 0 && (in_array($token, [';', ','], true) || isToken($token, T_CLOSE_TAG))) {
            return $end;
        }
    }
    return null;
}

/**
 * Line spans covering every closure and arrow function, in token order, or null when a bound is unknown.
 * Each span starts at the code token before the closure (and its `static` keyword and attributes) and
 * ends at the code token after it, so it covers every line ReflectionFunction reports for the closure.
 *
 * @param list<array|string> $tokens
 *
 * @return list<array{int, int}>|null
 */
function closureSpans(array $tokens): ?array
{
    $lines = tokenLines($tokens);
    $spans = [];
    foreach (array_keys($tokens) as $index) {
        if (! isClosureAt($tokens, $index)) {
            continue;
        }
        $end = closureEnd($tokens, $index);
        if ($end === null) {
            return null;
        }
        $first = $index;
        for ($previous = $index - 1; $previous >= 0; --$previous) {
            $token = $tokens[$previous];
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true)) {
                continue;
            }
            $first = $previous;
            if (! isToken($token, T_STATIC) && $token !== ']') {
                break;
            }
            if ($token === ']') {
                // Step back over an attribute group: its T_ATTRIBUTE `#[` opens the matching `]`.
                $depth = 0;
                for (; $previous >= 0; --$previous) {
                    if (in_array($tokens[$previous], [')', ']', '}'], true)) {
                        ++$depth;
                    } elseif (opensGroup($tokens[$previous]) && --$depth === 0) {
                        break;
                    }
                }
                if ($previous < 0 || ! isToken($tokens[$previous], T_ATTRIBUTE)) {
                    break;
                }
                $first = $previous;
            }
        }
        $after = nextSignificantIndex($tokens, $end) ?? $end;
        $spans[] = [$lines[$first], $lines[$after]];
    }
    return $spans;
}

/**
 * Why the closures' raw source lines differ between base and head, or null when every pair is identical.
 */
function closureProblem(string $base, array $baseTokens, string $current, array $currentTokens): ?string
{
    $baseSpans = closureSpans($baseTokens);
    $currentSpans = closureSpans($currentTokens);
    if ($baseSpans === null || $currentSpans === null) {
        return "a closure's bounds could not be determined";
    }
    if (count($baseSpans) !== count($currentSpans)) {
        return 'the closures could not be paired';
    }
    $baseLines = explode("\n", $base);
    $currentLines = explode("\n", $current);
    foreach ($baseSpans as $pair => [$baseStart, $baseEnd]) {
        [$currentStart, $currentEnd] = $currentSpans[$pair];
        if (array_slice($baseLines, $baseStart - 1, $baseEnd - $baseStart + 1)
            !== array_slice($currentLines, $currentStart - 1, $currentEnd - $currentStart + 1)) {
            return "a closure's source lines changed";
        }
    }
    return null;
}

/**
 * The fully qualified name declared by a class, interface or enum keyword at this token: null when the
 * token declares nothing, false when the declared name cannot be resolved.
 *
 * @param list<array|string> $tokens
 */
function declaredTypeAt(array $tokens, int $index, string $namespace): string|false|null
{
    $token = $tokens[$index];
    if (! is_array($token) || ! in_array($token[0], [T_CLASS, T_INTERFACE, T_ENUM], true)
        || isToken(previousSignificant($tokens, $index), T_DOUBLE_COLON)) {
        return null;
    }
    $name = nextSignificant($tokens, $index);
    if (! is_array($name) || $name[0] !== T_STRING) {
        return false;
    }
    return ($namespace === '' ? '' : $namespace . '\\') . $name[1];
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
    foreach (array_keys($tokens) as $index) {
        if (isToken($tokens[$index], T_NAMESPACE)) {
            $name = nextSignificant($tokens, $index);
            // `namespace\Foo` relative names are a single token, so T_NAMESPACE starts a declaration.
            $namespace = is_array($name) && in_array($name[0], [T_NAME_QUALIFIED, T_STRING], true) ? $name[1] : '';
            continue;
        }
        $problem = fingerprintedConstructAt($tokens, $index);
        $type = $problem === null ? declaredTypeAt($tokens, $index, $namespace) : null;
        if ($type === false) {
            $problem = 'a declared type name could not be resolved';
        }
        if ($problem !== null) {
            return [
                'declared' => $declared,
                'problem' => $problem,
            ];
        }
        if (is_string($type)) {
            $declared[] = $type;
        }
    }
    return [
        'declared' => $declared,
        'problem' => null,
    ];
}

/**
 * Loads each declared type through the repository autoloader and returns why the file is not exempt,
 * or null when every type loads from this file and none is a workflow.
 *
 * @param list<string> $declared
 */
function loadingProblem(array $declared, string $path): ?string
{
    $autoload = getcwd() . '/vendor/autoload.php';
    if (! is_file($autoload)) {
        return 'the repository autoloader is unavailable';
    }
    $expectedFile = realpath(getcwd() . '/' . $path);
    try {
        require_once $autoload;
        foreach ($declared as $type) {
            if (! class_exists($type) && ! interface_exists($type) && ! enum_exists($type)) {
                return "{$type} could not be loaded";
            }
            $file = (new ReflectionClass($type))->getFileName();
            if ($expectedFile === false || $file === false || realpath($file) !== $expectedFile) {
                return "{$type} does not load from this file";
            }
            foreach (WORKFLOW_BASE_CLASSES as $workflow) {
                if (is_subclass_of($type, $workflow)) {
                    return "{$type} is a {$workflow} subclass";
                }
            }
        }
    } catch (Throwable $error) {
        return 'loading its classes failed: ' . $error::class;
    }
    return null;
}

// Test hook: print the closure line spans of the source on stdin.
if (($argv[1] ?? null) === '--closure-spans') {
    $tokens = tokens((string) stream_get_contents(STDIN));
    echo json_encode($tokens === null ? null : closureSpans($tokens), JSON_THROW_ON_ERROR);
    exit(0);
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
$problem ??= closureProblem($input['base'], $baseTokens, $input['current'], $currentTokens);
if ($problem !== null) {
    result(false, 'comment-only change, but ' . $problem);
}
if ($declared === []) {
    result(false, 'comment-only change, but the file declares no class, interface or enum');
}

$problem = loadingProblem($declared, $input['path']);
if ($problem !== null) {
    result(false, 'comment-only change, but ' . $problem);
}

result(true, 'comment-only change in ' . implode(', ', $declared)
    . ': no trait or workflow class, and every closure\'s source lines are unchanged, so no workflow or'
    . ' condition fingerprint changes');
