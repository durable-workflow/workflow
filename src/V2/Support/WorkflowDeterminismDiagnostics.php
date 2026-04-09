<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use LogicException;
use ReflectionClass;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Workflow;

final class WorkflowDeterminismDiagnostics
{
    public const SOURCE_LIVE_DEFINITION = 'live_definition';

    public const SOURCE_UNAVAILABLE = 'unavailable';

    public const STATUS_CLEAN = 'clean';

    public const STATUS_WARNING = 'warning';

    public const STATUS_UNAVAILABLE = 'unavailable';

    /**
     * @return array{
     *     status: string,
     *     source: string,
     *     findings: list<array{
     *         rule: string,
     *         severity: string,
     *         symbol: string,
     *         message: string,
     *         file: string|null,
     *         line: int|null
     *     }>
     * }
     */
    public static function forRun(WorkflowRun $run): array
    {
        try {
            $workflowClass = TypeRegistry::resolveWorkflowClass($run->workflow_class, $run->workflow_type);
        } catch (LogicException) {
            return self::unavailable();
        }

        return self::forWorkflowClass($workflowClass);
    }

    /**
     * @param class-string $workflowClass
     *
     * @return array{
     *     status: string,
     *     source: string,
     *     findings: list<array{
     *         rule: string,
     *         severity: string,
     *         symbol: string,
     *         message: string,
     *         file: string|null,
     *         line: int|null
     *     }>
     * }
     */
    public static function forWorkflowClass(string $workflowClass): array
    {
        if (! class_exists($workflowClass) || ! is_subclass_of($workflowClass, Workflow::class)) {
            return self::unavailable();
        }

        $findings = self::findingsForWorkflowClass($workflowClass);

        return [
            'status' => $findings === []
                ? self::STATUS_CLEAN
                : self::STATUS_WARNING,
            'source' => self::SOURCE_LIVE_DEFINITION,
            'findings' => $findings,
        ];
    }

    /**
     * @return array{status: string, source: string, findings: list<array<string, mixed>>}
     */
    private static function unavailable(): array
    {
        return [
            'status' => self::STATUS_UNAVAILABLE,
            'source' => self::SOURCE_UNAVAILABLE,
            'findings' => [],
        ];
    }

    /**
     * @param class-string $workflowClass
     * @return list<array{
     *     rule: string,
     *     severity: string,
     *     symbol: string,
     *     message: string,
     *     file: string|null,
     *     line: int|null
     * }>
     */
    private static function findingsForWorkflowClass(string $workflowClass): array
    {
        $reflections = [];
        $seen = [];

        self::collectReflections(new ReflectionClass($workflowClass), $reflections, $seen);

        $findings = [];

        foreach ($reflections as $reflection) {
            $findings = [
                ...$findings,
                ...self::findingsForReflection($reflection),
            ];
        }

        usort($findings, static function (array $left, array $right): int {
            return [
                $left['file'] ?? '',
                $left['line'] ?? 0,
                $left['rule'],
                $left['symbol'],
            ] <=> [
                $right['file'] ?? '',
                $right['line'] ?? 0,
                $right['rule'],
                $right['symbol'],
            ];
        });

        return array_values($findings);
    }

    /**
     * @param list<ReflectionClass> $reflections
     * @param array<string, true> $seen
     */
    private static function collectReflections(
        ReflectionClass $reflection,
        array &$reflections,
        array &$seen,
    ): void {
        $name = $reflection->getName();

        if (isset($seen[$name])) {
            return;
        }

        $seen[$name] = true;

        if ($name !== Workflow::class) {
            $reflections[] = $reflection;
        }

        foreach ($reflection->getTraits() as $trait) {
            self::collectReflections($trait, $reflections, $seen);
        }

        $parent = $reflection->getParentClass();

        if ($parent instanceof ReflectionClass && is_subclass_of($parent->getName(), Workflow::class)) {
            self::collectReflections($parent, $reflections, $seen);
        }
    }

