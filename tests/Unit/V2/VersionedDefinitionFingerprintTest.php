<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Orchestra\Testbench\TestCase;
use Tests\Fixtures\V2\TestCommandTargetWorkflow;
use Tests\Fixtures\V2\TestCommentOnlyFingerprintWorkflow;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\ConditionWaitDefinition;
use Workflow\V2\Support\SourceFingerprint;
use Workflow\V2\Support\WorkflowDefinition;
use Workflow\V2\Support\WorkflowDefinitionFingerprint;

/**
 * Versioned definition and condition fingerprints (#1227).
 *
 * Each source variant is loaded in its own PHP process, so every variant
 * declares the same class at the same name, exactly as a redeployed workflow.
 */
final class VersionedDefinitionFingerprintTest extends TestCase
{
    private const SUBJECT = <<<'PHP'
<?php

declare(strict_types=1);

namespace Tests\Generated\VersionedFingerprint;

use Workflow\V2\Workflow;
use function Workflow\V2\await;

trait SubjectSteps
{
    private function label(string $value): string
    {
        return strtoupper($value);
    }
}

final class Subject extends Workflow
{
    use SubjectSteps;

    private bool $ready = false;

    public function handle(): string
    {
        await(fn (): bool => $this->ready === true, conditionKey: 'subject.ready');

        return $this->label('done');
    }

