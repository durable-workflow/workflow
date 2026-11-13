#!/usr/bin/env sh
set -eu

inputs="${1:?Brotli verification input directory is required.}"
shift
version="$(cat "$inputs/version")"
test -n "$version"

apt-get update
apt-get install -y --no-install-recommends \
    "libbrotli1=$version" "libbrotli-dev=$version" "$@"
