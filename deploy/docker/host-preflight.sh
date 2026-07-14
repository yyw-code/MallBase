#!/bin/sh
set -eu

SCRIPT_DIR=$(CDPATH= cd -P "$(dirname "$0")" && pwd)
PROJECT_ROOT=$(CDPATH= cd -P "$SCRIPT_DIR/../.." && pwd)
CHECK_ONLY=0
CUTOVER_JOB_ID=
BOOTSTRAP_ADOPT_OPERATION_ID=
MALLBASE_APP_UID=10000
SHARED_DIRECTORY_MODE=2770
if [ "$(uname -s)" = Darwin ]; then
    # APFS temporary roots may clear setgid. Released production Agent runs on
    # Linux and still requires 2770; Darwin remains a portable development gate.
    SHARED_DIRECTORY_MODE=770
fi

fail() {
    printf '%s\n' "$1" >&2
    exit 1
}

usage() {
    printf '%s\n' 'usage: host-preflight.sh [--check] [--project-root PATH] [--prepare-cutover JOB_ID] [--prepare-bootstrap-adopt OPERATION_ID]' >&2
    exit 2
}

while [ "$#" -gt 0 ]; do
    case "$1" in
        --check)
            CHECK_ONLY=1
            shift
            ;;
        --project-root)
            [ "$#" -ge 2 ] || usage
            PROJECT_ROOT=$2
            shift 2
            ;;
        --prepare-cutover)
            [ "$#" -ge 2 ] || usage
            CUTOVER_JOB_ID=$2
            shift 2
            ;;
        --prepare-bootstrap-adopt)
            [ "$#" -ge 2 ] || usage
            BOOTSTRAP_ADOPT_OPERATION_ID=$2
            shift 2
            ;;
        *) usage ;;
    esac
done

[ -d "$PROJECT_ROOT" ] && [ ! -L "$PROJECT_ROOT" ] || fail HOST_PREFLIGHT_PROJECT_ROOT_INVALID
PROJECT_ROOT=$(CDPATH= cd -P "$PROJECT_ROOT" && pwd)
UPGRADE_ROOT=$PROJECT_ROOT/upgrade
BIN_ROOT=$UPGRADE_ROOT/bin
ROOT_ENV=$PROJECT_ROOT/.env
CALLER_UID=$(id -u)
CALLER_GID=$(id -g)
AGENT_UID=
SHARED_GID=
[ -d "$UPGRADE_ROOT" ] && [ ! -L "$UPGRADE_ROOT" ] || fail HOST_PREFLIGHT_UPGRADE_ROOT_INVALID

case "$CUTOVER_JOB_ID" in
    '') ;;
    ????????-????-????-????-????????????)
        printf '%s\n' "$CUTOVER_JOB_ID" | grep -Eq '^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$' \
            || fail CUTOVER_JOB_ID_INVALID
        ;;
    *) fail CUTOVER_JOB_ID_INVALID ;;
esac

case "$BOOTSTRAP_ADOPT_OPERATION_ID" in
    '') ;;
    ????????-????-????-????-????????????)
        printf '%s\n' "$BOOTSTRAP_ADOPT_OPERATION_ID" | grep -Eq '^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$' \
            || fail BOOTSTRAP_ADOPT_OPERATION_ID_INVALID
        ;;
    *) fail BOOTSTRAP_ADOPT_OPERATION_ID_INVALID ;;
esac

sha256_file() {
    if command -v sha256sum >/dev/null 2>&1; then
        sha256sum "$1" | awk '{print $1}'
        return
    fi
    if command -v shasum >/dev/null 2>&1; then
        shasum -a 256 "$1" | awk '{print $1}'
        return
    fi
    fail HOST_PREFLIGHT_SHA256_UNAVAILABLE
}

mode_of() {
    if stat -f '%Lp' "$1" >/dev/null 2>&1; then
        stat -f '%Lp' "$1"
        return
    fi
    stat -c '%a' "$1" 2>/dev/null || fail HOST_PREFLIGHT_STAT_UNAVAILABLE
}

nlink_of() {
    if stat -f '%l' "$1" >/dev/null 2>&1; then
        stat -f '%l' "$1"
        return
    fi
    stat -c '%h' "$1" 2>/dev/null || fail HOST_PREFLIGHT_STAT_UNAVAILABLE
}

gid_of() {
    if stat -f '%g' "$1" >/dev/null 2>&1; then
        stat -f '%g' "$1"
        return
    fi
    stat -c '%g' "$1" 2>/dev/null || fail HOST_PREFLIGHT_STAT_UNAVAILABLE
}

