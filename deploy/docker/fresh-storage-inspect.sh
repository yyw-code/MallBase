#!/bin/sh
set -eu

EMPTY_SHA256=sha256:e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855
OUTPUT=/storage-init-results/fresh-inspection.json
MARKER=.mallbase-layout-marker.json

fail() {
    printf '%s\n' "$1" >&2
    exit 1
}

require_match() {
    printf '%s\n' "$2" | grep -Eq "$3" || fail "$1"
}

hash_literal() {
    printf '%s\n' "$1" | sha256sum | awk '{print "sha256:" $1}'
}

mode_of() {
    stat -c '%a' "$1" 2>/dev/null || stat -f '%Lp' "$1" 2>/dev/null || fail FRESH_STORAGE_STAT_FAILED
}

uid_of() {
    stat -c '%u' "$1" 2>/dev/null || stat -f '%u' "$1" 2>/dev/null || fail FRESH_STORAGE_STAT_FAILED
}

gid_of() {
    stat -c '%g' "$1" 2>/dev/null || stat -f '%g' "$1" 2>/dev/null || fail FRESH_STORAGE_STAT_FAILED
}

device_of() {
    stat -c '%d' "$1" 2>/dev/null || stat -f '%d' "$1" 2>/dev/null || fail FRESH_STORAGE_STAT_FAILED
}

inode_of() {
    stat -c '%i' "$1" 2>/dev/null || stat -f '%i' "$1" 2>/dev/null || fail FRESH_STORAGE_STAT_FAILED
}

nlink_of() {
    stat -c '%h' "$1" 2>/dev/null || stat -f '%l' "$1" 2>/dev/null || fail FRESH_STORAGE_STAT_FAILED
}

size_of() {
    stat -c '%s' "$1" 2>/dev/null || stat -f '%z' "$1" 2>/dev/null || fail FRESH_STORAGE_STAT_FAILED
}

adopt_backend_env() {
    file=/storage/env/backend.env
    [ -d /storage/env ] && [ ! -L /storage/env ] || fail FRESH_STORAGE_ENV_ROOT_INVALID
    [ -f "$file" ] && [ ! -L "$file" ] && [ "$(nlink_of "$file")" = 1 ] \
        || fail FRESH_STORAGE_ENV_FILE_INVALID
    [ "$(size_of "$file")" = 0 ] || fail FRESH_STORAGE_ENV_NOT_EMPTY
    chown "$MALLBASE_APP_UID:$MALLBASE_UPGRADE_SHARED_GID" "$file" || fail FRESH_STORAGE_ENV_CHOWN_FAILED
    chmod 0600 "$file" || fail FRESH_STORAGE_ENV_CHMOD_FAILED
    [ "$(uid_of "$file")" = "$MALLBASE_APP_UID" ] \
        && [ "$(gid_of "$file")" = "$MALLBASE_UPGRADE_SHARED_GID" ] \
        && [ "$(mode_of "$file")" = 600 ] || fail FRESH_STORAGE_ENV_POLICY_INVALID
}

assert_empty_root() {
    root=$1
    [ -d "$root" ] && [ ! -L "$root" ] || fail FRESH_STORAGE_ROOT_INVALID
    [ ! -e "$root/$MARKER" ] && [ ! -L "$root/$MARKER" ] || fail FRESH_STORAGE_MARKER_ALREADY_EXISTS
    if find "$root" -mindepth 1 -maxdepth 1 -print -quit | grep -q .; then
        fail FRESH_STORAGE_ROOT_NOT_EMPTY
    fi
    chown "$MALLBASE_AGENT_UID:$MALLBASE_UPGRADE_SHARED_GID" "$root" || fail FRESH_STORAGE_CHOWN_FAILED
    chmod 3770 "$root" || fail FRESH_STORAGE_CHMOD_FAILED
    [ "$(uid_of "$root")" = "$MALLBASE_AGENT_UID" ] || fail FRESH_STORAGE_OWNER_INVALID
    [ "$(gid_of "$root")" = "$MALLBASE_UPGRADE_SHARED_GID" ] || fail FRESH_STORAGE_GROUP_INVALID
    [ "$(mode_of "$root")" = 3770 ] || fail FRESH_STORAGE_MODE_INVALID
}

