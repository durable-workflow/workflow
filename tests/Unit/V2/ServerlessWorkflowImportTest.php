<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Workflow\V2\Support\CompiledWorkflowDefinition;
use Workflow\V2\Support\ServerlessWorkflowCompiler;

final class ServerlessWorkflowImportTest extends TestCase
{
    #[DataProvider('identities')]
    public function testImportResolvesIdentityAndVersionWithoutChangingSource(
        array $identity,
        ?string $override,
        string $type,
        string $version,
    ): void {
        $document = [
            ...$identity,
            'states' => [[
                'name' => 'Finish',
                'type' => 'operation',
                'end' => true,
            ]],
        ];
        $original = $document;
        $compiled = ServerlessWorkflowCompiler::compile($document, $override);
        CompiledWorkflowDefinition::assertValid($compiled);
        self::assertSame('durable-workflow.v2.compiled-workflow-ir', $compiled['schema']);
        self::assertSame(1, $compiled['schema_version']);
        self::assertSame($type, $compiled['workflow_type']);
        self::assertSame($version, $compiled['definition_version']);
        self::assertSame('serverless_workflow', $compiled['source']['format']);
        self::assertSame([
            'strategy' => 'single_definition',
            'selected_version' => $version,
            'available_versions' => [$version],
        ], $compiled['version_selection']);
        self::assertCount(1, $compiled['steps']);
        self::assertSame($compiled['steps'][0]['id'], $compiled['entrypoint_step_id']);
        self::assertTrue($compiled['steps'][0]['end']);
        self::assertSame('states.0', $compiled['steps'][0]['source_path']);
        self::assertMatchesRegularExpression('/^sha256:[0-9a-f]{64}$/', $compiled['source']['document_fingerprint']);
        self::assertMatchesRegularExpression('/^sha256:[0-9a-f]{64}$/', $compiled['definition_fingerprint']);
        self::assertSame($compiled, ServerlessWorkflowCompiler::compile($document, $override));
        self::assertSame($original, $document);
    }

    public static function identities(): iterable
    {
        yield 'name-only import defaults to unversioned' => [[
            'name' => ' named.workflow ',
        ], null, 'named.workflow', 'unversioned'];
        yield 'blank id falls back to name' => [[
            'id' => ' ',
            'name' => 'fallback.workflow',
        ], null, 'fallback.workflow', 'unversioned'];
        yield 'non-string id and version use supported fallbacks' => [
            [
                'id' => 12,
                'name' => 'fallback.workflow',
                'version' => 9,
            ], null, 'fallback.workflow', 'unversioned',
        ];
        yield 'explicit version overrides document version' => [
            [
                'id' => ' primary.workflow ',
                'name' => 'secondary.workflow',
                'version' => '1.0.0',
            ],
            ' 2.0.0 ', 'primary.workflow', '2.0.0',
        ];
        yield 'blank override retains trimmed document version' => [
            [
                'id' => 'workflow',
                'version' => ' 1.2.0 ',
            ], ' ', 'workflow', '1.2.0',
        ];
    }

    #[DataProvider('mappedStarts')]
    public function testMappedStatesResolveStartAndTransitionsWithStableRelationships(
        mixed $start,
        mixed $transition,
    ): void {
        $document = [
            'id' => 'mapped.workflow',
            'start' => $start,
            'states' => [
                'Initial' => [
                    'type' => 'operation',
                    'transition' => $transition,
                ],
                'Final' => [
                    'type' => 'operation',
                    'end' => true,
                ],
            ],
        ];
        $original = $document;
        $compiled = ServerlessWorkflowCompiler::compile($document);
        CompiledWorkflowDefinition::assertValid($compiled);
        self::assertSame(['Final', 'Initial'], array_column($compiled['steps'], 'name'));
        [$final, $initial] = $compiled['steps'];
        self::assertSame($initial['id'], $compiled['entrypoint_step_id']);
        self::assertSame('Final', $initial['transition']);
        self::assertSame($final['id'], $initial['transition_step_id']);
        self::assertSame('states.Initial', $initial['source_path']);
        self::assertSame('states.Final', $final['source_path']);
        self::assertFalse($initial['end']);
        self::assertTrue($final['end']);
        self::assertNull($final['transition']);
        self::assertNull($final['transition_step_id']);
        $reordered = $document;
        $reordered['states'] = array_reverse($document['states'], true);
        $again = ServerlessWorkflowCompiler::compile($reordered);
        self::assertSame($compiled, $again);
        self::assertSame($original, $document);
    }

