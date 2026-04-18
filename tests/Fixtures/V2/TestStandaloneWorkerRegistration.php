<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Illuminate\Database\Eloquent\Model;

final class TestStandaloneWorkerRegistration extends Model
{
    protected $table = 'test_standalone_worker_registrations';

    protected $guarded = [];

    protected $casts = [
        'supported_workflow_types' => 'array',
        'supported_activity_types' => 'array',
        'last_heartbeat_at' => 'datetime',
    ];
}
