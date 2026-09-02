#!/usr/bin/env bash

set -euo pipefail

laravel_version="${LARAVEL_VERSION:?Set LARAVEL_VERSION to a supported Laravel constraint such as 12.*}"
workflow_v2_version="${WORKFLOW_V2_VERSION:-}"
workflow_v2_path="${WORKFLOW_V2_PATH:-}"

if [ -n "$workflow_v2_version" ] && [ -n "$workflow_v2_path" ]; then
  echo "Set only one of WORKFLOW_V2_VERSION or WORKFLOW_V2_PATH." >&2
  exit 1
fi

if [ -z "$workflow_v2_version" ] && [ -z "$workflow_v2_path" ]; then
  echo "Set WORKFLOW_V2_VERSION for a published release or WORKFLOW_V2_PATH for source qualification." >&2
  exit 1
fi

temporary_root="${RUNNER_TEMP:-${TMPDIR:-/tmp}}"
if [ -d /dev/shm ] && [ -w /dev/shm ]; then
  database_root=/dev/shm
else
  database_root="$temporary_root"
fi

fixture_root=$(mktemp -d "${temporary_root%/}/laravel-embedded-upgrade.XXXXXX")
database_path=$(mktemp "${database_root%/}/laravel-embedded-upgrade.XXXXXX.sqlite")
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
trap 'echo "Laravel embedded upgrade smoke failed at line ${LINENO}: ${BASH_COMMAND}" >&2' ERR

composer create-project "laravel/laravel:${laravel_version}" "$app_path" \
  --no-interaction --no-progress --prefer-dist --no-blocking --quiet
cd "$app_path"

set_env() {
  local key="$1"
  local value="$2"

  php -r '
    $path = ".env";
    $key = $argv[1];
    $value = $argv[2];
    $contents = file_get_contents($path);
    if (!is_string($contents)) {
        fwrite(STDERR, "Unable to read .env\n");
        exit(1);
    }
    $line = $key . "=" . $value;
    $pattern = "/^" . preg_quote($key, "/") . "=.*$/m";
    if (preg_match($pattern, $contents) === 1) {
        $contents = preg_replace($pattern, $line, $contents);
    } else {
        $contents = rtrim($contents) . PHP_EOL . $line . PHP_EOL;
    }
    file_put_contents($path, $contents);
  ' "$key" "$value"
}

set_env APP_NAME '"Embedded Upgrade Host"'
set_env APP_ENV testing
set_env DB_CONNECTION sqlite
set_env DB_DATABASE "$database_path"
set_env QUEUE_CONNECTION database
set_env CACHE_DRIVER file
set_env CACHE_STORE file
set_env DW_V2_QUEUE workflow-v2
php artisan key:generate --force --no-interaction

composer require durable-workflow/workflow:1.0.82 \
  --with-all-dependencies --no-interaction --no-progress --no-blocking --quiet

