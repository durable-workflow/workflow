#!/usr/bin/env bash

# Installed compensation-scope upgrade journey (#1063).
#
# Installs published 2.28.1 (before the compensation ledger) into a fresh
# Laravel application, upgrades to the candidate, creates real retained scope
# state and checks recovery after the origin run closes, schema rollback with
# an empty and a populated ledger, code downgrade to the baseline and
# re-upgrade.

set -euo pipefail

laravel_version="${LARAVEL_VERSION:?Set LARAVEL_VERSION to a supported Laravel constraint such as 12.*}"

# shellcheck source=scripts/ci/upgrade-journey-lib.sh
. "$(dirname "${BASH_SOURCE[0]}")/upgrade-journey-lib.sh"
upgrade_journey_init 2.28.1

temporary_root="${RUNNER_TEMP:-${TMPDIR:-/tmp}}"
if [ -d /dev/shm ] && [ -w /dev/shm ]; then
  database_root=/dev/shm
else
  database_root="$temporary_root"
fi

fixture_root=$(mktemp -d "${temporary_root%/}/compensation-scope-upgrade.XXXXXX")
database_path=$(mktemp "${database_root%/}/compensation-scope-upgrade.XXXXXX.sqlite")
app_path="$fixture_root/app"

cleanup() {
  if [ "${KEEP_UPGRADE_FIXTURE:-0}" = 1 ]; then
    printf 'Preserving diagnostic fixture: app=%s database=%s\n' "$app_path" "$database_path" >&2
    return
  fi

  rm -f -- "$database_path"
  rm -rf -- "$fixture_root"
}
trap cleanup EXIT
trap 'echo "Compensation scope upgrade journey failed at line ${LINENO}: ${BASH_COMMAND}" >&2' ERR

ledger_tables='workflow_compensation_scopes,workflow_compensation_obligations,workflow_compensation_requests,workflow_compensation_children,workflow_compensation_journal'

create_upgrade_app "$laravel_version" "$app_path"
cd "$app_path"

set_env APP_NAME '"Compensation Upgrade Host"'
set_env APP_ENV testing
set_env DB_CONNECTION sqlite
set_env DB_DATABASE "$database_path"
set_env QUEUE_CONNECTION database
set_env CACHE_DRIVER file
set_env CACHE_STORE file
set_env DW_V2_QUEUE workflow-v2
php artisan key:generate --force --no-interaction

