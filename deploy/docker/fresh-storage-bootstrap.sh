#!/bin/sh
set -eu

SCRIPT_DIR=$(CDPATH= cd -P "$(dirname "$0")" && pwd)
PROJECT_ROOT=$(CDPATH= cd -P "$SCRIPT_DIR/../.." && pwd)
VALIDATOR=$SCRIPT_DIR/validate-fresh-storage.php
COMPOSE_FILE=$PROJECT_ROOT/docker-compose.storage-bootstrap.yml
HELPER_IMAGE=alpine:3.20@sha256:d9e853e87e55526f6b2917df91a2115c36dd7c696a35be12163d44e6e2a4b6bc
ACTION=
BOOTSTRAP_OUTPUT=
LAYOUT_OUTPUT=
RUNTIME_INSPECT=
UPLOADS_INSPECT=

fail() {
    printf '%s\n' "$1" >&2
    exit 1
}

usage() {
    printf '%s\n' 'usage: fresh-storage-bootstrap.sh [--project-root PATH] prepare|finalize|status' >&2
    exit 2
}

cleanup() {
    for path in "$BOOTSTRAP_OUTPUT" "$LAYOUT_OUTPUT" "$RUNTIME_INSPECT" "$UPLOADS_INSPECT"; do
        if [ -n "$path" ] && [ -f "$path" ] && [ ! -L "$path" ]; then
            chmod 0600 "$path" 2>/dev/null || true
            rm -f "$path"
        fi
    done
}
trap cleanup 0
trap 'exit 1' HUP INT TERM

while [ "$#" -gt 0 ]; do
    case "$1" in
        --project-root)
            [ "$#" -ge 2 ] || usage
            PROJECT_ROOT=$2
            shift 2
            ;;
        prepare|finalize|status)
            [ -z "$ACTION" ] || usage
            ACTION=$1
            shift
            ;;
        *) usage ;;
    esac
done
[ -n "$ACTION" ] || usage
[ -d "$PROJECT_ROOT" ] && [ ! -L "$PROJECT_ROOT" ] || fail FRESH_STORAGE_PROJECT_ROOT_INVALID
PROJECT_ROOT=$(CDPATH= cd -P "$PROJECT_ROOT" && pwd)
[ -f "$VALIDATOR" ] && [ ! -L "$VALIDATOR" ] || fail FRESH_STORAGE_VALIDATOR_INVALID
[ -f "$COMPOSE_FILE" ] && [ ! -L "$COMPOSE_FILE" ] || fail FRESH_STORAGE_COMPOSE_INVALID
command -v php >/dev/null 2>&1 || fail FRESH_STORAGE_PHP_UNAVAILABLE
command -v docker >/dev/null 2>&1 || fail FRESH_STORAGE_DOCKER_UNAVAILABLE
[ "$(uname -s)" = Linux ] || fail FRESH_STORAGE_HOST_OS_UNSUPPORTED
if ! sh "$SCRIPT_DIR/host-preflight.sh" --check --project-root "$PROJECT_ROOT" >/dev/null; then
    fail FRESH_STORAGE_HOST_PREFLIGHT_REQUIRED
fi

case "$(uname -m)" in
    x86_64|amd64) architecture=amd64 ;;
    aarch64|arm64) architecture=arm64 ;;
    *) fail AGENT_BINARY_ARCH_UNSUPPORTED ;;
esac
AGENT=$PROJECT_ROOT/upgrade/bin/mallbase-agent-linux-$architecture
[ -f "$AGENT" ] && [ ! -L "$AGENT" ] && [ -x "$AGENT" ] || fail AGENT_BINARY_MISSING

