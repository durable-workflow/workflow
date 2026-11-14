<?php

declare(strict_types=1);

namespace Workflow\V2\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Workflow\Traits\ResolvesStorageConnection;

/** Compact firing receipts outlive the bounded audit snapshots. */
class WorkflowScheduleOccurrence extends Model
{
    use HasUlids;
    use ResolvesStorageConnection;

    public $incrementing = false;

    public $timestamps = false;

    protected $table = 'workflow_schedule_occurrences';

    protected $guarded = [];

    protected $keyType = 'string';
}
