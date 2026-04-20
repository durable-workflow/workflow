<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Container\Container as IlluminateContainer;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory as ValidationFactory;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\TestCase;
use Workflow\V2\Support\WorkflowCommandNormalizer;

/**
 * Contract test for the `record_version_marker` command and the matching
 * `VersionMarkerRecorded` history-event wire format. See
 * `docs/api-stability.md#frozen-history-event-wire-formats`.
 *
 * Once a running workflow writes a `VersionMarkerRecorded` event, every
 * future SDK build that replays the workflow must decode the same field
 * set. Any key change — addition, rename, removal, or type shift — is a
 * protocol break and must instead introduce a new parallel primitive.
 *
 * This file pins the command half of the contract (the wire shape the
 * server accepts from any SDK). The persisted event-payload half is
 * pinned by `tests/Feature/V2/V2VersionWorkflowTest.php` end-to-end and
 * by the inline array literal at
 * `src/V2/Support/DefaultWorkflowTaskBridge.php::applyRecordVersionMarker()`.
 *
 * Extends `PHPUnit\Framework\TestCase` directly — WorkflowCommandNormalizer
 * is a static class with no Laravel-facade dependencies for the codepaths
 * exercised here. Keeping the test DB-free avoids the migration race that
 * Tests\TestCase walks into under concurrent dev-environment runs.
 */
final class VersionMarkerWireFormatTest extends TestCase
{
    /** @var list<string> */
    private const FROZEN_COMMAND_KEYS = [
        'type',
        'change_id',
        'version',
        'min_supported',
        'max_supported',
    ];

    /** @var list<string> */
    private const FROZEN_EVENT_PAYLOAD_KEYS = [
        'sequence',
        'change_id',
        'version',
        'min_supported',
        'max_supported',
    ];

    private mixed $priorFacadeApp = null;

    protected function setUp(): void
    {
        parent::setUp();

        // WorkflowCommandNormalizer invokes Validator::make() via the facade.
        // Provide the minimal Laravel Validation factory manually so the test
        // runs outside a full Laravel application bootstrap.
        $container = new IlluminateContainer();

        $translator = new Translator(new ArrayLoader(), 'en');
        $factory = new ValidationFactory($translator, $container);

        $container->instance('translator', $translator);
        $container->instance('validator', $factory);
        $container->instance('app', $container);

        // Save and replace the facade application so downstream tests in the
        // same process are not affected by our minimal container.
        $this->priorFacadeApp = \Illuminate\Support\Facades\Facade::getFacadeApplication();
        \Illuminate\Support\Facades\Facade::clearResolvedInstances();
        // @phpstan-ignore-next-line argument.type — Facade accepts any bound container at runtime.
        \Illuminate\Support\Facades\Facade::setFacadeApplication($container);
    }

    protected function tearDown(): void
    {
        \Illuminate\Support\Facades\Facade::clearResolvedInstances();
        \Illuminate\Support\Facades\Facade::setFacadeApplication($this->priorFacadeApp);
        $this->priorFacadeApp = null;

        parent::tearDown();
    }

    public function testNormalizedCommandKeySetIsFrozen(): void
    {
        $out = WorkflowCommandNormalizer::normalize([
            [
                'type' => 'record_version_marker',
                'change_id' => 'wire-contract',
                'version' => 2,
                'min_supported' => 1,
                'max_supported' => 3,
            ],
        ]);

        $this->assertCount(1, $out);
        $keys = array_keys($out[0]);
        sort($keys);
        $expected = self::FROZEN_COMMAND_KEYS;
        sort($expected);

        $this->assertSame(
            $expected,
            $keys,
            'record_version_marker command wire format has shifted — this is a protocol break. '
                .'If a new field is required, introduce a new command type (e.g. '
                .'record_version_marker_v2) and update docs/api-stability.md; do NOT extend '
                .'the existing shape.',
        );

        $this->assertSame('record_version_marker', $out[0]['type']);
        $this->assertSame('wire-contract', $out[0]['change_id']);
        $this->assertIsInt($out[0]['version']);
        $this->assertIsInt($out[0]['min_supported']);
        $this->assertIsInt($out[0]['max_supported']);
    }

