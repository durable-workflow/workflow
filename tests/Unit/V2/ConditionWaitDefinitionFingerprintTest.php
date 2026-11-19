<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use PHPUnit\Framework\TestCase;
use ReflectionFunction;
use Workflow\V2\Support\ConditionWaitDefinition;
use Workflow\V2\Support\SourceFingerprint;

/**
 * Pins what the condition-wait fingerprint reads. The regression-corpus comment-only exemption
 * (scripts/ci/classify-comment-only-change.php) relies on it hashing only the lines a callable spans:
 * comments and blank lines outside those lines must not change it. await() turns any callable into a
 * closure, so method callables and invokables are fingerprinted by their method's lines.
 *
 * Version 1 hashes the raw text of those lines, so a comment inside them changes it. Runs recorded with
 * version 1 keep that comparison, which is why the exemption still refuses such comments. Version 2 hashes
 * only their code tokens.
 */
final class ConditionWaitDefinitionFingerprintTest extends TestCase
{
    private const CLOSURES = <<<'PHP'
    public static function arrow(): Closure
    {
        return static fn (): bool => self::$ready === true;
    }

    public static function block(): Closure
    {
        return static function (): bool {
            return self::$ready === true;
        };
    }

    #[ConditionFixtureMarker]
    public
        static function ready(): bool
    {
        return self::$ready === true;
    }

