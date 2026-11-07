<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\Models\WorkflowRun;

/**
 * @internal A read-only export or redrive proposal, never durable actor authority.
 * Only QueryStateReplayer's read-only path recognizes this representation.
 * Mutation/claim guards always require authoritative persisted storage.
 */
final class OfflineHistoryRun extends WorkflowRun
{
    public function save(array $options = [])
    {
        throw new \Workflow\V2\Exceptions\RuntimeProfileRefusal('runtime_profile_identity');
    }

    public function delete()
    {
        throw new \Workflow\V2\Exceptions\RuntimeProfileRefusal('runtime_profile_identity');
    }

    public function newFromBuilder($attributes = [], $connection = null)
    {
        throw new \Workflow\V2\Exceptions\RuntimeProfileRefusal('runtime_profile_identity');
    }
}
