#!/bin/sh
set -eu

SCRIPT_DIR=$(CDPATH= cd -P "$(dirname "$0")" && pwd)
PROJECT_ROOT=$(CDPATH= cd -P "$SCRIPT_DIR/../.." && pwd)
VALIDATOR=$SCRIPT_DIR/validate-sealed-attestation.php
DISPLAY_TAG=
CREATE_OUTPUT=
CHALLENGE_FILE=
INSPECT_OUTPUT=
RECORD_REQUEST=
IMAGE_RECEIPT=
RELEASE_ID=

fail() {
    printf '%s\n' "$1" >&2
    exit 1
}

usage() {
    printf '%s\n' 'usage: build-sealed-image.sh [--project-root PATH] [--tag IMAGE_TAG] [--release-id RELEASE_ID]' >&2
    exit 2
}

cleanup() {
    for path in "$CREATE_OUTPUT" "$CHALLENGE_FILE" "$INSPECT_OUTPUT" "$RECORD_REQUEST" "$IMAGE_RECEIPT"; do
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
        --tag)
            [ "$#" -ge 2 ] || usage
            DISPLAY_TAG=$2
            shift 2
            ;;
        --release-id)
            [ "$#" -ge 2 ] || usage
            RELEASE_ID=$2
            shift 2
            ;;
        *) usage ;;
    esac
done

