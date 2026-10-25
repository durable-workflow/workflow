<?php

declare(strict_types=1);

namespace Workflow\V2\Contracts;

/**
 * An external payload driver whose URI depends only on the content (#930).
 * A workflow task completion can then write a payload's final reference
 * before the object is stored, and store it only once the completion has
 * passed every limit. put() must return exactly uriFor() for the same
 * content, and must be atomic, idempotent and verify the content hash.
 */
interface PredictableExternalPayloadStorageDriver extends ExternalPayloadStorageDriver
{
    /**
     * The URI put() returns for content with this hash and codec.
     */
    public function uriFor(string $sha256, string $codec): string;
}