umask 077
BOOTSTRAP_OUTPUT=$(mktemp "${TMPDIR:-/tmp}/mallbase-storage-bootstrap.XXXXXX") || fail FRESH_STORAGE_TEMP_FAILED
LAYOUT_OUTPUT=$(mktemp "${TMPDIR:-/tmp}/mallbase-storage-layout.XXXXXX") || fail FRESH_STORAGE_TEMP_FAILED
RUNTIME_INSPECT=$(mktemp "${TMPDIR:-/tmp}/mallbase-runtime-volume.XXXXXX") || fail FRESH_STORAGE_TEMP_FAILED
UPLOADS_INSPECT=$(mktemp "${TMPDIR:-/tmp}/mallbase-uploads-volume.XXXXXX") || fail FRESH_STORAGE_TEMP_FAILED
chmod 0600 "$BOOTSTRAP_OUTPUT" "$LAYOUT_OUTPUT" "$RUNTIME_INSPECT" "$UPLOADS_INSPECT"

if ! (cd "$PROJECT_ROOT" && printf '{}\n' | "$AGENT" storage bootstrap-id) > "$BOOTSTRAP_OUTPUT"; then
    fail FRESH_STORAGE_BOOTSTRAP_ID_FAILED
fi
operation_id=$(php "$VALIDATOR" bootstrap-field "$BOOTSTRAP_OUTPUT" operation_id)
namespace=$(php "$VALIDATOR" bootstrap-field "$BOOTSTRAP_OUTPUT" installation_storage_namespace)
agent_uid=$(php "$VALIDATOR" bootstrap-field "$BOOTSTRAP_OUTPUT" agent_uid)
shared_gid=$(php "$VALIDATOR" bootstrap-field "$BOOTSTRAP_OUTPUT" shared_gid)
php "$VALIDATOR" write-namespace-env "$BOOTSTRAP_OUTPUT" "$PROJECT_ROOT"

if (cd "$PROJECT_ROOT" && printf '{}\n' | "$AGENT" storage inspect) > "$LAYOUT_OUTPUT" 2>/dev/null; then
    existing_state=$(php "$VALIDATOR" layout-field "$LAYOUT_OUTPUT" "$operation_id" state)
    case "$existing_state" in
        provisioning)
            if [ "$ACTION" = prepare ]; then
                replay_control=$(printf '{"expected_authority_revision":0,"operation_id":"%s"}\n' "$operation_id")
                if ! (cd "$PROJECT_ROOT" && printf '%s' "$replay_control" | "$AGENT" storage prepare) > "$LAYOUT_OUTPUT"; then
                    fail FRESH_STORAGE_PREPARE_REPLAY_FAILED
                fi
                [ "$(php "$VALIDATOR" layout-field "$LAYOUT_OUTPUT" "$operation_id" state)" = provisioning ] \
                    || fail FRESH_STORAGE_PREPARE_STATE_INVALID
                printf 'MALLBASE_STORAGE_OPERATION_ID=%s\n' "$operation_id"
                printf 'MALLBASE_STORAGE_NAMESPACE=%s\n' "$namespace"
                exit 0
            fi
            ;;
        ready)
            if [ "$ACTION" = finalize ]; then
                ready_revision=$(php "$VALIDATOR" layout-field "$LAYOUT_OUTPUT" "$operation_id" authority_revision)
                ready_control=$(printf '{"expected_authority_revision":%s,"operation_id":"%s"}\n' "$ready_revision" "$operation_id")
                if ! (cd "$PROJECT_ROOT" && printf '%s' "$ready_control" | "$AGENT" storage finalize) > "$LAYOUT_OUTPUT"; then
                    fail FRESH_STORAGE_FINALIZE_REPLAY_FAILED
                fi
                [ "$(php "$VALIDATOR" layout-field "$LAYOUT_OUTPUT" "$operation_id" state)" = ready ] \
                    || fail FRESH_STORAGE_FINALIZE_STATE_INVALID
            fi
            printf '%s\n' 'MALLBASE_STORAGE_STATE=ready'
            exit 0
            ;;
        fresh) ;;
        *) fail FRESH_STORAGE_LAYOUT_STATE_INVALID ;;
    esac
fi

