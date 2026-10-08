<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\NonDatabaseTestCase;
use Workflow\V2\Support\WorkflowCommandNormalizer;

final class ParallelMetadataValidationTest extends NonDatabaseTestCase
{
    private const INCOMPLETE = 'Parallel workflow commands require complete top-level metadata and a non-empty group path.';

    private const INVALID_ENTRY = 'Each parallel group path entry must contain valid identity, kind, base, size, and index fields.';

    private const MISMATCH = 'The innermost parallel group path entry must match the top-level parallel metadata.';

    public function testPreflightCanonicalizesNestedAddressesBeforeFullNormalizationWithoutChangingPayloads(): void
    {
        $outer = [
            'parallel_group_id' => 'select-calls:10:3',
            'parallel_group_kind' => 'mixed',
            'parallel_group_mode' => 'select',
            'parallel_group_base_sequence' => 10,
            'parallel_group_size' => 3,
            'parallel_group_index' => 0,
            'selection_member_key' => 0,
            'selection_member_index' => 0,
            'selection_member_base_sequence' => 10,
            'selection_member_size' => 2,
            'selection_member_kind' => 'group',
        ];
        $inner = [
            'parallel_group_id' => 'parallel-timers:10:2',
            'parallel_group_kind' => 'timer',
            'parallel_group_base_sequence' => 10,
            'parallel_group_size' => 2,
            'parallel_group_index' => 0,
        ];
        $canonical = [[
            'type' => 'start_timer',
            'delay_seconds' => 2,
            ...$inner,
            'parallel_group_path' => [$outer, $inner],
        ]];
        $input = $canonical;
        foreach (['parallel_group_base_sequence', 'parallel_group_size', 'parallel_group_index'] as $field) {
            $input[0][$field] = (string) $input[0][$field];
        }
        foreach ($input[0]['parallel_group_path'] as &$entry) {
            foreach (['parallel_group_base_sequence', 'parallel_group_size', 'parallel_group_index',
                'selection_member_index', 'selection_member_base_sequence', 'selection_member_size'] as $field) {
                if (array_key_exists($field, $entry)) {
                    $entry[$field] = (string) $entry[$field];
                }
            }
        }
        unset($entry);
        $input[0]['transport_note'] = [false, 0, ''];
        $before = $input;
        $preflight = WorkflowCommandNormalizer::preflightParallelMetadata($input);
        $this->assertSame($this->sortCommandMaps([[
            ...$canonical[0],
            'transport_note' => [false, 0, ''],
        ]]), $this->sortCommandMaps($preflight));
        $this->assertSame($preflight, WorkflowCommandNormalizer::preflightParallelMetadata($preflight));
        $this->assertSame($canonical, WorkflowCommandNormalizer::normalize($preflight));
        $this->assertSame($before, $input);
    }

    /**
     * @return iterable<string, array{mixed, string, string}>
     */
    public static function invalidPaths(): iterable
    {
        yield 'empty path' => [[], 'parallel_group_path', self::INCOMPLETE];
        yield 'string path' => ['group', 'parallel_group_path', self::INCOMPLETE];
        yield 'associative path' => [[
            'group' => self::timerEntry(),
        ], 'parallel_group_path', self::INCOMPLETE];
        yield 'non-array nested entry' => [[false], 'parallel_group_path.0', self::INVALID_ENTRY];
        yield 'incomplete nested entry' => [[[
            'parallel_group_id' => 'parallel-timers:10:3',
        ]], 'parallel_group_path.0', self::INVALID_ENTRY];
        yield 'different innermost index' => [[[
            ...self::timerEntry(),
            'parallel_group_index' => 2,
        ]], 'parallel_group_path', self::MISMATCH];
    }

    #[DataProvider('invalidPaths')]
    public function testMalformedPathsHaveTheSameIndexedDiagnosticInPreflightAndNormalization(
        mixed $path,
        string $field,
        string $message,
    ): void {
        $commands = [[
            'type' => 'start_timer',
            'delay_seconds' => 2,
            ...self::timerEntry(),
            'parallel_group_path' => $path,
        ]];
        $before = $commands;
        $expected = [
            "commands.0.{$field}" => [$message],
        ];
        $this->assertSame($expected, $this->errors($commands, true));
        $this->assertSame($expected, $this->errors($commands, false));
        $this->assertSame($expected, $this->errors($commands, true));
        $this->assertSame($before, $commands);
    }

    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function invalidSelectionMembers(): iterable
    {
        foreach ([
            'selection_member_key' => ['', -1, false, []],
            'selection_member_index' => [-1, '1.5', false],
            'selection_member_base_sequence' => [9, 13, false],
            'selection_member_size' => [0, 4, []],
            'selection_member_kind' => ['unsupported', false, []],
        ] as $field => $values) {
            foreach ($values as $index => $value) {
                yield "{$field} case {$index}" => [$field, $value];
            }
        }
    }