    public static function mappedStarts(): iterable
    {
        yield 'trimmed string names' => [' Initial ', ' Final '];
        yield 'stateName and nextState aliases' => [[
            'stateName' => ' Initial ',
        ], [
            'nextState' => ' Final ',
        ]];
        yield 'state and state aliases' => [[
            'state' => 'Initial',
        ], [
            'state' => 'Final',
        ]];
        yield 'name and target aliases skip blank alternatives' => [
            [
                'stateName' => ' ',
                'state' => null,
                'name' => 'Initial',
            ],
            [
                'nextState' => ' ',
                'state' => null,
                'target' => 'Final',
            ],
        ];
    }

    public function testDefaultStartUsesOriginalFirstStateBeforeCanonicalSorting(): void
    {
        $document = [
            'id' => 'default-start',
            'states' => [
                [
                    'name' => 'Zebra',
                    'type' => 'operation',
                    'transition' => 'Alpha',
                ],
                [
                    'name' => 'Alpha',
                    'type' => 'operation',
                    'end' => true,
                ],
            ],
        ];
        $compiled = ServerlessWorkflowCompiler::compile($document);
        self::assertSame(['Alpha', 'Zebra'], array_column($compiled['steps'], 'name'));
        self::assertSame($compiled['steps'][1]['id'], $compiled['entrypoint_step_id']);
        self::assertSame($compiled['steps'][0]['id'], $compiled['steps'][1]['transition_step_id']);
        self::assertSame('states.0', $compiled['steps'][1]['source_path']);
        self::assertSame('states.1', $compiled['steps'][0]['source_path']);
    }

    #[DataProvider('actionReferences')]
    public function testSingleActionObjectInfersNamesFromPortableReferences(
        string $field,
        mixed $reference,
        string $expected,
    ): void {
        $document = [
            'id' => 'action-reference',
            'states' => [
                'Start' => [
                    'type' => 'operation',
                    'actions' => [
                        $field => $reference,
                    ],
                    'end' => true,
                ],
            ],
        ];
        $original = $document;
        $compiled = ServerlessWorkflowCompiler::compile($document);
        CompiledWorkflowDefinition::assertValid($compiled);
        self::assertCount(2, $compiled['steps']);
        [$state, $action] = $compiled['steps'];
        self::assertSame('workflow.action', $action['kind']);
        self::assertSame($expected, $action['name']);
        self::assertSame($state['id'], $action['state_step_id']);
        self::assertSame($state['id'], $compiled['entrypoint_step_id']);
        self::assertSame($field === 'functionRef' ? $expected : null, $action['function_ref']);
        self::assertSame($field === 'eventRef' ? $expected : null, $action['event_ref']);
        self::assertSame('states.Start.actions.0', $action['source_path']);
        self::assertSame($compiled, ServerlessWorkflowCompiler::compile($document));
        self::assertSame($original, $document);
    }

    public static function actionReferences(): iterable
    {
        yield 'function string' => ['functionRef', ' reserve ', 'reserve'];
        yield 'function refName' => ['functionRef', [
            'refName' => 'reserve',
        ], 'reserve'];
        yield 'function name' => ['functionRef', [
            'name' => 'reserve',
        ], 'reserve'];
        yield 'function alias skips empty candidates' => [
            'functionRef', [
                'refName' => ' ',
                'name' => null,
                'function' => 'reserve',
            ], 'reserve',
        ];
        yield 'event string' => ['eventRef', ' approved ', 'approved'];
        yield 'event refName' => ['eventRef', [
            'refName' => 'approved',
        ], 'approved'];
        yield 'event name' => ['eventRef', [
            'name' => 'approved',
        ], 'approved'];
        yield 'event alias skips empty candidates' => [
            'eventRef', [
                'refName' => ' ',
                'name' => null,
                'event' => 'approved',
            ], 'approved',
        ];
    }

    public function testUnnamedActionIdentityIsStableAcrossObjectKeyOrderAndExplicitNameWins(): void
    {
        $document = [
            'id' => 'actions',
            'states' => [[
                'name' => 'Start',
                'type' => 'operation',
                'end' => true,
                'actions' => [
                    [
                        'sleep' => [
                            'after' => 'PT1S',
                        ],
                        'metadata' => [
                            'b' => 2,
                            'a' => 1,
                        ],
                    ],
                    [
                        'name' => ' explicit ',
                        'functionRef' => 'reserve',
                        'eventRef' => 'approved',
                    ],
                ],
            ]],
        ];
        $compiled = ServerlessWorkflowCompiler::compile($document);
        self::assertCount(3, $compiled['steps']);
        [, $unnamed, $named] = $compiled['steps'];
        self::assertMatchesRegularExpression('/^action-[0-9a-f]{12}$/', $unnamed['name']);
        self::assertNull($unnamed['function_ref']);
        self::assertNull($unnamed['event_ref']);
        self::assertSame('explicit', $named['name']);
        self::assertSame('reserve', $named['function_ref']);
        self::assertSame('approved', $named['event_ref']);
        self::assertSame($compiled['steps'][0]['id'], $unnamed['state_step_id']);
        self::assertSame($unnamed['state_step_id'], $named['state_step_id']);
        $reordered = $document;
        $reordered['states'][0]['actions'][0] = [
            'metadata' => [
                'a' => 1,
                'b' => 2,
            ],
            'sleep' => [
                'after' => 'PT1S',
            ],
        ];
        self::assertSame($compiled, ServerlessWorkflowCompiler::compile($reordered));
        $changed = $document;
        $changed['states'][0]['actions'][0]['sleep']['after'] = 'PT2S';
        $changedCompiled = ServerlessWorkflowCompiler::compile($changed);
        self::assertNotSame($unnamed['id'], $changedCompiled['steps'][1]['id']);
        self::assertSame($named['id'], $changedCompiled['steps'][2]['id']);
    }

