#!/usr/bin/env bash
set -euo pipefail

events=${1:?Expected 10, 100 or 1000 events}
workers=${2:?Expected 1 or 4 workers}
runs=${3:?Expected 1 or 8 concurrent runs}
repetition=${4:?Expected repetition 1, 2 or 3}
case "$events" in 10|100|1000) ;; *) exit 2 ;; esac
case "$workers" in 1|4) ;; *) exit 2 ;; esac
case "$runs" in 1|8) ;; *) exit 2 ;; esac
case "$repetition" in 1|2|3) ;; *) exit 2 ;; esac
: "${DWF_PROFILE_IMAGE:?Build tests/Performance/continuation first}"

cd "$(dirname "$0")/.."
git diff --exit-code -- src resources tests scripts composer.json
cmp tests/Performance/continuation/COMPOSER-INPUT.lock composer.lock
git rev-parse HEAD HEAD^{tree}
sha256sum composer.lock
docker image inspect "$DWF_PROFILE_IMAGE" --format '{{.Id}}'

container=''
cleanup() {
    if [[ -n "$container" ]]; then docker container rm --force "$container" >/dev/null; fi
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
outcome=0
modes='replay retained'
if [[ "$repetition" == 2 ]]; then modes='retained replay'; fi
for mode in $modes; do
    container="dw-continuation-${GITHUB_RUN_ID:-local}-${repetition}-${mode}-$$"
    filter="${events}-events_${workers}-workers_${runs}-runs_${mode}_repeat-${repetition}"
    echo "CONTINUATION_CELL=$filter"
    if timeout --signal=TERM --kill-after=30s 62m \
        docker run --name "$container" --network none \
            --user "$(id -u):$(id -g)" --cpus 1 --memory 1g --memory-swap 1g \
            --volume "$PWD:/app" --workdir /app \
            --env PROFILE_EMBEDDED_CONTINUATION=measure \
            --env "DWF_GROWTH_OBSERVERS=${DWF_GROWTH_OBSERVERS:-on}" \
            "$DWF_PROFILE_IMAGE" \
            php vendor/bin/phpunit tests/Performance/EmbeddedContinuationGrowthProfileTest.php \
            --filter "$filter" --no-coverage --do-not-cache-result \
            --fail-on-empty-test-suite --fail-on-skipped --fail-on-warning; then
        status=0
    else
        status=$?
        outcome=1
    fi
    echo "CONTINUATION_CELL_EXIT=$status"
    docker container inspect "$container" --format \
        'CONTINUATION_CONTAINER={{json .Name}} exit={{.State.ExitCode}} oom={{.State.OOMKilled}} restarts={{.RestartCount}} cpu={{.HostConfig.NanoCpus}} memory={{.HostConfig.Memory}} memory_swap={{.HostConfig.MemorySwap}}'
    cleanup
    container=''
done
exit "$outcome"