    /**
     * @return list<array{
     *     rule: string,
     *     severity: string,
     *     symbol: string,
     *     message: string,
     *     file: string|null,
     *     line: int|null
     * }>
     */
    private static function findingsForReflection(ReflectionClass $reflection): array
    {
        $file = $reflection->getFileName();

        if (! is_string($file) || $file === '') {
            return [];
        }

        $startLine = $reflection->getStartLine();
        $endLine = $reflection->getEndLine();

        if (! is_int($startLine) || ! is_int($endLine) || $startLine < 1 || $endLine < $startLine) {
            return [];
        }

        $lines = @file($file);

        if (! is_array($lines)) {
            return [];
        }

        $source = implode('', array_slice($lines, $startLine - 1, $endLine - $startLine + 1));
        $tokens = token_get_all("<?php\n" . $source);
        $findings = [];
        $count = count($tokens);

        for ($index = 0; $index < $count; $index++) {
            $token = $tokens[$index];

            if (! self::isNameToken($token)) {
                continue;
            }

            $functionRule = self::functionRule(self::tokenText($token), $tokens, $index);

            if ($functionRule !== null) {
                $findings[] = self::finding(
                    $functionRule['rule'],
                    self::tokenText($token),
                    $functionRule['message'],
                    $file,
                    self::sourceLine($token, $startLine),
                );

                continue;
            }

            $staticRule = self::staticCallRule(self::tokenText($token), $tokens, $index);

            if ($staticRule !== null) {
                $findings[] = self::finding(
                    $staticRule['rule'],
                    $staticRule['symbol'],
                    $staticRule['message'],
                    $file,
                    self::sourceLine($token, $startLine),
                );
            }
        }

        return $findings;
    }

    /**
     * @param list<mixed> $tokens
     * @return array{rule: string, message: string}|null
     */
    private static function functionRule(string $name, array $tokens, int $index): ?array
    {
        $next = self::nextMeaningfulToken($tokens, $index);

        if ($next !== '(') {
            return null;
        }

        $previous = self::previousMeaningfulToken($tokens, $index);

        if (
            self::tokenId($previous) === T_DOUBLE_COLON
            || self::tokenId($previous) === T_OBJECT_OPERATOR
            || (defined('T_NULLSAFE_OBJECT_OPERATOR') && self::tokenId($previous) === T_NULLSAFE_OBJECT_OPERATOR)
            || self::tokenId($previous) === T_FUNCTION
            || self::tokenId($previous) === T_NEW
        ) {
            return null;
        }

        $baseName = self::baseName($name);

        $wallClockFunctions = [
            'date',
            'gmdate',
            'hrtime',
            'microtime',
            'now',
            'time',
        ];

        if (in_array($baseName, $wallClockFunctions, true)) {
            return [
                'rule' => 'workflow_wall_clock_call',
                'message' => 'Workflow code reads wall-clock time. Pass time in as input, use a durable timer, or snapshot the value with sideEffect().',
            ];
        }

        $randomFunctions = [
            'random_bytes',
            'random_int',
            'rand',
            'mt_rand',
            'uniqid',
        ];

        if (in_array($baseName, $randomFunctions, true)) {
            return [
                'rule' => 'workflow_random_call',
                'message' => 'Workflow code reads randomness. Pass the value in as input or snapshot it with sideEffect().',
            ];
        }

        $ambientFunctions = [
            'auth',
            'request',
        ];

        if (in_array($baseName, $ambientFunctions, true)) {
            return [
                'rule' => 'workflow_ambient_context_call',
                'message' => 'Workflow code reads request or authentication context. Pass that data in as workflow input or through a durable command payload.',
            ];
        }

        return null;
    }

