#!/usr/bin/env bash
set -euo pipefail

test "$(id -u)" -ne 0
cohort_cgroup=$(awk -F: '$1 == "0" {print $3}' /proc/self/cgroup)
test -n "$cohort_cgroup"
cohort_limits="/sys/fs/cgroup${cohort_cgroup}"
read -r cohort_quota cohort_period < "$cohort_limits/cpu.max"
test "$cohort_quota" != max
test "$cohort_quota" -eq "$((2 * cohort_period))"
test "$(cat "$cohort_limits/memory.max")" = 2147483648
test "$(cat "$cohort_limits/memory.swap.max")" = 0
printf 'Cohort cgroup: %s\n' "$cohort_cgroup"
printf 'CPU quota/period: %s/%s; memory: 2147483648 bytes; swap: 0\n' "$cohort_quota" "$cohort_period"
php -r 'echo json_encode(["xdebug_effective_modes" => function_exists("xdebug_info") ? xdebug_info("mode") : [], "pcntl" => extension_loaded("pcntl")], JSON_THROW_ON_ERROR), PHP_EOL;'
/usr/bin/time -v php .github/queue-contention/runner.php cohort