    #[DataProvider('invalidSelectionMembers')]
    public function testSelectionAddressesRefuseInvalidKeysTypesAndRanges(string $field, mixed $value): void
    {
        $entry = [
            ...self::timerEntry(),
            'parallel_group_id' => 'select-calls:10:3',
            'parallel_group_mode' => 'select',
            'selection_member_key' => 'timer',
            'selection_member_index' => 0,
            'selection_member_base_sequence' => 10,
            'selection_member_size' => 1,
            'selection_member_kind' => 'timer',
            $field => $value,
        ];
        $commands = [[
            'type' => 'start_timer',
            'delay_seconds' => 2,
            ...$entry,
            'parallel_group_path' => [$entry],
        ]];
        $before = $commands;
        $expected = [
            'commands.0.parallel_group_path' => [self::INCOMPLETE],
        ];
        $this->assertSame($expected, $this->errors($commands, true));
        $this->assertSame($expected, $this->errors($commands, false));
        $this->assertSame($before, $commands);
    }

    public function testUnrelatedCommandsRefuseEveryParallelFieldButAllowAbsentMetadata(): void
    {
        $commands = [[
            'type' => 'complete_workflow',
            ...self::timerEntry(),
            'parallel_group_mode' => 'select',
            'selection_member_key' => 'timer',
            'selection_member_index' => 0,
            'selection_member_base_sequence' => 10,
            'selection_member_size' => 1,
            'selection_member_kind' => 'timer',
            'parallel_group_path' => [self::timerEntry()],
        ]];
        $before = $commands;
        $expected = [];
        foreach (array_keys($commands[0]) as $field) {
            if ($field !== 'type') {
                $expected["commands.0.{$field}"] = [
                    "{$field} is only supported for activity, timer, and child-workflow scheduling commands.",
                ];
            }
        }
        $this->assertSame(
            $this->sortCommandMaps([$expected]),
            $this->sortCommandMaps([$this->errors($commands, true)])
        );
        $this->assertSame(
            $this->sortCommandMaps([$expected]),
            $this->sortCommandMaps([$this->errors($commands, false)])
        );
        $this->assertSame($before, $commands);
        foreach (array_keys($commands[0]) as $field) {
            if ($field !== 'type') {
                $commands[0][$field] = null;
            }
        }
        $this->assertSame($commands, WorkflowCommandNormalizer::preflightParallelMetadata($commands));
        $this->assertSame([[
            'type' => 'complete_workflow',
        ]], WorkflowCommandNormalizer::normalize($commands));
    }

    public function testIndependentPathFailuresRejectTheWholeBatchAndKeepTheirOriginalAddresses(): void
    {
        $valid = [
            'type' => 'start_timer',
            'delay_seconds' => 2,
            ...self::timerEntry(),
            'parallel_group_path' => [self::timerEntry()],
        ];
        $commands = [$valid, [
            ...$valid,
            'parallel_group_path' => [self::timerEntry(), false],
        ], [
            ...$valid,
            'parallel_group_path' => [[
                ...self::timerEntry(),
                'parallel_group_index' => 2,
            ]],
        ]];
        $before = $commands;
        $expected = [
            'commands.1.parallel_group_path.1' => [self::INVALID_ENTRY],
            'commands.2.parallel_group_path' => [self::MISMATCH],
        ];
        $this->assertSame($expected, $this->errors($commands, true));
        $this->assertSame($expected, $this->errors($commands, false));
        $this->assertSame($expected, $this->errors($commands, false));
        $this->assertSame($before, $commands);
    }

    public function testPreflightLeavesMissingCommandTypesForFullCommandValidation(): void
    {
        $commands = [[
            'unrelated_payload' => [false, 0, ''],
        ], [
            'type' => false,
        ]];
        $this->assertSame($commands, WorkflowCommandNormalizer::preflightParallelMetadata($commands));
        $this->assertSame([
            'commands.0.type' => ['Each command must declare a supported type.'],
            'commands.1.type' => ['Each command must declare a supported type.'],
        ], $this->errors($commands, false));
    }

    /**
     * @return array<string, mixed>
     */
    private static function timerEntry(): array
    {
        return [
            'parallel_group_id' => 'parallel-timers:10:3',
            'parallel_group_kind' => 'timer',
            'parallel_group_base_sequence' => 10,
            'parallel_group_size' => 3,
            'parallel_group_index' => 1,
        ];
    }

    /**
     * @param list<array<string, mixed>> $commands
     * @return array<string, list<string>>
     */
    private function errors(array $commands, bool $preflight): array
    {
        try {
            if ($preflight) {
                WorkflowCommandNormalizer::preflightParallelMetadata($commands);
            } else {
                WorkflowCommandNormalizer::normalize($commands);
            }
        } catch (ValidationException $exception) {
            return $exception->errors();
        }
        $this->fail('Malformed parallel metadata must reject the command batch.');
    }

    /**
     * @param list<array<string, mixed>> $commands
     * @return list<array<string, mixed>>
     */
    private function sortCommandMaps(array $commands): array
    {
        foreach ($commands as &$command) {
            ksort($command);
        }
        unset($command);

        return $commands;
    }
}
