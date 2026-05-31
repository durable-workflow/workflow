<?php

declare(strict_types=1);

namespace Workflow\Traits;

trait ResolvesStorageConnection
{
    public function getConnectionName(): ?string
    {
        return config('workflows.storage.connection') ?? $this->connection;
    }
}
