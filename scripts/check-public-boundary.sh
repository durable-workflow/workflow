#!/usr/bin/env bash
set -euo pipefail

root="${1:-.}"
cd "$root"

status=0

pattern_from_hex() {
  local hex="$1"
  local output=""
  local byte

  while [[ -n "$hex" ]]; do
    byte="${hex:0:2}"
    hex="${hex:2}"
    output+=$(printf '%b' "\\x$byte")
  done

  printf '%s' "$output"
}

file_patterns=(
  "$(pattern_from_hex 7a6f72706f726174696f6e2f)"
  "$(pattern_from_hex 2f686f6d652f7673636f6465)"
  "$(pattern_from_hex 2f686f6d652f6c61622f776f726b73706163652d6871)"
)

metadata_patterns=(
  "${file_patterns[@]}"
  "$(pattern_from_hex 4c6f6f702d49443a)"
  "$(pattern_from_hex 2e746d702f6c6f6f70732f)"
  "$(pattern_from_hex 6c6f6f702d72756e6e6572)"
)

pathspec=(
  .
  ':!.git'
  ':!vendor'
  ':!node_modules'
  ':!build'
  ':!dist'
  ':!coverage'
  ':!storage'
  ':!bootstrap/cache'
  ':!public/build'
  ':!var'
)

for pattern in "${file_patterns[@]}"; do
  while IFS=: read -r file line _; do
    [[ -n "${file:-}" ]] || continue
    printf 'public-boundary: forbidden file content at %s:%s\n' "$file" "$line" >&2
    status=1
  done < <(git grep -n -I -e "$pattern" -- "${pathspec[@]}" || true)
done

if [[ -n "${PUBLIC_BOUNDARY_GIT_RANGE:-}" ]]; then
  read -r -a rev_args <<< "$PUBLIC_BOUNDARY_GIT_RANGE"
else
  rev_args=(-1 HEAD)
fi

if mapfile -t commits < <(git rev-list "${rev_args[@]}" 2>/dev/null); then
  for commit in "${commits[@]}"; do
    metadata="$(git show -s --format='%an <%ae>%n%s%n%b' "$commit")"

    for pattern in "${metadata_patterns[@]}"; do
      if grep -Fq -- "$pattern" <<< "$metadata"; then
        printf 'public-boundary: forbidden commit metadata at %s\n' "${commit:0:12}" >&2
        status=1
        break
      fi
    done
  done
else
  printf 'public-boundary: unable to inspect commit metadata range: %s\n' "${PUBLIC_BOUNDARY_GIT_RANGE:-HEAD}" >&2
  status=1
fi

exit "$status"
