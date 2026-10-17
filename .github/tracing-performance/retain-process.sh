#!/bin/sh
set -u

: "${BENCH_STATE:?Select disposable state}" "${BENCH_ROLE:?Select worker or probe}" "${HOSTNAME:?Missing container identity}"
case "$BENCH_ROLE" in worker|probe) ;; *) exit 2 ;; esac
case "$#" in 0) exit 2 ;; esac

if [ -n "${BENCH_RELEASE_FILE:-}" ]; then
    while [ ! -f "$BENCH_RELEASE_FILE" ]; do sleep 0.05; done
fi

"$@"
retained_status=$?
printf '%s\n' "$retained_status" > "$BENCH_STATE/$BENCH_ROLE-$HOSTNAME.exit"

# Keep the cgroup readable after the child and its provider shutdown finish.
exec sleep infinity
