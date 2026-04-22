<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use InvalidArgumentException;
use Workflow\Serializers\CodecRegistry;

final class ExternalPayloadReference
{
    public const SCHEMA = 'durable-workflow.v2.external-payload-reference.v1';

    public function __construct(
        public readonly string $uri,
        public readonly string $sha256,
        public readonly int $sizeBytes,
        public readonly string $codec,
        public readonly string $schema = self::SCHEMA,
    ) {
        if ($this->schema !== self::SCHEMA) {
            throw new InvalidArgumentException('Unsupported external payload reference schema.');
        }

        if ($this->uri === '') {
            throw new InvalidArgumentException('External payload reference URI must be a non-empty string.');
        }

        self::validateSha256($this->sha256);

        if ($this->sizeBytes < 0) {
            throw new InvalidArgumentException('External payload reference size_bytes must be a non-negative integer.');
        }

        if ($this->codec === '') {
            throw new InvalidArgumentException('External payload reference codec must be a non-empty string.');
        }

        CodecRegistry::canonicalize($this->codec);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $schema = $data['schema'] ?? null;
        $uri = $data['uri'] ?? null;
        $sha256 = $data['sha256'] ?? null;
        $sizeBytes = $data['size_bytes'] ?? null;
        $codec = $data['codec'] ?? null;

        if ($schema !== self::SCHEMA) {
            throw new InvalidArgumentException('Unsupported external payload reference schema.');
        }

        if (! is_string($uri) || $uri === '') {
            throw new InvalidArgumentException('External payload reference URI must be a non-empty string.');
        }

        if (! is_string($sha256)) {
            throw new InvalidArgumentException('External payload reference sha256 must be a hex digest.');
        }

        if (! is_int($sizeBytes) || $sizeBytes < 0) {
            throw new InvalidArgumentException('External payload reference size_bytes must be a non-negative integer.');
        }

        if (! is_string($codec) || $codec === '') {
            throw new InvalidArgumentException('External payload reference codec must be a non-empty string.');
        }

        return new self(
            uri: $uri,
            sha256: strtolower($sha256),
            sizeBytes: $sizeBytes,
            codec: CodecRegistry::canonicalize($codec),
            schema: $schema,
        );
    }

    /**
     * @return array{schema: string, uri: string, sha256: string, size_bytes: int, codec: string}
     */
    public function toArray(): array
    {
        return [
            'schema' => $this->schema,
            'uri' => $this->uri,
            'sha256' => $this->sha256,
            'size_bytes' => $this->sizeBytes,
            'codec' => $this->codec,
        ];
    }

    private static function validateSha256(string $sha256): void
    {
        if (! preg_match('/\A[a-f0-9]{64}\z/i', $sha256)) {
            throw new InvalidArgumentException('External payload reference sha256 must be a hex digest.');
        }
    }
}
