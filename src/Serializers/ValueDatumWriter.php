<?php

declare(strict_types=1);

namespace Workflow\Serializers;

use Apache\Avro\Datum\AvroIOBinaryEncoder;
use Apache\Avro\Datum\AvroIODatumWriter;
use Apache\Avro\Schema\AvroSchema;
use InvalidArgumentException;

/**
 * Selects fixed Value branches without collapsing PHP map keys.
 *
 * Apache's PHP binding represents maps as native arrays. PHP converts
 * numeric-looking string keys to integers before the generic writer can
 * validate them. The private binding schema represents a map as an array of
 * key/value records, which has the same Avro binary layout as a map while
 * keeping the key in a string value. Apache's datum writer still performs all
 * validation and binary encoding.
 */
final class ValueDatumWriter
{
    private const BINDING_SCHEMA_JSON = '{"type":"record","name":"Value","namespace":"durable_workflow.protocol","fields":[{"name":"value","type":["null",{"type":"record","name":"BooleanValue","fields":[{"name":"boolean","type":"boolean"}]},{"type":"record","name":"LongValue","fields":[{"name":"long","type":"long"}]},{"type":"record","name":"DoubleValue","fields":[{"name":"double","type":"double"}]},{"type":"record","name":"BytesValue","fields":[{"name":"bytes","type":"bytes"}]},{"type":"record","name":"StringValue","fields":[{"name":"string","type":"string"}]},{"type":"record","name":"ArrayValue","fields":[{"name":"items","type":{"type":"array","items":"Value"}}]},{"type":"record","name":"MapValue","fields":[{"name":"entries","type":{"type":"array","items":{"type":"record","name":"MapEntry","fields":[{"name":"key","type":"string"},{"name":"value","type":"Value"}]}}}]}]}]}';

    private static ?AvroSchema $bindingSchema = null;

    /**
     * @param array{value: mixed} $datum
     */
    public function write(array $datum, AvroIOBinaryEncoder $encoder): void
    {
        (new AvroIODatumWriter(self::bindingSchema()))
            ->write($this->toBindingDatum($datum), $encoder);
    }

    /**
     * @param array{value: mixed} $datum
     * @return array{value: mixed}
     */
    private function toBindingDatum(array $datum): array
    {
        $branch = $datum['value'];
        if ($branch === null) {
            return $datum;
        }
        if (! is_array($branch)) {
            throw new InvalidArgumentException('Invalid Avro Value union branch.');
        }

        foreach (['boolean', 'long', 'double', 'bytes', 'string'] as $field) {
            if (array_key_exists($field, $branch)) {
                return $datum;
            }
        }

        if (array_key_exists('items', $branch) && is_array($branch['items'])) {
            return [
                'value' => [
                    'items' => array_map(
                        fn (array $item): array => $this->toBindingDatum($item),
                        $branch['items'],
                    ),
                ],
            ];
        }
        if (array_key_exists('entries', $branch) && $branch['entries'] instanceof AvroMapValue) {
            return [
                'value' => [
                    'entries' => array_map(
                        fn (array $pair): array => [
                            'key' => $pair[0],
                            'value' => $this->toBindingDatum($pair[1]),
                        ],
                        $branch['entries']->pairs,
                    ),
                ],
            ];
        }

        throw new InvalidArgumentException('Invalid Avro Value union branch.');
    }

    private static function bindingSchema(): AvroSchema
    {
        return self::$bindingSchema ??= Avro::parseSchema(self::BINDING_SCHEMA_JSON);
    }
}