validate_tag() {
    tag=$1
    [ ${#tag} -le 255 ] || fail SEALED_IMAGE_TAG_INVALID
    printf '%s\n' "$tag" | grep -Eq '^([a-z0-9]+([.-][a-z0-9]+)*(:[0-9]{1,5})?\/)?([a-z0-9]+([._-][a-z0-9]+)*\/)*[a-z0-9]+([._-][a-z0-9]+)*(:[A-Za-z0-9_][A-Za-z0-9_.-]{0,127})?$' \
        || fail SEALED_IMAGE_TAG_INVALID
}

mode_of() {
    stat -f '%Lp' "$1" 2>/dev/null || stat -c '%a' "$1" 2>/dev/null || fail SEALED_STAT_UNAVAILABLE
}

nlink_of() {
    stat -f '%l' "$1" 2>/dev/null || stat -c '%h' "$1" 2>/dev/null || fail SEALED_STAT_UNAVAILABLE
}

if [ -n "$DISPLAY_TAG" ]; then
    validate_tag "$DISPLAY_TAG"
fi
[ -d "$PROJECT_ROOT" ] && [ ! -L "$PROJECT_ROOT" ] || fail SEALED_PROJECT_ROOT_INVALID
PROJECT_ROOT=$(CDPATH= cd -P "$PROJECT_ROOT" && pwd)
[ -f "$VALIDATOR" ] && [ ! -L "$VALIDATOR" ] || fail SEALED_VALIDATOR_INVALID
command -v php >/dev/null 2>&1 || fail SEALED_PHP_UNAVAILABLE
command -v docker >/dev/null 2>&1 || fail SEALED_DOCKER_UNAVAILABLE
[ "$(uname -s)" = Linux ] || fail SEALED_HOST_OS_UNSUPPORTED

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

if [ ! -e "$PROJECT_ROOT/.mallbase-deployment.json" ] && [ -f "$PROJECT_ROOT/.mallbase-release-inventory.json" ]; then
    printf '%s\n' "$RELEASE_ID" | grep -Eq '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$' \
        || fail FRESH_STORAGE_RELEASE_ID_REQUIRED
    if ! sh "$SCRIPT_DIR/fresh-storage-bootstrap.sh" --project-root "$PROJECT_ROOT" prepare >/dev/null; then
        fail FRESH_STORAGE_PREPARE_FAILED
    fi
    if ! (cd "$PROJECT_ROOT" && printf '{"release_id":"%s"}\n' "$RELEASE_ID" | "$AGENT" provenance initialize) >/dev/null; then
        fail FRESH_STORAGE_PROVENANCE_INITIALIZE_FAILED
    fi
fi

SOURCE_DATE_EPOCH=${SOURCE_DATE_EPOCH-}
if [ -z "$SOURCE_DATE_EPOCH" ]; then
    SOURCE_DATE_EPOCH=$(date +%s)
fi
printf '%s\n' "$SOURCE_DATE_EPOCH" | grep -Eq '^[0-9]{1,12}$' || fail SOURCE_DATE_EPOCH_INVALID

umask 077
CREATE_OUTPUT=$(mktemp "${TMPDIR:-/tmp}/mallbase-seal-create.XXXXXX") || fail SEALED_TEMP_UNAVAILABLE
CHALLENGE_FILE=$(mktemp "${TMPDIR:-/tmp}/mallbase-seal-secret.XXXXXX") || fail SEALED_TEMP_UNAVAILABLE
INSPECT_OUTPUT=$(mktemp "${TMPDIR:-/tmp}/mallbase-image-inspect.XXXXXX") || fail SEALED_TEMP_UNAVAILABLE
RECORD_REQUEST=$(mktemp "${TMPDIR:-/tmp}/mallbase-image-record.XXXXXX") || fail SEALED_TEMP_UNAVAILABLE
IMAGE_RECEIPT=$(mktemp "${TMPDIR:-/tmp}/mallbase-image-receipt.XXXXXX") || fail SEALED_TEMP_UNAVAILABLE
chmod 0600 "$CREATE_OUTPUT" "$CHALLENGE_FILE" "$INSPECT_OUTPUT" "$RECORD_REQUEST" "$IMAGE_RECEIPT"

if ! (cd "$PROJECT_ROOT" && SOURCE_DATE_EPOCH=$SOURCE_DATE_EPOCH "$AGENT" seal-build-context create) > "$CREATE_OUTPUT"; then
    fail SEALED_CONTEXT_CREATE_FAILED
fi
receipt_id=$(php "$VALIDATOR" create-field "$CREATE_OUTPUT" "$PROJECT_ROOT" receipt_id)
seal_id=$(php "$VALIDATOR" create-field "$CREATE_OUTPUT" "$PROJECT_ROOT" seal_id)
tar_path=$(php "$VALIDATOR" create-field "$CREATE_OUTPUT" "$PROJECT_ROOT" tar_path)
php "$VALIDATOR" export-challenge "$CREATE_OUTPUT" "$PROJECT_ROOT" "$CHALLENGE_FILE"

if [ -z "$DISPLAY_TAG" ]; then
    DISPLAY_TAG=mallbase-backend:sealed-$receipt_id
fi
validate_tag "$DISPLAY_TAG"

if ! DOCKER_BUILDKIT=1 docker build \
    --file deploy/docker/Dockerfile \
    --secret "id=mallbase_context_seal,src=$CHALLENGE_FILE" \
    --build-arg "MALLBASE_EXPECTED_RECEIPT_ID=$receipt_id" \
    --build-arg "MALLBASE_EXPECTED_SEAL_ID=$seal_id" \
    --tag "$DISPLAY_TAG" - < "$tar_path" >&2; then
    fail SEALED_IMAGE_BUILD_FAILED
fi
if ! docker image inspect --format '{{.Id}}' "$DISPLAY_TAG" > "$INSPECT_OUTPUT"; then
    fail SEALED_IMAGE_INSPECT_FAILED
fi
image_id=$(php "$VALIDATOR" oci-id "$INSPECT_OUTPUT")
config_digest=$image_id
php "$VALIDATOR" write-record-request "$CREATE_OUTPUT" "$PROJECT_ROOT" "$image_id" "$config_digest" "$RECORD_REQUEST"
if ! (cd "$PROJECT_ROOT" && "$AGENT" seal-build-context record-image < "$RECORD_REQUEST") > "$IMAGE_RECEIPT"; then
    fail SEALED_IMAGE_RECORD_FAILED
fi
recorded_seal=$(php "$VALIDATOR" image-receipt-field "$IMAGE_RECEIPT" "$receipt_id" seal_id)
recorded_image=$(php "$VALIDATOR" image-receipt-field "$IMAGE_RECEIPT" "$receipt_id" image_id)
recorded_config=$(php "$VALIDATOR" image-receipt-field "$IMAGE_RECEIPT" "$receipt_id" config_digest)
[ "$recorded_seal" = "$seal_id" ] && [ "$recorded_image" = "$image_id" ] && [ "$recorded_config" = "$config_digest" ] \
    || fail SEALED_IMAGE_RECEIPT_BINDING_INVALID

printf 'MALLBASE_IMAGE_RECEIPT_ID=%s\n' "$receipt_id"
printf 'MALLBASE_BACKEND_IMAGE_ID=%s\n' "$image_id"
printf 'MALLBASE_IMAGE_DISPLAY_TAG=%s\n' "$DISPLAY_TAG"
