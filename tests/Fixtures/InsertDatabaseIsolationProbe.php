<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

final class InsertDatabaseIsolationProbe implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        private readonly string $email
    ) {
    }

    public function handle(): void
    {
        DB::table('users')->insert([
            'name' => 'Queue worker isolation probe',
            'email' => $this->email,
            'password' => 'not-used',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
