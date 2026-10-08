<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\NonDatabaseTestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Support\WorkflowCommandNormalizer;

final class ChildParallelMetadataTest extends NonDatabaseTestCase
{
    /**
     * @return iterable<string, array{array<string, mixed>, list<array<string, mixed>>, list<array<string, mixed>>}>
     */
    public static function childGroupPaths(): iterable
    {
        $child = self::childEntry();
        yield 'default all children' => [$child, [$child], [$child]];
        $explicitAll = [...$child, 'parallel_group_mode' => 'all'];
        yield 'explicit all children' => [$explicitAll, [$explicitAll], [$child]];
        $outer = [
            'parallel_group_id' => 'parallel-calls:10:3',
            'parallel_group_kind' => 'mixed',
            'parallel_group_base_sequence' => 10,
            'parallel_group_size' => 3,
            'parallel_group_index' => 1,
        ];
        yield 'nested mixed group' => [$child, [$outer, $child], [$outer, $child]];
        $selection = [
            'parallel_group_id' => 'select-calls:10:3',
            'parallel_group_kind' => 'mixed',
            'parallel_group_mode' => 'select',
            'parallel_group_base_sequence' => 10,
            'parallel_group_size' => 3,
            'parallel_group_index' => 1,
            'selection_member_key' => 'children',
            'selection_member_index' => 1,
            'selection_member_base_sequence' => 11,
            'selection_member_size' => 2,
            'selection_member_kind' => 'group',
        ];
        yield 'child group within a selection' => [$child, [$selection, $child], [$selection, $child]];
    }

    /**
     * @param array<string, mixed> $top
     * @param list<array<string, mixed>> $path
     * @param list<array<string, mixed>> $canonicalPath
     */
    #[DataProvider('childGroupPaths')]
    public function testChildAddressesCanonicalizeWithoutLosingPayloadsOrChangingTheCaller(
        array $top,
        array $path,
        array $canonicalPath,
    ): void {
        $arguments = [false, 0, '', ['order' => 'order-a']];
        $envelope = [
            'codec' => 'avro',
            'blob' => Serializer::serializeWithCodec('avro', $arguments),
        ];
        $commands = [[
            'type' => 'start_child_workflow',
            'workflow_type' => ' FulfilOrder ',
            'arguments' => $envelope,
            'transport_note' => [false, 0, ''],
            ...$top,
            'parallel_group_path' => $path,
        ]];
        foreach (['parallel_group_base_sequence', 'parallel_group_size', 'parallel_group_index'] as $field) {
            $commands[0][$field] = (string) $commands[0][$field];
        }
        foreach ($commands[0]['parallel_group_path'] as &$entry) {
            foreach (['parallel_group_base_sequence', 'parallel_group_size', 'parallel_group_index',
                'selection_member_index', 'selection_member_base_sequence', 'selection_member_size'] as $field) {
                if (array_key_exists($field, $entry)) {
                    $entry[$field] = (string) $entry[$field];
                }
            }
        }
        unset($entry);
        $before = $commands;
        $expectedPreflight = [[
            'type' => 'start_child_workflow',
            'workflow_type' => ' FulfilOrder ',
            'arguments' => $envelope,
            'transport_note' => [false, 0, ''],
            ...self::childEntry(),
            'parallel_group_path' => $canonicalPath,
        ]];
        $expected = [[
            'type' => 'start_child_workflow',
            'workflow_type' => 'FulfilOrder',
            'arguments' => $envelope['blob'],
            'payload_codec' => 'avro',
            ...self::childEntry(),
            'parallel_group_path' => $canonicalPath,
        ]];
        $preflight = WorkflowCommandNormalizer::preflightParallelMetadata($commands);
        $this->assertSame($expectedPreflight, $preflight);
        $this->assertSame($preflight, WorkflowCommandNormalizer::preflightParallelMetadata($preflight));
        $normalized = WorkflowCommandNormalizer::normalize($preflight);
        $this->assertSame($expected, $normalized);
        $this->assertSame($normalized, WorkflowCommandNormalizer::normalize($normalized));
        $this->assertSame($arguments, Serializer::unserializeWithCodec('avro', $normalized[0]['arguments']));
        $this->assertSame($before, $commands);
    }

    public function testChildGroupsRejectAnotherCommandFamilyPrefixInBothPublicPaths(): void
    {
        $entry = [...self::childEntry(), 'parallel_group_id' => 'parallel-timers:11:2'];
        $commands = [[
            'type' => 'start_child_workflow',
            'workflow_type' => 'FulfilOrder',
            ...$entry,
            'parallel_group_path' => [$entry],
        ]];
        $before = $commands;
        $expected = [
            'commands.0.parallel_group_path' => [
                'Parallel workflow commands require complete top-level metadata and a non-empty group path.',
            ],
        ];
        foreach ([true, false] as $preflight) {
            try {
                if ($preflight) {
                    WorkflowCommandNormalizer::preflightParallelMetadata($commands);
                } else {
                    WorkflowCommandNormalizer::normalize($commands);
                }
                $this->fail('A child group must retain the child command family identity.');
            } catch (ValidationException $exception) {
                $this->assertSame($expected, $exception->errors());
            }
        }
        $this->assertSame($before, $commands);
    }

    /**
     * @return array<string, mixed>
     */
    private static function childEntry(): array
    {
        return [
            'parallel_group_id' => 'parallel-children:11:2',
            'parallel_group_kind' => 'child',
            'parallel_group_base_sequence' => 11,
            'parallel_group_size' => 2,
            'parallel_group_index' => 0,
        ];
    }
}
