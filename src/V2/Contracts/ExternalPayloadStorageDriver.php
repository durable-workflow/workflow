<?php

declare(strict_types=1);

namespace Workflow\V2\Contracts;

interface ExternalPayloadStorageDriver
{
    /**
     * Persist encoded payload bytes and return a stable URI.
     */
    public function put(string $data, string $sha256, string $codec): string;

    /**
     * Fetch previously persisted encoded payload bytes.
     */
    public function get(string $uri): string;

    /**
     * Delete previously persisted payload bytes when retention removes a run.
     */
    public function delete(string $uri): void;
}
