#!/usr/bin/env bash

# Installed upgrade journey for the #1069 state-machine examples.
#
# Installs published 2.32.0 into a fresh Laravel application with a MySQL or
# PostgreSQL database and Redis queues. The application copies the
# provider-backed and configured Order/Shipment examples out of that installed
# package, leaves real runs mid-flight on queue workers, upgrades the package
# to the candidate and checks that the runs replay and finish there without
# repeating provider effects. It then checks schema rollback refusal while
# their compensation scopes are retained, a package downgrade that keeps that
# state and an upgrade that recovers it.

set -Eeuo pipefail

laravel_version="${LARAVEL_VERSION:?Set LARAVEL_VERSION to a supported Laravel constraint such as 12.*}"
script_root=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)

# shellcheck source=scripts/ci/upgrade-journey-lib.sh
. "$script_root/upgrade-journey-lib.sh"
# 2.32.0 is the first release whose compensation API matches the stable one,
# so its example sources run unchanged after the upgrade. The 2.29.0 to 2.31.0
# examples read OperationContext::$uncertainForward, which 2.32.0 removed.
upgrade_journey_init 2.32.0

db_connection="${DB_CONNECTION:-mysql}"
case "$db_connection" in
  mysql) default_port=3306 ;;
  pgsql) default_port=5432 ;;
  *) echo "DB_CONNECTION must be mysql or pgsql." >&2; exit 1 ;;
esac
db_host="${DB_HOST:-127.0.0.1}"
db_port="${DB_PORT:-$default_port}"
db_username="${DB_USERNAME:-root}"
db_password="${DB_PASSWORD:-password}"
database_prefix="${STATE_MACHINE_DATABASE_PREFIX:-state_machine_upgrade}"
settle_seconds="${STATE_MACHINE_SETTLE_SECONDS:-180}"
examples=(provider configured)

temporary_root="${RUNNER_TEMP:-${TMPDIR:-/tmp}}"
fixture_root=$(mktemp -d "${temporary_root%/}/state-machine-upgrade.XXXXXX")
app_path="$fixture_root/app"

