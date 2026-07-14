#!/bin/sh
set -eu

SCRIPT_DIR=$(CDPATH= cd -P "$(dirname "$0")" && pwd)
PROJECT_ROOT=$(CDPATH= cd -P "$SCRIPT_DIR/../.." && pwd)
VALIDATOR=$SCRIPT_DIR/validate-sealed-attestation.php
STORAGE_VALIDATOR=$SCRIPT_DIR/validate-fresh-storage.php
RECEIPT_ID=
VERIFY_REQUEST=
IMAGE_RECEIPT=
INSPECT_OUTPUT=
CONTAINER_INSPECT=
STORAGE_LAYOUT=
STORAGE_FINALIZE_OUTPUT=

fail() {
    printf '%s\n' "$1" >&2
    exit 1
}

usage() {
    printf '%s\n' 'usage: start-sealed-image.sh [--project-root PATH] RECEIPT_ID' >&2
    exit 2
}

cleanup() {
    for path in "$VERIFY_REQUEST" "$IMAGE_RECEIPT" "$INSPECT_OUTPUT" "$CONTAINER_INSPECT" \
        "$STORAGE_LAYOUT" "$STORAGE_FINALIZE_OUTPUT"; do
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
        --*) usage ;;
        *)
            [ -z "$RECEIPT_ID" ] || usage
            RECEIPT_ID=$1
            shift
            ;;
    esac
done

printf '%s\n' "$RECEIPT_ID" | grep -Eq '^[0-9a-f]{32}$' || fail SEALED_IMAGE_RECEIPT_ID_INVALID
[ -d "$PROJECT_ROOT" ] && [ ! -L "$PROJECT_ROOT" ] || fail SEALED_PROJECT_ROOT_INVALID
PROJECT_ROOT=$(CDPATH= cd -P "$PROJECT_ROOT" && pwd)
[ -f "$PROJECT_ROOT/docker-compose.yml" ] && [ ! -L "$PROJECT_ROOT/docker-compose.yml" ] \
    || fail SEALED_COMPOSE_FILE_INVALID
[ -f "$VALIDATOR" ] && [ ! -L "$VALIDATOR" ] || fail SEALED_VALIDATOR_INVALID
[ -f "$STORAGE_VALIDATOR" ] && [ ! -L "$STORAGE_VALIDATOR" ] || fail SEALED_STORAGE_VALIDATOR_INVALID
command -v php >/dev/null 2>&1 || fail SEALED_PHP_UNAVAILABLE
command -v docker >/dev/null 2>&1 || fail SEALED_DOCKER_UNAVAILABLE
[ "$(uname -s)" = Linux ] || fail SEALED_HOST_OS_UNSUPPORTED

mode_of() {
    stat -f '%Lp' "$1" 2>/dev/null || stat -c '%a' "$1" 2>/dev/null || fail SEALED_STAT_UNAVAILABLE
}

nlink_of() {
    stat -f '%l' "$1" 2>/dev/null || stat -c '%h' "$1" 2>/dev/null || fail SEALED_STAT_UNAVAILABLE
}

case "$(uname -m)" in
    x86_64|amd64) architecture=amd64 ;;
    aarch64|arm64) architecture=arm64 ;;
    *) fail AGENT_BINARY_ARCH_UNSUPPORTED ;;
esac
BIN_ROOT=$PROJECT_ROOT/upgrade/bin
MANIFEST=$BIN_ROOT/checksums.sha256
AGENT=$BIN_ROOT/mallbase-agent-linux-$architecture
[ -f "$MANIFEST" ] && [ ! -L "$MANIFEST" ] || fail AGENT_BINARY_CHECKSUM_MISSING
[ -f "$AGENT" ] && [ ! -L "$AGENT" ] && [ -s "$AGENT" ] || fail AGENT_BINARY_MISSING
[ "$(mode_of "$AGENT")" = 555 ] && [ "$(nlink_of "$AGENT")" = 1 ] || fail AGENT_BINARY_MODE_INVALID
[ "$(mode_of "$MANIFEST")" = 444 ] && [ "$(nlink_of "$MANIFEST")" = 1 ] || fail AGENT_BINARY_CHECKSUM_INVALID
manifest_lines=$(awk 'NF { count++ } END { print count + 0 }' "$MANIFEST")
[ "$manifest_lines" = 2 ] || fail AGENT_BINARY_CHECKSUM_INVALID
if ! awk 'NF && ($1 !~ /^[0-9a-f]{64}$/ || $2 !~ /^mallbase-agent-linux-(amd64|arm64)$/ || NF != 2) { exit 1 }' "$MANIFEST"; then
    fail AGENT_BINARY_CHECKSUM_INVALID
