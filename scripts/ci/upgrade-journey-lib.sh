# shellcheck shell=bash
#
# Shared steps for the installed upgrade journeys
# (compensation-scope-upgrade-smoke.sh, state-machine-upgrade-smoke.sh).
#
# Source this file, then call upgrade_journey_init. The journeys run in the
# Laravel application directory created by create_upgrade_app.

# Reads the candidate selection from the environment and validates it.
upgrade_journey_init() {
  baseline_version="${WORKFLOW_BASELINE_VERSION:-$1}"
  candidate_version="${WORKFLOW_CANDIDATE_VERSION:-}"
  candidate_path="${WORKFLOW_CANDIDATE_PATH:-}"

  if [[ -n "$candidate_version" && -n "$candidate_path" ]]; then
    echo "Set only one of WORKFLOW_CANDIDATE_VERSION or WORKFLOW_CANDIDATE_PATH." >&2
    exit 1
  fi

  if [[ -z "$candidate_version" && -z "$candidate_path" ]]; then
    echo "Set WORKFLOW_CANDIDATE_VERSION for a published release or WORKFLOW_CANDIDATE_PATH for source qualification." >&2
    exit 1
  fi

  if [[ -n "$candidate_path" ]]; then
    candidate_path=$(cd "$candidate_path" && pwd)
  fi
}

create_upgrade_app() {
  local version="$1" target="$2"
  composer create-project "laravel/laravel:${version}" "$target" \
    --no-interaction --no-progress --prefer-dist --no-blocking --quiet
}

set_env() {
  local key="$1" value="$2"
  php -r '
    $contents = file_get_contents(".env");
    if (!is_string($contents)) {
        fwrite(STDERR, "Unable to read .env\n");
        exit(1);
    }
    $line = $argv[1] . "=" . $argv[2];
    $pattern = "/^" . preg_quote($argv[1], "/") . "=.*$/m";
    $contents = preg_match($pattern, $contents) === 1
        ? preg_replace($pattern, $line, $contents)
        : rtrim($contents) . PHP_EOL . $line . PHP_EOL;
    file_put_contents(".env", $contents);
  ' "$key" "$value"
}

# Appends a provider class to bootstrap/providers.php once.
register_provider() {
  local provider="$1"
  php -r '
    $path = "bootstrap/providers.php";
    $providers = require $path;
    if (!in_array($argv[1], $providers, true)) {
        $providers[] = $argv[1];
    }
    file_put_contents($path, "<?php\n\nreturn " . var_export($providers, true) . ";\n");
  ' "$provider"
}

resolved_workflow() {
  composer show durable-workflow/workflow --format=json | php -r '
    $package = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    echo ltrim((string) $package["versions"][0], "v");
  '
}

assert_resolved_workflow() {
  local expected="$1" resolved
  resolved=$(resolved_workflow)
  if [[ "$resolved" != "$expected" ]]; then
    echo "Expected durable-workflow/workflow ${expected}, resolved ${resolved}." >&2
    exit 1
  fi
}

require_baseline() {
  if [[ -n "$candidate_path" ]]; then
    composer config --unset repositories.workflow
  fi
  composer require "durable-workflow/workflow:=${baseline_version}" \
    --with-all-dependencies --no-interaction --no-progress --no-blocking --quiet
  assert_resolved_workflow "$baseline_version"
}

require_candidate() {
  if [[ -n "$candidate_path" ]]; then
    composer config repositories.workflow "$(php -r '
      echo json_encode([
          "type" => "path",
          "url" => $argv[1],
          "options" => [
              "symlink" => false,
              "versions" => ["durable-workflow/workflow" => "2.0.x-dev"],
          ],
      ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    ' "$candidate_path")"
    composer require durable-workflow/workflow:2.0.x-dev \
      --with-all-dependencies --no-interaction --no-progress --no-blocking --quiet
    assert_resolved_workflow 2.0.x-dev
  else
    composer require "durable-workflow/workflow:=${candidate_version}" \
      --with-all-dependencies --no-interaction --no-progress --no-blocking --quiet
    assert_resolved_workflow "$candidate_version"
  fi
}

json_assert() {
  local json="$1"
  local expression="$2"
  local message="$3"

  printf '%s' "$json" | EXPRESSION="$expression" MESSAGE="$message" php -r '
    $r = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    if (eval("return " . getenv("EXPRESSION") . ";") !== true) {
        fwrite(STDERR, getenv("MESSAGE") . "\n" . json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        exit(1);
    }
  '
}

json_field() {
  local field="$1"
  php -r '
    $data = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    echo $data[$argv[1]];
  ' "$field"
}

artisan_output() {
  local output

  if ! output=$(php artisan "$@" 2>&1); then
    printf '%s\n' "$output" >&2
    return 1
  fi

  printf '%s' "$output"
}

# Runs migrate:rollback, which must be refused because retained compensation
# scopes exist. Fails unless the refusal comes from the package preflight.
expect_rollback_refused() {
  local rollback_output

  if rollback_output=$(COLUMNS=1000 php artisan migrate:rollback --force --no-interaction 2>&1 | tr -s ' \n' ' '; exit "${PIPESTATUS[0]}"); then
    printf '%s\n' "$rollback_output" >&2
    echo "$1" >&2
    exit 1
  fi
  if ! grep -q 'Retained compensation scopes cannot be discarded by a schema rollback' <<<"$rollback_output" \
    || ! grep -q 'refused before any migration ran' <<<"$rollback_output"; then
    printf '%s\n' "$rollback_output" >&2
    echo 'Schema rollback failed for an unexpected reason.' >&2
    exit 1
  fi
}
