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
[ "${MALLBASE_RUNTIME_ROLE-}" = target-verify ] || fail CUTOVER_TARGET_ROLE_INVALID
if [ -r /proc/self/status ]; then
    cap_eff=$(awk '/^CapEff:/ { print $2 }' /proc/self/status | sed 's/^0*//')
    [ "${cap_eff:-0}" = c9 ] || fail CUTOVER_CAPABILITY_SET_INVALID
fi
[ -f "$VALIDATOR" ] && [ ! -L "$VALIDATOR" ] || fail CUTOVER_VALIDATOR_INVALID
[ -d "$RESULT_ROOT" ] && [ ! -L "$RESULT_ROOT" ] || fail CUTOVER_RESULT_ROOT_INVALID
for artifact in cert demo install local_storage public_storage runtime_backup uploads; do
    [ -d "/target/$artifact" ] && [ ! -L "/target/$artifact" ] || fail CUTOVER_TARGET_MOUNT_INVALID
done

WORK_ROOT=$(mktemp -d /tmp/mallbase-cutover-target.XXXXXX) || fail CUTOVER_TEMP_UNAVAILABLE
cleanup() {
    [ -z "$WORK_ROOT" ] || rm -rf "$WORK_ROOT"
}
trap cleanup 0
trap 'exit 1' HUP INT TERM

php -d opcache.jit_buffer_size=0 "$VALIDATOR" selection-plan \
    "$SELECTION" "$TRUST" "$JOB_ID" provisioned > "$WORK_ROOT/selection.plan" \
    || fail CUTOVER_SELECTION_INVALID

tab=$(printf '\t')
header=$(sed -n '1p' "$WORK_ROOT/selection.plan")
old_ifs=$IFS
IFS=$tab
set -- $header
IFS=$old_ifs
[ "$#" -eq 13 ] && [ "$1" = selection ] && [ "$2" = "$JOB_ID" ] && [ "$3" = provisioned ] \
    || fail CUTOVER_SELECTION_HEADER_INVALID
namespace=$4
candidate_layout_version=${12}
candidate_layout_generation=${13}

sha256_file() {
    sha256sum "$1" | awk '{print $1}'
}

sha256_root() {
    { printf 'mallbase-content-root-v1\0'; cat "$1"; } | sha256sum | awk '{print $1}'
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
        fail CUTOVER_TARGET_TYPE_INVALID
    fi
    if ! find -P "$root" -xdev -type f ! -name .mallbase-layout-marker.json -exec sh -c '
        for path do [ "$(stat -c %h "$path")" = 1 ] || exit 1; done
    ' sh {} +; then
        fail CUTOVER_TARGET_HARDLINK_INVALID
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
            fail CUTOVER_TARGET_TYPE_INVALID
        fi
    done > "$output"
}

assert_policy() {
    ap_root=$1
    ap_expected_root=$2
    ap_expected_marker=$3
    ap_expected_directory=$4
    ap_expected_file=$5
    [ "$(stat -c %u:%g:%a "$ap_root")" = "$ap_expected_root" ] || fail CUTOVER_TARGET_POLICY_INVALID
    [ "$(stat -c %u:%g:%a "$ap_root/.mallbase-layout-marker.json")" = "$ap_expected_marker" ] \
        || fail CUTOVER_TARGET_POLICY_INVALID
    find -P "$ap_root" -xdev -mindepth 1 -type d -exec sh -c '
        expected=$1
        shift
        for path do [ "$(stat -c %u:%g:%a "$path")" = "$expected" ] || exit 1; done
    ' sh "$ap_expected_directory" {} + || fail CUTOVER_TARGET_POLICY_INVALID
    find -P "$ap_root" -xdev -mindepth 1 -type f ! -name .mallbase-layout-marker.json -exec sh -c '
        expected=$1
        shift
        for path do [ "$(stat -c %u:%g:%a "$path")" = "$expected" ] || exit 1; done
    ' sh "$ap_expected_file" {} + || fail CUTOVER_TARGET_POLICY_INVALID
}

