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
evidence_path="${DOCS_RELEASE_AUDIT_EVIDENCE:-}"

write_unavailable_evidence() {
    message="$1"

    [ -n "$evidence_path" ] || return 0

    node - "$evidence_path" "$artifact" "$expected" "$audit_url" "$message" <<'NODE'
const fs = require('fs');

const [evidencePath, artifact, expected, auditUrl, message] = process.argv.slice(2);

fs.writeFileSync(evidencePath, `${JSON.stringify({
  schema: 'durable-workflow.release.docs-release-audit-evidence',
  checked_at: new Date().toISOString(),
  surface: 'public_docs_release_audit',
  audit_url: auditUrl,
  artifact,
  expected_version: expected,
  outcome: 'unavailable',
  message,
}, null, 2)}\n`);
NODE
}

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
audit_path="${tmp_dir}/docs-page-release-audit-${artifact}-${expected}-$$.json"
trap 'rm -f "$audit_path"' EXIT HUP INT TERM
attempt=1

while [ "$attempt" -le "$attempts" ]; do
    if curl -fsSL --retry 3 --retry-all-errors --connect-timeout 10 --max-time 30 -o "$audit_path" "$audit_url"; then
        if node - "$audit_path" "$artifact" "$expected" "$audit_url" "$evidence_path" <<'NODE'
const fs = require('fs');

const [auditPath, artifact, expected, auditUrl, evidencePath] = process.argv.slice(2);
const title = 'Docs release-audit tuple stale';

function releaseCheckSource() {
  const serverUrl = process.env.GITHUB_SERVER_URL || 'https://github.com';
  const repository = process.env.GITHUB_REPOSITORY || null;
  const runId = process.env.GITHUB_RUN_ID || null;
  const runAttempt = process.env.GITHUB_RUN_ATTEMPT || null;

  return {
    repository,
    ref: process.env.GITHUB_REF_NAME || null,
    sha: process.env.GITHUB_SHA || null,
    run_id: runId,
    run_attempt: runAttempt,
    run_url: repository && runId
      ? `${serverUrl}/${repository}/actions/runs/${runId}`
      : null,
  };
}

function docsRefreshRequest(message, actualVersion, observedVersions) {
  const staleArtifact = {
    name: artifact,
    expected_version: expected,
    live_version: actualVersion,
  };

  return {
    schema: 'durable-workflow.docs.refresh-request',
    reason: 'public_docs_release_audit_stale',
    repository: 'durable-workflow.github.io',
    target_branch: 'main',
    refresh_command: 'npm run refresh:public-artifact-versions',
    stale_artifact: staleArtifact,
    observed_artifact_versions: observedVersions,
    source_release_check: releaseCheckSource(),
    ready_item: {
      title: `Refresh public docs artifact tuple for ${artifact} ${expected}`,
      body: [
        message,
        '',
        `Expected ${artifact} ${expected}; live docs release audit reports ${actualVersion || '<missing>'}.`,
        'Refresh scripts/public-artifact-versions.json and docs/compatibility.md through the normal docs merge path.',
      ].join('\n'),
      acceptance: [
        'The public docs release-audit JSON reports the current published artifact tuple.',
        'Stable 1.x remains the default public docs line.',
        'The refresh lands through the docs merge gate, not from a public release workflow.',
      ],
    },
  };
}

function writeEvidence(outcome, extra = {}) {
  if (!evidencePath) {
    return;
  }

  fs.writeFileSync(evidencePath, `${JSON.stringify({
    schema: 'durable-workflow.release.docs-release-audit-evidence',
    checked_at: new Date().toISOString(),
    surface: 'public_docs_release_audit',
    audit_url: auditUrl,
    artifact,
    expected_version: expected,
    source_release_check: releaseCheckSource(),
    outcome,
    ...extra,
  }, null, 2)}\n`);
}

function retry(message) {
  writeEvidence('retry', {message});
  console.error(message);
  process.exit(3);
}

function fail(message, extra = {}) {
  writeEvidence('stale', {
    message,
    ...extra,
  });

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
  const actualVersion = Object.prototype.hasOwnProperty.call(versions, artifact) ? actual : null;
  const message = `${auditUrl} reports artifact_versions.${artifact}=${actual || '<missing>'}, expected ${expected}. ` +
    'Run npm run refresh:public-artifact-versions in durable-workflow.github.io and land scripts/public-artifact-versions.json plus docs/compatibility.md through the normal docs merge path before treating this release as fully surfaced.';

  fail(
    `${message} When DOCS_RELEASE_AUDIT_EVIDENCE is set, the uploaded evidence includes a docs_refresh_request payload for the gate-owned refresh path.`,
    {
      actual_version: actualVersion,
      observed_artifact_versions: versions,
      docs_refresh_request: docsRefreshRequest(message, actualVersion, versions),
    }
  );
}

writeEvidence('pass', {actual_version: actual});
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

message="Could not fetch ${audit_url} after ${attempts} attempt(s)."
write_unavailable_evidence "$message"
fail "Docs release-audit unavailable" "$message"