write_sources() {
  mkdir -p app/CompensationUpgrade

  cat > app/CompensationUpgrade/GreetingWorkflow.php <<'PHP'
<?php

namespace App\CompensationUpgrade;

use Workflow\V2\Attributes\Signal;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\signal;
use Workflow\V2\Workflow;

#[Type('compensation-upgrade.greeting')]
#[Signal('finish', [['name' => 'confirmation', 'type' => 'string']])]
final class GreetingWorkflow extends Workflow
{
    public function handle(string $name): string
    {
        $confirmation = signal('finish');

        return sprintf('Hello, %s (%s)', $name, $confirmation);
    }
}
PHP

  # Compensation classes are referenced only by the candidate engine. The
  # baseline never autoloads them, so they can stay on disk after downgrade.
  cat > app/CompensationUpgrade/OrderWorkflow.php <<'PHP'
<?php

namespace App\CompensationUpgrade;

use Workflow\V2\Attributes\Type;
use Workflow\V2\Compensation\Definition\Obligation;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\DurableScope;
use Workflow\V2\Workflow;

#[Type('compensation-upgrade.order')]
final class OrderWorkflow extends Workflow
{
    public function handle(array $identity): array
    {
        $scope = new DurableScope($this, ScopeIdentity::fromArray($identity), 'upgrade-operator');
        $scope->execute(new Obligation('reserve-stock', 'upgrade.booking', '1', ['sku' => 'sku-1']));
        $scope->execute(new Obligation('book-carrier', 'upgrade.booking', '1', ['carrier' => 'carrier-1']));

        return ['status' => 'done'];
    }
}
PHP

  cat > app/CompensationUpgrade/BookingOperation.php <<'PHP'
<?php

namespace App\CompensationUpgrade;

use Workflow\V2\Compensation\Execution\ForwardResult;
use Workflow\V2\Compensation\Execution\OperationContext;
use Workflow\V2\Compensation\Execution\Resolution;
use Workflow\V2\Compensation\Execution\ScopedOperation;

final class BookingOperation implements ScopedOperation
{
    public function assertInput(array $input): void
    {
    }

    public function execute(OperationContext $context): ForwardResult
    {
        self::log('forward', $context);

        return ForwardResult::observed(['reference' => $context->operationId], ['booking:' . $context->operationId]);
    }

    public function compensate(OperationContext $context): Resolution
    {
        self::log('compensate', $context);

        $evidence = ['undo:' . $context->operationId];
        // Published releases before the explicit-outcome API take (evidence, forwardSettled).
        return (new \ReflectionMethod(Resolution::class, 'verified'))->getNumberOfParameters() > 1
            ? Resolution::verified($evidence, true)
            : Resolution::verified($evidence);
    }

    private static function log(string $kind, OperationContext $context): void
    {
        file_put_contents(storage_path('logs/compensation-upgrade.log'), json_encode([
            'kind' => $kind,
            'key' => $context->obligation->key,
            'operation' => $context->operationId,
        ], JSON_THROW_ON_ERROR) . "\n", FILE_APPEND);
    }
}
PHP

  cat > app/CompensationUpgrade/OperatorAuthorization.php <<'PHP'
<?php

namespace App\CompensationUpgrade;

use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\Security\ScopeAuthorization;

final class OperatorAuthorization implements ScopeAuthorization
{
    public function assertAllowed(ScopeIdentity $scope, string $principal, string $operation): string
    {
        if ($scope->application !== 'shop' || $principal !== 'upgrade-operator') {
            throw new \LogicException('Compensation access refused.');
        }

        return 'upgrade-policy-1';
    }
}
PHP

  cat > app/Providers/CompensationUpgradeServiceProvider.php <<'PHP'
<?php

namespace App\Providers;

use App\CompensationUpgrade\BookingOperation;
use App\CompensationUpgrade\OperatorAuthorization;
use Illuminate\Support\ServiceProvider;
use Workflow\V2\Compensation\Execution\HandlerRegistry;
use Workflow\V2\Compensation\Security\ScopeAuthorization;

final class CompensationUpgradeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The baseline engine has no compensation contracts to bind.
        if (! interface_exists(ScopeAuthorization::class)) {
            return;
        }
        $this->app->singleton(ScopeAuthorization::class, OperatorAuthorization::class);
        $this->app->singleton(HandlerRegistry::class, static fn (): HandlerRegistry => new HandlerRegistry([
            'upgrade.booking' => ['1' => new BookingOperation()],
        ]));
    }
}
PHP

  register_provider "App\\Providers\\CompensationUpgradeServiceProvider"

  cat > routes/console.php <<'PHP'
<?php

use App\CompensationUpgrade\GreetingWorkflow;
use App\CompensationUpgrade\OrderWorkflow;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\Persistence\ScopeRepository;
use Workflow\V2\Compensation\Recovery\ScopeRecovery;
use Workflow\V2\Compensation\Recovery\ScopeRetention;
use Workflow\V2\WorkflowStub;

$scope = static fn (): ScopeIdentity => new ScopeIdentity('shop', null, 'order-1', '1');
$json = static fn (mixed $value): string => json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

Artisan::command('upgrade:greeting-start {id}', function () use ($json): void {
    $start = WorkflowStub::make(GreetingWorkflow::class, $this->argument('id'))->start('Laravel');
    $this->line($json(['workflow_id' => $start->workflowId(), 'run_id' => $start->runId()]));
});

Artisan::command('upgrade:greeting-finish {id}', function () use ($json): void {
    $signal = WorkflowStub::load($this->argument('id'))->signal('finish', 'approved');
    $this->line($json(['accepted' => $signal->accepted()]));
});