resolved_v1=$(composer show durable-workflow/workflow --format=json | php -r '
  $package = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
  echo ltrim((string) $package["versions"][0], "v");
')
if [ "$resolved_v1" != 1.0.82 ]; then
  echo "Expected latest stable 1.0.82, resolved ${resolved_v1}." >&2
  exit 1
fi

if ! grep -Rqs "Schema::create('jobs'" database/migrations; then
  php artisan queue:table --no-interaction
fi
php artisan vendor:publish \
  --provider='Workflow\Providers\WorkflowServiceProvider' \
  --tag=migrations --force --no-interaction
php artisan vendor:publish \
  --provider='Workflow\Providers\WorkflowServiceProvider' \
  --tag=config --force --no-interaction
php -r '
  require "vendor/autoload.php";
  $app = require "bootstrap/app.php";
  $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

  if (config("workflows.serializer") !== Workflow\Serializers\Y::class) {
      fwrite(STDERR, "The published stable-v1 config no longer retains the official Y serializer.\n");
      exit(1);
  }
'
php artisan migrate --force --no-interaction

write_v1_sources() {
  mkdir -p app/WorkflowUpgrade

  cat > app/WorkflowUpgrade/GreetingService.php <<'PHP'
<?php

namespace App\WorkflowUpgrade;

final class GreetingService
{
    public function greet(string $name): string
    {
        return sprintf('Hello, %s! (%s)', $name, config('app.name'));
    }
}
PHP

  cat > app/WorkflowUpgrade/GreetingActivity.php <<'PHP'
<?php

namespace App\WorkflowUpgrade;

use Illuminate\Support\Facades\Log;
use Workflow\Activity;

final class GreetingActivity extends Activity
{
    public $connection = 'database';

    public $queue = 'workflow-v1';

    public function execute(GreetingService $greetings, string $name): string
    {
        Log::info('embedded-upgrade-v1-activity', ['name' => $name]);

        return $greetings->greet($name);
    }
}
PHP

  cat > app/WorkflowUpgrade/GreetingWorkflow.php <<'PHP'
<?php

namespace App\WorkflowUpgrade;

use Workflow\Workflow;
use function Workflow\activity;

final class GreetingWorkflow extends Workflow
{
    public $connection = 'database';

    public $queue = 'workflow-v1';

    public function execute(string $name)
    {
        return yield activity(GreetingActivity::class, $name);
    }
}
PHP

  cat > app/WorkflowUpgrade/OpenWorkflow.php <<'PHP'
<?php

namespace App\WorkflowUpgrade;

use Workflow\SignalMethod;
use Workflow\Workflow;
use function Workflow\{activity, await};

final class OpenWorkflow extends Workflow
{
    public $connection = 'database';

    public $queue = 'workflow-v1';

    private bool $released = false;

    #[SignalMethod]
    public function proceed(): void
    {
        $this->released = true;
    }

    public function execute(string $name)
    {
        yield await(fn (): bool => $this->released);

        return yield activity(GreetingActivity::class, $name);
    }
}
PHP
}

write_v1_routes() {
  cat > routes/console.php <<'PHP'
<?php

use App\WorkflowUpgrade\GreetingWorkflow;
use App\WorkflowUpgrade\OpenWorkflow;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Workflow\Events\WorkflowCompleted;
use Workflow\Events\WorkflowStarted;
use Workflow\Models\StoredWorkflow;
use Workflow\WorkflowStub;

$eventLog = storage_path('logs/embedded-upgrade-events.log');
Event::listen(WorkflowStarted::class, static function (WorkflowStarted $event) use ($eventLog): void {
    file_put_contents($eventLog, "v1.started:{$event->workflowId}\n", FILE_APPEND);
});
Event::listen(WorkflowCompleted::class, static function (WorkflowCompleted $event) use ($eventLog): void {
    file_put_contents($eventLog, "v1.completed:{$event->workflowId}\n", FILE_APPEND);
});

Artisan::command('upgrade:v1-start {--open}', function (): void {
    $class = $this->option('open') ? OpenWorkflow::class : GreetingWorkflow::class;
    $workflow = WorkflowStub::make($class);
    $workflow->start('Laravel');

    $this->line(json_encode([
        'engine' => 'v1',
        'id' => $workflow->id(),
        'logical_id' => 'upgrade-greeting',
        'class' => $class,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
});

Artisan::command('upgrade:v1-inspect {id}', function (): void {
    $workflow = WorkflowStub::load($this->argument('id'));
    $stored = StoredWorkflow::query()->findOrFail($this->argument('id'));

    $this->line(json_encode([
        'engine' => 'v1',
        'id' => $workflow->id(),
        'status' => $stored->getRawOriginal('status'),
        'completed' => $workflow->completed(),
        'output' => $workflow->output(),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
});

Artisan::command('upgrade:v1-release {id}', function (): void {
    WorkflowStub::load($this->argument('id'))->proceed();
    $this->line('released');
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
    $snapshot[$table] = $statement === false ? [] : $statement->fetchAll(PDO::FETCH_ASSOC);
}
echo hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));
PHP
}

json_field() {
  php -r '
    $data = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    echo $data[$argv[1]];
  ' "$1"
}

artisan_output() {
  local output

  if ! output=$(php artisan "$@" 2>&1); then
    printf '%s\n' "$output" >&2
    return 1
  fi

  printf '%s' "$output"
}

queue_depth() {
  QUEUE_NAME="$1" DATABASE_PATH="$database_path" php -r '
    $database = new PDO("sqlite:" . getenv("DATABASE_PATH"));
    $statement = $database->prepare("SELECT COUNT(*) FROM jobs WHERE queue = :queue");
    $statement->execute(["queue" => getenv("QUEUE_NAME")]);
    echo (int) $statement->fetchColumn();
  '
}

drain_queue() {
  local queue="$1"
  local remaining=0

  for pass in $(seq 1 20); do
    timeout 120 php artisan queue:work database --queue="$queue" \
      --stop-when-empty --sleep=1 --tries=3 --no-interaction
    remaining=$(queue_depth "$queue")
    if [ "$remaining" -eq 0 ]; then
      return 0
    fi
  done

  echo "Queue ${queue} still has ${remaining} job(s) after 20 drain passes." >&2
  return 1
}

write_v1_sources
write_v1_routes
composer dump-autoload --no-interaction --no-scripts --quiet

v1_start=$(artisan_output upgrade:v1-start)
v1_id=$(printf '%s' "$v1_start" | json_field id)
drain_queue workflow-v1
v1_result=$(artisan_output upgrade:v1-inspect "$v1_id")
printf '%s' "$v1_result" | php -r '
  $result = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
  if ($result["completed"] !== true || $result["output"] !== "Hello, Laravel! (Embedded Upgrade Host)") {
      fwrite(STDERR, "Stable v1 representative workflow did not complete with the injected dependency.\n");
      exit(1);
  }
'

v1_open_start=$(artisan_output upgrade:v1-start --open)
v1_open_id=$(printf '%s' "$v1_open_start" | json_field id)
drain_queue workflow-v1
v1_open_result=$(artisan_output upgrade:v1-inspect "$v1_open_id")
printf '%s' "$v1_open_result" | php -r '
  $result = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
  if ($result["status"] !== "waiting" || $result["completed"] !== false) {
      fwrite(STDERR, "The v1 coexistence run did not remain open.\n");
      exit(1);
  }
'

grep -q 'embedded-upgrade-v1-activity' storage/logs/laravel.log
grep -q 'v1.completed:' storage/logs/embedded-upgrade-events.log
v1_tables='workflows,workflow_logs,workflow_signals,workflow_timers,workflow_exceptions,workflow_relationships'
v1_state_before=$(hash_tables "$v1_tables")

if [ -n "$workflow_v2_path" ]; then
  workflow_v2_path=$(cd "$workflow_v2_path" && pwd)
  composer config repositories.workflow "$(php -r '
    echo json_encode([
        "type" => "path",
        "url" => $argv[1],
        "options" => [
            "symlink" => false,
            "versions" => ["durable-workflow/workflow" => "2.0.x-dev"],
        ],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
  ' "$workflow_v2_path")"
  composer require durable-workflow/workflow:2.0.x-dev \
    --with-all-dependencies --no-interaction --no-progress --no-blocking --quiet
  expected_v2='2.0.x-dev'
else
  composer require "durable-workflow/workflow:=${workflow_v2_version}" \
    --with-all-dependencies --no-interaction --no-progress --no-blocking --quiet
  expected_v2="$workflow_v2_version"
fi

resolved_v2=$(composer show durable-workflow/workflow --format=json | php -r '
  $package = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
  echo ltrim((string) $package["versions"][0], "v");
')
if [ "$resolved_v2" != "$expected_v2" ]; then
  echo "Expected embedded v2 ${expected_v2}, resolved ${resolved_v2}." >&2
  exit 1
fi

php artisan migrate --force --no-interaction
php artisan queue:restart --no-interaction

artisan_output workflow:v2:doctor --strict >/dev/null
doctor_json=$(artisan_output workflow:v2:doctor --strict --json)
printf '%s' "$doctor_json" | php -r '
  $snapshot = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
  $codec = $snapshot["codec"] ?? [];
  $issues = array_values(array_filter(
      $snapshot["issues"] ?? [],
      static fn (mixed $issue): bool => is_array($issue) && ($issue["component"] ?? null) === "codec",
  ));
  $issue = $issues[0] ?? [];
  $message = (string) ($issue["message"] ?? "");

  if (($snapshot["supported"] ?? false) !== true
      || ($codec["configured"] ?? null) !== Workflow\Serializers\Y::class
      || !array_key_exists("configured_canonical", $codec)
      || $codec["configured_canonical"] !== null
      || ($codec["canonical"] ?? null) !== "avro"
      || ($codec["configured_universal"] ?? true) !== false
      || ($codec["supported"] ?? false) !== true
      || count($issues) !== 1
      || ($issue["code"] ?? null) !== "codec_legacy_v1_drain"
      || ($issue["severity"] ?? null) !== "warning"
      || !str_contains($message, "ignores this setting and uses \"avro\" for all new v2 payloads")
      || !str_contains($message, "may remain only while v1 runs are draining")) {
      fwrite(STDERR, "The retained stable-v1 serializer did not produce the expected nonblocking migration diagnostic.\n");
      exit(1);
  }
'

if php artisan workflow:v2:upgrade-status --strategy=drain --json; then
  echo 'Drain strategy unexpectedly accepted an open v1 run.' >&2
  exit 1
fi

coexist_status=$(artisan_output workflow:v2:upgrade-status --strategy=coexist --json)
printf '%s' "$coexist_status" | php -r '
  $status = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
  if ($status["ready"] !== true
      || $status["v1"]["history_owner"] !== "v1"
      || $status["embedded_v2"]["history_owner"] !== "embedded_v2"
      || $status["state_transfer"] !== "none") {
      fwrite(STDERR, "Coexistence status did not preserve explicit state ownership.\n");
      exit(1);
  }
'

v1_state_after=$(hash_tables "$v1_tables")
if [ "$v1_state_before" != "$v1_state_after" ]; then
  echo 'Installing and migrating v2 changed v1 durable state.' >&2
  exit 1
fi

write_v2_sources() {
  cat > app/WorkflowUpgrade/GreetingActivity.php <<'PHP'
<?php

namespace App\WorkflowUpgrade;

use Illuminate\Support\Facades\Log;
use Workflow\V2\Activity;
use Workflow\V2\Attributes\Type;

#[Type('upgrade.greeting-activity')]
final class GreetingActivity extends Activity
{
    public function __construct(private readonly GreetingService $greetings)
    {
    }

    public function handle(GreetingService $methodGreetings, string $name): string
    {
        Log::info('embedded-upgrade-v2-activity', ['name' => $name]);

        $constructorGreeting = $this->greetings->greet($name);
        $methodGreeting = $methodGreetings->greet($name);

        if ($constructorGreeting !== $methodGreeting) {
            throw new \RuntimeException('Constructor and method dependency resolution diverged.');
        }

        return $methodGreeting;
    }
}
PHP

  cat > app/WorkflowUpgrade/GreetingWorkflow.php <<'PHP'
<?php

namespace App\WorkflowUpgrade;

use Workflow\UpdateMethod;
use Workflow\V2\Attributes\Signal;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\{activity, signal};
use Workflow\V2\Workflow;

#[Type('upgrade.greeting')]
#[Signal('finish', [['name' => 'confirmation', 'type' => 'string']])]
final class GreetingWorkflow extends Workflow
{
    private bool $approved = false;

    public function __construct(private readonly GreetingService $greetings)
    {
    }

    public function handle(GreetingService $methodGreetings, string $name): array
    {
        $constructorGreeting = $this->greetings->greet($name);
        $workflowGreeting = $methodGreetings->greet($name);

        if ($constructorGreeting !== $workflowGreeting) {
            throw new \RuntimeException('Constructor and method dependency resolution diverged.');
        }

        $activityGreeting = activity(GreetingActivity::class, $name);
        $confirmation = signal('finish');

        return [
            'workflow_greeting' => $workflowGreeting,
            'activity_greeting' => $activityGreeting,
            'confirmation' => $confirmation,
            'approved' => $this->approved,
            'workflow_id' => $this->workflowId(),
            'run_id' => $this->runId(),
        ];
    }

    #[UpdateMethod]
    public function approve(bool $approved): bool
    {
        $this->approved = $approved;

        return $this->approved;
    }
}
PHP
}

write_v2_routes() {
  cat > routes/console.php <<'PHP'
<?php

use App\WorkflowUpgrade\GreetingActivity;
use App\WorkflowUpgrade\GreetingWorkflow;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Workflow\V2\Events\WorkflowCompleted;
use Workflow\V2\Events\WorkflowStarted;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\StartOptions;
use Workflow\V2\WorkflowStub;

$eventLog = storage_path('logs/embedded-upgrade-events.log');
Event::listen(WorkflowStarted::class, static function (WorkflowStarted $event) use ($eventLog): void {
    file_put_contents($eventLog, "v2.started:{$event->instanceId}:{$event->runId}\n", FILE_APPEND);
});
Event::listen(WorkflowCompleted::class, static function (WorkflowCompleted $event) use ($eventLog): void {
    file_put_contents($eventLog, "v2.completed:{$event->instanceId}:{$event->runId}\n", FILE_APPEND);
});

Artisan::command('upgrade:v2-start', function (): void {
    $workflow = WorkflowStub::make(GreetingWorkflow::class, 'upgrade-greeting');
    $start = $workflow->start('Laravel', new StartOptions(
        memo: ['upgraded_from' => 'stable-v1'],
        searchAttributes: ['MigrationStage' => 'embedded-v2'],
    ));

    $this->line(json_encode([
        'engine' => 'embedded_v2',
        'workflow_id' => $start->workflowId(),
        'run_id' => $start->runId(),
        'workflow_type' => 'upgrade.greeting',
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
});

Artisan::command('upgrade:v2-finish', function (): void {
    $workflow = WorkflowStub::load('upgrade-greeting');
    $update = $workflow->attemptUpdate('approve', true);
    $signal = $workflow->signal('finish', 'approved');

    $this->line(json_encode([
        'update_accepted' => $update->accepted(),
        'signal_accepted' => $signal->accepted(),
    ], JSON_THROW_ON_ERROR));
});

Artisan::command('upgrade:v2-inspect', function (): void {
    $workflow = WorkflowStub::load('upgrade-greeting')->refresh();
    $queues = WorkflowTask::query()
        ->where('workflow_run_id', $workflow->runId())
        ->pluck('queue')
        ->unique()
        ->values()
        ->all();

    $this->line(json_encode([
        'status' => $workflow->status(),
        'output' => $workflow->output(),
        'workflow_id' => $workflow->workflowId(),
        'run_id' => $workflow->runId(),
        'payload_codec' => $workflow->payloadCodec(),
        'memo' => $workflow->memo(),
        'search_attributes' => $workflow->searchAttributes(),
        'queues' => $queues,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
});

Artisan::command('upgrade:v2-test-surface', function (): void {
    WorkflowStub::fake();
    $workflow = WorkflowStub::make(GreetingWorkflow::class, 'upgrade-greeting-fake');
    $workflow->start('Test');
    while (WorkflowStub::runReadyTasks() > 0) {
    }
    WorkflowStub::assertDispatched(GreetingActivity::class);

    $this->line('fake-and-assertion=passed');
});
PHP
}

write_v2_sources
write_v2_routes
composer dump-autoload --no-interaction --no-scripts --quiet

v2_start=$(artisan_output upgrade:v2-start)
printf '%s' "$v2_start" | php -r '
  $result = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
  if ($result["workflow_id"] !== "upgrade-greeting" || $result["workflow_type"] !== "upgrade.greeting") {
      fwrite(STDERR, "Embedded v2 did not preserve application-owned identity.\n");
      exit(1);
  }
'
drain_queue workflow-v2
v2_finish=$(artisan_output upgrade:v2-finish)
printf '%s' "$v2_finish" | php -r '
  $result = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
  if ($result["update_accepted"] !== true || $result["signal_accepted"] !== true) {
      fwrite(STDERR, "Embedded v2 update or signal was not accepted.\n");
      exit(1);
  }
'
drain_queue workflow-v2
v2_result=$(artisan_output upgrade:v2-inspect)
printf '%s' "$v2_result" | php -r '
  $result = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
  $output = $result["output"];
  if ($result["status"] !== "completed"
      || $output["workflow_greeting"] !== "Hello, Laravel! (Embedded Upgrade Host)"
      || $output["activity_greeting"] !== $output["workflow_greeting"]
      || $output["confirmation"] !== "approved"
      || $output["approved"] !== true
      || $result["payload_codec"] !== "avro"
      || $result["memo"]["upgraded_from"] !== "stable-v1"
      || $result["search_attributes"]["MigrationStage"] !== "embedded-v2"
      || $result["queues"] !== ["workflow-v2"]) {
      fwrite(STDERR, "Embedded v2 representative workflow evidence was incomplete.\n");
      exit(1);
  }
'

php artisan upgrade:v2-test-surface
grep -q 'embedded-upgrade-v2-activity' storage/logs/laravel.log
grep -q 'v2.completed:upgrade-greeting:' storage/logs/embedded-upgrade-events.log

v2_tables='workflow_instances,workflow_runs,workflow_history_events,workflow_tasks,workflow_commands,workflow_updates,workflow_signal_records,workflow_memos,workflow_search_attributes'
v2_state_before_rollback=$(hash_tables "$v2_tables")

write_v1_sources
write_v1_routes
composer dump-autoload --no-interaction --no-scripts --quiet
if [ -n "$workflow_v2_path" ]; then
  composer config --unset repositories.workflow
fi
composer require durable-workflow/workflow:^1.0 \
  --with-all-dependencies --no-interaction --no-progress --no-blocking --quiet

resolved_rollback=$(composer show durable-workflow/workflow --format=json | php -r '
  $package = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
  echo ltrim((string) $package["versions"][0], "v");
')
if [[ "$resolved_rollback" != 1.* ]]; then
  echo "Rollback did not restore stable 1.x: ${resolved_rollback}." >&2
  exit 1
fi

v2_state_after_rollback=$(hash_tables "$v2_tables")
if [ "$v2_state_before_rollback" != "$v2_state_after_rollback" ]; then
  echo 'Rolling application code back to stable 1.x changed v2 durable state.' >&2
  exit 1
fi

php artisan upgrade:v1-release "$v1_open_id"
drain_queue workflow-v1
v1_rollback_result=$(artisan_output upgrade:v1-inspect "$v1_open_id")
printf '%s' "$v1_rollback_result" | php -r '
  $result = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
  if ($result["completed"] !== true || $result["output"] !== "Hello, Laravel! (Embedded Upgrade Host)") {
      fwrite(STDERR, "The v1-owned open run did not complete after rollback.\n");
      exit(1);
  }
'

printf 'laravel=%s php=%s stable_v1=%s embedded_v2=%s rollback=%s status=passed\n' \
  "$laravel_version" "$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')" \
  "$resolved_v1" "$resolved_v2" "$resolved_rollback"
