<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\Serializers\CodecRegistry;
use Workflow\V2\Contracts\ExternalPayloadStorageDriver;
use Workflow\V2\Exceptions\ExternalPayloadIntegrityException;

final class ExternalPayloadStorage
{
    public static function store(
        ExternalPayloadStorageDriver $driver,
        string $data,
        string $codec
    ): ExternalPayloadReference {
        $codec = CodecRegistry::canonicalize($codec);
        $sha256 = hash('sha256', $data);
        $uri = $driver->put($data, $sha256, $codec);

        return new ExternalPayloadReference(uri: $uri, sha256: $sha256, sizeBytes: strlen($data), codec: $codec);
    }

    public static function fetch(ExternalPayloadStorageDriver $driver, ExternalPayloadReference $reference): string
    {
        $data = $driver->get($reference->uri);

        if (strlen($data) !== $reference->sizeBytes) {
            throw new ExternalPayloadIntegrityException('External payload size does not match its reference.');
        }

        if (! hash_equals($reference->sha256, hash('sha256', $data))) {
            throw new ExternalPayloadIntegrityException('External payload hash does not match its reference.');
        }

        return $data;
    }
}
