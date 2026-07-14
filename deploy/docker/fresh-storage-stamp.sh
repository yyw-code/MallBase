#!/bin/sh
set -eu

REQUEST=/storage-input/request.json
MARKER_NAME=.mallbase-layout-marker.json
OUTPUT=/storage-init-results/${MALLBASE_STORAGE_OPERATION_ID-}.json

fail() {
    printf '%s\n' "$1" >&2
    exit 1
}

require_match() {
    printf '%s\n' "$2" | grep -Eq "$3" || fail "$1"
}

hash_file() {
    printf 'sha256:'
    sha256sum "$1" | awk '{print $1}'
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

nlink_of() {
    stat -c '%h' "$1" 2>/dev/null || stat -f '%l' "$1" 2>/dev/null || fail FRESH_STORAGE_STAT_FAILED
}

field() {
    value=$(printf '%s' "$1" | sed -n 's/.*"'"$2"'":"\([^"]*\)".*/\1/p')
    [ -n "$value" ] || fail FRESH_STORAGE_REQUEST_INVALID
    printf '%s' "$value"
}

number_field() {
    value=$(printf '%s' "$1" | sed -n 's/.*"'"$2"'":\([0-9][0-9]*\).*/\1/p')
    [ -n "$value" ] || fail FRESH_STORAGE_REQUEST_INVALID
    printf '%s' "$value"
}

request_item() {
    artifact=$1
    next=$2
    if [ -n "$next" ]; then
        printf '%s' "$request_line" | sed -n 's/.*"'"$artifact"'":\({[^}]*}\),"'"$next"'".*/\1/p'
    else
        printf '%s' "$request_line" | sed -n 's/.*"'"$artifact"'":\({[^}]*}\)}}$/\1/p'
    fi
}

root_for() {
    case "$1" in
        cert) printf '%s' /storage/cert ;;
        demo) printf '%s' /storage/demo ;;
        install) printf '%s' /storage/runtime/install ;;
        local_storage) printf '%s' /storage/runtime/storage ;;
        public_storage) printf '%s' /storage/public-storage ;;
        runtime_backup) printf '%s' /storage/runtime/backup ;;
        uploads) printf '%s' /storage/uploads ;;
        *) fail FRESH_STORAGE_ARTIFACT_INVALID ;;
    esac
}

stamp_artifact() {
    artifact=$1
    next=$2
    root=$(root_for "$artifact")
    source=/storage-input/markers/$artifact.json
    target=$root/$MARKER_NAME
    marker_temp=$root/.mallbase-layout-marker.$MALLBASE_STORAGE_OPERATION_ID.tmp
    item=$(request_item "$artifact" "$next")
    [ -n "$item" ] || fail FRESH_STORAGE_REQUEST_INVALID
    [ "$(field "$item" artifact)" = "$artifact" ] || fail FRESH_STORAGE_REQUEST_INVALID
    marker_id=$(field "$item" marker_id)
    marker_sha256=$(field "$item" marker_sha256)
    require_match FRESH_STORAGE_MARKER_ID_INVALID "$marker_id" '^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$'
    require_match FRESH_STORAGE_MARKER_HASH_INVALID "$marker_sha256" '^sha256:[0-9a-f]{64}$'
    [ -f "$source" ] && [ ! -L "$source" ] && [ "$(mode_of "$source")" = 444 ] && [ "$(nlink_of "$source")" = 1 ] \
        || fail FRESH_STORAGE_MARKER_SOURCE_INVALID
    [ "$(hash_file "$source")" = "$marker_sha256" ] || fail FRESH_STORAGE_MARKER_HASH_INVALID
    expected='{"schema_version":1,"installation_storage_namespace":"'"$MALLBASE_STORAGE_NAMESPACE"'","artifact":"'"$artifact"'","storage_layout_version":1,"layout_generation":'"$MALLBASE_STORAGE_LAYOUT_GENERATION"',"marker_id":"'"$marker_id"'"}'
    expected_file=/tmp/marker-$artifact.json
    printf '%s\n' "$expected" > "$expected_file"
    cmp -s "$source" "$expected_file" || fail FRESH_STORAGE_MARKER_CANONICAL_INVALID
    rm -f "$expected_file"

    [ -d "$root" ] && [ ! -L "$root" ] && [ "$(uid_of "$root")" = "$MALLBASE_AGENT_UID" ] \
        && [ "$(gid_of "$root")" = "$MALLBASE_UPGRADE_SHARED_GID" ] && [ "$(mode_of "$root")" = 3770 ] \
        || fail FRESH_STORAGE_ROOT_INVALID
    extra=$(find "$root" -mindepth 1 -maxdepth 1 ! -name "$MARKER_NAME" ! -name ".mallbase-layout-marker.$MALLBASE_STORAGE_OPERATION_ID.tmp" -print -quit)
    [ -z "$extra" ] || fail FRESH_STORAGE_ROOT_NOT_EMPTY
    if [ -e "$marker_temp" ] || [ -L "$marker_temp" ]; then
        temp_uid=$(uid_of "$marker_temp")
        [ -f "$marker_temp" ] && [ ! -L "$marker_temp" ] && [ "$(nlink_of "$marker_temp")" = 1 ] \
            && { [ "$temp_uid" = 0 ] || [ "$temp_uid" = "$MALLBASE_AGENT_UID" ]; } \
            || fail FRESH_STORAGE_MARKER_TEMP_INVALID
        rm -f "$marker_temp" || fail FRESH_STORAGE_MARKER_TEMP_CLEANUP_FAILED
    fi
    if [ -e "$target" ] || [ -L "$target" ]; then
        [ -f "$target" ] && [ ! -L "$target" ] && [ "$(nlink_of "$target")" = 1 ] \
            && [ "$(uid_of "$target")" = "$MALLBASE_AGENT_UID" ] \
            && [ "$(gid_of "$target")" = "$MALLBASE_UPGRADE_SHARED_GID" ] \
            && [ "$(mode_of "$target")" = 444 ] && [ "$(hash_file "$target")" = "$marker_sha256" ] \
            || fail FRESH_STORAGE_MARKER_REPLAY_INVALID
    else
        cp "$source" "$marker_temp" || fail FRESH_STORAGE_MARKER_COPY_FAILED
        chown "$MALLBASE_AGENT_UID:$MALLBASE_UPGRADE_SHARED_GID" "$marker_temp" || fail FRESH_STORAGE_MARKER_CHOWN_FAILED
        chmod 0444 "$marker_temp" || fail FRESH_STORAGE_MARKER_MODE_FAILED
        sync "$marker_temp" 2>/dev/null || sync
        mv "$marker_temp" "$target" || fail FRESH_STORAGE_MARKER_PUBLISH_FAILED
        sync "$root" 2>/dev/null || sync
    fi
    [ -f "$target" ] && [ ! -L "$target" ] && [ "$(nlink_of "$target")" = 1 ] \
        && [ "$(hash_file "$target")" = "$marker_sha256" ] || fail FRESH_STORAGE_MARKER_FINAL_INVALID

    base=${item%?}
    printf '%s' "$base,\"root_uid\":$(uid_of "$root"),\"root_gid\":$(gid_of "$root"),\"root_mode\":\"03770\",\"marker_uid\":$(uid_of "$target"),\"marker_gid\":$(gid_of "$target"),\"marker_mode\":\"0444\",\"marker_nlink\":$(nlink_of "$target"),\"complete\":true}"
}