docker_labels_json() {
    role=$1
    printf '%s' '{"com.mallbase.storage.layout-generation":"1","com.mallbase.storage.layout-version":"1","com.mallbase.storage.managed":"true","com.mallbase.storage.namespace":"'"$MALLBASE_STORAGE_NAMESPACE"'","com.mallbase.storage.role":"'"$role"'"}'
}

docker_item() {
    artifact=$1
    role=$2
    root=$3
    volume_name=$4
    mount_identity=$5
    policy_sha256=$6
    labels=$(docker_labels_json "$role")
    [ "$(hash_literal "$labels")" = "$policy_sha256" ] || fail FRESH_STORAGE_DOCKER_POLICY_INVALID
    printf '%s' '{"artifact":"'"$artifact"'","storage_kind":"docker_volume","volume_name":"'"$volume_name"'","mount_identity":"'"$mount_identity"'","policy_sha256":"'"$policy_sha256"'","content_sha256":"'"$EMPTY_SHA256"'","docker":{"driver":"local","scope":"local","labels":'"$labels"'},"root_uid":'"$(uid_of "$root")"',"root_gid":'"$(gid_of "$root")"',"root_mode":"03770","marker_absent":true,"empty":true,"complete":true}'
}

bind_item() {
    artifact=$1
    root=$2
    device=$(device_of "$root")
    inode=$(inode_of "$root")
    require_match FRESH_STORAGE_BIND_DEVICE_INVALID "$device" '^[1-9][0-9]*$'
    require_match FRESH_STORAGE_BIND_INODE_INVALID "$inode" '^[1-9][0-9]*$'
    identity_payload='{"device_id":'"$device"',"inode":'"$inode"',"relative_role":"'"$artifact"'"}'
    bind_payload='{"relative_role":"'"$artifact"'","device_id":'"$device"',"inode":'"$inode"'}'
    policy_payload='{"agent_uid":'"$MALLBASE_AGENT_UID"',"artifact":"'"$artifact"'","relative_role":"'"$artifact"'","root_mode":"03770","shared_gid":'"$MALLBASE_UPGRADE_SHARED_GID"',"storage_kind":"bind"}'
    mount_identity=bind:$(hash_literal "$identity_payload")
    policy_sha256=$(hash_literal "$policy_payload")
    printf '%s' '{"artifact":"'"$artifact"'","storage_kind":"bind","volume_name":"mallbase_bind_'"$artifact"'","mount_identity":"'"$mount_identity"'","policy_sha256":"'"$policy_sha256"'","content_sha256":"'"$EMPTY_SHA256"'","bind":'"$bind_payload"',"root_uid":'"$(uid_of "$root")"',"root_gid":'"$(gid_of "$root")"',"root_mode":"03770","marker_absent":true,"empty":true,"complete":true}'
}

: "${MALLBASE_STORAGE_OPERATION_ID:?}"
: "${MALLBASE_STORAGE_NAMESPACE:?}"
: "${MALLBASE_AGENT_UID:?}"
: "${MALLBASE_APP_UID:?}"
: "${MALLBASE_UPGRADE_SHARED_GID:?}"
: "${MALLBASE_RUNTIME_VOLUME_NAME:?}"
: "${MALLBASE_RUNTIME_MOUNT_IDENTITY:?}"
: "${MALLBASE_RUNTIME_POLICY_SHA256:?}"
: "${MALLBASE_UPLOADS_VOLUME_NAME:?}"
: "${MALLBASE_UPLOADS_MOUNT_IDENTITY:?}"
: "${MALLBASE_UPLOADS_POLICY_SHA256:?}"

