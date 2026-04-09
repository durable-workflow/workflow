<?php

declare(strict_types=1);

namespace Tests\Unit\Commands;

use Tests\TestCase;

final class V2DoctorCommandTest extends TestCase
{
    public function testStrictModeFailsWhenTheQueueDriverIsSync(): void
    {
        config()->set('queue.default', 'sync');
        config()
            ->set('queue.connections.sync.driver', 'sync');

        $this->artisan('workflow:v2:doctor', [
            '--strict' => true,
        ])->assertFailed();
    }

    public function testJsonOutputSucceedsWithoutStrictMode(): void
    {
        config()->set('queue.default', 'sync');
        config()
            ->set('queue.connections.sync.driver', 'sync');

        $this->artisan('workflow:v2:doctor', [
            '--json' => true,
        ])->assertSuccessful();
    }
}