: > "$WORK_ROOT/contents.tsv"
artifact_count=0
sed -n '2,$p' "$WORK_ROOT/selection.plan" | while IFS= read -r line; do
    IFS=$tab
    set -- $line
    IFS=$old_ifs
    [ "$#" -eq 29 ] && [ "$1" = artifact ] || fail CUTOVER_SELECTION_ARTIFACT_INVALID
    artifact=$2
    marker_id=${13}
    marker_sha=${14}
    root_identity=${15}:${16}:${17#0}
    marker_identity=${18}:${19}:${20#0}
    directory_identity=${21}:${22}:${23#0}
    file_identity=${24}:${25}:${26#0}
    expected_manifest=${27}
    expected_root=${28}
    expected_count=${29}
    root=/target/$artifact
    marker=$root/.mallbase-layout-marker.json
    [ -f "$marker" ] && [ ! -L "$marker" ] && [ "$(stat -c %h "$marker")" = 1 ] \
        || fail CUTOVER_TARGET_MARKER_INVALID
    printf '%s' '{"schema_version":1,"installation_storage_namespace":"'"$namespace"'","artifact":"'"$artifact"'","storage_layout_version":'"$candidate_layout_version"',"layout_generation":'"$candidate_layout_generation"',"marker_id":"'"$marker_id"'"}' \
        > "$WORK_ROOT/$artifact.marker"
    cmp -s "$WORK_ROOT/$artifact.marker" "$marker" \
        && [ "sha256:$(sha256_file "$marker")" = "$marker_sha" ] \
        || fail CUTOVER_TARGET_MARKER_INVALID
    assert_policy "$root" "$root_identity" "$marker_identity" "$directory_identity" "$file_identity"
    manifest=$WORK_ROOT/$artifact.manifest
    write_manifest "$root" "$manifest" "$WORK_ROOT/$artifact.list"
    manifest_sha=sha256:$(sha256_file "$manifest")
    root_sha=sha256:$(sha256_root "$manifest")
    entry_count=$(wc -l < "$manifest" | tr -d ' ')
    [ "$manifest_sha" = "$expected_manifest" ] && [ "$root_sha" = "$expected_root" ] \
        && [ "$entry_count" = "$expected_count" ] \
        || fail "CUTOVER_TARGET_CONTENT_INVALID:$artifact"
    printf '%s\t%s\t%s\t%s\n' "$artifact" "$manifest_sha" "$root_sha" "$entry_count" \
        >> "$WORK_ROOT/contents.tsv"
done
while IFS=$tab read -r artifact manifest_sha root_sha entry_count; do
    artifact_count=$((artifact_count + 1))
done < "$WORK_ROOT/contents.tsv"
[ "$artifact_count" -eq 7 ] || fail CUTOVER_SELECTION_ARTIFACT_SET_INVALID

MALLBASE_UPGRADE_SHARED_GID=$SHARED_GID php -d opcache.enable_cli=0 -d opcache.jit_buffer_size=0 \
    /usr/local/bin/run-target-php.php \
    > "$WORK_ROOT/php-snapshot.json" || fail CUTOVER_PHP_TARGET_SNAPSHOT_FAILED
[ -s "$WORK_ROOT/php-snapshot.json" ] || fail CUTOVER_PHP_TARGET_SNAPSHOT_FAILED

php -d opcache.jit_buffer_size=0 "$VALIDATOR" write-target-verification \
    "$SELECTION" "$TRUST" "$JOB_ID" "$WORK_ROOT/contents.tsv" "$WORK_ROOT/php-snapshot.json" \
    /.version /.mallbase-deployment.json "$WORK_ROOT/verification.json" \
    || fail CUTOVER_TARGET_VERIFICATION_INVALID

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
    source=$1
    destination=$2
    parent=${destination%/*}
    if [ -e "$destination" ] || [ -L "$destination" ]; then
        [ -f "$destination" ] && [ ! -L "$destination" ] \
            && [ "$(stat -c %h "$destination")" = 1 ] \
            && [ "$(stat -c %u:%g:%a "$destination")" = "$AGENT_UID:$SHARED_GID:640" ] \
            && cmp -s "$source" "$destination" || fail CUTOVER_RESULT_CONFLICT
        return
    fi
    temp=$(mktemp "$parent/.cutover-target-$JOB_ID.tmp.XXXXXX") || fail CUTOVER_RESULT_WRITE_FAILED
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

umask 0027
ensure_result_directory "$RESULT_ROOT/target"
publish_file "$WORK_ROOT/verification.json" "$RESULT_ROOT/target/verification.json"
printf '%s\n' CUTOVER_TARGET_VERIFIED
