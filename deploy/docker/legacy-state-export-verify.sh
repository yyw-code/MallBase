#!/bin/sh
set -eu

SELECTION=/cutover/selection.json
TRUST=/cutover/storage-ready.pub
VALIDATOR=/usr/local/bin/validate-storage-cutover.php
RESULT_ROOT=/result
JOB_ID=${MALLBASE_UPGRADE_JOB_ID-}
AGENT_UID=${MALLBASE_AGENT_UID-}
SHARED_GID=$(id -g)
WORK_ROOT=

fail() {
    printf '%s\n' "$1" >&2
    exit 1
}

case "$JOB_ID" in
    ????????-????-????-????-????????????)
        printf '%s\n' "$JOB_ID" | grep -Eq '^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$' \
            || fail CUTOVER_JOB_ID_INVALID
        ;;
    *) fail CUTOVER_JOB_ID_INVALID ;;
esac
case "$AGENT_UID:$SHARED_GID" in
    *[!0-9:]*|:*|*:|*:*:*) fail CUTOVER_RESULT_IDENTITY_INVALID ;;
esac
[ "$(id -u)" = 0 ] && [ "$AGENT_UID" -gt 0 ] && [ "$SHARED_GID" -gt 0 ] \
    || fail CUTOVER_RESULT_IDENTITY_INVALID
if [ -r /proc/self/status ]; then
    cap_eff=$(awk '/^CapEff:/ { print $2 }' /proc/self/status | sed 's/^0*//')
    [ "${cap_eff:-0}" = d ] || fail CUTOVER_CAPABILITY_SET_INVALID
fi
[ -f "$VALIDATOR" ] && [ ! -L "$VALIDATOR" ] || fail CUTOVER_VALIDATOR_INVALID
[ -d "$RESULT_ROOT" ] && [ ! -L "$RESULT_ROOT" ] || fail CUTOVER_RESULT_ROOT_INVALID

WORK_ROOT=$(mktemp -d /tmp/mallbase-cutover-export.XXXXXX) || fail CUTOVER_TEMP_UNAVAILABLE
cleanup() {
    [ -z "$WORK_ROOT" ] || rm -rf "$WORK_ROOT"
}
trap cleanup 0
trap 'exit 1' HUP INT TERM

php -d opcache.jit_buffer_size=0 "$VALIDATOR" selection-plan "$SELECTION" "$TRUST" "$JOB_ID" prepared > "$WORK_ROOT/selection.plan" \
    || fail CUTOVER_SELECTION_INVALID
[ -s "$WORK_ROOT/selection.plan" ] || fail CUTOVER_SELECTION_INVALID

tab=$(printf '\t')
header=$(sed -n '1p' "$WORK_ROOT/selection.plan")
old_ifs=$IFS
IFS=$tab
set -- $header
IFS=$old_ifs
[ "$#" -eq 13 ] && [ "$1" = selection ] && [ "$2" = "$JOB_ID" ] && [ "$3" = prepared ] \
    || fail CUTOVER_SELECTION_HEADER_INVALID
namespace=$4
bootstrap_version=$5
main_manifest_sha256=$6
authority_revision=$7
source_plan_sha256=$8
key_id=$9
candidate_version=${10}
candidate_deployment_id=${11}
candidate_layout_version=${12}
candidate_layout_generation=${13}

sha256_file() {
    sha256sum "$1" | awk '{print $1}'
}

sha256_root() {
    { printf 'mallbase-content-root-v1\0'; cat "$1"; } | sha256sum | awk '{print $1}'
}

source_root() {
    artifact=$1
    mode=$2
    relative=$3
    case "$mode" in
        legacy_volume)
            case "$artifact" in
                install|local_storage|runtime_backup) printf '/source/runtime/%s\n' "$relative" ;;
                *) printf '/source/%s\n' "$artifact" ;;
            esac
            ;;
        container_export) printf '/input/%s\n' "$relative" ;;
        already_namespaced) printf '/target/%s\n' "$artifact" ;;
        absent) printf '%s\n' - ;;
        *) fail CUTOVER_SOURCE_MODE_INVALID ;;
    esac
}

validate_tree() {
    root=$1
    list=$2
    find -P "$root" -xdev -mindepth 1 ! -name .mallbase-layout-marker.json -print > "$list"
    line_count=$(wc -l < "$list" | tr -d ' ')
    nul_count=$(find -P "$root" -xdev -mindepth 1 ! -name .mallbase-layout-marker.json -print0 \
        | tr -cd '\000' | wc -c | tr -d ' ')
    [ "$line_count" = "$nul_count" ] || fail CUTOVER_PATH_INVALID
    if LC_ALL=C grep '[[:cntrl:]]' "$list" >/dev/null 2>&1; then
        fail CUTOVER_PATH_INVALID
    fi
    if find -P "$root" -xdev -mindepth 1 \( -type l -o -type b -o -type c -o -type p -o -type s \) -print -quit \
        | grep . >/dev/null 2>&1; then
        fail CUTOVER_SOURCE_TYPE_INVALID
    fi
    if ! find -P "$root" -xdev -type f ! -name .mallbase-layout-marker.json -exec sh -c '
        for path do
            [ "$(stat -c %h "$path")" = 1 ] || exit 1
        done
    ' sh {} +; then
        fail CUTOVER_SOURCE_HARDLINK_INVALID
    fi
}

