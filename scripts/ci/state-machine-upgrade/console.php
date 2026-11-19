<?php

declare(strict_types=1);

// Host commands for scripts/ci/state-machine-upgrade-smoke.sh. Each command
// prints one JSON document. STATE_MACHINE_EXAMPLE selects the example (see
// App\Providers\StateMachineUpgradeServiceProvider).

use App\Providers\StateMachineUpgradeServiceProvider as Host;
use Composer\InstalledVersions;
use DurableWorkflow\Examples\ConfiguredStateMachines\MachineStore as ConfiguredStore;
use DurableWorkflow\Examples\ConfiguredStateMachines\Order as ConfiguredOrder;
use DurableWorkflow\Examples\ConfiguredStateMachines\Ownership;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Request;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Status;
use DurableWorkflow\Examples\ProviderStateMachines\CancellationStore;
use DurableWorkflow\Examples\ProviderStateMachines\Catalogue;
use DurableWorkflow\Examples\ProviderStateMachines\MachineStore as ProviderStore;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\Persistence\ScopeRepository;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\WorkflowReplayer;
use Workflow\V2\WorkflowStub;

const LEDGER_TABLES = [
    'workflow_compensation_scopes',
    'workflow_compensation_obligations',
    'workflow_compensation_requests',
    'workflow_compensation_children',
    'workflow_compensation_journal',
];

const BUSINESS_PREFIXES = ['example_machine_', 'provider_machine_', 'configured_machine_'];

$json = static fn (mixed $value): string => json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

$example = static function (): string {
    $example = Host::example();
    if ($example === null) {
        throw new LogicException('Set STATE_MACHINE_EXAMPLE to provider or configured.');
    }

    return $example;
};

$namespace = static fn (): string => $example() === Host::PROVIDER ? Catalogue::NAMESPACE : Ownership::NAMESPACE;

$instance = static fn (string $scenario): string => $example() === Host::PROVIDER
    ? 'provider-order-' . Host::terms($scenario)->subjectKey()
    : Ownership::instance(Host::CONFIGURED_SUBJECT);

$origin = static fn (string $scenario): WorkflowStub => WorkflowStub::load(
    $instance($scenario),
    $namespace()
)->refresh();

// Order-insensitive digest of whole tables, so it is the same on every driver.
$tableDigest = static function (array $tables): string {
    $snapshot = [];
    foreach ($tables as $table) {
        $rows = array_map(
            static fn (object $row): string => json_encode((array) $row, JSON_THROW_ON_ERROR),
            DB::table($table)->get()->all(),
        );
        sort($rows);
        $snapshot[$table] = $rows;
    }

    return hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));
};

// Tables of this connection's own database or search path; Laravel lists
// every schema the server user can see otherwise.
$tables = static fn (): array => Schema::getTableListing(Schema::getCurrentSchemaListing(), false);

$businessTables = static fn (): array => array_values(array_filter(
    $tables(),
    static fn (string $table): bool => array_filter(
        BUSINESS_PREFIXES,
        static fn (string $prefix): bool => str_starts_with($table, $prefix),
    ) !== [],
));

$schemaDigest = static function () use ($tables): string {
    $names = $tables();
    sort($names);
    $snapshot = [];
    foreach ($names as $table) {
        $snapshot[$table] = [
            'columns' => array_map(
                static fn (array $column): array => [$column['name'], $column['type'], $column['nullable']],
                Schema::getColumns($table),
            ),
            'indexes' => array_map(
                static fn (array $index): array => [$index['name'], $index['columns'], $index['unique']],
                Schema::getIndexes($table),
            ),
        ];
    }
    $snapshot['@migrations'] = DB::table('migrations')->orderBy('id')->get(['migration', 'batch'])->all();

    return hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));
};

$histories = static function () use ($namespace): array {
    $runs = [];
    foreach (WorkflowRun::query()->where('namespace', $namespace())->orderBy('id')->get() as $run) {
        $events = WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)->orderBy('sequence')->get()->map(
            static fn (WorkflowHistoryEvent $event): array => [
                $event->sequence,
                $event->event_type->value,
                $event->payload,
            ]
        )->all();
        $runs[$run->id] = [
            'status' => $run->status->value,
            'closed' => $run->closed_at !== null,
            'history' => hash('sha256', json_encode($events, JSON_THROW_ON_ERROR)),
        ];
    }

    return $runs;
};

