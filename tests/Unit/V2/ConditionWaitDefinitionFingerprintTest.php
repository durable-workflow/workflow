<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use PHPUnit\Framework\TestCase;
use ReflectionFunction;
use Workflow\V2\Support\ConditionWaitDefinition;

/**
 * Pins what the condition-wait fingerprint reads. The regression-corpus comment-only exemption
 * (scripts/ci/classify-comment-only-change.php) relies on it hashing only the raw text of the lines a
 * closure spans: comments and blank lines outside those lines must not change it.
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
                '    public static function block',
                "    /**\n     * A docblock above the closure's method.\n     */\n\n    public static function block",
                self::CLOSURES
            ),
        );

        foreach (['arrow', 'block'] as $method) {
            $before = [$plain, $method]();
            $after = [$commented, $method]();
            // The closures moved down the file, so their position differs ...
            $this->assertGreaterThan(
                (new ReflectionFunction($before))->getStartLine(),
                (new ReflectionFunction($after))->getStartLine(),
            );
            // ... and the fingerprint does not.
            $this->assertNotNull(ConditionWaitDefinition::fingerprint($before));
            $this->assertSame(
                ConditionWaitDefinition::fingerprint($before),
                ConditionWaitDefinition::fingerprint($after),
                $method,
            );
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
                '            return self::$ready',
                "            // inside\n            return self::\$ready",
                self::CLOSURES
            ),
        );

        foreach (['arrow', 'block'] as $method) {
            $this->assertNotSame(
                ConditionWaitDefinition::fingerprint([$plain, $method]()),
                ConditionWaitDefinition::fingerprint([$changed, $method]()),
                $method,
            );
        }
        // A comment inside the closure's lines is part of the fingerprint, so the exemption refuses it.
        $this->assertNotSame(
            ConditionWaitDefinition::fingerprint([$plain, 'block']()),
            ConditionWaitDefinition::fingerprint([$commentedInside, 'block']()),
        );
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
PHP;
        $class = $this->fixture('Spans', '', '', $closures);
        $file = (string) (new \ReflectionClass($class))->getFileName();
        $reflected = [
            new ReflectionFunction([$class, 'arrow']()),
            new ReflectionFunction([$class, 'block']()),
            new ReflectionFunction([$class, 'byReference']()),
            new ReflectionFunction([$class, 'attributed']()),
            null,
            new ReflectionFunction([$class, 'inArgument']()),
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
                "closure {$index} starts inside its span"
            );
            $this->assertGreaterThanOrEqual($reflection->getEndLine(), $end, "closure {$index} ends inside its span");
        }
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