uid_of() {
    if stat -f '%u' "$1" >/dev/null 2>&1; then
        stat -f '%u' "$1"
        return
    fi
    stat -c '%u' "$1" 2>/dev/null || fail HOST_PREFLIGHT_STAT_UNAVAILABLE
}

assert_directory() {
    path=$1
    expected_mode=$2
    [ -d "$path" ] && [ ! -L "$path" ] || fail HOST_PREFLIGHT_DIRECTORY_INVALID
    actual_mode=$(mode_of "$path")
    [ "$actual_mode" = "$expected_mode" ] \
        || fail "HOST_PREFLIGHT_MODE_INVALID:${path#"$PROJECT_ROOT"/}:$actual_mode:$expected_mode"
    [ "$(uid_of "$path")" = "$AGENT_UID" ] || fail HOST_PREFLIGHT_OWNER_INVALID
    [ "$(gid_of "$path")" = "$SHARED_GID" ] || fail HOST_PREFLIGHT_GROUP_INVALID
}

assert_regular() {
    path=$1
    expected_mode=$2
    expected_uid=${3:-$AGENT_UID}
    expected_gid=${4:-$SHARED_GID}
    [ -f "$path" ] && [ ! -L "$path" ] || fail HOST_PREFLIGHT_FILE_INVALID
    [ "$(nlink_of "$path")" = 1 ] || fail HOST_PREFLIGHT_FILE_INVALID
    actual_mode=$(mode_of "$path")
    [ "$actual_mode" = "$expected_mode" ] \
        || fail "HOST_PREFLIGHT_MODE_INVALID:${path#"$PROJECT_ROOT"/}:$actual_mode:$expected_mode"
    [ "$(uid_of "$path")" = "$expected_uid" ] || fail HOST_PREFLIGHT_OWNER_INVALID
    [ "$(gid_of "$path")" = "$expected_gid" ] || fail HOST_PREFLIGHT_GROUP_INVALID
}