    public function condition(): \Closure
    {
        return fn (): bool => $this->ready === true;
    }
}
PHP;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir() . '/versioned-fingerprint-' . bin2hex(random_bytes(6));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->directory . '/*') ?: []);
        rmdir($this->directory);

        parent::tearDown();
    }

    public function testCommentAndWhitespaceOnlyEditsDoNotChangeVersionTwo(): void
    {
        $original = $this->fingerprints(self::SUBJECT);

        foreach ([
            'line comments' => [
                '        await(fn' => "        // Wait for readiness.\n        await(fn",
                "        return \$this->label('done');" => "        return \$this->label('done'); // finished",
            ],
            'docblocks' => [
                '    public function handle()' => "    /**\n     * The entry method.\n     */\n    public function handle()",
                '    private function label' => "    /** Formats a label. */\n    private function label",
            ],
            'comments inside the condition closure' => [
                'fn (): bool => $this->ready === true, conditionKey' => 'fn (): bool => /* ready */ $this->ready === true, # key' . "\n            conditionKey",
                'return fn (): bool => $this->ready === true;' => 'return fn (): bool => $this->ready /* flag */ === true; // done',
            ],
            'reformatting' => [
                "    private bool \$ready = false;\n\n" => "    private bool \$ready = false;\n",
                'await(fn (): bool => $this->ready === true, conditionKey: \'subject.ready\');' => "await(\n            fn (): bool\n                => \$this->ready   ===   true,\n            conditionKey: 'subject.ready'\n        );",
                '        return strtoupper($value);' => "\t\treturn strtoupper( \$value );",
            ],
        ] as $edit => $replacements) {
            $edited = $this->fingerprints(strtr(self::SUBJECT, $replacements));

            $this->assertNotSame(self::SUBJECT, strtr(self::SUBJECT, $replacements), $edit);
            $this->assertSame($original['v2'], $edited['v2'], "{$edit}: workflow v2");
            $this->assertSame($original['condition_v2'], $edited['condition_v2'], "{$edit}: condition v2");
            // Version 1 keeps hashing raw lines; histories recorded with it keep failing closed.
            $this->assertNotSame($original['v1'], $edited['v1'], "{$edit}: workflow v1");
        }
    }

    public function testCodeChangesChangeVersionTwo(): void
    {
        $original = $this->fingerprints(self::SUBJECT);

        foreach ([
            'workflow string literal' => [
                "\$this->label('done')" => "\$this->label('Done')",
            ],
            'whitespace inside a string literal' => [
                "\$this->label('done')" => "\$this->label('done ')",
            ],
            'trait body' => [
                'return strtoupper($value);' => 'return strtolower($value);',
            ],
            'condition expression' => [
                'await(fn (): bool => $this->ready === true' => 'await(fn (): bool => $this->ready !== true',
                'return fn (): bool => $this->ready === true;' => 'return fn (): bool => $this->ready !== true;',
            ],
        ] as $edit => $replacements) {
            $edited = $this->fingerprints(strtr(self::SUBJECT, $replacements));

            $this->assertNotSame($original['v2'], $edited['v2'], "{$edit}: workflow v2");
        }

        $condition = $this->fingerprints(strtr(self::SUBJECT, [
            'return fn (): bool => $this->ready === true;' => 'return fn (): bool => $this->ready !== true;',
        ]));
        $this->assertNotSame($original['condition_v2'], $condition['condition_v2']);
        $this->assertNotSame($original['condition_v1'], $condition['condition_v1']);
    }

    public function testTokenFramingKeepsTokenBoundaries(): void
    {
        // `+ +` is two tokens and `++` one; unframed concatenation would equate them.
        file_put_contents($this->directory . '/separate.php', "<?php\n\$a = \$b + +\$c;\n");
        file_put_contents($this->directory . '/joined.php', "<?php\n\$a = \$b ++\$c;\n");

        $this->assertNotSame(
            SourceFingerprint::codeTokens($this->directory . '/separate.php', 2, 2),
            SourceFingerprint::codeTokens($this->directory . '/joined.php', 2, 2),
        );
    }

    public function testVersionsAreNamedByTheirPrefix(): void
    {
        $v1 = WorkflowDefinition::fingerprint(TestCommandTargetWorkflow::class, SourceFingerprint::V1);
        $v2 = WorkflowDefinition::fingerprint(TestCommandTargetWorkflow::class);

        $this->assertMatchesRegularExpression('/\Asha256:[0-9a-f]{64}\z/', (string) $v1);
        $this->assertMatchesRegularExpression('/\Asha256-tokens:[0-9a-f]{64}\z/', (string) $v2);
        $this->assertSame(
            $v2,
            WorkflowDefinition::fingerprint(TestCommandTargetWorkflow::class, SourceFingerprint::V2)
        );
        $this->assertSame(SourceFingerprint::V1, SourceFingerprint::versionOf($v1));
        $this->assertSame(SourceFingerprint::V2, SourceFingerprint::versionOf($v2));

        // Absent and foreign values compare by version 1, as before versions existed.
        foreach ([null, '', 'opaque-worker-fingerprint', 'tokens:abc'] as $value) {
            $this->assertSame(SourceFingerprint::V1, SourceFingerprint::versionOf($value));
        }
    }

    public function testVersionOneIsTheUnchangedRawLineAlgorithm(): void
    {
        $reflection = new \ReflectionClass(TestCommentOnlyFingerprintWorkflow::class);
        $lines = file((string) $reflection->getFileName());
        $source = implode('', array_slice(
            $lines,
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1,
        ));

        $this->assertSame(
            'sha256:' . hash('sha256', json_encode([
                TestCommentOnlyFingerprintWorkflow::class => [
                    'symbol' => TestCommentOnlyFingerprintWorkflow::class,
                    'kind' => 'class',
                    'source' => $source,
                ],
            ], JSON_THROW_ON_ERROR)),
            WorkflowDefinition::fingerprint(TestCommentOnlyFingerprintWorkflow::class, SourceFingerprint::V1),
        );
    }

    public function testRecordedFingerprintsCompareByTheirOwnVersion(): void
    {
        $class = TestCommentOnlyFingerprintWorkflow::class;
        $v1 = (string) WorkflowDefinition::fingerprint($class, SourceFingerprint::V1);
        $v2 = (string) WorkflowDefinition::fingerprint($class);
        $earlierV1 = 'sha256:' . hash('sha256', 'a build of this class with other comments');

        $this->assertTrue(WorkflowDefinition::matchesFingerprint($class, $v1));
        $this->assertTrue(WorkflowDefinition::matchesFingerprint($class, $v2));
        $this->assertFalse(WorkflowDefinition::matchesFingerprint($class, $earlierV1));
        $this->assertFalse(WorkflowDefinition::matchesFingerprint($class, null));
        $this->assertSame($class, WorkflowDefinition::findClassByFingerprint($v1));
        $this->assertSame($class, WorkflowDefinition::findClassByFingerprint($v2));

        foreach ([
            'v1' => [$v1, $v1, true, SourceFingerprint::V1],
            'v2' => [$v2, $v2, true, SourceFingerprint::V2],
            'earlier v1' => [$earlierV1, $v1, false, SourceFingerprint::V1],
            'none' => [null, $v2, null, SourceFingerprint::V1],
        ] as $case => [$recorded, $current, $matches, $version]) {
            $run = $this->runWithRecordedFingerprint($class, $recorded);

            $this->assertSame($recorded, WorkflowDefinitionFingerprint::recordedForRun($run), $case);
            $this->assertSame($current, WorkflowDefinitionFingerprint::currentForRun($run), $case);
            $this->assertSame($matches, WorkflowDefinitionFingerprint::matchesCurrent($run), $case);
            $this->assertSame($version, WorkflowDefinitionFingerprint::versionForRun($run), $case);
            $this->assertSame($class, WorkflowDefinitionFingerprint::resolveClassForRun($run), $case);
        }
    }

    public function testRecordedConditionFingerprintsCompareByTheirOwnVersion(): void
    {
        $ready = false;
        $condition = static fn (): bool => $ready === true;
        $v1 = (string) ConditionWaitDefinition::fingerprint($condition, SourceFingerprint::V1);
        $v2 = (string) ConditionWaitDefinition::fingerprint($condition);
        $earlierV1 = 'sha256:' . hash('sha256', 'static fn (): bool => $ready === true; // other comment');

        $this->assertStringStartsWith('sha256:', $v1);
        $this->assertStringStartsWith('sha256-tokens:', $v2);
        // A version 1 recording is compared by version 1 even when the call carries version 2.
        $this->assertTrue(ConditionWaitDefinition::matches($condition, $v1, $v2));
        $this->assertTrue(ConditionWaitDefinition::matches($condition, $v2, $v2));
        $this->assertTrue(ConditionWaitDefinition::matches($condition, $v2, $v1));
        $this->assertFalse(ConditionWaitDefinition::matches($condition, $earlierV1, $v2));
        $this->assertFalse(ConditionWaitDefinition::matches(
            $condition,
            'sha256-tokens:' . hash('sha256', 'another condition'),
            $v2,
        ));
    }

    /**
     * @return array{v1: string, v2: string, condition_v1: string, condition_v2: string}
     */
    private function fingerprints(string $source): array
    {
        $file = $this->directory . '/subject-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($file, $source);
        $script = $this->directory . '/fingerprint-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($script, <<<'PHP'
<?php

require $argv[1];
require $argv[2];

use Workflow\V2\Support\ConditionWaitDefinition;
use Workflow\V2\Support\WorkflowDefinition;

$class = 'Tests\Generated\VersionedFingerprint\Subject';
$condition = (new ReflectionClass($class))->newInstanceWithoutConstructor()->condition();

echo json_encode([
    'v1' => WorkflowDefinition::fingerprint($class, 1),
    'v2' => WorkflowDefinition::fingerprint($class, 2),
    'condition_v1' => ConditionWaitDefinition::fingerprint($condition, 1),
    'condition_v2' => ConditionWaitDefinition::fingerprint($condition, 2),
], JSON_THROW_ON_ERROR);
PHP);

        $output = [];
        exec(
            implode(' ', array_map('escapeshellarg', [
                PHP_BINARY,
                $script,
                dirname(__DIR__, 3) . '/vendor/autoload.php',
                $file,
            ])) . ' 2>&1',
            $output,
            $status,
        );
        $this->assertSame(0, $status, implode("\n", $output));

        $fingerprints = json_decode(implode("\n", $output), true, flags: JSON_THROW_ON_ERROR);
        foreach ([
            'v1' => 'sha256:',
            'v2' => 'sha256-tokens:',
            'condition_v1' => 'sha256:',
            'condition_v2' => 'sha256-tokens:',
        ] as $key => $prefix) {
            $this->assertIsString($fingerprints[$key] ?? null, $key);
            $this->assertStringStartsWith($prefix, $fingerprints[$key], $key);
        }

        return $fingerprints;
    }

    private function runWithRecordedFingerprint(string $class, ?string $fingerprint): WorkflowRun
    {
        $run = new WorkflowRun();
        $run->id = 'versioned-fingerprint-run';
        $run->workflow_class = $class;
        $run->workflow_type = $class;

        $started = new WorkflowHistoryEvent();
        $started->forceFill([
            'workflow_run_id' => $run->id,
            'sequence' => 1,
            'event_type' => HistoryEventType::WorkflowStarted,
            'payload' => $fingerprint === null ? [] : [
                'workflow_definition_fingerprint' => $fingerprint,
            ],
        ]);
        $run->setRelation('historyEvents', new EloquentCollection([$started]));

        return $run;
    }
}
