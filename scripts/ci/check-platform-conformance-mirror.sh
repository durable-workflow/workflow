#!/usr/bin/env sh

set -eu

authority_url="${PLATFORM_CONFORMANCE_AUTHORITY_URL:-https://durable-workflow.com/platform-conformance-contract.json}"
version="${WORKFLOW_PLATFORM_CONFORMANCE_VERSION:-${GITHUB_REF_NAME:-}}"
mirror_file="${WORKFLOW_PLATFORM_CONFORMANCE_MIRROR_FILE:-}"
attempts="${PLATFORM_CONFORMANCE_AUTHORITY_ATTEMPTS:-6}"
sleep_seconds="${PLATFORM_CONFORMANCE_AUTHORITY_RETRY_SLEEP:-20}"
repo_root="$(CDPATH= cd -- "$(dirname "$0")/../.." && pwd)"
tmp_dir="$(mktemp -d "${RUNNER_TEMP:-${TMPDIR:-/tmp}}/workflow-platform-conformance.XXXXXX")"
authority_file="$tmp_dir/public-authority.json"
workflow_file="$tmp_dir/workflow-mirror.json"
trap 'rm -rf "$tmp_dir"' EXIT HUP INT TERM

version="${version#v}"

case "$attempts" in
    ''|*[!0-9]*) echo "PLATFORM_CONFORMANCE_AUTHORITY_ATTEMPTS must be a positive integer." >&2; exit 2 ;;
esac

attempt=1
while [ "$attempt" -le "$attempts" ]; do
    if curl -fsSL --retry 3 --retry-all-errors --connect-timeout 10 --max-time 30 -o "$authority_file" "$authority_url"; then
        break
    fi

    if [ "$attempt" -eq "$attempts" ]; then
        echo "Unable to retrieve public platform conformance authority from $authority_url." >&2
        exit 1
    fi

    sleep "$sleep_seconds"
    attempt=$((attempt + 1))
done

if [ -n "$mirror_file" ]; then
    cp "$mirror_file" "$workflow_file"
else
    if [ -z "$version" ]; then
        echo "WORKFLOW_PLATFORM_CONFORMANCE_VERSION or GITHUB_REF_NAME must name the published Workflow prerelease." >&2
        exit 2
    fi

    composer --working-dir="$tmp_dir" init --name=durable-workflow/conformance-release-audit --no-interaction
    composer --working-dir="$tmp_dir" require --no-interaction --no-progress --prefer-dist \
        "durable-workflow/workflow:$version"

    WORKFLOW_AUDIT_AUTOLOAD="$tmp_dir/vendor/autoload.php" \
    WORKFLOW_AUDIT_EXPECTED_VERSION="$version" \
    WORKFLOW_AUDIT_OUTPUT="$workflow_file" \
    php -r '
        require getenv("WORKFLOW_AUDIT_AUTOLOAD");
        $expected = getenv("WORKFLOW_AUDIT_EXPECTED_VERSION");
        $installed = Composer\InstalledVersions::getPrettyVersion("durable-workflow/workflow")
            ?: Composer\InstalledVersions::getVersion("durable-workflow/workflow");
        if ($installed !== $expected) {
            fwrite(STDERR, "Installed Workflow version {$installed} does not match {$expected}.\n");
            exit(1);
        }
        file_put_contents(
            getenv("WORKFLOW_AUDIT_OUTPUT"),
            json_encode(Workflow\V2\Support\PlatformConformanceSuite::manifest(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n",
        );
    '
fi

php "$repo_root/scripts/ci/compare-platform-conformance-mirrors.php" "$workflow_file" "$authority_file"