prepare_directory() {
    path=$1
    mode=$2
    if [ -L "$path" ]; then
        fail HOST_PREFLIGHT_DIRECTORY_INVALID
    fi
    mkdir -p "$path"
    if [ "$(uid_of "$path")" != "$AGENT_UID" ]; then
        chown "$AGENT_UID:$SHARED_GID" "$path" || fail HOST_PREFLIGHT_OWNER_INVALID
    fi
    chgrp "$SHARED_GID" "$path"
    chmod "$mode" "$path"
    expected=${mode#0}
    if [ "$expected" = 2770 ]; then
        expected=$SHARED_DIRECTORY_MODE
    fi
    assert_directory "$path" "$expected"
}

set_root_env_value() {
    key=$1
    value=$2
    if [ -L "$ROOT_ENV" ] || { [ -e "$ROOT_ENV" ] && [ ! -f "$ROOT_ENV" ]; }; then
        fail HOST_PREFLIGHT_ROOT_ENV_INVALID
    fi
    tmp=$(mktemp "$PROJECT_ROOT/.env.preflight.XXXXXX")
    if [ -f "$ROOT_ENV" ]; then
        if grep -q "^${key}=" "$ROOT_ENV"; then
            sed "s|^${key}=.*|${key}=${value}|" "$ROOT_ENV" > "$tmp"
        else
            cat "$ROOT_ENV" > "$tmp"
            printf '%s=%s\n' "$key" "$value" >> "$tmp"
        fi
    else
        printf '%s=%s\n' "$key" "$value" > "$tmp"
    fi
    chmod 0600 "$tmp"
    mv "$tmp" "$ROOT_ENV"
}

assert_root_env_value() {
    key=$1
    value=$2
    [ -f "$ROOT_ENV" ] && [ ! -L "$ROOT_ENV" ] || fail HOST_PREFLIGHT_ROOT_ENV_INVALID
    [ "$(get_root_env_value "$key")" = "$value" ] || fail HOST_PREFLIGHT_ROOT_ENV_INVALID
}

get_root_env_value() {
    key=$1
    grep "^${key}=" "$ROOT_ENV" | tail -n1 | sed "s|^${key}=||" || true
}

[ -d "$BIN_ROOT" ] && [ ! -L "$BIN_ROOT" ] || fail AGENT_BINARY_ROOT_MISSING
MANIFEST=$BIN_ROOT/checksums.sha256
[ -f "$MANIFEST" ] && [ ! -L "$MANIFEST" ] || fail AGENT_BINARY_CHECKSUM_MISSING

manifest_lines=$(awk 'NF { count++ } END { print count + 0 }' "$MANIFEST")
[ "$manifest_lines" = 2 ] || fail AGENT_BINARY_CHECKSUM_INVALID
if awk 'NF && ($1 !~ /^[0-9a-f]{64}$/ || $2 !~ /^mallbase-agent-linux-(amd64|arm64)$/ || NF != 2) { exit 1 }' "$MANIFEST"; then
    :
else
    fail AGENT_BINARY_CHECKSUM_INVALID
fi

for architecture in amd64 arm64; do
    name=mallbase-agent-linux-$architecture
    binary=$BIN_ROOT/$name
    [ -f "$binary" ] && [ ! -L "$binary" ] && [ -s "$binary" ] || fail AGENT_BINARY_MISSING
    count=$(awk -v name="$name" '$2 == name { count++ } END { print count + 0 }' "$MANIFEST")
    [ "$count" = 1 ] || fail AGENT_BINARY_CHECKSUM_INVALID
    expected=$(awk -v name="$name" '$2 == name { print $1 }' "$MANIFEST")
    actual=$(sha256_file "$binary")
    [ "$expected" = "$actual" ] || fail AGENT_BINARY_CHECKSUM_INVALID
done

# The published Agent binary identity is the ownership authority. A caller
# with a different identity must not silently create a second trust domain.
AGENT_UID=$(uid_of "$BIN_ROOT/mallbase-agent-linux-amd64")
SHARED_GID=$(gid_of "$BIN_ROOT/mallbase-agent-linux-amd64")
[ "$(uid_of "$BIN_ROOT/mallbase-agent-linux-arm64")" = "$AGENT_UID" ] \
    && [ "$(gid_of "$BIN_ROOT/mallbase-agent-linux-arm64")" = "$SHARED_GID" ] \
    || fail HOST_PREFLIGHT_AGENT_BINARY_OWNER_MISMATCH
[ "$AGENT_UID" != 0 ] && [ "$SHARED_GID" != 0 ] || fail HOST_PREFLIGHT_AGENT_IDENTITY_INVALID
[ "$CALLER_UID" = "$AGENT_UID" ] || fail HOST_PREFLIGHT_CALLER_IDENTITY_INVALID
case " $(id -G) " in
    *" $SHARED_GID "*) : ;;
    *) fail HOST_PREFLIGHT_CALLER_IDENTITY_INVALID ;;
esac
[ "$AGENT_UID" != "$MALLBASE_APP_UID" ] || fail HOST_PREFLIGHT_AGENT_APP_UID_CONFLICT