$snapshotPath = static fn (string $name): string => storage_path(
    'app/state-machine-snapshot-' . $example() . '-' . $name . '.json'
);

$scopeStatus = static function (ScopeIdentity $scope): ?string {
    try {
        return (new ScopeRepository())->metadata($scope)['status'];
    } catch (Throwable) {
        return null;
    }
};

// Actions from the synthetic provider journal, optionally for one scenario's
// purchase: confirmed effects in the order they happened, or operations the
// provider admitted but has not resolved.
$journalQueries = [
    'effects' => "SELECT json_extract(o.request, '$.action') FROM provider_effects e"
        . ' JOIN provider_operations o ON o.id = e.operation_id'
        . " WHERE (? IS NULL OR json_extract(o.request, '$.identity.subject') = ?) ORDER BY e.sequence",
    'pending' => "SELECT json_extract(o.request, '$.action') FROM provider_operations o"
        . " WHERE o.status IN ('processing', 'unknown')"
        . " AND (? IS NULL OR json_extract(o.request, '$.identity.subject') = ?) ORDER BY o.id",
];
$journalActions = static function (string $kind, ?string $scenario = null) use ($journalQueries): array {
    $journal = new PDO('sqlite:' . Host::journalPath());
    $subject = $scenario === null ? null : 'purchase-' . $scenario;
    $statement = $journal->prepare($journalQueries[$kind]);
    $statement->execute([$subject, $subject]);

    return $statement->fetchAll(PDO::FETCH_COLUMN);
};

$journalEffects = static fn (?string $scenario = null): array => $journalActions('effects', $scenario);

$journalPending = static fn (string $scenario): array => $journalActions('pending', $scenario);

$journalRequests = static function (string $kind = 'command'): int {
    $journal = new PDO('sqlite:' . Host::journalPath());
    $statement = $journal->prepare('SELECT COUNT(*) FROM provider_requests WHERE kind = ?');
    $statement->execute([$kind]);

    return (int) $statement->fetchColumn();
};

Artisan::command('machine:engine', function () use ($json): void {
    $this->line($json([
        'version' => InstalledVersions::getPrettyVersion('durable-workflow/workflow'),
        'reference' => InstalledVersions::getReference('durable-workflow/workflow'),
        'ledger' => Schema::hasTable('workflow_compensation_scopes'),
    ]));
});

Artisan::command('machine:setup', function () use ($json, $example): void {
    if ($example() === Host::CONFIGURED) {
        app(ConfiguredStore::class)->seed(Host::CONFIGURED_SUBJECT, 'prepare');
        $this->line($json([
            'seeded' => [Host::CONFIGURED_SUBJECT],
        ]));

        return;
    }
    $journal = Host::journal();
    $journal->install();
    foreach (Host::PROVIDER_SCENARIOS as $scenario) {
        $terms = Host::terms($scenario);
        foreach (['order', 'payment', 'shipment'] as $role) {
            $descriptor = $terms->descriptor($role);
            $journal->plan(Request::fromArray($descriptor['forward']), Status::Confirmed);
            // The refund scenario's provider keeps the refund processing until it is resolved.
            $undo = $scenario === 'refund' && $role === 'payment' ? Status::Processing : Status::Confirmed;
            $journal->plan(Request::fromArray($descriptor['compensation']), $undo);
        }
        app(ProviderStore::class)->seed($terms);
    }
    $this->line($json([
        'seeded' => Host::PROVIDER_SCENARIOS,
    ]));
});

Artisan::command('machine:start {scenario}', function () use ($json, $example, $instance, $namespace): void {
    $scenario = (string) $this->argument('scenario');
    if ($example() === Host::PROVIDER) {
        $stub = WorkflowStub::make(Catalogue::rootClass(Catalogue::RESERVE_FIRST), $instance($scenario), $namespace());
        $stub->start(Host::terms($scenario)->subjectKey());
    } else {
        $stub = WorkflowStub::make(ConfiguredOrder::class, $instance($scenario), $namespace());
        $stub->start(Host::CONFIGURED_SUBJECT, 'begin');
    }
    $this->line($json([
        'workflow_id' => $stub->id(),
        'run_id' => $stub->runId(),
    ]));
});