write_manifest() {
    root=$1
    output=$2
    list=$3
    validate_tree "$root" "$list"
    : > "$output"
    LC_ALL=C sort "$list" | while IFS= read -r absolute; do
        relative=${absolute#"$root"/}
        if [ -d "$absolute" ]; then
            printf 'd\t%s\n' "$relative"
        elif [ -f "$absolute" ]; then
            printf 'f\t%s\t%s\t%s\n' "$(stat -c %s "$absolute")" "$(sha256_file "$absolute")" "$relative"
        else
            fail CUTOVER_SOURCE_TYPE_INVALID
        fi
    done > "$output"
}

publish_file() {
    source=$1
    destination=$2
    parent=${destination%/*}
    if [ -e "$destination" ] || [ -L "$destination" ]; then
        [ -f "$destination" ] && [ ! -L "$destination" ] \
            && [ "$(stat -c %h "$destination")" = 1 ] \
            && [ "$(stat -c %u:%g:%a "$destination")" = "$AGENT_UID:$SHARED_GID:640" ] \
            && cmp -s "$source" "$destination" \
            || fail CUTOVER_RESULT_CONFLICT
        return
    fi
    [ -d "$parent" ] && [ ! -L "$parent" ] || fail CUTOVER_RESULT_WRITE_FAILED
    temp=$(mktemp "$parent/.cutover-export-$JOB_ID.tmp.XXXXXX") || fail CUTOVER_RESULT_WRITE_FAILED
    [ -f "$temp" ] && [ ! -L "$temp" ] && [ "$(stat -c %h "$temp")" = 1 ] \
        || fail CUTOVER_RESULT_WRITE_FAILED
    cp "$source" "$temp" || fail CUTOVER_RESULT_WRITE_FAILED
    [ -f "$temp" ] && [ ! -L "$temp" ] && [ "$(stat -c %h "$temp")" = 1 ] \
        || fail CUTOVER_RESULT_WRITE_FAILED
    chown "$AGENT_UID:$SHARED_GID" "$temp" || fail CUTOVER_RESULT_WRITE_FAILED
    chmod 0640 "$temp" || fail CUTOVER_RESULT_WRITE_FAILED
    sync "$temp"
    mv "$temp" "$destination" || fail CUTOVER_RESULT_WRITE_FAILED
    sync "$parent"
}

ensure_result_directory() {
    directory=$1
    if [ -e "$directory" ] || [ -L "$directory" ]; then
        [ -d "$directory" ] && [ ! -L "$directory" ] || fail CUTOVER_RESULT_WRITE_FAILED
        identity=$(stat -c %u:%g:%a "$directory")
        [ "$identity" = "$AGENT_UID:$SHARED_GID:2770" ] && return
        [ "$identity" = "0:$SHARED_GID:770" ] || fail CUTOVER_RESULT_WRITE_FAILED
    else
        parent=${directory%/*}
        [ -d "$parent" ] && [ ! -L "$parent" ] || fail CUTOVER_RESULT_WRITE_FAILED
        mkdir "$directory" || fail CUTOVER_RESULT_WRITE_FAILED
        chown "$AGENT_UID:$SHARED_GID" "$directory" || fail CUTOVER_RESULT_WRITE_FAILED
        chmod 2770 "$directory" || fail CUTOVER_RESULT_WRITE_FAILED
    fi
}

umask 0027
ensure_result_directory "$RESULT_ROOT/export"
ensure_result_directory "$RESULT_ROOT/export/manifests"
artifacts_json=
artifact_count=0
: > "$WORK_ROOT/artifacts.tsv"
sed -n '2,$p' "$WORK_ROOT/selection.plan" | while IFS= read -r line; do
    IFS=$tab
    set -- $line
    IFS=$old_ifs
    [ "$#" -eq 29 ] && [ "$1" = artifact ] || fail CUTOVER_SELECTION_ARTIFACT_INVALID
    artifact=$2
    mode=$3
    relative=$4
    root=$(source_root "$artifact" "$mode" "$relative")
    manifest=$WORK_ROOT/$artifact.manifest
    if [ "$mode" = absent ]; then
        : > "$manifest"
    else
        [ -d "$root" ] && [ ! -L "$root" ] || fail "CUTOVER_SOURCE_MISSING:$artifact"
        write_manifest "$root" "$manifest" "$WORK_ROOT/$artifact.list"
    fi
    manifest_digest=$(sha256_file "$manifest")
    root_digest=$(sha256_root "$manifest")
    entry_count=$(wc -l < "$manifest" | tr -d ' ')
    publish_file "$manifest" "$RESULT_ROOT/export/manifests/$artifact.manifest"
    printf '%s\tsha256:%s\tsha256:%s\t%s\n' "$artifact" "$manifest_digest" "$root_digest" "$entry_count" \
        >> "$WORK_ROOT/artifacts.tsv"
done

[ -f "$WORK_ROOT/artifacts.tsv" ] || fail CUTOVER_SELECTION_ARTIFACT_INVALID
while IFS=$tab read -r artifact manifest_digest root_digest entry_count; do
    artifact_count=$((artifact_count + 1))
done < "$WORK_ROOT/artifacts.tsv"
[ "$artifact_count" -eq 7 ] || fail CUTOVER_SELECTION_ARTIFACT_SET_INVALID

receipt=$WORK_ROOT/receipt.json
php -d opcache.jit_buffer_size=0 "$VALIDATOR" write-export-receipt \
    "$SELECTION" "$TRUST" "$JOB_ID" "$WORK_ROOT/artifacts.tsv" "$receipt" \
    || fail CUTOVER_RECEIPT_INVALID
publish_file "$receipt" "$RESULT_ROOT/export/receipt.json"

printf '%s\n' CUTOVER_EXPORT_VERIFIED
