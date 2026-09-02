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
workflow_install_path_file="$tmp_dir/workflow-install-path.txt"
history_resolver_file="$tmp_dir/history-export-resolver.json"
worker_protocol_resolvers_file="$tmp_dir/worker-protocol-resolvers.tsv"
trap 'rm -rf "$tmp_dir"' EXIT HUP INT TERM

version="${version#v}"

case "$attempts" in
    ''|*[!0-9]*) echo "PLATFORM_CONFORMANCE_AUTHORITY_ATTEMPTS must be a positive integer." >&2; exit 2 ;;
esac

download_with_retry() {
    download_url="$1"
    download_file="$2"
    download_label="$3"
    attempt=1

    while [ "$attempt" -le "$attempts" ]; do
        if curl -fsSL --retry 3 --retry-all-errors --connect-timeout 10 --max-time 30 \
            -o "$download_file" "$download_url"; then
            return
        fi

        if [ "$attempt" -eq "$attempts" ]; then
            echo "Unable to retrieve $download_label from $download_url." >&2
            exit 1
        fi

        sleep "$sleep_seconds"
        attempt=$((attempt + 1))
    done
}

download_with_retry "$authority_url" "$authority_file" "public platform conformance authority"

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
    WORKFLOW_AUDIT_INSTALL_PATH_OUTPUT="$workflow_install_path_file" \
    php -r '
        require getenv("WORKFLOW_AUDIT_AUTOLOAD");
        $expected = getenv("WORKFLOW_AUDIT_EXPECTED_VERSION");
        $installed = Composer\InstalledVersions::getPrettyVersion("durable-workflow/workflow")
            ?: Composer\InstalledVersions::getVersion("durable-workflow/workflow");
        if ($installed !== $expected) {
            fwrite(STDERR, "Installed Workflow version {$installed} does not match {$expected}.\n");
            exit(1);
        }
        if (Workflow\V2\Support\PlatformConformanceSuite::workflowSourceRelease() !== $expected) {
            fwrite(STDERR, "Workflow source release identity does not match {$expected}.\n");
            exit(1);
        }
        $manifest = Workflow\V2\Support\PlatformConformanceSuite::manifest();
        file_put_contents(
            getenv("WORKFLOW_AUDIT_OUTPUT"),
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n",
        );
        $installPath = Composer\InstalledVersions::getInstallPath("durable-workflow/workflow");
        if (!is_string($installPath) || !is_dir($installPath)) {
            fwrite(STDERR, "Published Workflow package install path is unavailable.\n");
            exit(1);
        }
        if (file_put_contents(getenv("WORKFLOW_AUDIT_INSTALL_PATH_OUTPUT"), $installPath) === false) {
            fwrite(STDERR, "Unable to retain the Workflow package install path for release audit.\n");
            exit(1);
        }
    '

    history_resolver_url="$(php "$repo_root/scripts/ci/verify-history-export-source-identity.php" \
        resolver-url "$workflow_file")"
    download_with_retry "$history_resolver_url" "$history_resolver_file" \
        "published history-export schema resolver"

    workflow_install_path="$(cat "$workflow_install_path_file")"
    php "$repo_root/scripts/ci/verify-history-export-source-identity.php" \
        verify "$workflow_file" "$workflow_install_path" "$history_resolver_file"
fi

php "$repo_root/scripts/ci/compare-platform-conformance-mirrors.php" "$workflow_file" "$authority_file"

php "$repo_root/scripts/ci/list-worker-protocol-resolvers.php" \
    "$workflow_file" > "$worker_protocol_resolvers_file"

tab="$(printf '\t')"
resolver_number=0
while IFS="$tab" read -r artifact_id resolver_url expected_digest; do
    resolver_number=$((resolver_number + 1))
    resolver_file="$tmp_dir/worker-protocol-resolver-$resolver_number"

    download_with_retry "$resolver_url" "$resolver_file" "$artifact_id resolver"

    actual_digest="sha256:$(php -r 'echo hash_file("sha256", $argv[1]);' "$resolver_file")"
    if [ "$actual_digest" != "$expected_digest" ]; then
        echo "Worker protocol resolver digest mismatch for $artifact_id: expected $expected_digest, got $actual_digest from $resolver_url." >&2
        exit 1
    fi

    printf 'Verified %s at %s (%s).\n' "$artifact_id" "$resolver_url" "$actual_digest"
done < "$worker_protocol_resolvers_file"
