<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Support\CommandPayloadPreview;

final class CommandPayloadPreviewTest extends TestCase
{
    public function testAvailableRejectsNonStringOrEmptyBlobs(): void
    {
        $this->assertFalse(CommandPayloadPreview::available(null));
        $this->assertFalse(CommandPayloadPreview::available(''));
        $this->assertFalse(CommandPayloadPreview::available(['not', 'a', 'string']));
        $this->assertTrue(CommandPayloadPreview::available('{}'));
    }

    public function testPreviewWithCodecDecodesAvroBlob(): void
    {
        if (! class_exists(\Apache\Avro\Schema\AvroSchema::class)) {
            $this->markTestSkipped('apache/avro package is not installed in this environment.');
        }

        $blob = Serializer::serializeWithCodec('avro', [
            'name' => 'Taylor',
            'n' => 7,
        ]);

        $this->assertSame(
            [
                'name' => 'Taylor',
                'n' => 7,
            ],
            CommandPayloadPreview::previewWithCodec($blob, 'avro'),
        );
    }

    public function testPreviewWithCodecDecodesAvroWrappedBlob(): void
    {
        if (! class_exists(\Apache\Avro\Schema\AvroSchema::class)) {
            $this->markTestSkipped('apache/avro package is not installed in this environment.');
        }

        $payload = [
            'name' => 'Taylor',
            'count' => 3,
            'tags' => ['priority', 'vip'],
        ];
        $blob = Serializer::serializeWithCodec('avro', $payload);

        $this->assertSame($payload, CommandPayloadPreview::previewWithCodec($blob, 'avro'));
    }

    public function testPreviewWithCodecFallsBackToRawBlobOnCodecMismatch(): void
    {
        if (! class_exists(\Apache\Avro\Schema\AvroSchema::class)) {
            $this->markTestSkipped('apache/avro package is not installed in this environment.');
        }

        // Valid JSON bytes tagged as Avro — decode must fail safely and
        // return the raw blob instead of throwing. Strict mixed-codec
        // detection happens at ingress (PayloadEnvelopeResolver), not in
        // this display helper.
        $jsonBlob = '["hello"]';

        $this->assertSame($jsonBlob, CommandPayloadPreview::previewWithCodec($jsonBlob, 'avro'));
    }

    public function testPreviewWithCodecAcceptsLegacyCodecFqcnAliases(): void
    {
        $blob = Serializer::serializeWithCodec('workflow-serializer-y', ['a', 'b']);

        $this->assertSame(
            ['a', 'b'],
            CommandPayloadPreview::previewWithCodec($blob, \Workflow\Serializers\Y::class),
        );
    }

    public function testPreviewWithCodecFallsThroughToLegacySniffWhenCodecNull(): void
    {
        $jsonBlob = '{"legacy":true}';

        $this->assertSame([
            'legacy' => true,
        ], CommandPayloadPreview::previewWithCodec($jsonBlob, null),);
    }

    public function testPreviewWithCodecReturnsNullForEmptyOrNonStringInput(): void
    {
        $this->assertNull(CommandPayloadPreview::previewWithCodec(null, 'avro'));
        $this->assertNull(CommandPayloadPreview::previewWithCodec('', 'avro'));
        $this->assertNull(CommandPayloadPreview::previewWithCodec(['x'], 'avro'));
    }

    public function testPreviewWithCodecRendersAvroTypedRecordWhenSchemaContextIsSet(): void
    {
        if (! class_exists(\Apache\Avro\Schema\AvroSchema::class)) {
            $this->markTestSkipped('apache/avro package is not installed in this environment.');
        }

        $schemaJson = '{"type":"record","name":"OrderPayload","namespace":"durable_workflow.test","fields":['
            . '{"name":"order_id","type":"string"},'
            . '{"name":"amount","type":"double"},'
            . '{"name":"items_count","type":"int"}]}';

        $schema = \Workflow\Serializers\Avro::parseSchema($schemaJson);

        \Workflow\Serializers\Avro::withSchema($schema);
        $blob = Serializer::serializeWithCodec('avro', [
            'order_id' => 'ord-42',
            'amount' => 19.95,
            'items_count' => 3,
        ]);

        \Workflow\Serializers\Avro::withSchema($schema);
        $decoded = CommandPayloadPreview::previewWithCodec($blob, 'avro');

        $this->assertIsArray($decoded);
        $this->assertSame('ord-42', $decoded['order_id']);
        $this->assertSame(19.95, $decoded['amount']);
        $this->assertSame(3, $decoded['items_count']);
    }
}
