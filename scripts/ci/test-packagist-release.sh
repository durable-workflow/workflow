#!/usr/bin/env sh

set -eu

repo_root="$(CDPATH= cd -- "$(dirname "$0")/../.." && pwd)"
tmp_dir="$(mktemp -d "${TMPDIR:-/tmp}/workflow-packagist-test.XXXXXX")"
trap 'rm -rf "$tmp_dir"' EXIT
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM
mkdir "$tmp_dir/bin" "$tmp_dir/runtime"
printf '%s\n' 'untouched' > "$tmp_dir/victim"
ln -s "$tmp_dir/victim" "$tmp_dir/runtime/packagist-package.json"
cat > "$tmp_dir/bin/curl" <<'CURL'
#!/usr/bin/env sh
set -eu
output=''
while [ "$#" -gt 0 ]; do
    case "$1" in
        -o) output="$2"; shift 2 ;;
        --proto|--proto-redir|--retry|--connect-timeout|--max-time) shift 2 ;;
        *) shift ;;
    esac
done
printf '%s\n' "$output" >> "$PACKAGIST_TEST_PATHS"
if [ "${PACKAGIST_TEST_FAIL:-0}" = 1 ]; then exit 22; fi
printf '%s\n' '{"packages":{"fixture/workflow":[{"version":"2.20.4","dist":{"url":"https://package.example/dist.zip"}}]}}' > "$output"
stat -c '%a' "$output" >> "$PACKAGIST_TEST_MODES"
CURL
chmod 700 "$tmp_dir/bin/curl"
export PACKAGIST_TEST_PATHS="$tmp_dir/paths"
export PACKAGIST_TEST_MODES="$tmp_dir/modes"
export RUNNER_TEMP="$tmp_dir/runtime"
export GITHUB_STEP_SUMMARY="$tmp_dir/summary"
export PACKAGIST_PACKAGE_NAME=fixture/workflow
export PACKAGIST_PACKAGE_VERSION=2.20.4
export PACKAGIST_RELEASE_ATTEMPTS=1
export PACKAGIST_RELEASE_RETRY_SLEEP=0
PATH="$tmp_dir/bin:$PATH"
export PATH

"$repo_root/scripts/ci/check-packagist-release.sh"
"$repo_root/scripts/ci/check-packagist-release.sh"
if PACKAGIST_TEST_FAIL=1 "$repo_root/scripts/ci/check-packagist-release.sh" > "$tmp_dir/failure" 2>&1; then
    printf '%s\n' 'Failed Packagist transport unexpectedly passed.' >&2
    exit 1
fi
if [ "$(cat "$tmp_dir/victim")" != untouched ]; then
    printf '%s\n' 'Packagist verification followed the old shared-path symlink.' >&2
    exit 1
fi
if [ "$(sort -u "$tmp_dir/paths" | wc -l | tr -d ' ')" -ne 3 ]; then
    printf '%s\n' 'Packagist temporary paths were reused.' >&2
    exit 1
fi
while IFS= read -r path; do
    if [ -e "$path" ] || [ -L "$path" ]; then
        printf '%s\n' 'Packagist temporary file survived its invocation.' >&2
        exit 1
    fi
done < "$tmp_dir/paths"
if [ "$(sort -u "$tmp_dir/modes")" != 600 ]; then
    printf '%s\n' 'Packagist temporary files were not private.' >&2
    exit 1
fi
printf '%s\n' 'Packagist verification owns distinct private temporary files and cleans success and failure.'