require_match FRESH_STORAGE_OPERATION_INVALID "$MALLBASE_STORAGE_OPERATION_ID" '^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$'
require_match FRESH_STORAGE_NAMESPACE_INVALID "$MALLBASE_STORAGE_NAMESPACE" '^mbs_[a-z0-9][a-z0-9_-]{0,59}$'
require_match FRESH_STORAGE_AGENT_UID_INVALID "$MALLBASE_AGENT_UID" '^(0|[1-9][0-9]{0,9})$'
require_match FRESH_STORAGE_APP_UID_INVALID "$MALLBASE_APP_UID" '^[1-9][0-9]{0,9}$'
require_match FRESH_STORAGE_SHARED_GID_INVALID "$MALLBASE_UPGRADE_SHARED_GID" '^(0|[1-9][0-9]{0,9})$'
[ "$MALLBASE_AGENT_UID" != "$MALLBASE_APP_UID" ] || fail FRESH_STORAGE_UID_CONFLICT
[ "$MALLBASE_RUNTIME_VOLUME_NAME" = "${MALLBASE_STORAGE_NAMESPACE}_runtime" ] || fail FRESH_STORAGE_RUNTIME_NAME_INVALID
[ "$MALLBASE_UPLOADS_VOLUME_NAME" = "${MALLBASE_STORAGE_NAMESPACE}_uploads" ] || fail FRESH_STORAGE_UPLOADS_NAME_INVALID
require_match FRESH_STORAGE_RUNTIME_IDENTITY_INVALID "$MALLBASE_RUNTIME_MOUNT_IDENTITY" '^docker:sha256:[0-9a-f]{64}$'
require_match FRESH_STORAGE_UPLOADS_IDENTITY_INVALID "$MALLBASE_UPLOADS_MOUNT_IDENTITY" '^docker:sha256:[0-9a-f]{64}$'
require_match FRESH_STORAGE_RUNTIME_POLICY_INVALID "$MALLBASE_RUNTIME_POLICY_SHA256" '^sha256:[0-9a-f]{64}$'
require_match FRESH_STORAGE_UPLOADS_POLICY_INVALID "$MALLBASE_UPLOADS_POLICY_SHA256" '^sha256:[0-9a-f]{64}$'

[ -d /storage/runtime ] && [ ! -L /storage/runtime ] || fail FRESH_STORAGE_RUNTIME_ROOT_INVALID
adopt_backend_env
if find /storage/runtime -mindepth 1 -maxdepth 1 ! -name install ! -name storage ! -name backup -print -quit | grep -q .; then
    fail FRESH_STORAGE_RUNTIME_NOT_EMPTY
fi
for child in install storage backup; do
    [ ! -e "/storage/runtime/$child" ] || [ -d "/storage/runtime/$child" ] || fail FRESH_STORAGE_RUNTIME_CHILD_INVALID
    [ ! -e "/storage/runtime/$child" ] || [ ! -L "/storage/runtime/$child" ] || fail FRESH_STORAGE_RUNTIME_CHILD_INVALID
    mkdir -p "/storage/runtime/$child" || fail FRESH_STORAGE_RUNTIME_CHILD_CREATE_FAILED
done
chown "$MALLBASE_AGENT_UID:$MALLBASE_UPGRADE_SHARED_GID" /storage/runtime || fail FRESH_STORAGE_RUNTIME_CHOWN_FAILED
chmod 3770 /storage/runtime || fail FRESH_STORAGE_RUNTIME_CHMOD_FAILED
[ "$(uid_of /storage/runtime)" = "$MALLBASE_AGENT_UID" ] \
    && [ "$(gid_of /storage/runtime)" = "$MALLBASE_UPGRADE_SHARED_GID" ] \
    && [ "$(mode_of /storage/runtime)" = 3770 ] || fail FRESH_STORAGE_RUNTIME_POLICY_INVALID

assert_empty_root /storage/cert
assert_empty_root /storage/demo
assert_empty_root /storage/runtime/install
assert_empty_root /storage/runtime/storage
assert_empty_root /storage/public-storage
assert_empty_root /storage/runtime/backup
assert_empty_root /storage/uploads

