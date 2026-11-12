<?php

declare(strict_types=1);

namespace Workflow\V2\Compensation\Persistence;

use LogicException;
use Workflow\V2\Compensation\Definition\Obligation;
use Workflow\V2\Compensation\Definition\PortableDescriptor;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Support\ConfiguredV2Models;
use Workflow\V2\Support\SqliteTransaction;

/**
 * @internal Bounded sequential scope participants, not state-bound machine definitions.
 *
 * @experimental The Compensation API is not covered by stability guarantees and may change in a minor release.
 */
final class ChildScopes
{
    public const TYPE = 'dw.child-scope';

    public const VERSION = '1';

    public const MAX_CHILDREN = 16;

    public const MAX_DEPTH = 1;

    public function register(ScopeIdentity $parent, string $originalRunId, string $key, ScopeIdentity $child): string
    {
        if ($parent->application !== $child->application || $parent->namespace !== $child->namespace || $parent->id() === $child->id()) {
            throw new LogicException('A child scope must have another identity in the same application and namespace.');
        }
        $database = ConfiguredV2Models::connection();
        $graph = hash('sha256', PortableDescriptor::encode([$parent->application, $parent->namespace]));
        return SqliteTransaction::run(static function () use (
            $parent,
            $originalRunId,
            $key,
            $child,
            $database,
            $graph
        ): string {
            // Serialize edge mutations before scope locks. This is a graph-integrity lock, not executor ownership.
            $database->table('workflow_compensation_graphs')
                ->insertOrIgnore([
                    'id' => $graph,
                ]);
            $database->table('workflow_compensation_graphs')
                ->where('id', $graph)
                ->lockForUpdate()
                ->first();
            $existing = $database->table('workflow_compensation_children')
                ->where('child_id', $child->id())
                ->lockForUpdate()
                ->first();
            if (($existing !== null && $existing->parent_id !== $parent->id())
                || $database->table('workflow_compensation_children')
                    ->where('child_id', $parent->id())
                    ->lockForUpdate()
                    ->first() !== null
                || $database->table('workflow_compensation_children')
                    ->where('parent_id', $child->id())
                    ->lockForUpdate()
                    ->first() !== null) {
                throw new LogicException(
                    'A child scope cannot be shared, cyclic, or nested beyond the supported depth.'
                );
            }
            if ($existing === null && $database->table('workflow_compensation_children')->where(
                'parent_id',
                $parent->id()
            )->lockForUpdate()
                ->get()
                ->count() >= self::MAX_CHILDREN) {
                throw new LogicException('The child scope fan-out limit is exhausted.');
            }
            $repository = new ScopeRepository();
            $repository->open($parent, $originalRunId);
            $obligation = new Obligation($key, self::TYPE, self::VERSION, [
                'child' => $child->toArray(),
            ]);
            $id = $repository->register($parent, $obligation);
            if ($existing !== null && $existing->obligation_id !== $id) {
                throw new LogicException('The child generation already belongs to another participant.');
            }
            if ($existing === null) {
                $database->table('workflow_compensation_children')
                    ->insert([
                        'child_id' => $child->id(),
                        'parent_id' => $parent->id(),
                        'obligation_id' => $id,
                    ]);
            }
            return $id;
        }, connection: $database);
    }
}