Artisan::command('machine:signal {scenario} {signal}', function () use ($json, $origin): void {
    $result = $origin((string) $this->argument('scenario'))
        ->signal((string) $this->argument('signal'));
    $this->line($json([
        'accepted' => $result->accepted(),
    ]));
});

Artisan::command('machine:cancel {scenario} {request}', function () use ($json, $example, $origin): void {
    $run = (string) $origin((string) $this->argument('scenario'))
        ->runId();
    $request = (string) $this->argument('request');
    $this->line($json($example() === Host::PROVIDER
        ? app(CancellationStore::class)->request(
            Host::terms((string) $this->argument('scenario'))->subjectKey(),
            $run,
            $request
        )
        : app(ConfiguredStore::class)->requestCancellation(Host::CONFIGURED_SUBJECT, $run, $request)));
});

Artisan::command('machine:finish-cancel {scenario} {request}', function () use ($json, $example, $origin): void {
    $run = (string) $origin((string) $this->argument('scenario'))
        ->runId();
    $request = (string) $this->argument('request');
    $this->line($json($example() === Host::PROVIDER
        ? app(CancellationStore::class)->finish(
            Host::terms((string) $this->argument('scenario'))->subjectKey(),
            $run,
            $request
        )
        : app(ConfiguredStore::class)->finishCancellation(Host::CONFIGURED_SUBJECT, $run, $request)));
});

Artisan::command('machine:resolve-refund {scenario}', function () use ($json): void {
    $descriptor = Host::terms((string) $this->argument('scenario'))->descriptor('payment');
    $observation = Host::journal()->resolve(Request::fromArray($descriptor['compensation']), Status::Confirmed);
    $this->line($json([
        'status' => $observation->status->value,
    ]));
});

Artisan::command('machine:inspect', function () use (
    $json,
    $example,
    $origin,
    $scopeStatus,
    $journalEffects,
    $journalPending,
    $journalRequests,
    $namespace
): void {
    $runs = WorkflowRun::query()->where('namespace', $namespace())->get();
    $report = [
        'runs' => $runs->countBy(static fn (WorkflowRun $run): string => $run->status->value)
            ->all(),
        'scenarios' => [],
    ];
    if ($example() === Host::PROVIDER) {
        $store = app(ProviderStore::class);
        foreach (Host::PROVIDER_SCENARIOS as $scenario) {
            $key = Host::terms($scenario)->subjectKey();
            $subject = $store->authority->subject($key, 'order');
            [$status, $scopes] = [null, []];
            try {
                $status = $origin($scenario)
                    ->status();
                $plan = $store->authority->plan($key);
                $scopes = [
                    'root' => $scopeStatus($plan->rootScope()),
                    'payment' => $scopeStatus($plan->childScope('payment')),
                    'shipment' => $scopeStatus($plan->childScope('shipment')),
                ];
            } catch (Throwable) {
                // Not started yet, or its root owner has not initialized.
            }
            $report['scenarios'][$scenario] = [
                'origin' => $status,
                'state' => $subject->state,
                'revision' => (int) $subject->revision,
                'effects' => $journalEffects($scenario),
                'pending' => $journalPending($scenario),
                'scopes' => $scopes,
            ];
        }
        $report['effects'] = count($journalEffects());
        $report['provider_commands'] = $journalRequests();
    } else {
        $root = DB::table('example_machine_subjects')->whereNull('parent_subject_id')->first();
        try {
            $stub = $origin('shipment');
            [$status, $runId] = [$stub->status(), $stub->runId()];
        } catch (Throwable) {
            [$status, $runId] = [null, null];
        }
        $report['scenarios']['shipment'] = [
            'origin' => $status,
            'state' => $root?->state,
            'revision' => (int) ($root?->revision ?? 0),
            'effects' => DB::table('example_machine_effects')->orderBy('action')->pluck('status', 'action')->all(),
            'compensations' => DB::table('example_machine_compensation_log')->orderBy('sequence')->pluck(
                'action'
            )->all(),
            'publications' => DB::table('example_machine_publications')->count(),
            'bindings' => DB::table('example_machine_bindings')->count(),
            'child_outcome_verified' => (bool) DB::table('example_machine_bindings')->value(
                'business_outcome_verified'
            ),
            'scopes' => [
                'root' => $runId === null ? null : $scopeStatus(Ownership::scope(Host::CONFIGURED_SUBJECT, $runId)),
            ],
        ];
    }
    $this->line($json($report));
});

