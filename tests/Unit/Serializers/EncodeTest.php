<?php

declare(strict_types=1);

namespace Tests\Unit\Serializers;

use Tests\NonDatabaseTestCase;
use Workflow\Serializers\Base64;
use Workflow\Serializers\Serializer;
use Workflow\Serializers\Y;

final class EncodeTest extends NonDatabaseTestCase
{
    #[\PHPUnit\Framework\Attributes\DataProvider('dataProvider')]
    public function testYEncode(string $bytes): void
    {
        config([
            'workflows.serializer' => Y::class,
        ]);
        $decoded = Serializer::decode(Serializer::encode($bytes));
        $this->assertSame($bytes, $decoded);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('dataProvider')]
    public function testBase64Encode(string $bytes): void
    {
        config([
            'workflows.serializer' => Base64::class,
        ]);
        $decoded = Serializer::decode(Serializer::encode($bytes));
        $this->assertSame($bytes, $decoded);
    }

    public static function dataProvider(): array
    {
        return [
            'empty' => [''],
            'foo' => ['foo'],
            'bytes' => [random_bytes(4096)],
            'bytes x2' => [random_bytes(8192)],
            'null' => [chr(0)],
            'null x2' => [chr(0) . chr(0)],
            'escape x2' => [chr(1) . chr(1)],
            'null escape' => [chr(0) . chr(1)],
            'escape next' => [chr(1) . chr(2)],
            'null escape x2' => [chr(0) . chr(1) . chr(0) . chr(1)],
            'escape next x2' => [chr(1) . chr(2) . chr(1) . chr(2)],
            'escape null escape next' => [chr(1) . chr(0) . chr(1) . chr(2)],
            'next escape null escape' => [chr(2) . chr(1) . chr(0) . chr(1)],
        ];
    }
}