: "${MALLBASE_STORAGE_OPERATION_ID:?}"
: "${MALLBASE_STORAGE_NAMESPACE:?}"
: "${MALLBASE_AGENT_UID:?}"
: "${MALLBASE_UPGRADE_SHARED_GID:?}"
: "${MALLBASE_STORAGE_LAYOUT_GENERATION:?}"
: "${MALLBASE_STORAGE_INIT_REQUEST_SHA256:?}"
require_match FRESH_STORAGE_OPERATION_INVALID "$MALLBASE_STORAGE_OPERATION_ID" '^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$'
require_match FRESH_STORAGE_NAMESPACE_INVALID "$MALLBASE_STORAGE_NAMESPACE" '^mbs_[a-z0-9][a-z0-9_-]{0,59}$'
require_match FRESH_STORAGE_AGENT_UID_INVALID "$MALLBASE_AGENT_UID" '^(0|[1-9][0-9]{0,9})$'
require_match FRESH_STORAGE_SHARED_GID_INVALID "$MALLBASE_UPGRADE_SHARED_GID" '^(0|[1-9][0-9]{0,9})$'
[ "$MALLBASE_STORAGE_LAYOUT_GENERATION" = 1 ] || fail FRESH_STORAGE_LAYOUT_GENERATION_INVALID
require_match FRESH_STORAGE_REQUEST_HASH_INVALID "$MALLBASE_STORAGE_INIT_REQUEST_SHA256" '^sha256:[0-9a-f]{64}$'
[ -f "$REQUEST" ] && [ ! -L "$REQUEST" ] && [ "$(mode_of "$REQUEST")" = 444 ] && [ "$(nlink_of "$REQUEST")" = 1 ] \
    || fail FRESH_STORAGE_REQUEST_SOURCE_INVALID
