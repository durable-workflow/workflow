<?php

declare(strict_types=1);

namespace Workflow\V2\Compensation\Recovery;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Throwable;
use Workflow\V2\Compensation\Definition\PortableDescriptor;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\Persistence\ScopeRepository;
use Workflow\V2\Compensation\Persistence\ScopeStorage;
use Workflow\V2\Compensation\Security\ScopeAuthorization;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\ConfiguredV2Models;
use Workflow\V2\Support\NamespaceScope;

/**
 * @internal Routes a closed origin's accepted request to canonical recovery (#1205).
 *
 * A request accepted while the origin ran asks that run to compensate. If the
 * origin closes with the scope still unverified, the task watchdog repeats the
 * scope's primary request: the same key, principal and reason an operator would
 * repeat. The route is bound per request under the scope lock, and native
 * admission to the canonical recovery instance returns an existing active run,
 * so concurrent passes or a racing manual request still start one run.
 *
 * The primary principal is authorized again first. If that fails, nothing is
 * started; a recovery_authorization_failed journal entry records the refusal
 * once, and a fresh authorized request can still route recovery manually.
 *
 * @experimental The Compensation API is not covered by stability guarantees and may change in a minor release.
 */
final class CompensationAutoRecovery
{
    public const REFUSED = 'recovery_authorization_failed';

    /**
     * @param list<string> $runIds restricts candidates to these original runs
     * @return array{candidates: int, started: int, refused: int, failures: list<array{scope_id: string, message: string}>}
     */
    public static function pass(int $limit, array $runIds = [], ?NamespaceScope $namespaces = null): array
    {
        $report = [
            'candidates' => 0,
            'started' => 0,
            'refused' => 0,
            'failures' => [],
        ];
        $database = ConfiguredV2Models::connection();
        if (! $database->getSchemaBuilder()->hasTable(ScopeStorage::SCOPES)) {
            return $report;
        }
        foreach (self::candidates($database, $limit, $runIds, $namespaces ?? NamespaceScope::configured()) as $row) {
            $report['candidates']++;
            try {
                $scope = ScopeIdentity::fromArray(json_decode($row->identity, true, flags: JSON_THROW_ON_ERROR));
                $outcome = self::route($scope);
                $report[$outcome]++;
            } catch (Throwable $failure) {
                report($failure);
                $report['failures'][] = [
                    'scope_id' => (string) $row->id,
                    'message' => $failure->getMessage(),
                ];
            }
        }
        return $report;
    }

    /**
     * @return 'started'|'refused'
     */
    private static function route(ScopeIdentity $scope): string
    {
        $request = (new ScopeRepository())->primaryRequest($scope);
        try {
            $policy = app(ScopeAuthorization::class)->assertAllowed($scope, $request['principal'], 'request');
            PortableDescriptor::identifier($policy, 'authorization policy version');
        } catch (Throwable $refusal) {
            ScopeStorage::mutate($scope, static function (array $locked, Connection $database) use (
                $request,
                $refusal
            ): void {
                if ($database->table(ScopeStorage::JOURNAL)->where('scope_id', $locked['id'])
                    ->where('kind', self::REFUSED)
                    ->exists()) {
                    return;
                }
                ScopeStorage::append($locked, self::REFUSED, [
                    'requestKey' => $request['key'],
                    'principal' => $request['principal'],
                    'reason' => substr($refusal->getMessage(), 0, 256),
                ]);
            });
            return 'refused';
        }
        app(ScopeRecovery::class)->request($scope, $request['key'], $request['principal'], $request['reason']);
        return 'started';
    }

    /**
     * Requested scopes whose origin is terminal, with no request bound to
     * recovery yet and no recorded authorization refusal.
     *
     * @param list<string> $runIds
     */
    private static function candidates(
        Connection $database,
        int $limit,
        array $runIds,
        NamespaceScope $namespaces,
    ): array {
        $runs = ConfiguredV2Models::query('run_model', WorkflowRun::class)->getModel()->getTable();
        $terminal = array_map(
            static fn (RunStatus $status): string => $status->value,
            array_filter(RunStatus::cases(), static fn (RunStatus $status): bool => $status->isTerminal()),
        );
        $query = $database->table(ScopeStorage::SCOPES . ' as scope')
            ->join($runs . ' as origin', 'origin.id', '=', 'scope.original_run_id');
        $namespaces->apply($query, 'origin.namespace');
        return $query
            ->where('scope.status', 'requested')
            ->whereIn('origin.status', array_values($terminal))
            ->when(
                $runIds !== [],
                static fn (Builder $query): Builder => $query->whereIn('scope.original_run_id', $runIds)
            )
            ->whereNotExists(
                static fn (Builder $query): Builder => $query->from('workflow_compensation_requests as routed')
                    ->whereColumn('routed.scope_id', 'scope.id')
                    ->whereNotNull('routed.recovery_route')
            )
            ->whereNotExists(static fn (Builder $query): Builder => $query->from(ScopeStorage::JOURNAL . ' as refused')
                ->whereColumn('refused.scope_id', 'scope.id')
                ->where('refused.kind', self::REFUSED))
            ->orderBy('scope.id')
            ->limit($limit)
            ->get(['scope.id', 'scope.identity'])
            ->all();
    }
}
