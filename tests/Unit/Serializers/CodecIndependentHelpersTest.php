<?php

declare(strict_types=1);

namespace Tests\Unit\Serializers;

use Exception;
use Tests\TestCase;
use Workflow\Serializers\Base64;
use Workflow\Serializers\Serializer;
use Workflow\Serializers\Y;

final class CodecIndependentHelpersTest extends TestCase
{
    /**
     * @dataProvider codecProvider
     */
    public function testSerializableReturnsTrueForScalarsRegardlessOfCodec(string $codec): void
    {
        config([
            'workflows.serializer' => $codec,
        ]);

        $this->assertTrue(Serializer::serializable('foo'));
        $this->assertTrue(Serializer::serializable(42));
        $this->assertTrue(Serializer::serializable([
            'a' => 1,
        ]));
        $this->assertTrue(Serializer::serializable(null));
    }

    /**
     * @dataProvider codecProvider
     */
    public function testSerializableReturnsFalseForClosureRegardlessOfCodec(string $codec): void
    {
        config([
            'workflows.serializer' => $codec,
        ]);

        $this->assertFalse(Serializer::serializable(static fn (): string => 'closure'));
    }

    /**
     * @dataProvider codecProvider
     */
    public function testSerializeModelsPassesThroughPlainArraysRegardlessOfCodec(string $codec): void
    {
        config([
            'workflows.serializer' => $codec,
        ]);

        $input = [
            'a' => 1,
            'b' => [
                'nested' => true,
            ],
        ];

        $this->assertSame($input, Serializer::serializeModels($input));
    }

    /**
     * @dataProvider codecProvider
     */
    public function testSerializeModelsConvertsThrowableToArrayRegardlessOfCodec(string $codec): void
    {
        config([
            'workflows.serializer' => $codec,
        ]);

        $throwable = new Exception('boom', 7);
        $data = Serializer::serializeModels($throwable);

        $this->assertIsArray($data);
        $this->assertSame(Exception::class, $data['class']);
        $this->assertSame('boom', $data['message']);
        $this->assertSame(7, $data['code']);
        $this->assertArrayHasKey('trace', $data);
        $this->assertIsArray($data['trace']);
    }

    /**
     * @dataProvider codecProvider
     */
    public function testUnserializeModelsIsIdentityForPlainArraysRegardlessOfCodec(string $codec): void
    {
        config([
            'workflows.serializer' => $codec,
        ]);

        $input = [
            'a' => 1,
            'b' => [
                'c' => 'x',
            ],
        ];

        $this->assertSame($input, Serializer::unserializeModels($input));
    }

    /**
     * @dataProvider languageNeutralCodecProvider
     */
    public function testSerializeThrowableUnderLanguageNeutralCodecPreservesDiagnosticData(string $codec): void
    {
        if ($codec === 'avro' && ! class_exists(\Apache\Avro\Schema\AvroSchema::class)) {
            $this->markTestSkipped('apache/avro package is not installed in this environment.');
        }

        // Use the explicit-codec round trip: the legacy __callStatic('unserialize')
        // sniffs the blob and cannot auto-detect binary Avro. Callers that start a
        // run with a specific codec always persist the codec name alongside the
        // blob and reopen through {@see Serializer::unserializeWithCodec()}.
        $throwable = new Exception('boom', 9);
        $serialized = Serializer::serializeWithCodec($codec, $throwable);
        $decoded = Serializer::unserializeWithCodec($codec, $serialized);

        $this->assertIsArray($decoded);
        $this->assertSame(Exception::class, $decoded['class']);
        $this->assertSame('boom', $decoded['message']);
        $this->assertSame(9, $decoded['code']);
        $this->assertArrayHasKey('trace', $decoded);
        $this->assertIsArray($decoded['trace']);
    }

    public static function languageNeutralCodecProvider(): array
    {
        return [
            'avro' => ['avro'],
        ];
    }

    public function testLegacyCodecsRoundTripBytesThroughEncodeDecode(): void
    {
        foreach ([Y::class, Base64::class] as $codec) {
            config([
                'workflows.serializer' => $codec,
            ]);

            $bytes = "\x00\x01binary" . random_bytes(32);
            $roundTripped = Serializer::decode(Serializer::encode($bytes));

            $this->assertSame($bytes, $roundTripped, "Codec {$codec} must round-trip raw bytes");
        }
    }

    public static function codecProvider(): array
    {
        return [
            'avro' => ['avro'],
            'Y' => [Y::class],
            'Base64' => [Base64::class],
        ];
    }
}
