<?php

declare(strict_types=1);

namespace Workflow\V2\Contracts;

/**
 * An external payload driver whose URI depends only on the content. A
 * workflow task completion can then write a payload's final reference before
 * the object is stored, and store it only after the completion has passed
 * every limit, so a refused completion stores nothing.
 *
 * Implementing this interface is optional: a driver that implements only
 * {@see ExternalPayloadStorageDriver} keeps storing objects when a
 * completion externalizes them.
 *
 * An implementation must guarantee that put():
 *  - returns exactly uriFor() for the same hash and codec;
 *  - refuses content that does not match the given SHA-256;
 *  - never leaves a partial or corrupt object at that URI: the object is
 *    either absent or holds exactly this content;
 *  - is idempotent, so a repeated put of the same content succeeds and
 *    leaves the same object.
 */
interface PredictableExternalPayloadStorageDriver extends ExternalPayloadStorageDriver
{
    /**
     * The URI put() returns for content with this hash and codec.
     */
    public function uriFor(string $sha256, string $codec): string;
}
