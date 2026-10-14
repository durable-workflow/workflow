#!/bin/sh
set -eu

# Run inside each application container. RSS includes masters and workers.
count=${1:-120}
index=0
while [ "$index" -lt "$count" ]; do
    cpu=$(awk '$1 == "usage_usec" {print $2}' /sys/fs/cgroup/cpu.stat)
    memory=$(cat /sys/fs/cgroup/memory.current)
    peak=$(cat /sys/fs/cgroup/memory.peak)
    swap=$(cat /sys/fs/cgroup/memory.swap.current)
    rss=$(awk '/^VmRSS:/ {sum += $2} END {print sum + 0}' /proc/[0-9]*/status 2>/dev/null || true)
    printf '{"time":%s,"cpu_usec":%s,"memory_bytes":%s,"memory_peak_bytes":%s,"swap_bytes":%s,"rss_kib":%s}\n' \
        "$(date +%s)" "$cpu" "$memory" "$peak" "$swap" "$rss"
    index=$((index + 1))
    sleep 1
done