Artisan::command('upgrade:greeting-inspect {id}', function () use ($json): void {
    $workflow = WorkflowStub::load($this->argument('id'))->refresh();
    $this->line($json(['status' => $workflow->status(), 'output' => $workflow->output()]));
});

Artisan::command('upgrade:engine', function () use ($json): void {
    $this->line($json([
        'recovery' => class_exists(ScopeRecovery::class),
        'ledger' => Schema::hasTable('workflow_compensation_scopes'),
    ]));
});

Artisan::command('upgrade:scope-start', function () use ($json, $scope): void {
    $start = WorkflowStub::make(OrderWorkflow::class, 'order-1')->start($scope()->toArray());
    $this->line($json(['workflow_id' => $start->workflowId(), 'run_id' => $start->runId()]));
});

Artisan::command('upgrade:scope-inspect', function () use ($json, $scope): void {
    $origin = WorkflowStub::load('order-1')->refresh();
    $ledger = (new ScopeRepository())->inspect($scope());
    $this->line($json([
        'origin_status' => $origin->status(),
        'origin_run_id' => $origin->runId(),
        'scope_status' => $ledger['status'],
        'original_run_id' => $ledger['original_run_id'],
        'obligations' => array_map(static fn (array $obligation): array => [
            'key' => $obligation['descriptor']['key'] ?? null,
            'forward' => $obligation['forward_status'],
            'compensation' => $obligation['compensation_status'],
        ], $ledger['obligations']),
    ]));
});

Artisan::command('upgrade:scope-recover {request}', function () use ($json, $scope): void {
    $this->line($json(app(ScopeRecovery::class)->request(
        $scope(),
        $this->argument('request'),
        'upgrade-operator',
        'order-cancelled',
    )));
});

Artisan::command('upgrade:scope-release', function () use ($json, $scope): void {
    app(ScopeRetention::class)->release($scope(), 'upgrade-operator');
    $this->line($json(['released' => true]));
});
PHP
}

hash_tables() {
  TABLES="$1" DATABASE_PATH="$database_path" php <<'PHP'
<?php

$database = new PDO('sqlite:' . getenv('DATABASE_PATH'));
$snapshot = [];
foreach (explode(',', (string) getenv('TABLES')) as $table) {
    $statement = $database->query(sprintf('SELECT * FROM "%s" ORDER BY rowid', $table));
    if ($statement === false) {
        fwrite(STDERR, "Table {$table} is unavailable.\n");
        exit(1);
    }
    $snapshot[$table] = $statement->fetchAll(PDO::FETCH_ASSOC);
}
echo hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));
PHP
}

hash_origin_history() {
  RUN_ID="$1" DATABASE_PATH="$database_path" php -r '
    $database = new PDO("sqlite:" . getenv("DATABASE_PATH"));
    $statement = $database->prepare("SELECT * FROM workflow_history_events WHERE workflow_run_id = :run ORDER BY sequence");
    $statement->execute(["run" => getenv("RUN_ID")]);
    $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
    if ($rows === []) {
        fwrite(STDERR, "The origin run has no history.\n");
        exit(1);
    }
    echo hash("sha256", json_encode($rows, JSON_THROW_ON_ERROR));
  '
}

ledger_rows() {
  DATABASE_PATH="$database_path" php -r '
    $database = new PDO("sqlite:" . getenv("DATABASE_PATH"));
    echo (int) $database->query("SELECT COUNT(*) FROM workflow_compensation_obligations")->fetchColumn();
  '
}

