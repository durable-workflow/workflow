<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\Connection;
use PDO;
use Tests\TestCase;

/** Keeping a fixture's Connection object must not keep a database session after application teardown. */
final class DatabaseConnectionLifecycleTest extends TestCase
{
    private ?Connection $retainedConnection = null;

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            if ($this->retainedConnection !== null) {
                $this->assertNull(
                    $this->retainedConnection->getRawPdo(),
                    'A retained test owner must not retain its old application database session.'
                );
                $this->assertNull($this->retainedConnection->getRawReadPdo());
                $this->retainedConnection = null;
            }
        }
    }

    public function testTheFixtureClosesItsConnectionEvenWhenAnOwnerRetainsTheObject(): void
    {
        self::stopWorkers();
        $this->retainedConnection = $this->app->make('db')
            ->connection();
        $this->assertSame(1, (int) $this->retainedConnection->scalar('SELECT 1'));
        $this->assertInstanceOf(PDO::class, $this->retainedConnection->getRawPdo());
    }
}
