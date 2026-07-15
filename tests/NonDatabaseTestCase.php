<?php

declare(strict_types=1);

namespace Tests;

abstract class NonDatabaseTestCase extends TestCase
{
    protected const USE_DATABASE_TRUNCATION = false;

    protected function setUpWithLaravelMigrations(): void
    {
        // This harness deliberately boots the package without a database.
    }

    protected function setUpTraits(): array
    {
        return $this->setUpTraitsWithoutDatabase();
    }
}