    /**
     * @param list<mixed> $tokens
     * @return array{rule: string, symbol: string, message: string}|null
     */
    private static function staticCallRule(string $className, array $tokens, int $index): ?array
    {
        $doubleColonIndex = self::nextMeaningfulIndex($tokens, $index);

        if ($doubleColonIndex === null || self::tokenId($tokens[$doubleColonIndex]) !== T_DOUBLE_COLON) {
            return null;
        }

        $methodIndex = self::nextMeaningfulIndex($tokens, $doubleColonIndex);

        if ($methodIndex === null || ! self::isNameToken($tokens[$methodIndex])) {
            return null;
        }

        $classBaseName = self::baseName($className);
        $method = strtolower(self::tokenText($tokens[$methodIndex]));
        $symbol = sprintf('%s::%s', ltrim($className, '\\'), self::tokenText($tokens[$methodIndex]));

        if (in_array($classBaseName, ['DB', 'Database'], true)) {
            return [
                'rule' => 'workflow_database_facade_call',
                'symbol' => $symbol,
                'message' => 'Workflow code reads or writes the live database. Move this work to an activity or snapshot the result with sideEffect().',
            ];
        }

        if ($classBaseName === 'Cache') {
            return [
                'rule' => 'workflow_cache_facade_call',
                'symbol' => $symbol,
                'message' => 'Workflow code reads or writes cache state. Use signals, updates, workflow input, or an activity result as the durable boundary.',
            ];
        }

        if ($classBaseName === 'Auth') {
            return [
                'rule' => 'workflow_auth_facade_call',
                'symbol' => $symbol,
                'message' => 'Workflow code reads authentication state. Pass actor data through workflow input or durable command metadata.',
            ];
        }

        if ($classBaseName === 'Http') {
            return [
                'rule' => 'workflow_http_facade_call',
                'symbol' => $symbol,
                'message' => 'Workflow code performs an HTTP call. Move external I/O to an activity so replay stays deterministic.',
            ];
        }

        if (
            in_array($classBaseName, ['Carbon', 'Date', 'DateTime', 'DateTimeImmutable'], true)
            && in_array($method, ['now', 'today', 'tomorrow', 'yesterday'], true)
        ) {
            return [
                'rule' => 'workflow_wall_clock_call',
                'symbol' => $symbol,
                'message' => 'Workflow code reads wall-clock time. Pass time in as input, use a durable timer, or snapshot the value with sideEffect().',
            ];
        }

        if (
            $classBaseName === 'Str'
            && in_array($method, ['ordereduuid', 'password', 'random', 'uuid', 'ulid'], true)
        ) {
            return [
                'rule' => 'workflow_random_call',
                'symbol' => $symbol,
                'message' => 'Workflow code reads randomness. Pass the value in as input or snapshot it with sideEffect().',
            ];
        }

        return null;
    }

    /**
     * @return array{
     *     rule: string,
     *     severity: string,
     *     symbol: string,
     *     message: string,
     *     file: string|null,
     *     line: int|null
     * }
     */
    private static function finding(
        string $rule,
        string $symbol,
        string $message,
        ?string $file,
        ?int $line,
    ): array {
        return [
            'rule' => $rule,
            'severity' => 'warning',
            'symbol' => $symbol,
            'message' => $message,
            'file' => $file,
            'line' => $line,
        ];
    }

    private static function sourceLine(mixed $token, int $startLine): ?int
    {
        if (! is_array($token) || ! is_int($token[2] ?? null)) {
            return null;
        }

        return $token[2] + $startLine - 2;
    }

    private static function nextMeaningfulIndex(array $tokens, int $index): ?int
    {
        $count = count($tokens);

        for ($next = $index + 1; $next < $count; $next++) {
            if (self::isSkippableToken($tokens[$next])) {
                continue;
            }

            return $next;
        }

        return null;
    }

    private static function nextMeaningfulToken(array $tokens, int $index): mixed
    {
        $next = self::nextMeaningfulIndex($tokens, $index);

        return $next === null ? null : $tokens[$next];
    }

    private static function previousMeaningfulToken(array $tokens, int $index): mixed
    {
        for ($previous = $index - 1; $previous >= 0; $previous--) {
            if (self::isSkippableToken($tokens[$previous])) {
                continue;
            }

            return $tokens[$previous];
        }

        return null;
    }

    private static function isSkippableToken(mixed $token): bool
    {
        return is_array($token)
            && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true);
    }

    private static function isNameToken(mixed $token): bool
    {
        if (! is_array($token)) {
            return false;
        }

        $nameTokenIds = [
            T_STRING,
            T_NAME_FULLY_QUALIFIED,
            T_NAME_QUALIFIED,
        ];

        if (defined('T_NAME_RELATIVE')) {
            $nameTokenIds[] = T_NAME_RELATIVE;
        }

        return in_array($token[0], $nameTokenIds, true);
    }

    private static function tokenId(mixed $token): ?int
    {
        return is_array($token)
            ? $token[0]
            : (is_string($token) && $token === '::' ? T_DOUBLE_COLON : null);
    }

    private static function tokenText(mixed $token): string
    {
        return is_array($token)
            ? $token[1]
            : (is_string($token) ? $token : '');
    }

    private static function baseName(string $name): string
    {
        $name = ltrim($name, '\\');
        $parts = explode('\\', $name);

        return end($parts) ?: $name;
    }
}
