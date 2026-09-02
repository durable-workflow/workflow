#!/usr/bin/env sh

set -eu

repo_root="$(CDPATH= cd -- "$(dirname "$0")/../.." && pwd)"
tmp_dir="$(mktemp -d "${RUNNER_TEMP:-${TMPDIR:-/tmp}}/workflow-resolver-audit-test.XXXXXX")"
authority_file="$tmp_dir/public-authority.json"
mirror_file="$tmp_dir/workflow-mirror.json"
api_file="$tmp_dir/worker-protocol-api.openapi.yaml"
stream_file="$tmp_dir/worker-protocol-stream.asyncapi.yaml"
output_file="$tmp_dir/audit-output.txt"
trap 'rm -rf "$tmp_dir"' EXIT HUP INT TERM

printf '%s\n' 'openapi: 3.1.0' > "$api_file"
printf '%s\n' 'asyncapi: 3.0.0' > "$stream_file"

api_digest="sha256:$(php -r 'echo hash_file("sha256", $argv[1]);' "$api_file")"
stream_digest="sha256:$(php -r 'echo hash_file("sha256", $argv[1]);' "$stream_file")"

write_manifest() {
    api_url="$1"
    stream_url="$2"
    expected_api_digest="$3"
    expected_stream_digest="$4"

    php -r '
        [$apiUrl, $streamUrl, $apiDigest, $streamDigest] = array_slice($argv, 1);
        $binding = static fn (string $id, string $url, string $digest): array => [
            "suite_version" => 45,
            "status" => "current",
            "lifecycle" => "lifecycle_neutral",
            "artifact_id" => $id,
            "resolver_url" => $url,
            "sha256" => $digest,
        ];
        echo json_encode([
            "version" => 45,
            "artifact_version_history" => [
                "worker_protocol_api" => [
                    "bindings" => [$binding("worker-protocol-api", $apiUrl, $apiDigest)],
                ],
                "worker_protocol_stream" => [
                    "bindings" => [$binding("worker-protocol-stream", $streamUrl, $streamDigest)],
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
    ' "$api_url" "$stream_url" "$expected_api_digest" "$expected_stream_digest" > "$mirror_file"
    cp "$mirror_file" "$authority_file"
}

run_audit() {
    PLATFORM_CONFORMANCE_AUTHORITY_URL="file://$authority_file" \
    WORKFLOW_PLATFORM_CONFORMANCE_MIRROR_FILE="$mirror_file" \
    PLATFORM_CONFORMANCE_AUTHORITY_ATTEMPTS=1 \
    PLATFORM_CONFORMANCE_AUTHORITY_RETRY_SLEEP=0 \
        "$repo_root/scripts/ci/check-platform-conformance-mirror.sh"
}

write_manifest "file://$api_file" "file://$stream_file" "$api_digest" "$stream_digest"
run_audit > "$output_file" 2>&1

missing_file="$tmp_dir/missing-worker-protocol-api.openapi.yaml"
write_manifest "file://$missing_file" "file://$stream_file" "$api_digest" "$stream_digest"
if run_audit > "$output_file" 2>&1; then
    printf '%s\n' 'Platform conformance resolver audit accepted a missing advertised resolver.' >&2
    exit 1
fi
case "$(cat "$output_file")" in
    *"Unable to retrieve worker-protocol-api resolver"*) ;;
    *)
        printf '%s\n' 'Missing-resolver failure did not identify the advertised worker protocol artifact.' >&2
        exit 1
        ;;
esac

wrong_digest='sha256:0000000000000000000000000000000000000000000000000000000000000000'
write_manifest "file://$api_file" "file://$stream_file" "$wrong_digest" "$stream_digest"
if run_audit > "$output_file" 2>&1; then
    printf '%s\n' 'Platform conformance resolver audit accepted resolver bytes with the wrong digest.' >&2
    exit 1
fi
case "$(cat "$output_file")" in
    *"Worker protocol resolver digest mismatch for worker-protocol-api"*) ;;
    *)
        printf '%s\n' 'Digest failure did not identify the divergent worker protocol artifact.' >&2
        exit 1
        ;;
esac

printf '%s\n' 'Platform conformance resolver audit rejects missing and divergent advertised protocol bytes.'