if [ "$ACTION" = status ]; then
    if ! (cd "$PROJECT_ROOT" && printf '{}\n' | "$AGENT" storage inspect) > "$LAYOUT_OUTPUT"; then
        fail FRESH_STORAGE_LAYOUT_INSPECT_FAILED
    fi
    state=$(php "$VALIDATOR" layout-field "$LAYOUT_OUTPUT" "$operation_id" state)
    printf 'MALLBASE_STORAGE_STATE=%s\n' "$state"
    exit 0
fi

# The helper remains networkless. Pulling the one fixed digest is an explicit
# host action and happens before either application volume is created.
if ! docker image inspect "$HELPER_IMAGE" >/dev/null 2>&1; then
    docker pull "$HELPER_IMAGE" >/dev/null || fail FRESH_STORAGE_HELPER_IMAGE_PULL_FAILED
fi

runtime_name=${namespace}_runtime
uploads_name=${namespace}_uploads
create_volume() {
    role=$1
    name=$2
    docker volume create --driver local \
        --label "com.mallbase.storage.namespace=$namespace" \
        --label "com.mallbase.storage.role=$role" \
        --label com.mallbase.storage.layout-version=1 \
        --label com.mallbase.storage.layout-generation=1 \
        --label com.mallbase.storage.managed=true \
        "$name" >/dev/null || fail FRESH_STORAGE_VOLUME_CREATE_FAILED
}
create_volume runtime "$runtime_name"
create_volume uploads "$uploads_name"
docker volume inspect "$runtime_name" > "$RUNTIME_INSPECT" || fail FRESH_STORAGE_VOLUME_INSPECT_FAILED
docker volume inspect "$uploads_name" > "$UPLOADS_INSPECT" || fail FRESH_STORAGE_VOLUME_INSPECT_FAILED

export MALLBASE_STORAGE_OPERATION_ID=$operation_id
export MALLBASE_STORAGE_NAMESPACE=$namespace
export MALLBASE_AGENT_UID=$agent_uid
export MALLBASE_UPGRADE_SHARED_GID=$shared_gid
export MALLBASE_RUNTIME_VOLUME_NAME=$runtime_name
export MALLBASE_RUNTIME_MOUNT_IDENTITY
MALLBASE_RUNTIME_MOUNT_IDENTITY=$(php "$VALIDATOR" docker-field "$RUNTIME_INSPECT" "$namespace" runtime mount_identity)
export MALLBASE_RUNTIME_POLICY_SHA256
MALLBASE_RUNTIME_POLICY_SHA256=$(php "$VALIDATOR" docker-field "$RUNTIME_INSPECT" "$namespace" runtime policy_sha256)
export MALLBASE_UPLOADS_VOLUME_NAME=$uploads_name
export MALLBASE_UPLOADS_MOUNT_IDENTITY
MALLBASE_UPLOADS_MOUNT_IDENTITY=$(php "$VALIDATOR" docker-field "$UPLOADS_INSPECT" "$namespace" uploads mount_identity)
export MALLBASE_UPLOADS_POLICY_SHA256
MALLBASE_UPLOADS_POLICY_SHA256=$(php "$VALIDATOR" docker-field "$UPLOADS_INSPECT" "$namespace" uploads policy_sha256)

results_root=$PROJECT_ROOT/upgrade/storage-init-results
work_root=$results_root/.helper-$operation_id
[ -d "$results_root" ] && [ ! -L "$results_root" ] || fail FRESH_STORAGE_RESULTS_ROOT_INVALID
if [ -e "$work_root" ]; then
    [ -d "$work_root" ] && [ ! -L "$work_root" ] || fail FRESH_STORAGE_HELPER_RESULT_INVALID
else
    mkdir "$work_root" || fail FRESH_STORAGE_HELPER_RESULT_CREATE_FAILED
fi
chmod 0770 "$work_root" || fail FRESH_STORAGE_HELPER_RESULT_MODE_FAILED

compose() {
    docker compose --project-directory "$PROJECT_ROOT" --file "$COMPOSE_FILE" "$@"
}

