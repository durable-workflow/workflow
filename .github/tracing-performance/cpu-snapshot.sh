#!/usr/bin/env bash
set -euo pipefail

project=${1:?Select the experiment Compose project}
[[ "$project" =~ ^[a-z0-9][a-z0-9-]{0,62}$ ]]
mapfile -t containers < <(docker ps -q --filter "label=com.docker.compose.project=$project")
[[ "${#containers[@]}" -eq 9 ]]
declare -A role_counts=([mysql]=0 [redis]=0 [app]=0 [worker]=0 [collector]=0 [probe]=0)

for container in "${containers[@]}"; do
    read -r process_id role full_id < <(docker inspect --format '{{.State.Pid}} {{index .Config.Labels "com.docker.compose.service"}} {{.Id}}' "$container")
    [[ "$process_id" =~ ^[1-9][0-9]*$ ]]
    [[ "$role" =~ ^(mysql|redis|app|worker|collector|probe)$ ]]
    role_counts[$role]=$((role_counts[$role] + 1))
    group=$(awk -F: '$1 == "0" {print $3}' "/proc/$process_id/cgroup")
    [[ "$group" == /* && "$group" != *..* ]]
    limits="/sys/fs/cgroup$group"
    jq -cn --arg container "$full_id" --arg role "$role" \
        --arg sampled_at "$(date -u '+%Y-%m-%dT%H:%M:%S.%NZ')" \
        --rawfile cpu "$limits/cpu.stat" --rawfile quota "$limits/cpu.max" \
        --rawfile memory_events "$limits/memory.events" \
        --rawfile memory_current "$limits/memory.current" \
        --rawfile memory_peak "$limits/memory.peak" \
        --rawfile memory_limit "$limits/memory.max" \
        --rawfile swap_limit "$limits/memory.swap.max" \
        '{container:$container,role:$role,sampled_at:$sampled_at,cpu:$cpu,cpu_max:$quota,
          memory_events:$memory_events,memory_max:$memory_limit,swap_max:$swap_limit,
          memory_current_bytes:($memory_current|tonumber),lifetime_memory_peak_bytes:($memory_peak|tonumber)}'
done

[[ "${role_counts[worker]}" -eq 4 ]]
for role in mysql redis app collector probe; do
    [[ "${role_counts[$role]}" -eq 1 ]]
done