// Replays every recorded run on the installed engine. Replay must use recorded
// results only: no business-table query and no provider request.
Artisan::command('machine:replay', function () use ($json, $example, $namespace, $journalRequests): void {
    $provider = $example() === Host::PROVIDER;
    $requestsBefore = $provider ? $journalRequests('command') + $journalRequests('lookup') : 0;
    $queries = 0;
    $listening = true;
    DB::listen(static function (QueryExecuted $query) use (&$queries, &$listening): void {
        foreach (BUSINESS_PREFIXES as $prefix) {
            if ($listening && str_contains($query->sql, $prefix)) {
                ++$queries;

                return;
            }
        }
    });
    [$replayed, $failed] = [0, 0];
    try {
        foreach (WorkflowRun::query()->where('namespace', $namespace())->orderBy('id')->get() as $run) {
            try {
                app(WorkflowReplayer::class)->replay($run);
            } catch (Throwable $failure) {
                // A failed run replays to the failure it recorded, and only to that.
                $recorded = WorkflowFailure::query()->where('workflow_run_id', $run->id)->pluck('message')->all();
                if ($run->status !== RunStatus::Failed || ! in_array($failure->getMessage(), $recorded, true)) {
                    throw $failure;
                }
                ++$failed;
            }
            ++$replayed;
        }
    } finally {
        $listening = false;
    }
    $requestsAfter = $provider ? $journalRequests('command') + $journalRequests('lookup') : 0;
    $this->line($json([
        'replayed' => $replayed,
        'failed' => $failed,
        'business_queries' => $queries,
        'provider_requests' => $requestsAfter - $requestsBefore,
    ]));
});

Artisan::command('machine:snapshot {name}', function () use (
    $json,
    $tableDigest,
    $businessTables,
    $schemaDigest,
    $histories,
    $snapshotPath
): void {
    $snapshot = [
        'ledger' => $tableDigest(LEDGER_TABLES),
        'ledger_rows' => array_sum(
            array_map(static fn (string $table): int => DB::table($table)->count(), LEDGER_TABLES)
        ),
        'business' => $tableDigest($businessTables()),
        'schema' => $schemaDigest(),
        'runs' => $histories(),
    ];
    file_put_contents($snapshotPath((string) $this->argument('name')), $json($snapshot));
    $this->line($json(array_diff_key($snapshot, [
        'runs' => true,
    ])));
});

// Compares the current state with a saved snapshot. Recorded histories of runs
// that were already closed must never change; the other fields are compared
// only when named.
Artisan::command('machine:compare {name} {fields?*}', function () use (
    $json,
    $tableDigest,
    $businessTables,
    $schemaDigest,
    $histories,
    $snapshotPath
): int {
    $saved = json_decode(
        (string) file_get_contents($snapshotPath((string) $this->argument('name'))),
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    $current = [
        'ledger' => static fn (): string => $tableDigest(LEDGER_TABLES),
        'business' => static fn (): string => $tableDigest($businessTables()),
        'schema' => $schemaDigest,
    ];
    $differences = [];
    foreach ($this->argument('fields') as $field) {
        if ($saved[$field] !== $current[$field]()) {
            $differences[] = $field;
        }
    }
    $now = $histories();
    foreach ($saved['runs'] as $run => $recorded) {
        if ($recorded['closed'] && ($now[$run]['history'] ?? null) !== $recorded['history']) {
            $differences[] = 'history:' . $run;
        }
    }
    $this->line($json([
        'differences' => $differences,
        'closed_runs' => count(array_filter(array_column($saved['runs'], 'closed'))),
    ]));

    return $differences === [] ? 0 : 1;
});
