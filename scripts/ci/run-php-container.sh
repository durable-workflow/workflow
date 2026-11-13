#!/usr/bin/env bash
set -euo pipefail

command="${0##*/}"
case "$command" in php|composer) ;; *) echo 'Invoke this launcher as php or composer.' >&2; exit 2 ;; esac
root="${WORKFLOW_PHP_CI_ROOT:?PHP CI repository root is required}"
state="${WORKFLOW_PHP_CI_STATE:?PHP CI state directory is required}"
image="${WORKFLOW_PHP_CI_IMAGE:?PHP CI image is required}"
if [[ ! "$image" =~ ^sha256:[0-9a-f]{64}$ ]]; then
    echo 'PHP CI must use the inspected immutable image config ID.' >&2
    exit 1
fi

options=(--rm --init --interactive --name "workflow-php-ci-${command}-${BASHPID}"
    --cpus 2 --memory 2g --pids-limit 512 --network host
    --cap-drop ALL --security-opt no-new-privileges
    --user "$(id -u):$(id -g)" --workdir "$PWD"
    --mount "type=bind,source=$root,target=$root"
    --mount "type=bind,source=$state,target=$state"
    --mount "type=bind,source=$root/docker/php-ci/ci-defaults.ini,target=/usr/local/etc/php/conf.d/zz-workflow-php-ci.ini,readonly")

# Composer's guard is a composite action outside the repository checkout.
if [[ -n "${GITHUB_ACTION_PATH:-}" ]]; then
    options+=(--mount "type=bind,source=$GITHUB_ACTION_PATH,target=$GITHUB_ACTION_PATH,readonly")
fi
if [[ -n "${WORKFLOW_COMPOSER_TRUST_VERIFIER:-}" ]]; then
    options+=(--mount "type=bind,source=$WORKFLOW_COMPOSER_TRUST_VERIFIER,target=$WORKFLOW_COMPOSER_TRUST_VERIFIER,readonly")
fi
# Preserve the original host-shared SQLite database and its WAL sidecars across
# PHP invocations without mounting the host's complete shared-memory directory.
if [[ -n "${WORKFLOW_UPGRADE_DATABASE_DIRECTORY:-}" ]]; then
    database="$WORKFLOW_UPGRADE_DATABASE_DIRECTORY"
    if [[ ! "$database" =~ ^/dev/shm/laravel-embedded-upgrade\.[a-zA-Z0-9]+$ &&
          ! "$database" =~ ^"$state"/tmp/laravel-embedded-upgrade\.[a-zA-Z0-9]+$ ]] ||
       [[ ! -d "$database" || -L "$database" || ! -O "$database" || "$(stat -c %a "$database")" != 700 ]]; then
        echo 'Upgrade database must be its owned private temporary directory.' >&2
        exit 1
    fi
    options+=(--mount "type=bind,source=$database,target=$database")
fi
# A local worktree has its Git metadata outside the source mount.
git_directory="$(git -C "$root" rev-parse --path-format=absolute --git-common-dir)"
if [[ "$git_directory" != "$root/"* ]]; then
    options+=(--mount "type=bind,source=$git_directory,target=$git_directory,readonly")
fi

declare -A mounted=()
for variable in SSL_CERT_FILE CURL_CA_BUNDLE GIT_SSL_CAINFO COMPOSER_CAFILE \
    REQUESTS_CA_BUNDLE PIP_CERT NPM_CONFIG_CAFILE CARGO_HTTP_CAINFO CARGO_HTTP_PROXY_CAINFO \
    SSL_CERT_DIR GIT_SSL_CAPATH COMPOSER_CAPATH; do
    if [[ -n "${!variable:-}" ]]; then
        selected="${!variable}"
        if [[ -z "${mounted[$selected]:-}" ]]; then
            options+=(--mount "type=bind,source=$selected,target=$selected,readonly")
            mounted[$selected]=1
        fi
        options+=(--env "$variable")
    fi
done

# Keep the original upgrade inputs and caller-owned dependency state.
while IFS= read -r variable; do
    case "$variable" in
        COMPOSER_HOME|COMPOSER_CACHE_DIR|COMPOSER_MAX_PARALLEL_*|COMPOSER_IPRESOLVE|\
        LARAVEL_VERSION|WORKFLOW_V2_VERSION|WORKFLOW_V2_PATH|KEEP_UPGRADE_FIXTURE|\
        TABLES|DATABASE_PATH|QUEUE_NAME|\
        WORKFLOW_SQLITE_TIMER_QUALIFICATION|WORKFLOW_QUEUE_QUALIFICATION_EVIDENCE|\
        DW_CI_CA_SHA256|GITHUB_ACTIONS|CI|RUNNER_TEMP|TMPDIR|TZ)
            options+=(--env "$variable") ;;
        *) ;; # Host credentials and unrelated configuration stay outside.
    esac
done < <(compgen -e)

# The host verifier discovers the launcher, but must inspect the real PHAR.
arguments=()
for argument in "$@"; do
    if [[ "$argument" == "$state/bin/composer" ]]; then
        argument=/usr/local/bin/composer
    fi
    arguments+=("$argument")
done
exec docker run "${options[@]}" "$image" "$command" "${arguments[@]}"
