<?php

declare(strict_types=1);

namespace Workflow\V2\Compensation\Recovery;

use LogicException;
use Workflow\V2\Compensation\Definition\PortableDescriptor;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\Persistence\ScopeRepository;
use Workflow\V2\Compensation\Persistence\ScopeStorage;
use Workflow\V2\Compensation\Security\ScopeAuthorization;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\ConfiguredV2Models;
use Workflow\V2\Support\SqliteTransaction;

/** Explicit retirement. Normal run-history pruning never removes retained obligations. */
final readonly class ScopeRetention
{
    public function __construct(
        private ScopeRepository $repository,
        private ScopeAuthorization $authorization
    ) {
    }

    public function release(ScopeIdentity $scope, string $principal): void
    {
        $policy = $this->authorization->assertAllowed($scope, $principal, 'release');
        PortableDescriptor::identifier($policy, 'authorization policy version');
        $snapshot = $this->repository->inspect($scope);
        SqliteTransaction::run(static function () use ($scope, $principal, $policy, $snapshot): void {
            $origin = ConfiguredV2Models::query('run_model', WorkflowRun::class)->whereKey(
                $snapshot['original_run_id']
            )->lockForUpdate()
                ->first();
            $recovery = ConfiguredV2Models::query('run_model', WorkflowRun::class)->where(
                'workflow_instance_id',
                $scope->recoveryInstanceId()
            )
                ->orderByDesc('run_number')
                ->lockForUpdate()
                ->first();
            if (! $origin instanceof WorkflowRun || $origin->namespace !== $scope->namespace || ! $origin->status->isTerminal()
                || ($recovery instanceof WorkflowRun && ! $recovery->status->isTerminal())) {
                throw new LogicException('Native execution still retains this compensation scope.');
            }
            $database = ConfiguredV2Models::connection();
            $link = $database->table('workflow_compensation_children')
                ->where('child_id', $scope->id())
                ->first();
            if ($link !== null) {
                $parent = $database->table(ScopeStorage::SCOPES)->where('id', $link->parent_id)->lockForUpdate()
                    ->first();
                if ($parent === null || ! in_array($parent->status, ['verified', 'released'], true)) {
                    throw new LogicException('An unresolved parent retains this child scope.');
                }
            }
            ScopeStorage::mutate($scope, static function (array $locked) use (
                $scope,
                $principal,
                $policy,
                $database
            ): void {
                if ($locked['status'] === 'released') {
                    return;
                }
                if ($locked['status'] !== 'verified') {
                    throw new LogicException('Unresolved compensation cannot be released.');
                }
                foreach ($database->table(ScopeStorage::OBLIGATIONS)->where('scope_id', $scope->id())->get() as $row) {
                    $descriptor = json_decode($row->descriptor, true, flags: JSON_THROW_ON_ERROR);
                    unset($descriptor['input']);
                    $descriptor['released'] = true;
                    $database->table(ScopeStorage::OBLIGATIONS)->where('id', $row->id)->update([
                        'descriptor' => PortableDescriptor::encode($descriptor),
                        'forward_result' => null,
                    ]);
                }
                $database->table(ScopeStorage::SCOPES)->where('id', $scope->id())->update([
                    'status' => 'released',
                ]);
                ScopeStorage::append($locked, 'released', [
                    'principal' => $principal,
                    'policyVersion' => $policy,
                    'reason' => 'explicit-release',
                ]);
            });
        }, connection: ConfiguredV2Models::connection());
    }
}