fi
agent_name=${AGENT##*/}
count=$(awk -v name="$agent_name" '$2 == name { count++ } END { print count + 0 }' "$MANIFEST")
[ "$count" = 1 ] || fail AGENT_BINARY_CHECKSUM_INVALID
expected=$(awk -v name="$agent_name" '$2 == name { print $1 }' "$MANIFEST")
if command -v sha256sum >/dev/null 2>&1; then
    actual=$(sha256sum "$AGENT" | awk '{print $1}')
elif command -v shasum >/dev/null 2>&1; then
    actual=$(shasum -a 256 "$AGENT" | awk '{print $1}')
else
    fail SEALED_SHA256_UNAVAILABLE
fi
[ "$actual" = "$expected" ] || fail AGENT_BINARY_CHECKSUM_INVALID

umask 077
VERIFY_REQUEST=$(mktemp "${TMPDIR:-/tmp}/mallbase-image-verify.XXXXXX") || fail SEALED_TEMP_UNAVAILABLE
IMAGE_RECEIPT=$(mktemp "${TMPDIR:-/tmp}/mallbase-image-receipt.XXXXXX") || fail SEALED_TEMP_UNAVAILABLE
INSPECT_OUTPUT=$(mktemp "${TMPDIR:-/tmp}/mallbase-image-inspect.XXXXXX") || fail SEALED_TEMP_UNAVAILABLE
CONTAINER_INSPECT=$(mktemp "${TMPDIR:-/tmp}/mallbase-container-inspect.XXXXXX") || fail SEALED_TEMP_UNAVAILABLE
STORAGE_LAYOUT=$(mktemp "${TMPDIR:-/tmp}/mallbase-storage-layout.XXXXXX") || fail SEALED_TEMP_UNAVAILABLE
STORAGE_FINALIZE_OUTPUT=$(mktemp "${TMPDIR:-/tmp}/mallbase-storage-finalize.XXXXXX") || fail SEALED_TEMP_UNAVAILABLE
chmod 0600 "$VERIFY_REQUEST" "$IMAGE_RECEIPT" "$INSPECT_OUTPUT" "$CONTAINER_INSPECT" \
    "$STORAGE_LAYOUT" "$STORAGE_FINALIZE_OUTPUT"
printf '{"receipt_id":"%s"}' "$RECEIPT_ID" > "$VERIFY_REQUEST"
if ! (cd "$PROJECT_ROOT" && "$AGENT" seal-build-context verify-image-receipt < "$VERIFY_REQUEST") > "$IMAGE_RECEIPT"; then
    fail SEALED_IMAGE_RECEIPT_VERIFY_FAILED
fi
image_id=$(php "$VALIDATOR" image-receipt-field "$IMAGE_RECEIPT" "$RECEIPT_ID" image_id)
config_digest=$(php "$VALIDATOR" image-receipt-field "$IMAGE_RECEIPT" "$RECEIPT_ID" config_digest)

if ! docker image inspect --format '{{.Id}}' "$image_id" > "$INSPECT_OUTPUT"; then
    fail SEALED_IMAGE_INSPECT_FAILED
fi
local_image_id=$(php "$VALIDATOR" oci-id "$INSPECT_OUTPUT")
[ "$local_image_id" = "$image_id" ] && [ "$local_image_id" = "$config_digest" ] \
    || fail SEALED_IMAGE_IDENTITY_CHANGED

if [ -f "$PROJECT_ROOT/.mallbase-deployment.json" ] || [ -f "$PROJECT_ROOT/.mallbase-release-inventory.json" ]; then
    if ! (cd "$PROJECT_ROOT" && printf '{}\n' | "$AGENT" storage inspect) > "$STORAGE_LAYOUT"; then
        fail SEALED_STORAGE_INSPECT_FAILED
    fi
    storage_start_mode=$(php "$STORAGE_VALIDATOR" start-field "$STORAGE_LAYOUT" mode)
    case "$storage_start_mode" in
        fresh_finalize)
            if ! sh "$SCRIPT_DIR/fresh-storage-bootstrap.sh" --project-root "$PROJECT_ROOT" finalize >/dev/null; then
                fail FRESH_STORAGE_FINALIZE_FAILED
            fi
            ;;
        adoption_ready)
            storage_revision=$(php "$STORAGE_VALIDATOR" start-field "$STORAGE_LAYOUT" authority_revision)
            storage_operation=$(php "$STORAGE_VALIDATOR" start-field "$STORAGE_LAYOUT" operation_id)
            storage_control=$(printf '{"expected_authority_revision":%s,"operation_id":"%s"}\n' \
                "$storage_revision" "$storage_operation")
            if ! (cd "$PROJECT_ROOT" && printf '%s' "$storage_control" \
                | "$AGENT" storage bootstrap-adopt finalize) > "$STORAGE_FINALIZE_OUTPUT"; then
                fail SEALED_STORAGE_ADOPTION_REPLAY_FAILED
            fi
            [ "$(php "$STORAGE_VALIDATOR" start-field "$STORAGE_FINALIZE_OUTPUT" mode)" = adoption_ready ] \
                || fail SEALED_STORAGE_ADOPTION_REPLAY_INVALID
            ;;
        *) fail SEALED_STORAGE_NOT_READY ;;
    esac
fi

export MALLBASE_BACKEND_IMAGE_ID=$image_id
compose() {
    docker compose --project-directory "$PROJECT_ROOT" --file "$PROJECT_ROOT/docker-compose.yml" "$@"
}
if ! compose up -d --pull never --no-build backend queue cron; then
    fail SEALED_COMPOSE_START_FAILED
fi

for service in backend queue cron; do
    container_id=$(compose ps -q "$service")
    if [ -z "$container_id" ]; then
        compose stop backend queue cron >/dev/null 2>&1 || true
        fail SEALED_CONTAINER_MISSING
    fi
    : > "$CONTAINER_INSPECT"
    if ! docker inspect --format '{{.Image}}' "$container_id" > "$CONTAINER_INSPECT"; then
        compose stop backend queue cron >/dev/null 2>&1 || true
        fail SEALED_CONTAINER_INSPECT_FAILED
    fi
    container_image=$(php "$VALIDATOR" oci-id "$CONTAINER_INSPECT")
    if [ "$container_image" != "$image_id" ]; then
        compose stop backend queue cron >/dev/null 2>&1 || true
        fail SEALED_CONTAINER_IMAGE_MISMATCH
    fi
done

printf 'MALLBASE_STARTED_IMAGE_RECEIPT_ID=%s\n' "$RECEIPT_ID"
printf 'MALLBASE_STARTED_IMAGE_ID=%s\n' "$image_id"