if [ "$CHECK_ONLY" -eq 0 ]; then
    chgrp "$SHARED_GID" "$UPGRADE_ROOT" "$BIN_ROOT" \
        "$BIN_ROOT/mallbase-agent-linux-amd64" "$BIN_ROOT/mallbase-agent-linux-arm64" "$MANIFEST"
    chmod 0750 "$UPGRADE_ROOT"
    chmod 0555 "$BIN_ROOT" "$BIN_ROOT/mallbase-agent-linux-amd64" "$BIN_ROOT/mallbase-agent-linux-arm64"
    chmod 0444 "$MANIFEST"

    prepare_directory "$UPGRADE_ROOT/config" 2770
    prepare_directory "$UPGRADE_ROOT/run" 2770
    prepare_directory "$UPGRADE_ROOT/state" 2770
    prepare_directory "$UPGRADE_ROOT/jobs" 2770
    prepare_directory "$UPGRADE_ROOT/backups" 2770
    prepare_directory "$UPGRADE_ROOT/lifetime-locks" 0750
    prepare_directory "$UPGRADE_ROOT/staging" 0750
    prepare_directory "$UPGRADE_ROOT/packages" 0700
    prepare_directory "$UPGRADE_ROOT/logs" 0700
    prepare_directory "$UPGRADE_ROOT/agent-private" 0700
    prepare_directory "$UPGRADE_ROOT/bootstrap-retention" 0700
    prepare_directory "$UPGRADE_ROOT/bootstrap-retention/env" 2770
    prepare_directory "$UPGRADE_ROOT/bootstrap-retention/cert" 2770
    prepare_directory "$UPGRADE_ROOT/bootstrap-retention/demo" 2770
    prepare_directory "$UPGRADE_ROOT/bootstrap-retention/public-storage" 2770
    prepare_directory "$UPGRADE_ROOT/bootstrap-retention/operations" 0700
    prepare_directory "$UPGRADE_ROOT/storage-init-results" 0700
    prepare_directory "$UPGRADE_ROOT/legacy-import" 0700
    prepare_directory "$UPGRADE_ROOT/legacy-import/bootstrap-adopt" 0700
    prepare_directory "$UPGRADE_ROOT/legacy-results" 0700
    prepare_directory "$UPGRADE_ROOT/legacy-results/bootstrap-adopt" 2770
    prepare_directory "$UPGRADE_ROOT/agent-private/bootstrap-retention" 0700
    prepare_directory "$UPGRADE_ROOT/agent-private/bootstrap-retention/receipts" 0700

    BACKEND_ENV=$UPGRADE_ROOT/bootstrap-retention/env/backend.env
    if [ -L "$BACKEND_ENV" ] || { [ -e "$BACKEND_ENV" ] && [ ! -f "$BACKEND_ENV" ]; }; then
        fail HOST_PREFLIGHT_FILE_INVALID
    fi
    if [ ! -e "$BACKEND_ENV" ]; then
        # The host-side Agent owns this credential placeholder. The no-network
        # bootstrap helper performs the only ownership hand-off to UID 10000
        # before any business role can start.
        (umask 0177 && : > "$BACKEND_ENV")
    fi
    backend_env_uid=$(uid_of "$BACKEND_ENV")
    case "$backend_env_uid" in
        "$AGENT_UID") chmod 0600 "$BACKEND_ENV" ;;
        "$MALLBASE_APP_UID") : ;;
        *) fail HOST_PREFLIGHT_ENV_OWNER_INVALID ;;
    esac
    assert_regular "$BACKEND_ENV" 600 "$backend_env_uid" "$SHARED_GID"

    set_root_env_value MALLBASE_AGENT_UID "$AGENT_UID"
    set_root_env_value MALLBASE_UPGRADE_SHARED_GID "$SHARED_GID"
    set_root_env_value MALLBASE_DEV_UID "$AGENT_UID"
    set_root_env_value MALLBASE_DEV_GID "$SHARED_GID"

    for lock_name in serve slot-1 slot-2 slot-3 slot-4; do
        lock=$UPGRADE_ROOT/lifetime-locks/$lock_name.lock
        if [ -L "$lock" ] || { [ -e "$lock" ] && [ ! -f "$lock" ]; }; then
            fail HOST_PREFLIGHT_FILE_INVALID
        fi
        if [ ! -e "$lock" ]; then
            (umask 0337 && : > "$lock")
        fi
        chgrp "$SHARED_GID" "$lock"
        chmod 0440 "$lock"
    done

    if [ -n "$CUTOVER_JOB_ID" ]; then
        prepare_directory "$UPGRADE_ROOT/legacy-import/$CUTOVER_JOB_ID" 0700
        prepare_directory "$UPGRADE_ROOT/legacy-results/$CUTOVER_JOB_ID" 2770
    fi
    if [ -n "$BOOTSTRAP_ADOPT_OPERATION_ID" ]; then
        prepare_directory "$UPGRADE_ROOT/bootstrap-retention/operations/$BOOTSTRAP_ADOPT_OPERATION_ID" 0700
        prepare_directory "$UPGRADE_ROOT/bootstrap-retention/operations/$BOOTSTRAP_ADOPT_OPERATION_ID/retention" 0700
        prepare_directory "$UPGRADE_ROOT/bootstrap-retention/operations/$BOOTSTRAP_ADOPT_OPERATION_ID/target-output" 2770
        prepare_directory "$UPGRADE_ROOT/legacy-results/bootstrap-adopt/$BOOTSTRAP_ADOPT_OPERATION_ID" 2770
        for child in normalization import target host recovery finalize; do
            prepare_directory "$UPGRADE_ROOT/legacy-results/bootstrap-adopt/$BOOTSTRAP_ADOPT_OPERATION_ID/$child" 2770
        done
    fi
fi