publish_result() {
    source=$1
    target=$2
    [ -f "$source" ] && [ ! -L "$source" ] || fail FRESH_STORAGE_HELPER_RESULT_MISSING
    if [ -e "$target" ] || [ -L "$target" ]; then
        [ -f "$target" ] && [ ! -L "$target" ] && cmp -s "$source" "$target" \
            || fail FRESH_STORAGE_RESULT_CONFLICT
        rm -f "$source"
    else
        mv "$source" "$target" || fail FRESH_STORAGE_RESULT_PUBLISH_FAILED
    fi
}

if [ "$ACTION" = prepare ]; then
    if [ ! -f "$work_root/fresh-inspection.json" ]; then
        if ! compose run --rm --no-deps fresh-storage-inspect; then
            fail FRESH_STORAGE_INSPECTION_FAILED
        fi
    fi
    publish_result "$work_root/fresh-inspection.json" "$results_root/fresh-inspection.json"
    rmdir "$work_root" 2>/dev/null || true
    control=$(printf '{"expected_authority_revision":0,"operation_id":"%s"}\n' "$operation_id")
    if ! (cd "$PROJECT_ROOT" && printf '%s' "$control" | "$AGENT" storage prepare) > "$LAYOUT_OUTPUT"; then
        fail FRESH_STORAGE_PREPARE_FAILED
    fi
    state=$(php "$VALIDATOR" layout-field "$LAYOUT_OUTPUT" "$operation_id" state)
    [ "$state" = provisioning ] || fail FRESH_STORAGE_PREPARE_STATE_INVALID
    printf 'MALLBASE_STORAGE_OPERATION_ID=%s\n' "$operation_id"
    printf 'MALLBASE_STORAGE_NAMESPACE=%s\n' "$namespace"
    exit 0
fi

if ! (cd "$PROJECT_ROOT" && printf '{}\n' | "$AGENT" storage inspect) > "$LAYOUT_OUTPUT"; then
    fail FRESH_STORAGE_LAYOUT_INSPECT_FAILED
fi
state=$(php "$VALIDATOR" layout-field "$LAYOUT_OUTPUT" "$operation_id" state)
if [ "$state" = ready ]; then
    printf '%s\n' 'MALLBASE_STORAGE_STATE=ready'
    exit 0
fi
[ "$state" = provisioning ] || fail FRESH_STORAGE_FINALIZE_STATE_INVALID
revision=$(php "$VALIDATOR" layout-field "$LAYOUT_OUTPUT" "$operation_id" authority_revision)
request=$PROJECT_ROOT/upgrade/staging/storage-init/$operation_id/request.json
layout_generation=$(php "$VALIDATOR" request-field "$request" "$operation_id" layout_generation)
request_namespace=$(php "$VALIDATOR" request-field "$request" "$operation_id" namespace)
[ "$request_namespace" = "$namespace" ] || fail FRESH_STORAGE_REQUEST_NAMESPACE_INVALID
export MALLBASE_STORAGE_LAYOUT_GENERATION=$layout_generation
export MALLBASE_STORAGE_INIT_REQUEST_SHA256
MALLBASE_STORAGE_INIT_REQUEST_SHA256=$(php "$VALIDATOR" request-field "$request" "$operation_id" sha256)
if [ ! -f "$work_root/$operation_id.json" ]; then
    if ! compose run --rm --no-deps fresh-storage-stamp; then
        fail FRESH_STORAGE_STAMP_FAILED
    fi
fi
publish_result "$work_root/$operation_id.json" "$results_root/$operation_id.json"
rmdir "$work_root" 2>/dev/null || true
control=$(printf '{"expected_authority_revision":%s,"operation_id":"%s"}\n' "$revision" "$operation_id")
if ! (cd "$PROJECT_ROOT" && printf '%s' "$control" | "$AGENT" storage finalize) > "$LAYOUT_OUTPUT"; then
    fail FRESH_STORAGE_FINALIZE_FAILED
fi
[ "$(php "$VALIDATOR" layout-field "$LAYOUT_OUTPUT" "$operation_id" state)" = ready ] \
    || fail FRESH_STORAGE_FINALIZE_STATE_INVALID
printf '%s\n' 'MALLBASE_STORAGE_STATE=ready'
