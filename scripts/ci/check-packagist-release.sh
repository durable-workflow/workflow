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

package_name="${PACKAGIST_PACKAGE_NAME:-}"
package_version="${PACKAGIST_PACKAGE_VERSION:-${GITHUB_REF_NAME:-}}"
attempts="${PACKAGIST_RELEASE_ATTEMPTS:-20}"
sleep_seconds="${PACKAGIST_RELEASE_RETRY_SLEEP:-30}"

if [ -z "$package_name" ]; then
    fail "Packagist package required" "PACKAGIST_PACKAGE_NAME must name the Composer package to verify."
fi

package_version="${package_version#v}"
if [ -z "$package_version" ]; then
    fail "Packagist version required" "PACKAGIST_PACKAGE_VERSION or GITHUB_REF_NAME must name the published package version."
fi

case "$attempts" in
    ''|*[!0-9]*) fail "Invalid Packagist retry count" "PACKAGIST_RELEASE_ATTEMPTS must be a positive integer." ;;
esac
case "$sleep_seconds" in
    ''|*[!0-9]*) fail "Invalid Packagist retry delay" "PACKAGIST_RELEASE_RETRY_SLEEP must be a non-negative integer." ;;
esac
if [ "$attempts" -lt 1 ]; then
    fail "Invalid Packagist retry count" "PACKAGIST_RELEASE_ATTEMPTS must be at least 1."
fi

package_url="https://repo.packagist.org/p2/${package_name}.json"
tmp_dir="${RUNNER_TEMP:-${TMPDIR:-/tmp}}"
package_path="${tmp_dir}/packagist-package.json"
attempt=1

while [ "$attempt" -le "$attempts" ]; do
    if curl -fsSL --retry 3 --retry-all-errors --connect-timeout 10 --max-time 30 -o "$package_path" "$package_url"; then
        if node - "$package_path" "$package_name" "$package_version" "$package_url" <<'NODE'
const fs = require('fs');

const [packagePath, packageName, packageVersion, packageUrl] = process.argv.slice(2);

let payload;
try {
  payload = JSON.parse(fs.readFileSync(packagePath, 'utf8'));
} catch (err) {
  console.error(`Packagist response from ${packageUrl} was not parseable JSON: ${err.message}`);
  process.exit(3);
}

const entries = payload.packages && payload.packages[packageName];
if (!Array.isArray(entries)) {
  console.error(`Packagist response from ${packageUrl} did not contain packages.${packageName}.`);
  process.exit(3);
}

const found = entries.some(entry => entry && entry.version === packageVersion && (entry.dist || entry.source));
if (!found) {
  console.error(`${packageUrl} does not list ${packageName} ${packageVersion} with source or dist metadata yet.`);
  process.exit(3);
}

console.log(`${packageUrl} lists ${packageName} ${packageVersion}.`);
NODE
        then
            exit 0
        fi
    fi

    if [ "$attempt" -lt "$attempts" ]; then
        printf 'Waiting for Packagist release (%s/%s): %s %s\n' "$attempt" "$attempts" "$package_name" "$package_version" >&2
        sleep "$sleep_seconds"
    fi
    attempt=$((attempt + 1))
done

fail "Packagist release unavailable" "Packagist did not list ${package_name} ${package_version} after ${attempts} attempt(s)."