schema_snapshot() {
  DATABASE_PATH="$database_path" php -r '
    $database = new PDO("sqlite:" . getenv("DATABASE_PATH"));
    $snapshot = [];
    foreach ($database->query("SELECT name, sql FROM sqlite_master WHERE name NOT LIKE '"'"'sqlite_%'"'"' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $snapshot["schema"][] = $row;
    }
    $snapshot["migrations"] = $database->query("SELECT migration, batch FROM migrations ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    echo hash("sha256", json_encode($snapshot, JSON_THROW_ON_ERROR));
  '
}

queue_depth() {
  DATABASE_PATH="$database_path" php -r '
    $database = new PDO("sqlite:" . getenv("DATABASE_PATH"));
    echo (int) $database->query("SELECT COUNT(*) FROM jobs WHERE queue = '"'"'workflow-v2'"'"'")->fetchColumn();
  '
}

drain_queue() {
  local remaining=0

  for pass in $(seq 1 20); do
    timeout 120 php artisan queue:work database --queue=workflow-v2 \
      --stop-when-empty --sleep=1 --tries=3 --no-interaction
    remaining=$(queue_depth)
    if [ "$remaining" -eq 0 ]; then
      return 0
    fi
  done

  echo "Queue workflow-v2 still has ${remaining} job(s) after 20 drain passes." >&2
  return 1
}

compensation_log() {
  if [ -f storage/logs/compensation-upgrade.log ]; then
    php -r '
      $keys = [];
      foreach (file("storage/logs/compensation-upgrade.log", FILE_IGNORE_NEW_LINES) as $line) {
          $entry = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
          $keys[] = $entry["kind"] . ":" . $entry["key"];
      }
      echo implode(",", $keys);
    '
  fi
}

# 1. Baseline: published release without the compensation ledger.
require_baseline
write_sources
composer dump-autoload --no-interaction --no-scripts --quiet
php artisan migrate --force --no-interaction

engine=$(artisan_output upgrade:engine)
json_assert "$engine" '$r["recovery"] === false && $r["ledger"] === false' \
  "The baseline unexpectedly provides compensation scopes."

artisan_output upgrade:greeting-start greeting-open >/dev/null
drain_queue
open_state=$(artisan_output upgrade:greeting-inspect greeting-open)
json_assert "$open_state" '$r["status"] === "waiting"' "The baseline run did not stay open across the upgrade."

# 2. Upgrade to the candidate.
require_candidate
php artisan migrate --force --no-interaction
php artisan queue:restart --no-interaction
engine=$(artisan_output upgrade:engine)
json_assert "$engine" '$r["recovery"] === true && $r["ledger"] === true' \
  "The candidate did not install the compensation ledger."

# 3. An empty ledger rolls back with the rest of the upgrade batch.
php artisan migrate:rollback --force --no-interaction
engine=$(artisan_output upgrade:engine)
json_assert "$engine" '$r["ledger"] === false' "An empty compensation ledger did not roll back."
php artisan migrate --force --no-interaction
engine=$(artisan_output upgrade:engine)
json_assert "$engine" '$r["ledger"] === true' "The compensation ledger did not migrate again."

# The baseline run continues on the candidate.
artisan_output upgrade:greeting-finish greeting-open >/dev/null
drain_queue
open_state=$(artisan_output upgrade:greeting-inspect greeting-open)
json_assert "$open_state" '$r["status"] === "completed" && $r["output"] === "Hello, Laravel (approved)"' \
  "The baseline run did not complete after the upgrade."

# 4. Real retained scope state: the origin run completes and closes.
scope_start=$(artisan_output upgrade:scope-start)
origin_run_id=$(printf '%s' "$scope_start" | json_field run_id)
drain_queue
scope_state=$(artisan_output upgrade:scope-inspect)
json_assert "$scope_state" '$r["origin_status"] === "completed" && $r["scope_status"] === "open"
  && $r["original_run_id"] === "'"$origin_run_id"'"
  && array_column($r["obligations"], "forward") === ["observed", "observed"]' \
  "The closed origin did not retain both observed obligations."
if [ "$(compensation_log)" != 'forward:reserve-stock,forward:book-carrier' ]; then
  echo "Unexpected forward effects: $(compensation_log)." >&2
  exit 1
fi
origin_history=$(hash_origin_history "$origin_run_id")

# 5. A populated ledger refuses schema rollback before any migration in the
# batch runs down(), so the schema and migration records stay intact.
ledger_before=$(hash_tables "$ledger_tables")
schema_before=$(schema_snapshot)
expect_rollback_refused 'Schema rollback discarded retained compensation scopes.'
if [ "$(hash_tables "$ledger_tables")" != "$ledger_before" ]; then
  echo 'A refused schema rollback changed retained compensation rows.' >&2
  exit 1
fi
if [ "$(schema_snapshot)" != "$schema_before" ]; then
  echo 'A refused schema rollback still rolled back part of its batch.' >&2
  exit 1
fi

# 6. Code downgrade to the baseline keeps the ledger but cannot recover it.
require_baseline
php artisan queue:restart --no-interaction
engine=$(artisan_output upgrade:engine)
json_assert "$engine" '$r["recovery"] === false && $r["ledger"] === true' \
  "The downgraded engine should keep the ledger tables without recovery classes."
php artisan migrate --force --no-interaction
# The candidate's migration files are absent, so rollback cannot reach them.
php artisan migrate:rollback --force --no-interaction
if [ "$(hash_tables "$ledger_tables")" != "$ledger_before" ] \
  || [ "$(hash_origin_history "$origin_run_id")" != "$origin_history" ]; then
  echo 'Downgrading code changed retained compensation state.' >&2
  exit 1
fi
artisan_output upgrade:greeting-start greeting-downgraded >/dev/null
drain_queue
artisan_output upgrade:greeting-finish greeting-downgraded >/dev/null
drain_queue
downgraded_state=$(artisan_output upgrade:greeting-inspect greeting-downgraded)
json_assert "$downgraded_state" '$r["status"] === "completed"' \
  "Ordinary workflows did not run on the downgraded engine with the upgraded schema."

# 7. Re-upgrade and recover the closed origin through canonical recovery.
require_candidate
php artisan migrate --force --no-interaction
php artisan queue:restart --no-interaction
if [ "$(hash_tables "$ledger_tables")" != "$ledger_before" ]; then
  echo 'Re-upgrading changed retained compensation rows.' >&2
  exit 1
fi
recovery=$(artisan_output upgrade:scope-recover cancel-1)
json_assert "$recovery" '$r["route"] === "recovery" && $r["runId"] !== "'"$origin_run_id"'"' \
  "Recovery after closure did not use a separate canonical recovery run."
drain_queue
scope_state=$(artisan_output upgrade:scope-inspect)
json_assert "$scope_state" '$r["origin_status"] === "completed" && $r["scope_status"] === "verified"
  && array_column($r["obligations"], "compensation") === ["verified", "verified"]' \
  "Recovery did not verify both retained obligations."
expected_log='forward:reserve-stock,forward:book-carrier,compensate:book-carrier,compensate:reserve-stock'
if [ "$(compensation_log)" != "$expected_log" ]; then
  echo "Unexpected effects after recovery: $(compensation_log)." >&2
  exit 1
fi
if [ "$(hash_origin_history "$origin_run_id")" != "$origin_history" ]; then
  echo 'Recovery changed the original terminal history.' >&2
  exit 1
fi
recovery_run_id=$(printf '%s' "$recovery" | json_field runId)
repeat=$(artisan_output upgrade:scope-recover cancel-1)
json_assert "$repeat" '$r["runId"] === "'"$recovery_run_id"'" && $r["scopeStatus"] === "verified"' \
  "Repeating the recovery request did not return its original run."

# 8. A released tombstone still refuses rollback, again without partial effects.
artisan_output upgrade:scope-release >/dev/null
scope_state=$(artisan_output upgrade:scope-inspect)
json_assert "$scope_state" '$r["scope_status"] === "released"' "Explicit release did not retire the scope."
schema_before=$(schema_snapshot)
expect_rollback_refused 'Schema rollback discarded a released scope tombstone.'
if [ "$(ledger_rows)" -ne 2 ] || [ "$(schema_snapshot)" != "$schema_before" ]; then
  echo 'A refused rollback removed released tombstone rows or part of its batch.' >&2
  exit 1
fi

printf 'laravel=%s php=%s baseline=%s candidate=%s recovery=verified rollback=refused downgrade=retained status=passed\n' \
  "$laravel_version" "$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')" \
  "$baseline_version" "${candidate_version:-source}"
