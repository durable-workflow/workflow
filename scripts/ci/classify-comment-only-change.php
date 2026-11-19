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
 *  3. It contains no carriage return, no anonymous class, and every function, method, closure and
 *     arrow function spans byte-identical raw source lines at base and head, paired in token order.
 *     ConditionWaitDefinition::fingerprint() hashes exactly the raw text of the whole lines a callable
 *     spans (no line numbers, offsets or path), and await() turns any callable into a closure, so a
 *     method or __invoke can be fingerprinted too. Comments outside those lines, such as docblocks
 *     above methods, cannot change it; any edit on them can. A lone carriage return moves PHP's line
 *     numbers without moving file()'s, so any source containing one is refused.
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
function previousSignificantIndex(array $tokens, int $index): ?int
{
    for ($previous = $index - 1; $previous >= 0; --$previous) {
        $token = $tokens[$previous];
        if (! is_array($token) || ! in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true)) {
            return $previous;
        }
    }
    return null;
}

/**
 * @param list<array|string> $tokens
 */
function previousSignificant(array $tokens, int $index): array|string|null
{
    $previous = previousSignificantIndex($tokens, $index);
    return $previous === null ? null : $tokens[$previous];
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
function isFunctionAt(array $tokens, int $index): bool
{
    if (isToken($tokens[$index], T_FN)) {
        return true;
    }
    // `use function Foo\bar;` imports a function; it declares nothing.
    return isToken($tokens[$index], T_FUNCTION) && ! isToken(previousSignificant($tokens, $index), T_USE);
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
 * Index of the first token after $from that matches, or null.
 *
 * @param list<array|string> $tokens
 * @param callable(array|string): bool $matches
 */
function firstIndexAfter(array $tokens, int $from, callable $matches): ?int
{
    for ($index = $from + 1; isset($tokens[$index]); ++$index) {
        if ($matches($tokens[$index])) {
            return $index;
        }
    }
    return null;
}

/**
 * Index of the token that opens the group closed at $close, or null.
 *
 * @param list<array|string> $tokens
 */
function groupStart(array $tokens, int $close): ?int
{
    $depth = 0;
    for ($index = $close; $index >= 0; --$index) {
        if (in_array($tokens[$index], [')', ']', '}'], true)) {
            ++$depth;
        } elseif (opensGroup($tokens[$index]) && --$depth === 0) {
            return $index;
        }
    }
    return null;
}

/**
 * Index of the token ending an arrow function's expression: the first `;`, `,`, closing tag or closing
 * bracket at the expression's own depth.
 *
 * @param list<array|string> $tokens
 */
function arrowExpressionEnd(array $tokens, int $arrow): ?int
{
    $depth = 0;
    for ($end = $arrow + 1; isset($tokens[$end]); ++$end) {
        $token = $tokens[$end];
        $closes = in_array($token, [')', ']', '}'], true);
        if ($depth === 0 && ($closes || in_array($token, [';', ','], true) || isToken($token, T_CLOSE_TAG))) {
            return $end;
        }
        $depth += opensGroup($token) ? 1 : ($closes ? -1 : 0);
    }
    return null;
}

/**
 * Index of the last token of the function, method, closure or arrow function at $index, or null.
 *
 * @param list<array|string> $tokens
 */
function closureEnd(array $tokens, int $index): ?int
{
    $open = firstIndexAfter($tokens, $index, static fn (array|string $token): bool => $token === '(');
    $parameters = $open === null ? null : groupEnd($tokens, $open);
    if ($parameters === null) {
        return null;
    }
    if (isToken($tokens[$index], T_FUNCTION)) {
        // Neither parameter defaults, `use` lists nor return types contain `{` or `;`: the first one opens
        // the body, or ends a declaration without one.
        $body = firstIndexAfter(
            $tokens,
            $parameters,
            static fn (array|string $token): bool => $token === '{' || $token === ';',
        );
        return $body === null || $tokens[$body] === ';' ? $body : groupEnd($tokens, $body);
    }
    $arrow = firstIndexAfter(
        $tokens,
        $parameters,
        static fn (array|string $token): bool => isToken($token, T_DOUBLE_ARROW),
    );
    return $arrow === null ? null : arrowExpressionEnd($tokens, $arrow);
}

/**
 * Index of the code token before the closure at $index, stepping back over its `static` keyword and
 * attribute groups, so a span starting there covers the line ReflectionFunction reports as its start.
 *
 * @param list<array|string> $tokens
 */
function closureStart(array $tokens, int $index): int
{
    $first = $index;
    while (($previous = previousSignificantIndex($tokens, $first)) !== null) {
        $token = $tokens[$previous];
        $group = $token === ']' ? groupStart($tokens, $previous) : null;
        if (isToken($token, T_STATIC)) {
            $first = $previous;
        } elseif ($group !== null && isToken($tokens[$group], T_ATTRIBUTE)) {
            $first = $group;
        } else {
            return $previous;
        }
    }
    return $first;
}

/**
 * Index of the first token of the named function or method declared at $index: its modifiers and
 * attribute groups, never the docblock above them.
 *
 * @param list<array|string> $tokens
 */
function declarationStart(array $tokens, int $index): int
{
    $modifiers = [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_ABSTRACT, T_FINAL];
    $first = $index;
    while (($previous = previousSignificantIndex($tokens, $first)) !== null) {
        $token = $tokens[$previous];
        $group = $token === ']' ? groupStart($tokens, $previous) : null;
        if (is_array($token) && in_array($token[0], $modifiers, true)) {
            $first = $previous;
        } elseif ($group !== null && isToken($tokens[$group], T_ATTRIBUTE)) {
            $first = $group;
        } else {
            break;
        }
    }
    return $first;
}

/**
 * Line spans covering every function, method, closure and arrow function, in token order, or null when a
 * bound is unknown. A closure's span runs from the code token before it to the code token after it; a
 * named function's from its first modifier or attribute to the end of its body. Either way it covers
 * every line ReflectionFunction or ReflectionMethod reports for it.
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
        if (! isFunctionAt($tokens, $index)) {
            continue;
        }
        $end = closureEnd($tokens, $index);
        if ($end === null) {
            return null;
        }
        $spans[] = isClosureAt($tokens, $index)
            ? [$lines[closureStart($tokens, $index)], $lines[nextSignificantIndex($tokens, $end) ?? $end]]
            : [$lines[declarationStart($tokens, $index)], $lines[$end]];
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
        return "a function or closure's bounds could not be determined";
    }
    if (count($baseSpans) !== count($currentSpans)) {
        return 'the functions and closures could not be paired';
    }
    $baseLines = explode("\n", $base);
    $currentLines = explode("\n", $current);
    foreach ($baseSpans as $pair => [$baseStart, $baseEnd]) {
        [$currentStart, $currentEnd] = $currentSpans[$pair];
        if (array_slice($baseLines, $baseStart - 1, $baseEnd - $baseStart + 1)
            !== array_slice($currentLines, $currentStart - 1, $currentEnd - $currentStart + 1)) {
            return "a function or closure's source lines changed";
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
if (str_contains($input['base'], "\r") || str_contains($input['current'], "\r")) {
    result(false, 'comment-only change, but the source contains a carriage return');
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
    . ': no trait or workflow class, and every function and closure\'s source lines are unchanged, so no'
    . ' workflow or condition fingerprint changes');
