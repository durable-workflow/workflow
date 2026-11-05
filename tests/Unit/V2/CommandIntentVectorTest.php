<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Workflow\Serializers\AvroBinaryValue;
use Workflow\Serializers\AvroMapValue;
use Workflow\V2\Enums\CommandType;
use Workflow\V2\Support\CommandIntent;

final class CommandIntentVectorTest extends TestCase
{
    public function testPublishedSourceVectorsMatchTheExistingCanonicalImplementation(): void
    {
        $vectors = json_decode(
            file_get_contents(__DIR__ . '/../../../resources/protocol/command-intent-v1-vectors.json'),
            true,
            flags: JSON_THROW_ON_ERROR
        );
        $this->assertKeys(
            ['schema', 'value_cases', 'command_cases', 'native_argument_cases', 'digest_relations'],
            $vectors
        );
        $this->assertSame('durable-workflow.command-intent/v1', $vectors['schema']);
        $ids = [];
        foreach ($vectors['value_cases'] as $case) {
            $this->assertKeys(['id', 'value', 'canonical_base64', 'native_input'], $case);
            $this->assertNewId($case['id'], $ids);
            $value = $this->decodeValue($case['value']);
            $this->assertSame(
                $case['canonical_base64'],
                base64_encode(CommandIntent::canonical($value)),
                $case['id']
            );
            $native = match (true) {
                is_float($value) && ! is_finite($value) => 'nonfinite_refused',
                $value instanceof AvroMapValue && $value->pairs === [['__dw_intent', 'plain-digest']]
                    => 'declared_marker_not_plaintext_proof',
                default => 'finite_avro',
            };
            $this->assertSame($native, $case['native_input'], $case['id']);
        }

        $digests = [];
        foreach ($vectors['command_cases'] as $case) {
            $this->assertKeys(
                ['id', 'command_type', 'name', 'arguments', 'target_scope', 'requested_run_id', 'digest'],
                $case
            );
            $this->assertNewId($case['id'], $ids);
            $this->assertContains($case['command_type'], ['signal', 'update']);
            $this->assertContains($case['target_scope'], ['instance', 'run']);
            $this->assertTrue(is_string($case['name']));
            $this->assertTrue($case['requested_run_id'] === null || is_string($case['requested_run_id']));
            $this->assertTrue(array_is_list($case['arguments']));
            $actual = CommandIntent::digest(
                CommandType::from($case['command_type']),
                $case['name'],
                array_map($this->decodeValue(...), $case['arguments']),
                $case['target_scope'],
                $case['requested_run_id']
            );
            $this->assertSame($case['digest'], $actual, $case['id']);
            $digests[$case['id']] = $actual;
        }

        foreach ($vectors['digest_relations'] as $relation) {
            $this->assertKeys(['left', 'right', 'equal'], $relation);
            $this->assertArrayHasKey($relation['left'], $digests);
            $this->assertArrayHasKey($relation['right'], $digests);
            $this->assertIsBool($relation['equal']);
            $this->assertSame($relation['equal'], $digests[$relation['left']] === $digests[$relation['right']]);
        }

        // These assert the source mapping, not execution of a native adapter.
        foreach ($vectors['native_argument_cases'] as $case) {
            $this->assertKeys(['id', 'request_root', 'arguments', 'outcome'], $case);
            $this->assertNewId($case['id'], $ids);
            $root = $this->decodeValue($case['request_root']);
            $arguments = null;
            if (is_array($root) && array_is_list($root)) {
                $arguments = $root;
            } elseif ($root instanceof AvroMapValue && $root->pairs !== []) {
                $pairs = $root->pairs;
                usort($pairs, static fn (array $left, array $right): int => strcmp($left[0], $right[0]));
                $arguments = array_column($pairs, 1);
            }
            $this->assertSame($arguments === null ? 'refuse' : 'argument_list', $case['outcome']);
            $expected = $case['arguments'] === null
                ? null : array_map($this->decodeValue(...), $case['arguments']);
            $this->assertSame(CommandIntent::canonical($expected), CommandIntent::canonical($arguments), $case['id']);
        }
    }

    /**
     * @param array<string, mixed> $node
     */
    private function decodeValue(array $node): mixed
    {
        $keys = match ($node['type'] ?? null) {
            'null' => ['type'],
            'bool', 'int', 'string' => ['type', 'value'],
            'double' => ['type', 'bits'],
            'bytes' => ['type', 'base64'],
            'array' => ['type', 'items'],
            'map' => ['type', 'entries'],
            default => throw new InvalidArgumentException('Unknown typed fixture value.'),
        };
        $this->assertKeys($keys, $node);

        return match ($node['type']) {
            'null' => null,
            'bool' => $this->booleanValue($node['value']),
            'int' => $this->integerValue($node['value']),
            'string' => $this->stringValue($node['value']),
            'double' => $this->doubleValue($node['bits']),
            'bytes' => AvroBinaryValue::fromBytes($this->bytesValue($node['base64'])),
            'array' => array_map($this->decodeValue(...), $this->listValue($node['items'])),
            'map' => AvroMapValue::fromPairs(array_map(function (array $pair): array {
                $this->assertTrue(array_is_list($pair));
                $this->assertCount(2, $pair);

                return [$this->stringValue($pair[0]), $this->decodeValue($pair[1])];
            }, $this->listValue($node['entries']))),
        };
    }

    private function booleanValue(mixed $value): bool
    {
        $this->assertIsBool($value);

        return $value;
    }

    private function stringValue(mixed $value): string
    {
        $this->assertIsString($value);

        return $value;
    }

    private function integerValue(mixed $value): int
    {
        $this->assertIsString($value);
        $this->assertMatchesRegularExpression('/\A(?:0|-?[1-9][0-9]*)\z/', $value);
        $integer = filter_var($value, FILTER_VALIDATE_INT);
        $this->assertNotFalse($integer);

        return $integer;
    }

    private function doubleValue(mixed $bits): float
    {
        $this->assertIsString($bits);
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{16}\z/', $bits);

        return unpack('Evalue', hex2bin($bits))['value'];
    }

    private function bytesValue(mixed $encoded): string
    {
        $this->assertIsString($encoded);
        $bytes = base64_decode($encoded, true);
        $this->assertNotFalse($bytes);
        $this->assertSame($encoded, base64_encode($bytes));

        return $bytes;
    }

    /**
     * @return list<mixed>
     */
    private function listValue(mixed $value): array
    {
        $this->assertIsArray($value);
        $this->assertTrue(array_is_list($value));

        return $value;
    }

    /**
     * @param list<string> $expected
     * @param array<string, mixed> $value
     */
    private function assertKeys(array $expected, array $value): void
    {
        $actual = array_keys($value);
        sort($expected);
        sort($actual);
        $this->assertSame($expected, $actual);
    }

    /**
     * @param array<string, true> $seen
     */
    private function assertNewId(string $id, array &$seen): void
    {
        $this->assertMatchesRegularExpression('/\A[a-z][a-z0-9-]+\z/', $id);
        $this->assertArrayNotHasKey($id, $seen);
        $seen[$id] = true;
    }
}
