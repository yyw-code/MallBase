#!/bin/sh
set -eu

ACTION=${1-}
if [ "$ACTION" = verify-export ]; then
    exec sh /usr/local/bin/legacy-state-export-verify.sh
fi
[ "$ACTION" = import ] || {
    printf '%s\n' CUTOVER_ACTION_INVALID >&2
    exit 1
}

SELECTION=/cutover/selection.json
TRUST=/cutover/storage-ready.pub
VALIDATOR=/usr/local/bin/validate-storage-cutover.php
RESULT_ROOT=/result
JOB_ID=${MALLBASE_UPGRADE_JOB_ID-}
AGENT_UID=${MALLBASE_AGENT_UID-}
SHARED_GID=$(id -g)
WORK_ROOT=
COPY_TEMP=

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
for artifact in cert demo install local_storage public_storage runtime_backup uploads; do
    [ -d "/target/$artifact" ] && [ ! -L "/target/$artifact" ] || fail CUTOVER_TARGET_MOUNT_INVALID
done

WORK_ROOT=$(mktemp -d /tmp/mallbase-cutover-import.XXXXXX) || fail CUTOVER_TEMP_UNAVAILABLE
cleanup() {
    if [ -n "$COPY_TEMP" ] && [ -f "$COPY_TEMP" ] && [ ! -L "$COPY_TEMP" ]; then
        rm -f "$COPY_TEMP"
    fi
    [ -z "$WORK_ROOT" ] || rm -rf "$WORK_ROOT"
}
trap cleanup 0
trap 'exit 1' HUP INT TERM

php -d opcache.jit_buffer_size=0 "$VALIDATOR" selection-plan \
    "$SELECTION" "$TRUST" "$JOB_ID" importing > "$WORK_ROOT/selection.plan" \
    || fail CUTOVER_SELECTION_INVALID
[ -s "$WORK_ROOT/selection.plan" ] || fail CUTOVER_SELECTION_INVALID

tab=$(printf '\t')
header=$(sed -n '1p' "$WORK_ROOT/selection.plan")
old_ifs=$IFS
IFS=$tab
set -- $header
IFS=$old_ifs
[ "$#" -eq 13 ] && [ "$1" = selection ] && [ "$2" = "$JOB_ID" ] && [ "$3" = importing ] \
    || fail CUTOVER_SELECTION_HEADER_INVALID
namespace=$4
main_manifest_sha256=$6
authority_revision=$7
source_plan_sha256=$8
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