    public function testNormalizerDropsUnknownFields(): void
    {
        // Extra keys on an inbound command must be dropped by the normalizer.
        // If that ever changes (e.g. the normalizer starts storing unknown
        // keys in the command), replayers on older SDK builds will start to
        // diverge. Lock the drop-on-unknown behaviour.
        $out = WorkflowCommandNormalizer::normalize([
            [
                'type' => 'record_version_marker',
                'change_id' => 'wire-contract',
                'version' => 2,
                'min_supported' => 1,
                'max_supported' => 3,
                // future SDKs might naively add fields; the server must not
                // promote them into the frozen wire shape.
                'payload_codec' => 'avro',
                'scope' => 'workflow',
            ],
        ]);

        $this->assertSame(self::FROZEN_COMMAND_KEYS, array_keys($out[0]));
    }

    public function testCommandValidationRequiresEveryFrozenField(): void
    {
        foreach (['change_id', 'version', 'min_supported', 'max_supported'] as $missing) {
            $command = [
                'type' => 'record_version_marker',
                'change_id' => 'required',
                'version' => 1,
                'min_supported' => 0,
                'max_supported' => 1,
            ];
            unset($command[$missing]);

            $this->expectExceptionOnce(
                fn () => WorkflowCommandNormalizer::normalize([$command]),
                sprintf(
                    'Expected normalization to fail when "%s" is missing — the frozen contract '
                        .'requires every field on every command.',
                    $missing,
                ),
            );
        }
    }

    private function expectExceptionOnce(callable $callable, string $message): void
    {
        try {
            $callable();
        } catch (ValidationException) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail($message);
    }

    public function testBridgeEmissionSiteStillUsesFrozenEventPayloadKeys(): void
    {
        // Source-level guard: the bridge emits VersionMarkerRecorded with an
        // inline array literal. Refactoring it to a helper or struct would
        // silently remove the frozen-key assertion expressed by this test.
        // Read the source, find the applyRecordVersionMarker() function, and
        // verify its payload literal still contains exactly the frozen keys.
        $source = file_get_contents(
            dirname(__DIR__, 3).'/src/V2/Support/DefaultWorkflowTaskBridge.php',
        );
        $this->assertIsString($source);

        // Extract the body between `applyRecordVersionMarker(...) { ... }` and
        // scan it for the `VersionMarkerRecorded` literal. Bracket-balanced
        // regex is brittle because `$command['change_id']` contains `[...]`;
        // work line-by-line instead.
        $functionStart = strpos($source, 'function applyRecordVersionMarker(');
        $this->assertNotFalse(
            $functionStart,
            'Could not locate applyRecordVersionMarker() — was it renamed? If so, update this contract test.',
        );

        $eventMarker = 'HistoryEventType::VersionMarkerRecorded';
        $eventOffset = strpos($source, $eventMarker, $functionStart);
        $this->assertNotFalse(
            $eventOffset,
            'applyRecordVersionMarker() no longer emits HistoryEventType::VersionMarkerRecorded. '
                .'This is a protocol break — see docs/api-stability.md#frozen-history-event-wire-formats.',
        );

        // Collect every `'key' =>` pair on the lines immediately after the
        // VersionMarkerRecorded marker, up to the closing `]` of the payload array.
        $tail = substr($source, $eventOffset, 400);
        preg_match_all("/'([a-z_]+)'\s*=>/", $tail, $matches);
        $foundKeys = $matches[1];

        sort($foundKeys);
        $expected = self::FROZEN_EVENT_PAYLOAD_KEYS;
        sort($expected);

        $this->assertSame(
            $expected,
            $foundKeys,
            'VersionMarkerRecorded payload keys in applyRecordVersionMarker() have shifted — '
                .'this is a protocol break. Old workflow rows still carry the old keys, and '
                .'replayers on other SDKs still read them by name. Introduce a parallel event '
                .'type (e.g. VersionMarkerRecordedV2) instead of changing this shape.',
        );
    }
}
