#!/usr/bin/env sh

set -eu

fail() {
    title="$1"
    message="$2"

    if [ -n "${GITHUB_STEP_SUMMARY:-}" ]; then
        {
            printf '## %s\n\n' "$title"
            printf '%s\n' "$message"
        } >> "$GITHUB_STEP_SUMMARY"
    fi

    printf '::error title=%s::%s\n' "$title" "$message" >&2
    printf '%s\n' "$message" >&2
    exit 1
}

artifact="${DOCS_RELEASE_AUDIT_ARTIFACT:-}"
expected="${DOCS_RELEASE_AUDIT_VERSION:-${GITHUB_REF_NAME:-}}"
audit_url="${DOCS_RELEASE_AUDIT_URL:-https://durable-workflow.com/docs-page-release-audit.json}"
attempts="${DOCS_RELEASE_AUDIT_ATTEMPTS:-6}"
sleep_seconds="${DOCS_RELEASE_AUDIT_RETRY_SLEEP:-20}"

case "$artifact" in
    cli|sdk-python|server|workflow|waterline) ;;
    *) fail "Docs release-audit artifact required" "DOCS_RELEASE_AUDIT_ARTIFACT must be one of cli, sdk-python, server, workflow, or waterline." ;;
esac

expected="${expected#v}"
if [ -z "$expected" ]; then
    fail "Docs release-audit version required" "DOCS_RELEASE_AUDIT_VERSION or GITHUB_REF_NAME must name the published artifact version."
fi

case "$attempts" in
    ''|*[!0-9]*) fail "Invalid docs release-audit retry count" "DOCS_RELEASE_AUDIT_ATTEMPTS must be a positive integer." ;;
esac
case "$sleep_seconds" in
    ''|*[!0-9]*) fail "Invalid docs release-audit retry delay" "DOCS_RELEASE_AUDIT_RETRY_SLEEP must be a non-negative integer." ;;
esac
if [ "$attempts" -lt 1 ]; then
    fail "Invalid docs release-audit retry count" "DOCS_RELEASE_AUDIT_ATTEMPTS must be at least 1."
fi

tmp_dir="${RUNNER_TEMP:-${TMPDIR:-/tmp}}"
audit_path="${tmp_dir}/docs-page-release-audit.json"
attempt=1

while [ "$attempt" -le "$attempts" ]; do
    if curl -fsSL --retry 3 --retry-all-errors --connect-timeout 10 --max-time 30 -o "$audit_path" "$audit_url"; then
        if node - "$audit_path" "$artifact" "$expected" "$audit_url" <<'NODE'
const fs = require('fs');

const [auditPath, artifact, expected, auditUrl] = process.argv.slice(2);
const title = 'Docs release-audit tuple stale';

function retry(message) {
  console.error(message);
  process.exit(3);
}

function fail(message) {
  if (process.env.GITHUB_STEP_SUMMARY) {
    fs.appendFileSync(
      process.env.GITHUB_STEP_SUMMARY,
      `## ${title}\n\n${message}\n\n`
    );
  }
  console.error(`::error title=${title}::${message}`);
  console.error(message);
  process.exit(2);
}

let audit;
try {
  audit = JSON.parse(fs.readFileSync(auditPath, 'utf8'));
} catch (err) {
  retry(`${auditUrl} did not return parseable JSON: ${err.message}`);
}

if (audit.schema !== 'durable-workflow.docs.page-release-audit') {
  retry(`${auditUrl} returned schema ${audit.schema || '<missing>'}, not durable-workflow.docs.page-release-audit.`);
}

const versions = audit.artifact_versions;
if (!versions || typeof versions !== 'object' || Array.isArray(versions)) {
  retry(`${auditUrl} must contain an artifact_versions object.`);
}

const actual = versions[artifact];
if (actual !== expected) {
  fail(
    `${auditUrl} reports artifact_versions.${artifact}=${actual || '<missing>'}, expected ${expected}. ` +
    'Run npm run refresh:public-artifact-versions in durable-workflow.github.io and land scripts/public-artifact-versions.json plus docs/compatibility.md through the normal docs merge path before treating this release as fully surfaced.'
  );
}

console.log(`${auditUrl} confirms artifact_versions.${artifact}=${expected}.`);
NODE
        then
            exit 0
        else
            node_status=$?
            if [ "$node_status" -eq 2 ]; then
                exit 1
            fi
        fi
    fi

    if [ "$attempt" -lt "$attempts" ]; then
        printf 'Waiting for docs release-audit JSON (%s/%s): %s\n' "$attempt" "$attempts" "$audit_url" >&2
        sleep "$sleep_seconds"
    fi
    attempt=$((attempt + 1))
done

fail "Docs release-audit unavailable" "Could not fetch ${audit_url} after ${attempts} attempt(s)."
