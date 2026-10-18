# Published embedded Nexus qualification

This profile uses a normally installed Core preview, a real SQLite database
queue and a new `queue:work --once` PHP process for each delivery. It exercises
same-namespace and cross-namespace completion, failure, target cancellation,
waiting-caller cancellation, backend interruption and external payloads.
Each original has a 30-second observer budget;
the recorded call/execution deadlines and cancellation cleanup budget remain
separate and immutable.

Use PHP 8.3 or later with 64-bit integers, Composer and PDO SQLite in a disposable
runtime container. Give its user writable `/work` and `/state` directories.
Copy this example into `/work/app`; keep the package source outside that app.
From `/work/app`, prepare the CLI application:

```sh
set -eu
export DB_CONNECTION=sqlite DB_DATABASE=/state/workflow.sqlite
export QUEUE_CONNECTION=database LOG_CHANNEL=stderr
export APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')"
mkdir -p /state/storage/framework/cache/data /state/storage/framework/sessions \
  /state/storage/framework/views /state/storage/logs
touch "$DB_DATABASE"

# This CLI profile does not install the example's optional HTTP host packages.
rm config/octane.php composer.lock
cp qualification/nexus-providers.php bootstrap/providers.php
php -r '
$path = "composer.json";
$composer = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
$composer["require"] = [
    "php" => "^8.3",
    "durable-workflow/workflow" => "2.6.0-rc.1",
    "laravel/framework" => "^12.69.3",
];
file_put_contents($path, json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
'
composer install --prefer-dist --no-interaction
php artisan migrate --force
php qualification/nexus.php
```

The application provider uses the package's normal local-filesystem external
storage driver with a 1,024-byte threshold. The large case must retain real
external argument/result references and return the exact original data through
a separate cold reader. Every cold read must leave database bytes unchanged.
The backend control temporarily denies access to its own SQLite DELETE-mode
file, requires a real pre-claim refusal without changing the database, restores
the original permissions, and completes the same run inside its original budget.

The probe refuses an existing workflow database, a package outside `vendor`,
the wrong package version/reference, an in-memory database, or synchronous
queue dispatch. It seeds the service catalog and starts workflows through the
public API. Queue processes perform the workflow transitions. It checks the
original run/call bindings, one terminal service event, typed int64/null/Unicode
results and portable failure types, cancellation lineage/deadline, and no
runnable tasks left on the terminal originals.

Nexus failure replay may use `RestoredWorkflowException`; its `failurePayload()`
retains the canonical error type. The probe requires that type and message to
match the service's failure and durable call metadata.

The output contains small per-case results and a final summary with package,
framework and lock identities. Destroy the disposable container and its database
after reviewing the result. Keep the fixture code and the result in the owning
qualification record.