[ -d /storage-init-results ] && [ ! -L /storage-init-results ] || fail FRESH_STORAGE_RESULT_ROOT_INVALID
[ ! -e "$OUTPUT" ] && [ ! -L "$OUTPUT" ] || fail FRESH_STORAGE_RESULT_ALREADY_EXISTS
cert_item=$(bind_item cert /storage/cert) || fail FRESH_STORAGE_CERT_INSPECTION_FAILED
demo_item=$(bind_item demo /storage/demo) || fail FRESH_STORAGE_DEMO_INSPECTION_FAILED
install_item=$(docker_item install runtime /storage/runtime/install "$MALLBASE_RUNTIME_VOLUME_NAME" "$MALLBASE_RUNTIME_MOUNT_IDENTITY" "$MALLBASE_RUNTIME_POLICY_SHA256") || fail FRESH_STORAGE_INSTALL_INSPECTION_FAILED
local_storage_item=$(docker_item local_storage runtime /storage/runtime/storage "$MALLBASE_RUNTIME_VOLUME_NAME" "$MALLBASE_RUNTIME_MOUNT_IDENTITY" "$MALLBASE_RUNTIME_POLICY_SHA256") || fail FRESH_STORAGE_LOCAL_STORAGE_INSPECTION_FAILED
public_storage_item=$(bind_item public_storage /storage/public-storage) || fail FRESH_STORAGE_PUBLIC_STORAGE_INSPECTION_FAILED
runtime_backup_item=$(docker_item runtime_backup runtime /storage/runtime/backup "$MALLBASE_RUNTIME_VOLUME_NAME" "$MALLBASE_RUNTIME_MOUNT_IDENTITY" "$MALLBASE_RUNTIME_POLICY_SHA256") || fail FRESH_STORAGE_RUNTIME_BACKUP_INSPECTION_FAILED
uploads_item=$(docker_item uploads uploads /storage/uploads "$MALLBASE_UPLOADS_VOLUME_NAME" "$MALLBASE_UPLOADS_MOUNT_IDENTITY" "$MALLBASE_UPLOADS_POLICY_SHA256") || fail FRESH_STORAGE_UPLOADS_INSPECTION_FAILED
umask 077
tmp=$(mktemp /storage-init-results/.fresh-inspection.XXXXXX) || fail FRESH_STORAGE_RESULT_TEMP_FAILED
trap 'rm -f "$tmp"' 0
trap 'exit 1' HUP INT TERM
{
    printf '%s' '{"schema_version":1,"purpose":"fresh_storage_inspection","operation_id":"'"$MALLBASE_STORAGE_OPERATION_ID"'","agent_uid":'"$MALLBASE_AGENT_UID"',"shared_gid":'"$MALLBASE_UPGRADE_SHARED_GID"',"root_mode":"03770","complete":true,"artifacts":{'
    printf '"cert":%s,' "$cert_item"
    printf '"demo":%s,' "$demo_item"
    printf '"install":%s,' "$install_item"
    printf '"local_storage":%s,' "$local_storage_item"
    printf '"public_storage":%s,' "$public_storage_item"
    printf '"runtime_backup":%s,' "$runtime_backup_item"
    printf '"uploads":%s}}\n' "$uploads_item"
} > "$tmp"
chown "$MALLBASE_AGENT_UID:$MALLBASE_UPGRADE_SHARED_GID" "$tmp" || fail FRESH_STORAGE_RESULT_CHOWN_FAILED
chmod 0640 "$tmp" || fail FRESH_STORAGE_RESULT_MODE_FAILED
sync "$tmp" 2>/dev/null || sync
mv "$tmp" "$OUTPUT" || fail FRESH_STORAGE_RESULT_PUBLISH_FAILED
sync /storage-init-results 2>/dev/null || sync
trap - 0 HUP INT TERM