    #[DataProvider('invalidDocuments')]
    public function testMalformedDocumentFailsWithExactDiagnosticAndPreservesInput(
        array $document,
        string $message
    ): void {
        $original = $document;
        try {
            ServerlessWorkflowCompiler::compile($document);
            self::fail('Malformed import must be rejected.');
        } catch (LogicException $exception) {
            self::assertSame($message, $exception->getMessage());
        }
        self::assertSame($original, $document);
    }

    public static function invalidDocuments(): iterable
    {
        yield 'missing identity' => [[], 'Serverless Workflow imports require a non-empty top-level id or name.'];
        yield 'blank identities' => [[
            'id' => ' ',
            'name' => ' ',
        ], 'Serverless Workflow imports require a non-empty top-level id or name.'];
        foreach ([null, [], 'states'] as $states) {
            yield 'invalid states ' . get_debug_type($states) => [
                [
                    'id' => 'workflow',
                    'states' => $states,
                ], 'Serverless Workflow imports require a non-empty states collection.',
            ];
        }
        yield 'non-object list state' => [
            [
                'id' => 'workflow',
                'states' => ['bad'],
            ], 'Serverless Workflow states must be objects.',
        ];
        yield 'missing list state name' => [
            [
                'id' => 'workflow',
                'states' => [[]],
            ], 'Serverless Workflow state at index [0] is missing a non-empty name.',
        ];
        yield 'non-object mapped state' => [
            [
                'id' => 'workflow',
                'states' => [
                    'Start' => 'bad',
                ],
            ], 'Serverless Workflow states must be objects.',
        ];
        yield 'mapped numeric key without a name' => [
            [
                'id' => 'workflow',
                'states' => [
                    1 => [],
                ],
            ], 'Serverless Workflow mapped states require string keys or names.',
        ];
        yield 'duplicate mapped names' => [
            [
                'id' => 'workflow',
                'states' => [
                    'A' => [
                        'name' => 'same',
                    ],
                    'B' => [
                        'name' => 'same',
                    ],
                ],
            ],
            'Serverless Workflow state [same] is duplicated.',
        ];
        yield 'unknown starting state' => [
            [
                'id' => 'workflow',
                'start' => 'Missing',
                'states' => [[
                    'name' => 'Start',
                ]],
            ],
            'Serverless Workflow start state [Missing] does not match an imported state.',
        ];
        yield 'unknown transition state' => [
            [
                'id' => 'workflow',
                'states' => [[
                    'name' => 'Start',
                    'transition' => [
                        'nextState' => 'Missing',
                    ],
                ]],
            ],
            'Serverless Workflow state [Start] transitions to unknown state [Missing].',
        ];
        foreach ([[], 'bad'] as $actions) {
            yield 'invalid actions ' . get_debug_type($actions) => [
                [
                    'id' => 'workflow',
                    'states' => [[
                        'name' => 'Start',
                        'actions' => $actions,
                    ]],
                ],
                'Serverless Workflow actions must be an object or non-empty list.',
            ];
        }
        yield 'non-object action list entry' => [
            [
                'id' => 'workflow',
                'states' => [[
                    'name' => 'Start',
                    'actions' => ['bad'],
                ]],
            ],
            'Serverless Workflow actions must be objects.',
        ];
    }

    public function testVersionSelectionRejectsNonObjectDocumentsFromAnIterable(): void
    {
        $documents = (static function (): iterable {
            yield [
                'id' => 'workflow',
                'version' => '1.0.0',
                'states' => [[
                    'name' => 'Start',
                    'end' => true,
                ]],
            ];
            yield 'not-an-object';
        })();
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Serverless Workflow version sets may only contain JSON objects.');
        ServerlessWorkflowCompiler::compileSelected($documents);
    }
}