assert_directory "$UPGRADE_ROOT" 750
assert_directory "$BIN_ROOT" 555
assert_regular "$BIN_ROOT/mallbase-agent-linux-amd64" 555
assert_regular "$BIN_ROOT/mallbase-agent-linux-arm64" 555
assert_regular "$MANIFEST" 444
assert_directory "$UPGRADE_ROOT/config" "$SHARED_DIRECTORY_MODE"
assert_directory "$UPGRADE_ROOT/run" "$SHARED_DIRECTORY_MODE"
assert_directory "$UPGRADE_ROOT/state" "$SHARED_DIRECTORY_MODE"
assert_directory "$UPGRADE_ROOT/jobs" "$SHARED_DIRECTORY_MODE"
assert_directory "$UPGRADE_ROOT/backups" "$SHARED_DIRECTORY_MODE"
assert_directory "$UPGRADE_ROOT/lifetime-locks" 750
assert_directory "$UPGRADE_ROOT/staging" 750
assert_directory "$UPGRADE_ROOT/packages" 700
assert_directory "$UPGRADE_ROOT/logs" 700
assert_directory "$UPGRADE_ROOT/agent-private" 700
assert_directory "$UPGRADE_ROOT/bootstrap-retention" 700
assert_directory "$UPGRADE_ROOT/bootstrap-retention/env" "$SHARED_DIRECTORY_MODE"
assert_directory "$UPGRADE_ROOT/bootstrap-retention/cert" "$SHARED_DIRECTORY_MODE"
assert_directory "$UPGRADE_ROOT/bootstrap-retention/demo" "$SHARED_DIRECTORY_MODE"
assert_directory "$UPGRADE_ROOT/bootstrap-retention/public-storage" "$SHARED_DIRECTORY_MODE"
assert_directory "$UPGRADE_ROOT/bootstrap-retention/operations" 700
assert_directory "$UPGRADE_ROOT/storage-init-results" 700
assert_directory "$UPGRADE_ROOT/legacy-import" 700
assert_directory "$UPGRADE_ROOT/legacy-import/bootstrap-adopt" 700
assert_directory "$UPGRADE_ROOT/legacy-results" 700
assert_directory "$UPGRADE_ROOT/legacy-results/bootstrap-adopt" "$SHARED_DIRECTORY_MODE"
assert_directory "$UPGRADE_ROOT/agent-private/bootstrap-retention" 700
assert_directory "$UPGRADE_ROOT/agent-private/bootstrap-retention/receipts" 700
backend_env_uid=$(uid_of "$UPGRADE_ROOT/bootstrap-retention/env/backend.env")
case "$backend_env_uid" in
    "$AGENT_UID"|"$MALLBASE_APP_UID") : ;;
    *) fail HOST_PREFLIGHT_ENV_OWNER_INVALID ;;
esac
assert_regular "$UPGRADE_ROOT/bootstrap-retention/env/backend.env" 600 "$backend_env_uid" "$SHARED_GID"
assert_root_env_value MALLBASE_AGENT_UID "$AGENT_UID"
assert_root_env_value MALLBASE_UPGRADE_SHARED_GID "$SHARED_GID"
assert_root_env_value MALLBASE_DEV_UID "$AGENT_UID"
assert_root_env_value MALLBASE_DEV_GID "$SHARED_GID"
assert_regular "$ROOT_ENV" 600
for lock_name in serve slot-1 slot-2 slot-3 slot-4; do
    assert_regular "$UPGRADE_ROOT/lifetime-locks/$lock_name.lock" 440
done

if [ -n "$CUTOVER_JOB_ID" ]; then
    assert_directory "$UPGRADE_ROOT/legacy-import/$CUTOVER_JOB_ID" 700
    assert_directory "$UPGRADE_ROOT/legacy-results/$CUTOVER_JOB_ID" "$SHARED_DIRECTORY_MODE"
fi

if [ -n "$BOOTSTRAP_ADOPT_OPERATION_ID" ]; then
    assert_directory "$UPGRADE_ROOT/bootstrap-retention/operations/$BOOTSTRAP_ADOPT_OPERATION_ID" 700
    assert_directory "$UPGRADE_ROOT/bootstrap-retention/operations/$BOOTSTRAP_ADOPT_OPERATION_ID/retention" 700
    assert_directory "$UPGRADE_ROOT/bootstrap-retention/operations/$BOOTSTRAP_ADOPT_OPERATION_ID/target-output" "$SHARED_DIRECTORY_MODE"
    assert_directory "$UPGRADE_ROOT/legacy-results/bootstrap-adopt/$BOOTSTRAP_ADOPT_OPERATION_ID" "$SHARED_DIRECTORY_MODE"
    for child in normalization import target host recovery finalize; do
        assert_directory "$UPGRADE_ROOT/legacy-results/bootstrap-adopt/$BOOTSTRAP_ADOPT_OPERATION_ID/$child" "$SHARED_DIRECTORY_MODE"
    done
fi

printf '%s\n' HOST_PREFLIGHT_OK