databases() {
  DB_DRIVER="$db_connection" DB_HOST="$db_host" DB_PORT="$db_port" DB_USERNAME="$db_username" \
    DB_PASSWORD="$db_password" DB_PREFIX="$database_prefix" ACTION="$1" php -r '
      $driver = getenv("DB_DRIVER");
      $dsn = $driver === "pgsql"
          ? sprintf("pgsql:host=%s;port=%s;dbname=postgres", getenv("DB_HOST"), getenv("DB_PORT"))
          : sprintf("mysql:host=%s;port=%s", getenv("DB_HOST"), getenv("DB_PORT"));
      $pdo = new PDO($dsn, getenv("DB_USERNAME"), getenv("DB_PASSWORD"), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
      foreach (["provider", "configured"] as $example) {
          $name = getenv("DB_PREFIX") . "_" . $example;
          $pdo->exec("DROP DATABASE IF EXISTS " . $name);
          if (getenv("ACTION") === "create") {
              $pdo->exec("CREATE DATABASE " . $name);
          }
      }
    '
}

# A runaway writer must fail with evidence, not by filling the runner's disk.
# The guard reports the largest files and stops the journey below the floor.
disk_guard() {
  local floor_kb=$(( ${STATE_MACHINE_MIN_FREE_MB:-2048} * 1024 ))
  local free_kb largest ticks=0
  while sleep 15; do
    free_kb=$(df -Pk "$fixture_root" | awk 'NR == 2 { print $4 }')
    ticks=$((ticks + 1))
    if (( ticks % 2 == 0 )); then
      echo "Journey heartbeat: $((free_kb / 1024)) MB free; $(du -sh "$fixture_root" 2>/dev/null | cut -f1) fixture;" \
        "$(find "$fixture_root" -name '*.log' -printf '%f=%s ' 2>/dev/null)" >&2
    fi
    if (( free_kb >= floor_kb )); then
      continue
    fi
    largest=$(find "$fixture_root" "${HOME:-/root}" /tmp -xdev -type f -size +20M -printf '%s %p\n' 2>/dev/null \
      | sort -rn | head -n 5)
    {
      echo "Disk guard: ${free_kb} KB free below the ${floor_kb} KB floor. Largest files:"
      echo "$largest"
      while read -r _ file; do
        [[ -n "$file" ]] || continue
        echo "--- last 4000 bytes of $file"
        tail -c 4000 "$file"
      done <<<"$(echo "$largest" | head -n 2)"
    } >&2
    kill -TERM "$journey_pid"
    return
  done
}
journey_pid=$$
disk_guard &
disk_guard_pid=$!

# Prints the example's run summaries and latest workflow tasks, so a worker
# that never empties its queue shows which runs keep receiving work.
task_report() {
  DB_DRIVER="$db_connection" DB_HOST="$db_host" DB_PORT="$db_port" DB_USERNAME="$db_username" \
    DB_PASSWORD="$db_password" DB_NAME="${database_prefix}_$1" php -r '
      $driver = getenv("DB_DRIVER");
      $dsn = sprintf("%s:host=%s;port=%s;dbname=%s", $driver, getenv("DB_HOST"), getenv("DB_PORT"), getenv("DB_NAME"));
      $pdo = new PDO($dsn, getenv("DB_USERNAME"), getenv("DB_PASSWORD"), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
      $queries = [
          "runs" => "SELECT s.id, s.workflow_type, s.status, s.liveness_state, s.liveness_reason, s.wait_kind,"
              . " s.task_problem, s.next_task_type, s.next_task_status, s.repair_attention,"
              . " (SELECT COUNT(*) FROM workflow_tasks t WHERE t.workflow_run_id = s.id) AS tasks"
              . " FROM workflow_run_summaries s ORDER BY s.created_at",
          "tasks" => "SELECT t.workflow_run_id, t.task_type, t.status, t.created_at, t.attempt_count, t.repair_count,"
              . " t.last_error, t.payload FROM workflow_tasks t ORDER BY t.created_at DESC, t.id DESC LIMIT 20",
      ];
      foreach ($queries as $label => $query) {
          echo "--- {$label}\n";
          foreach ($pdo->query($query, PDO::FETCH_ASSOC) as $row) {
              echo substr(json_encode($row, JSON_UNESCAPED_SLASHES), 0, 600), "\n";
          }
      }
    ' || true
}

cleanup() {
  kill "$disk_guard_pid" 2>/dev/null || true
  if [[ "${KEEP_UPGRADE_FIXTURE:-0}" == 1 ]]; then
    printf 'Preserving diagnostic fixture: app=%s databases=%s_*\n' "$app_path" "$database_prefix" >&2
    return
  fi

  databases drop || true
  rm -rf -- "$fixture_root"
}
trap cleanup EXIT

diagnosed=0
diagnose() {
  # Functions and command substitutions inherit the trap; report once, from
  # the main shell.
  if [[ "$BASH_SUBSHELL" -ne 0 || "$diagnosed" == 1 ]]; then
    return
  fi
  diagnosed=1
  echo "State-machine upgrade journey failed at line $1: $2" >&2
  for example in "${examples[@]}"; do
    in_example "$example" php artisan machine:inspect >&2 2>&1 || true
    tail -n 40 "$fixture_root/worker-$example.log" >&2 2>/dev/null || true
  done
}
trap 'diagnose "$LINENO" "$BASH_COMMAND"' ERR

# Each example binds the engine's handler registry, scope authorization and
# child definitions, so each one has its own database, queue and cache prefix
# in the same application.
in_example() {
  local example="$1"
  shift
  STATE_MACHINE_EXAMPLE="$example" DB_DATABASE="${database_prefix}_${example}" \
    DW_V2_QUEUE="state-machine-${example}" CACHE_PREFIX="state-machine-${example}-" "$@"
}

machine() {
  local example="$1"
  shift
  in_example "$example" artisan_output "$@"
}

each_example() {
  for example in "${examples[@]}"; do
    in_example "$example" "$@"
  done
}

drain() {
  local example="$1" status=0
  in_example "$example" timeout 300 php artisan queue:work redis --queue="state-machine-${example}" \
    --stop-when-empty --sleep=1 --tries=3 --no-interaction >>"$fixture_root/worker-${example}.log" 2>&1 || status=$?
  if (( status != 0 )); then
    {
      echo "The ${example} queue worker exited with status ${status}."
      echo "--- last 6000 bytes of worker-${example}.log"
      tail -c 6000 "$fixture_root/worker-${example}.log" 2>/dev/null || true
      echo "--- last 6000 bytes of storage/logs/laravel.log"
      tail -c 6000 "$app_path/storage/logs/laravel.log" 2>/dev/null || true
      echo "--- machine:inspect"
      in_example "$example" php artisan machine:inspect 2>&1 | tail -c 4000 || true
      task_report "$example"
    } >&2
    return "$status"
  fi
}

# Runs the example's queue worker until machine:inspect satisfies the
# expression. Timers and retry backoff need real time, so this polls.
settle() {
  local example="$1"
  local expression="$2"
  local message="$3"
  local deadline=$((SECONDS + settle_seconds))
  local state

  while :; do
    drain "$example"
    state=$(machine "$example" machine:inspect)
    if json_assert "$state" "$expression" '' 2>/dev/null; then
      return 0
    fi
    if [[ "$SECONDS" -ge "$deadline" ]]; then
      json_assert "$state" "$expression" "$message"
    fi
    sleep 1
  done
}

assert_unchanged() {
  local example="$1"
  local snapshot="$2"
  shift 2
  machine "$example" machine:compare "$snapshot" "$@" >/dev/null
}

assert_replay_is_recorded() {
  local stage="$1" replay
  for example in "${examples[@]}"; do
    replay=$(machine "$example" machine:replay)
    json_assert "$replay" '$r["replayed"] > 0 && $r["business_queries"] === 0 && $r["provider_requests"] === 0' \
      "Replaying ${example} runs on ${stage} queried business state or the provider."
  done
}

assert_engine() {
  local expression="$1" message="$2" engine
  for example in "${examples[@]}"; do
    engine=$(machine "$example" machine:engine)
    json_assert "$engine" "$expression" "$message"
  done
}

booked='["reserve", "capture", "book"]'
settled='["reserve", "capture", "book", "cancel-booking", "refund", "release"]'

create_upgrade_app "$laravel_version" "$app_path"
cd "$app_path"

set_env APP_NAME '"State Machine Upgrade Host"'
set_env APP_ENV testing
set_env DB_CONNECTION "$db_connection"
set_env DB_HOST "$db_host"
set_env DB_PORT "$db_port"
set_env DB_USERNAME "$db_username"
set_env DB_PASSWORD "$db_password"
set_env QUEUE_CONNECTION redis
set_env REDIS_CLIENT phpredis
set_env REDIS_HOST "${REDIS_HOST:-127.0.0.1}"
set_env REDIS_PORT "${REDIS_PORT:-6379}"
set_env CACHE_STORE file
php artisan key:generate --force --no-interaction
databases create

# 1. Baseline: install the published release and copy the example sources out
# of it, so the application code stays the same across the package upgrade.
require_baseline
mkdir state-machine-examples
for example in business configured provider; do
  cp -R "vendor/durable-workflow/workflow/examples/${example}-state-machines" state-machine-examples/
done
cp "$script_root/state-machine-upgrade/StateMachineUpgradeServiceProvider.php" app/Providers/
cp "$script_root/state-machine-upgrade/console.php" routes/console.php
cp "$script_root/state-machine-upgrade/create_state_machine_example_tables.php" \
  database/migrations/2026_11_19_000000_create_state_machine_example_tables.php
register_provider "App\\Providers\\StateMachineUpgradeServiceProvider"
composer dump-autoload --no-interaction --no-scripts --quiet
each_example php artisan migrate --force --no-interaction
each_example artisan_output machine:setup >/dev/null

# Leave runs mid-flight: two checkout Orders booked and waiting for `finish`,
# one of them with a cancellation whose provider refund is still processing,
# and a configured Order hosting its completed Shipment and waiting for `finish`.
machine provider machine:start checkout >/dev/null
machine provider machine:start refund >/dev/null
machine configured machine:start shipment >/dev/null
settle provider '$r["scenarios"]["checkout"]["origin"] === "waiting" && $r["scenarios"]["checkout"]["state"] === "booked"
  && $r["scenarios"]["refund"]["origin"] === "waiting" && $r["scenarios"]["refund"]["state"] === "booked"
  && $r["scenarios"]["checkout"]["effects"] === '"$booked"' && $r["scenarios"]["refund"]["effects"] === '"$booked" \
  "The provider-backed checkout Orders did not book on the baseline."
cancellation=$(machine provider machine:cancel refund refund-processing)
json_assert "$cancellation" '$r["status"] === "requested"' "The active Order did not accept its cancellation."
settle provider '$r["scenarios"]["refund"]["pending"] === ["refund"] && $r["scenarios"]["refund"]["scopes"]["root"] === "requested"
  && $r["scenarios"]["refund"]["effects"] === ["reserve", "capture", "book", "cancel-booking"]' \
  "The cancellation did not reach the processing provider refund on the baseline."
settle configured '$r["scenarios"]["shipment"]["origin"] === "waiting" && $r["scenarios"]["shipment"]["state"] === "working"
  && $r["scenarios"]["shipment"]["revision"] === 2 && count($r["scenarios"]["shipment"]["effects"]) === 3
  && $r["scenarios"]["shipment"]["child_outcome_verified"] === false' \
  "The configured Order did not host its Shipment on the baseline."
for example in "${examples[@]}"; do
  machine "$example" machine:snapshot baseline >/dev/null
done

# 2. Upgrade the package under the recorded runs.
require_candidate
each_example php artisan migrate --force --no-interaction
php artisan queue:restart --no-interaction
assert_engine '$r["version"] !== "'"$baseline_version"'" && $r["ledger"] === true' \
  "The candidate package is not installed."
for example in "${examples[@]}"; do
  assert_unchanged "$example" baseline ledger business
done
assert_replay_is_recorded candidate

# 3. Recovery on the candidate.
machine provider machine:signal checkout finish >/dev/null
machine provider machine:resolve-refund refund >/dev/null
cancellation=$(machine provider machine:cancel refund refund-confirmed)
json_assert "$cancellation" '$r["status"] === "requested"' "The fresh cancellation request was refused."
machine configured machine:signal shipment finish >/dev/null
settle provider '$r["scenarios"]["checkout"]["origin"] === "completed" && $r["scenarios"]["checkout"]["state"] === "booked"
  && $r["scenarios"]["checkout"]["effects"] === '"$booked"'
  && $r["scenarios"]["refund"]["scopes"]["root"] === "verified" && $r["scenarios"]["refund"]["effects"] === '"$settled"'
  && $r["scenarios"]["refund"]["pending"] === []' \
  "The mid-flight provider Orders did not finish or recover on the candidate."
finalizer=$(machine provider machine:finish-cancel refund refund-confirmed)
json_assert "$finalizer" '$r["status"] === "started"' "The verified cancellation could not be finalized."
settle provider '$r["scenarios"]["refund"]["state"] === "cancelled"' \
  "The recovered Order did not publish its cancellation."
settle configured '$r["scenarios"]["shipment"]["origin"] === "completed" && $r["scenarios"]["shipment"]["state"] === "completed"
  && $r["scenarios"]["shipment"]["child_outcome_verified"] === true && $r["scenarios"]["shipment"]["bindings"] === 1
  && $r["scenarios"]["shipment"]["effects"] === ["deliver" => "active", "prepare" => "active", "reserve" => "active"]' \
  "The configured Order did not finish its hosted Shipment on the candidate."
for example in "${examples[@]}"; do
  assert_unchanged "$example" baseline
done
assert_replay_is_recorded candidate

# 4. Retained compensation scopes refuse a schema rollback before any
# migration runs; package and application tables stay intact.
for example in "${examples[@]}"; do
  machine "$example" machine:snapshot retained >/dev/null
  in_example "$example" expect_rollback_refused "Schema rollback discarded the ${example} example's retained scopes."
  assert_unchanged "$example" retained ledger business schema
done

# 5. A package downgrade keeps the ledger and the recorded state. The baseline
# also refuses the rollback and runs new state-machine work on that schema.
require_baseline
php artisan queue:restart --no-interaction
each_example php artisan migrate --force --no-interaction
assert_engine '$r["version"] === "'"$baseline_version"'" && $r["ledger"] === true' \
  "The downgrade did not keep the ledger."
for example in "${examples[@]}"; do
  assert_unchanged "$example" retained ledger business
  in_example "$example" expect_rollback_refused "The downgraded engine discarded the ${example} example's retained scopes."
done
machine provider machine:start late >/dev/null
settle provider '$r["scenarios"]["late"]["origin"] === "waiting" && $r["scenarios"]["late"]["state"] === "booked"
  && $r["scenarios"]["late"]["effects"] === '"$booked" \
  "The downgraded engine did not run a new checkout Order."
for example in "${examples[@]}"; do
  machine "$example" machine:snapshot downgraded >/dev/null
done

# 6. Upgrade again: retained scopes of closed Orders recover, and the run
# started on the downgraded engine finishes.
require_candidate
each_example php artisan migrate --force --no-interaction
php artisan queue:restart --no-interaction
for example in "${examples[@]}"; do
  assert_unchanged "$example" downgraded ledger business
done
machine provider machine:signal late finish >/dev/null
cancellation=$(machine provider machine:cancel checkout checkout-closed)
json_assert "$cancellation" '$r["route"]["route"] === "recovery"' "The closed checkout Order did not route to recovery."
cancellation=$(machine configured machine:cancel shipment shipment-closed)
json_assert "$cancellation" '$r["route"]["route"] === "recovery"' "The closed configured Order did not route to recovery."
settle provider '$r["scenarios"]["late"]["origin"] === "completed" && $r["scenarios"]["late"]["effects"] === '"$booked"'
  && $r["scenarios"]["checkout"]["scopes"]["root"] === "verified" && $r["scenarios"]["checkout"]["effects"] === '"$settled" \
  "The retained checkout scope did not recover after the re-upgrade."
finalizer=$(machine provider machine:finish-cancel checkout checkout-closed)
json_assert "$finalizer" '$r["status"] === "started"' "The recovered checkout cancellation could not be finalized."
settle configured '$r["scenarios"]["shipment"]["scopes"]["root"] === "verified"
  && $r["scenarios"]["shipment"]["compensations"] === ["deliver", "prepare", "reserve"]' \
  "The retained configured scope did not recover after the re-upgrade."
finalizer=$(machine configured machine:finish-cancel shipment shipment-closed)
json_assert "$finalizer" 'in_array($r["status"], ["started", "completed"], true)' \
  "The recovered configured cancellation could not be finalized."
settle provider '$r["scenarios"]["checkout"]["state"] === "cancelled" && $r["effects"] === 15' \
  "The recovered checkout Order did not publish its cancellation."
settle configured '$r["scenarios"]["shipment"]["state"] === "cancelled"
  && $r["scenarios"]["shipment"]["effects"] === ["deliver" => "voided", "prepare" => "voided", "reserve" => "voided"]' \
  "The recovered configured Order did not publish its cancellation."
for example in "${examples[@]}"; do
  assert_unchanged "$example" baseline
  assert_unchanged "$example" retained
done
assert_replay_is_recorded "the re-upgraded candidate"

summary=$(machine provider machine:inspect)
printf 'laravel=%s php=%s db=%s baseline=%s candidate=%s provider_effects=%s provider_commands=%s recovery=verified rollback=refused downgrade=retained status=passed\n' \
  "$laravel_version" "$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')" "$db_connection" \
  "$baseline_version" "${candidate_version:-source}" \
  "$(printf '%s' "$summary" | json_field effects)" "$(printf '%s' "$summary" | json_field provider_commands)"