publish_file() {
    pf_source=$1
    pf_destination=$2
    pf_parent=${pf_destination%/*}
    if [ -e "$pf_destination" ] || [ -L "$pf_destination" ]; then
        [ -f "$pf_destination" ] && [ ! -L "$pf_destination" ] \
            && [ "$(stat -c %h "$pf_destination")" = 1 ] \
            && [ "$(stat -c %u:%g:%a "$pf_destination")" = "$AGENT_UID:$SHARED_GID:640" ] \
            && cmp -s "$pf_source" "$pf_destination" \
            || fail CUTOVER_RESULT_CONFLICT
        return
    fi
    [ -d "$pf_parent" ] && [ ! -L "$pf_parent" ] || fail CUTOVER_RESULT_WRITE_FAILED
    pf_temp=$(mktemp "$pf_parent/.cutover-import-$JOB_ID.tmp.XXXXXX") \
        || fail CUTOVER_RESULT_WRITE_FAILED
    [ -f "$pf_temp" ] && [ ! -L "$pf_temp" ] && [ "$(stat -c %h "$pf_temp")" = 1 ] \
        || fail CUTOVER_RESULT_WRITE_FAILED
    cp "$pf_source" "$pf_temp" || fail CUTOVER_RESULT_WRITE_FAILED
    [ -f "$pf_temp" ] && [ ! -L "$pf_temp" ] && [ "$(stat -c %h "$pf_temp")" = 1 ] \
        || fail CUTOVER_RESULT_WRITE_FAILED
    chown "$AGENT_UID:$SHARED_GID" "$pf_temp" || fail CUTOVER_RESULT_WRITE_FAILED
    chmod 0640 "$pf_temp" || fail CUTOVER_RESULT_WRITE_FAILED
    sync "$pf_temp"
    mv "$pf_temp" "$pf_destination" || fail CUTOVER_RESULT_WRITE_FAILED
    sync "$pf_parent"
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
    if find -P "$root" -xdev -mindepth 1 ! -name .mallbase-layout-marker.json \
        \( -type l -o -type b -o -type c -o -type p -o -type s \) -print -quit | grep . >/dev/null 2>&1; then
        fail CUTOVER_TREE_TYPE_INVALID
    fi
    if ! find -P "$root" -xdev -type f ! -name .mallbase-layout-marker.json -exec sh -c '
        for path do [ "$(stat -c %h "$path")" = 1 ] || exit 1; done
    ' sh {} +; then
        fail CUTOVER_TREE_HARDLINK_INVALID
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
            fail CUTOVER_TREE_TYPE_INVALID
        fi
    done > "$output"
}

source_root() {
    artifact=$1
    mode=$2
    relative=$3
    case "$mode" in
        legacy_volume) printf '/source/runtime/%s\n' "$relative" ;;
        container_export) printf '/input/%s\n' "$relative" ;;
        already_namespaced) printf '/target/%s\n' "$artifact" ;;
        absent) printf '%s\n' - ;;
        *) fail CUTOVER_SOURCE_MODE_INVALID ;;
    esac
}

clean_interrupted_temps() {
    target=$1
    invalid=$(find -P "$target" -xdev -mindepth 1 -name ".mallbase-import-$JOB_ID.tmp.*" ! -type f -print -quit)
    [ -z "$invalid" ] || fail CUTOVER_TARGET_CONFLICT
    find -P "$target" -xdev -mindepth 1 -name ".mallbase-import-$JOB_ID.tmp.*" -type f -exec sh -c '
        for path do
            [ ! -L "$path" ] && [ "$(stat -c %h "$path")" = 1 ] || exit 1
            rm -f "$path" || exit 1
        done
    ' sh {} + || fail CUTOVER_TARGET_CONFLICT
}

assert_target_subset() {
    source_manifest=$1
    target_manifest=$2
    while IFS= read -r line; do
        grep -Fqx "$line" "$source_manifest" || fail CUTOVER_TARGET_CONFLICT
    done < "$target_manifest"
}

write_marker() {
    artifact=$1
    target=$2
    marker_id=$3
    marker_sha=$4
    marker=$target/.mallbase-layout-marker.json
    expected=$WORK_ROOT/$artifact.marker
    printf '%s' '{"schema_version":1,"installation_storage_namespace":"'"$namespace"'","artifact":"'"$artifact"'","storage_layout_version":'"$candidate_layout_version"',"layout_generation":'"$candidate_layout_generation"',"marker_id":"'"$marker_id"'"}' > "$expected"
    [ "sha256:$(sha256_file "$expected")" = "$marker_sha" ] || fail CUTOVER_MARKER_EVIDENCE_INVALID
    if [ -e "$marker" ] || [ -L "$marker" ]; then
        [ -f "$marker" ] && [ ! -L "$marker" ] && [ "$(stat -c %h "$marker")" = 1 ] \
            && [ "$(stat -c %u:%g:%a "$marker")" = "0:$SHARED_GID:444" ] \
            && cmp -s "$expected" "$marker" || fail CUTOVER_TARGET_CONFLICT
        return
    fi
    temp=$(mktemp "$target/.mallbase-marker-$JOB_ID.tmp.XXXXXX") || fail CUTOVER_TARGET_WRITE_FAILED
    [ -f "$temp" ] && [ ! -L "$temp" ] && [ "$(stat -c %h "$temp")" = 1 ] \
        || fail CUTOVER_TARGET_WRITE_FAILED
    cp "$expected" "$temp" || fail CUTOVER_TARGET_WRITE_FAILED
    chown "0:$SHARED_GID" "$temp" || fail CUTOVER_TARGET_WRITE_FAILED
    chmod 0444 "$temp" || fail CUTOVER_TARGET_WRITE_FAILED
    sync "$temp"
    mv "$temp" "$marker" || fail CUTOVER_TARGET_WRITE_FAILED
    sync "$target"
}

copy_artifact() {
    artifact=$1
    mode=$2
    source=$3
    target=$4
    marker_id=$5
    marker_sha=$6
    directory_uid=$7
    directory_gid=$8
    directory_mode=$9
    shift 9
    file_uid=$1
    file_gid=$2
    file_mode=$3

    source_manifest=$WORK_ROOT/$artifact.source.manifest
    if [ "$mode" = absent ]; then
        : > "$source_manifest"
        : > "$WORK_ROOT/$artifact.source.list"
    else
        [ -d "$source" ] && [ ! -L "$source" ] || fail "CUTOVER_SOURCE_MISSING:$artifact"
        write_manifest "$source" "$source_manifest" "$WORK_ROOT/$artifact.source.list"
    fi

    clean_interrupted_temps "$target"
    write_manifest "$target" "$WORK_ROOT/$artifact.target.before" "$WORK_ROOT/$artifact.target.before.list"
    assert_target_subset "$source_manifest" "$WORK_ROOT/$artifact.target.before"

    progress=$WORK_ROOT/$artifact.progress.json
    printf '%s' '{"schema_version":1,"purpose":"storage_cutover_import_progress","job_id":"'"$JOB_ID"'","main_manifest_sha256":"'"$main_manifest_sha256"'","source_plan_sha256":"'"$source_plan_sha256"'","authority_revision":'"$authority_revision"',"artifact":"'"$artifact"'","state":"copying"}' > "$progress"
    publish_file "$progress" "$RESULT_ROOT/import/progress/$artifact.json"

    chown "0:$SHARED_GID" "$target" || fail CUTOVER_TARGET_WRITE_FAILED
    chmod 3770 "$target" || fail CUTOVER_TARGET_WRITE_FAILED
    if [ "$mode" != absent ]; then
        LC_ALL=C sort "$WORK_ROOT/$artifact.source.list" | while IFS= read -r absolute; do
            relative=${absolute#"$source"/}
            destination=$target/$relative
            if [ -d "$absolute" ]; then
                if [ -e "$destination" ]; then
                    [ -d "$destination" ] && [ ! -L "$destination" ] || fail CUTOVER_TARGET_CONFLICT
                else
                    mkdir "$destination" || fail CUTOVER_TARGET_WRITE_FAILED
                fi
                chown "$directory_uid:$directory_gid" "$destination" || fail CUTOVER_TARGET_WRITE_FAILED
                chmod "$directory_mode" "$destination" || fail CUTOVER_TARGET_WRITE_FAILED
                continue
            fi
            [ -f "$absolute" ] && [ ! -L "$absolute" ] || fail CUTOVER_TREE_TYPE_INVALID
            if [ -e "$destination" ] || [ -L "$destination" ]; then
                [ -f "$destination" ] && [ ! -L "$destination" ] \
                    && [ "$(stat -c %h "$destination")" = 1 ] \
                    && [ "$(stat -c %s "$absolute")" = "$(stat -c %s "$destination")" ] \
                    && [ "$(sha256_file "$absolute")" = "$(sha256_file "$destination")" ] \
                    || fail CUTOVER_TARGET_CONFLICT
            else
                parent=${destination%/*}
                COPY_TEMP=$(mktemp "$parent/.mallbase-import-$JOB_ID.tmp.XXXXXX") \
                    || fail CUTOVER_TARGET_WRITE_FAILED
                [ -f "$COPY_TEMP" ] && [ ! -L "$COPY_TEMP" ] && [ "$(stat -c %h "$COPY_TEMP")" = 1 ] \
                    || fail CUTOVER_TARGET_WRITE_FAILED
                cp "$absolute" "$COPY_TEMP" || fail CUTOVER_TARGET_WRITE_FAILED
                [ -f "$COPY_TEMP" ] && [ ! -L "$COPY_TEMP" ] && [ "$(stat -c %h "$COPY_TEMP")" = 1 ] \
                    || fail CUTOVER_TARGET_WRITE_FAILED
                chown "$file_uid:$file_gid" "$COPY_TEMP" || fail CUTOVER_TARGET_WRITE_FAILED
                chmod "$file_mode" "$COPY_TEMP" || fail CUTOVER_TARGET_WRITE_FAILED
                sync "$COPY_TEMP"
                mv "$COPY_TEMP" "$destination" || fail CUTOVER_TARGET_WRITE_FAILED
                COPY_TEMP=
                sync "$parent"
            fi
            chown "$file_uid:$file_gid" "$destination" || fail CUTOVER_TARGET_WRITE_FAILED
            chmod "$file_mode" "$destination" || fail CUTOVER_TARGET_WRITE_FAILED
        done
    fi

    if [ "$mode" != absent ]; then
        write_manifest "$source" "$WORK_ROOT/$artifact.source.after" "$WORK_ROOT/$artifact.source.after.list"
        cmp -s "$source_manifest" "$WORK_ROOT/$artifact.source.after" || fail CUTOVER_SOURCE_CHANGED
    fi
    write_manifest "$target" "$WORK_ROOT/$artifact.target.after" "$WORK_ROOT/$artifact.target.after.list"
    cmp -s "$source_manifest" "$WORK_ROOT/$artifact.target.after" || fail CUTOVER_TARGET_VERIFY_FAILED
    write_marker "$artifact" "$target" "$marker_id" "$marker_sha"

    manifest_sha=sha256:$(sha256_file "$source_manifest")
    root_sha=sha256:$(sha256_root "$source_manifest")
    entry_count=$(wc -l < "$source_manifest" | tr -d ' ')
    done_file=$WORK_ROOT/$artifact.done.json
    printf '%s' '{"schema_version":1,"purpose":"storage_cutover_import_artifact_done","job_id":"'"$JOB_ID"'","main_manifest_sha256":"'"$main_manifest_sha256"'","source_plan_sha256":"'"$source_plan_sha256"'","authority_revision":'"$authority_revision"',"artifact":"'"$artifact"'","content":{"manifest_sha256":"'"$manifest_sha"'","root_sha256":"'"$root_sha"'","entry_count":'"$entry_count"'},"state":"complete"}' > "$done_file"
    publish_file "$done_file" "$RESULT_ROOT/import/done/$artifact.json"
    printf '%s\t%s\t%s\t%s\n' "$artifact" "$manifest_sha" "$root_sha" "$entry_count" >> "$WORK_ROOT/artifacts.tsv"
}

umask 0027
ensure_result_directory "$RESULT_ROOT/import"
ensure_result_directory "$RESULT_ROOT/import/progress"
ensure_result_directory "$RESULT_ROOT/import/done"

export_receipt=$RESULT_ROOT/export/receipt.json
[ -f "$export_receipt" ] && [ ! -L "$export_receipt" ] \
    && [ "$(stat -c %h "$export_receipt")" = 1 ] \
    && [ "$(stat -c %u:%g:%a "$export_receipt")" = "$AGENT_UID:$SHARED_GID:640" ] \
    || fail CUTOVER_EXPORT_RECEIPT_INVALID
php -d opcache.jit_buffer_size=0 "$VALIDATOR" verify-export-receipt \
    "$SELECTION" "$TRUST" "$JOB_ID" "$export_receipt" \
    || fail CUTOVER_EXPORT_RECEIPT_INVALID

intent=$WORK_ROOT/intent.json
printf '%s' '{"schema_version":1,"purpose":"storage_cutover_import_intent","job_id":"'"$JOB_ID"'","installation_storage_namespace":"'"$namespace"'","main_manifest_sha256":"'"$main_manifest_sha256"'","authority_revision":'"$authority_revision"',"candidate":{"app_version":"'"$candidate_version"'","deployment_id":"'"$candidate_deployment_id"'","storage_layout_version":'"$candidate_layout_version"',"layout_generation":'"$candidate_layout_generation"'},"source_plan_sha256":"'"$source_plan_sha256"'","state":"started"}' > "$intent"
publish_file "$intent" "$RESULT_ROOT/import/intent.json"
: > "$WORK_ROOT/artifacts.tsv"

sed -n '2,$p' "$WORK_ROOT/selection.plan" | while IFS= read -r line; do
    IFS=$tab
    set -- $line
    IFS=$old_ifs
    [ "$#" -eq 29 ] && [ "$1" = artifact ] || fail CUTOVER_SELECTION_ARTIFACT_INVALID
    artifact=$2
    mode=$3
    relative=$4
    expected_manifest=$7
    expected_root=$8
    expected_count=$9
    marker_id=${13}
    marker_sha=${14}
    directory_uid=${21}
    directory_gid=${22}
    directory_mode=${23}
    file_uid=${24}
    file_gid=${25}
    file_mode=${26}
    source=$(source_root "$artifact" "$mode" "$relative")
    copy_artifact "$artifact" "$mode" "$source" "/target/$artifact" "$marker_id" "$marker_sha" \
        "$directory_uid" "$directory_gid" "$directory_mode" "$file_uid" "$file_gid" "$file_mode"
    actual=$(tail -n 1 "$WORK_ROOT/artifacts.tsv")
    IFS=$tab
    set -- $actual
    IFS=$old_ifs
    [ "$2" = "$expected_manifest" ] && [ "$3" = "$expected_root" ] && [ "$4" = "$expected_count" ] \
        || fail CUTOVER_SOURCE_EVIDENCE_CHANGED
done

[ "$(wc -l < "$WORK_ROOT/artifacts.tsv" | tr -d ' ')" = 7 ] || fail CUTOVER_SELECTION_ARTIFACT_SET_INVALID
receipt=$WORK_ROOT/receipt.json
php -d opcache.jit_buffer_size=0 "$VALIDATOR" write-import-receipt \
    "$SELECTION" "$TRUST" "$JOB_ID" "$WORK_ROOT/artifacts.tsv" "$receipt" \
    || fail CUTOVER_RECEIPT_INVALID
publish_file "$receipt" "$RESULT_ROOT/import/receipt.json"

printf '%s\n' CUTOVER_IMPORT_COMPLETE