[ "$(hash_file "$REQUEST")" = "$MALLBASE_STORAGE_INIT_REQUEST_SHA256" ] || fail FRESH_STORAGE_REQUEST_HASH_INVALID
[ "$(tail -c 1 "$REQUEST" | od -An -tuC | tr -d ' ')" = 10 ] || fail FRESH_STORAGE_REQUEST_CANONICAL_INVALID
[ "$(wc -l < "$REQUEST" | tr -d ' ')" = 1 ] || fail FRESH_STORAGE_REQUEST_CANONICAL_INVALID
request_line=$(sed -n '1p' "$REQUEST")
[ "$(field "$request_line" purpose)" = fresh_storage_init ] || fail FRESH_STORAGE_REQUEST_INVALID
[ "$(field "$request_line" operation_id)" = "$MALLBASE_STORAGE_OPERATION_ID" ] || fail FRESH_STORAGE_REQUEST_INVALID
[ "$(field "$request_line" installation_storage_namespace)" = "$MALLBASE_STORAGE_NAMESPACE" ] || fail FRESH_STORAGE_REQUEST_INVALID
[ "$(number_field "$request_line" layout_generation)" = 1 ] || fail FRESH_STORAGE_REQUEST_INVALID
[ "$(number_field "$request_line" agent_uid)" = "$MALLBASE_AGENT_UID" ] || fail FRESH_STORAGE_REQUEST_INVALID
[ "$(number_field "$request_line" shared_gid)" = "$MALLBASE_UPGRADE_SHARED_GID" ] || fail FRESH_STORAGE_REQUEST_INVALID
[ -d /storage/runtime ] && [ ! -L /storage/runtime ] \
    && [ "$(uid_of /storage/runtime)" = "$MALLBASE_AGENT_UID" ] \
    && [ "$(gid_of /storage/runtime)" = "$MALLBASE_UPGRADE_SHARED_GID" ] \
    && [ "$(mode_of /storage/runtime)" = 3770 ] || fail FRESH_STORAGE_RUNTIME_POLICY_INVALID

frozen_prepare_sha256=$(field "$request_line" frozen_prepare_sha256)
fresh_inspection_sha256=$(field "$request_line" fresh_inspection_sha256)
[ -d /storage-init-results ] && [ ! -L /storage-init-results ] || fail FRESH_STORAGE_RESULT_ROOT_INVALID
cert_result=$(stamp_artifact cert demo) || fail FRESH_STORAGE_CERT_STAMP_FAILED
demo_result=$(stamp_artifact demo install) || fail FRESH_STORAGE_DEMO_STAMP_FAILED
install_result=$(stamp_artifact install local_storage) || fail FRESH_STORAGE_INSTALL_STAMP_FAILED
local_storage_result=$(stamp_artifact local_storage public_storage) || fail FRESH_STORAGE_LOCAL_STORAGE_STAMP_FAILED
public_storage_result=$(stamp_artifact public_storage runtime_backup) || fail FRESH_STORAGE_PUBLIC_STORAGE_STAMP_FAILED
runtime_backup_result=$(stamp_artifact runtime_backup uploads) || fail FRESH_STORAGE_RUNTIME_BACKUP_STAMP_FAILED
uploads_result=$(stamp_artifact uploads '') || fail FRESH_STORAGE_UPLOADS_STAMP_FAILED
umask 077
tmp=$(mktemp /storage-init-results/.fresh-finalize.XXXXXX) || fail FRESH_STORAGE_RESULT_TEMP_FAILED
trap 'rm -f "$tmp"' 0
trap 'exit 1' HUP INT TERM
{
    printf '%s' '{"schema_version":1,"purpose":"fresh_storage_finalize","operation_id":"'"$MALLBASE_STORAGE_OPERATION_ID"'","layout_generation":1,"frozen_prepare_sha256":"'"$frozen_prepare_sha256"'","init_request_sha256":"'"$MALLBASE_STORAGE_INIT_REQUEST_SHA256"'","fresh_inspection_sha256":"'"$fresh_inspection_sha256"'","agent_uid":'"$MALLBASE_AGENT_UID"',"shared_gid":'"$MALLBASE_UPGRADE_SHARED_GID"',"root_mode":"03770","marker_mode":"0444","directory_mode":"0770","file_mode":"0660","complete":true,"artifacts":{'
    printf '"cert":%s,' "$cert_result"
    printf '"demo":%s,' "$demo_result"
    printf '"install":%s,' "$install_result"
    printf '"local_storage":%s,' "$local_storage_result"
    printf '"public_storage":%s,' "$public_storage_result"
    printf '"runtime_backup":%s,' "$runtime_backup_result"
    printf '"uploads":%s}}\n' "$uploads_result"
} > "$tmp"
chown "$MALLBASE_AGENT_UID:$MALLBASE_UPGRADE_SHARED_GID" "$tmp" || fail FRESH_STORAGE_RESULT_CHOWN_FAILED
chmod 0640 "$tmp" || fail FRESH_STORAGE_RESULT_MODE_FAILED
sync "$tmp" 2>/dev/null || sync
if [ -e "$OUTPUT" ] || [ -L "$OUTPUT" ]; then
    [ -f "$OUTPUT" ] && [ ! -L "$OUTPUT" ] && [ "$(nlink_of "$OUTPUT")" = 1 ] \
        && [ "$(uid_of "$OUTPUT")" = "$MALLBASE_AGENT_UID" ] \
        && [ "$(gid_of "$OUTPUT")" = "$MALLBASE_UPGRADE_SHARED_GID" ] \
        && [ "$(mode_of "$OUTPUT")" = 640 ] && cmp -s "$tmp" "$OUTPUT" \
        || fail FRESH_STORAGE_RESULT_REPLAY_INVALID
    rm -f "$tmp"
else
    mv "$tmp" "$OUTPUT" || fail FRESH_STORAGE_RESULT_PUBLISH_FAILED
    sync /storage-init-results 2>/dev/null || sync
fi
trap - 0 HUP INT TERM
