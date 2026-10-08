<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Workflow\V2\Support\CompiledWorkflowDefinition;
use Workflow\V2\Support\ServerlessWorkflowCompiler;

final class ServerlessWorkflowReferenceFallbackTest extends TestCase
{
    #[DataProvider('emptyReferences')]
    public function testUnresolvedOptionalAliasesKeepTerminalStateAndStableAnonymousActions(
        array $transition,
        array $function,
        array $event,
    ): void {
        $document = [
            'id' => 'optional-references',
            'version' => '1.0.0',
            'states' => [
                'Finish' => [
                    'type' => 'operation',
                    'end' => true,
                    'transition' => $transition,
                    'actions' => [
                        [
                            'functionRef' => $function,
                        ],
                        [
                            'eventRef' => $event,
                        ],
                    ],
                ],
            ],
        ];
        $original = $document;
        $compiled = ServerlessWorkflowCompiler::compile($document);
        CompiledWorkflowDefinition::assertValid($compiled);
        self::assertCount(3, $compiled['steps']);
        [$state, $functionAction, $eventAction] = $compiled['steps'];
        self::assertSame('Finish', $state['name']);
        self::assertSame($state['id'], $compiled['entrypoint_step_id']);
        self::assertTrue($state['end']);
        self::assertNull($state['transition']);
        self::assertNull($state['transition_step_id']);
        self::assertCount(3, array_unique(array_column($compiled['steps'], 'id')));
        foreach ([$functionAction, $eventAction] as $position => $action) {
            self::assertSame('workflow.action', $action['kind']);
            self::assertMatchesRegularExpression('/^action-[0-9a-f]{12}$/', $action['name']);
            self::assertSame($state['id'], $action['state_step_id']);
            self::assertNull($action['function_ref']);
            self::assertNull($action['event_ref']);
            self::assertSame('states.Finish.actions.' . $position, $action['source_path']);
        }
        $reordered = $document;
        $reordered['states']['Finish']['transition'] = array_reverse($transition, true);
        $reordered['states']['Finish']['actions'][0]['functionRef'] = array_reverse($function, true);
        $reordered['states']['Finish']['actions'][1]['eventRef'] = array_reverse($event, true);
        self::assertSame($compiled, ServerlessWorkflowCompiler::compile($reordered));
        self::assertSame($compiled, ServerlessWorkflowCompiler::compile($document));
        self::assertSame($original, $document);
    }

    public static function emptyReferences(): iterable
    {
        yield 'no recognized aliases' => [[], [], []];
        yield 'null aliases' => [
            [
                'nextState' => null,
                'state' => null,
                'target' => null,
            ],
            [
                'refName' => null,
                'name' => null,
                'function' => null,
            ],
            [
                'refName' => null,
                'name' => null,
                'event' => null,
            ],
        ];
        yield 'blank aliases' => [
            [
                'nextState' => ' ',
                'state' => '',
                'target' => ' ',
            ],
            [
                'refName' => ' ',
                'name' => '',
                'function' => ' ',
            ],
            [
                'refName' => ' ',
                'name' => '',
                'event' => ' ',
            ],
        ];
        yield 'non-string aliases' => [
            [
                'nextState' => 7,
                'state' => false,
                'target' => [],
            ],
            [
                'refName' => 7,
                'name' => false,
                'function' => [],
            ],
            [
                'refName' => 7,
                'name' => false,
                'event' => [],
            ],
        ];
    }
}