    public function __invoke(): bool
    {
        return self::$ready === true;
    }
PHP;

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/condition-fingerprint-' . bin2hex(random_bytes(6));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->directory . '/*.php') ?: []);
        rmdir($this->directory);
    }

    public function testCommentsAndBlankLinesOutsideTheClosureDoNotChangeTheFingerprint(): void
    {
        $plain = $this->fixture('Plain', '', '', self::CLOSURES);
        $commented = $this->fixture(
            'Commented',
            "/**\n * A docblock above the class.\n */\n\n\n",
            "    // A line comment above the methods.\n\n",
            str_replace(
                ['    public static function block', '    #[ConditionFixtureMarker]', '    public function __invoke'],
                [
                    "    /**\n     * A docblock above the closure's method.\n     */\n\n    public static function block",
                    "    /**\n     * A docblock above an attributed method.\n     */\n    #[ConditionFixtureMarker]",
                    "    // Above the invokable.\n    public function __invoke",
                ],
                self::CLOSURES
            ),
        );

        foreach (array_keys(self::callables($plain)) as $method) {
            $before = self::callables($plain)[$method];
            $after = self::callables($commented)[$method];
            // The closures moved down the file, so their position differs ...
            $this->assertGreaterThan(
                (new ReflectionFunction($before))->getStartLine(),
                (new ReflectionFunction($after))->getStartLine(),
            );
            // ... and neither fingerprint version does.
            foreach ([SourceFingerprint::V1, SourceFingerprint::V2] as $version) {
                $this->assertNotNull(ConditionWaitDefinition::fingerprint($before, $version));
                $this->assertSame(
                    ConditionWaitDefinition::fingerprint($before, $version),
                    ConditionWaitDefinition::fingerprint($after, $version),
                    "{$method} v{$version}",
                );
            }
        }
    }

    public function testOneCharacterInsideTheClosureChangesTheFingerprint(): void
    {
        $plain = $this->fixture('Original', '', '', self::CLOSURES);
        $changed = $this->fixture('Changed', '', '', str_replace('=== true', '=== false', self::CLOSURES));
        $commentedInside = $this->fixture(
            'CommentedInside',
            '',
            '',
            str_replace(
                ['            return self::$ready', "    {\n        return self::\$ready"],
                [
                    "            // inside\n            return self::\$ready",
                    "    {\n        // inside\n        return self::\$ready",
                ],
                self::CLOSURES,
            ),
        );

        foreach (array_keys(self::callables($plain)) as $method) {
            foreach ([SourceFingerprint::V1, SourceFingerprint::V2] as $version) {
                $this->assertNotSame(
                    ConditionWaitDefinition::fingerprint(self::callables($plain)[$method], $version),
                    ConditionWaitDefinition::fingerprint(self::callables($changed)[$method], $version),
                    "{$method} v{$version}",
                );
            }
        }
        // A comment inside a callable's lines is part of the version 1 fingerprint, so the exemption refuses
        // it. This includes the body of a method passed to await() as a callable or an invokable object.
        // Version 2 ignores it.
        foreach (['block', 'ready', 'invokable'] as $method) {
            $this->assertNotSame(
                ConditionWaitDefinition::fingerprint(self::callables($plain)[$method], SourceFingerprint::V1),
                ConditionWaitDefinition::fingerprint(self::callables($commentedInside)[$method], SourceFingerprint::V1),
                $method,
            );
            $this->assertSame(
                ConditionWaitDefinition::fingerprint(self::callables($plain)[$method], SourceFingerprint::V2),
                ConditionWaitDefinition::fingerprint(self::callables($commentedInside)[$method], SourceFingerprint::V2),
                $method,
            );
        }
    }

    public function testTheCorpusExemptionSpansCoverEveryLineTheFingerprintReads(): void
    {
        $closures = <<<'PHP'
    public static function arrow(): Closure
    {
        return static fn (): bool => self::$ready === true;
    }

    public static function block(): Closure
    {
        return static function (): bool {
            return self::$ready === true;
        };
    }

    public static function byReference(): Closure
    {
        return function &(): bool {
            return self::$ready;
        };
    }

    public static function attributed(): Closure
    {
        return #[ConditionFixtureMarker]
            static fn (
                array $options = ['ready' => true],
            ): bool => self::$ready
                && $options['ready'];
    }

    public static function argument(): array
    {
        return array_map(
            static fn (int $value): int => $value + 1,
            [1, 2],
        );
    }

    public static function inArgument(): Closure
    {
        return self::wrap(static function () use (&$unused): bool {
            return self::$ready;
        }, 'key');
    }

    private static function wrap(Closure $closure, string $key): Closure
    {
        return $closure;
    }

    #[ConditionFixtureMarker]
    public
        static function ready(): bool
    {
        return self::$ready === true;
    }

    public function __invoke(): bool
    {
        return self::$ready === true;
    }
PHP;
        $class = $this->fixture('Spans', '', '', $closures);
        $file = (string) (new \ReflectionClass($class))->getFileName();
        $method = static fn (string $name): \ReflectionMethod => new \ReflectionMethod($class, $name);
        // Every function in token order: each method, then the closure it returns.
        $reflected = [
            $method('arrow'),
            new ReflectionFunction([$class, 'arrow']()),
            $method('block'),
            new ReflectionFunction([$class, 'block']()),
            $method('byReference'),
            new ReflectionFunction([$class, 'byReference']()),
            $method('attributed'),
            new ReflectionFunction([$class, 'attributed']()),
            $method('argument'),
            null,
            $method('inArgument'),
            new ReflectionFunction([$class, 'inArgument']()),
            $method('wrap'),
            $method('ready'),
            $method('__invoke'),
        ];
        $spans = $this->closureSpans((string) file_get_contents($file));
        $this->assertCount(count($reflected), $spans);
        foreach ($reflected as $index => $reflection) {
            if ($reflection === null) {
                continue;
            }
            [$start, $end] = $spans[$index];
            $this->assertLessThanOrEqual(
                $reflection->getStartLine(),
                $start,
                "function {$index} starts inside its span"
            );
            $this->assertGreaterThanOrEqual($reflection->getEndLine(), $end, "function {$index} ends inside its span");
        }
        // A method's span starts at its first modifier or attribute, so a docblock above it stays outside.
        $lines = file($file, FILE_IGNORE_NEW_LINES);
        $this->assertStringContainsString('#[ConditionFixtureMarker]', $lines[$spans[13][0] - 1]);
    }

    /**
     * The closures await() would fingerprint for each fixture callable.
     *
     * @param class-string $class
     *
     * @return array<string, \Closure>
     */
    private static function callables(string $class): array
    {
        return [
            'arrow' => [$class, 'arrow'](),
            'block' => [$class, 'block'](),
            'ready' => \Closure::fromCallable([$class, 'ready']),
            'invokable' => \Closure::fromCallable(new $class()),
        ];
    }

    /**
     * @return list<array{int, int}>
     */
    private function closureSpans(string $source): array
    {
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 3) . '/scripts/ci/classify-comment-only-change.php', '--closure-spans'],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
            ],
            $pipes,
        );
        $this->assertIsResource($process);
        fwrite($pipes[0], $source);
        fclose($pipes[0]);
        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $this->assertSame(0, proc_close($process));
        $spans = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($spans);

        return $spans;
    }

    /**
     * @return class-string
     */
    private function fixture(string $suffix, string $aboveClass, string $aboveMethods, string $closures): string
    {
        $class = 'ConditionFingerprintFixture' . $suffix . bin2hex(random_bytes(4));
        $file = "{$this->directory}/{$class}.php";
        file_put_contents($file, <<<PHP
        <?php

        namespace Tests\\Unit\\V2\\Generated;

        use Closure;

        {$aboveClass}final class {$class}
        {
            public static bool \$ready = true;

        {$aboveMethods}{$closures}
        }

        PHP);
        require $file;

        return 'Tests\\Unit\\V2\\Generated\\' . $class;
    }
}
