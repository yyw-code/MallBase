#!/bin/sh
set -eu

SCRIPT_DIR=$(CDPATH= cd -P "$(dirname "$0")" && pwd)
PROJECT_ROOT=$(CDPATH= cd -P "$SCRIPT_DIR/../../.." && pwd)
PREPARE_SCRIPT=$PROJECT_ROOT/deploy/docker/prepare-data-dirs.sh
COMPOSE_FILE=$PROJECT_ROOT/docker-compose.dev.yml
UPGRADE_IGNORE=$PROJECT_ROOT/upgrade/.gitignore
TEST_ROOT=$(mktemp -d "${TMPDIR:-/tmp}/mallbase-prepare-data-dirs-test.XXXXXX")
TRACE_FILE=$TEST_ROOT/trace.log
FIXTURE=$TEST_ROOT/project
TEST_UID=$(id -u)
TEST_GID=$(id -g)
trap 'chmod -R u+rwx "$TEST_ROOT" 2>/dev/null || true; rm -rf "$TEST_ROOT"' 0 HUP INT TERM

mode_of() {
    if stat -f '%Lp' "$1" >/dev/null 2>&1; then
        stat -f '%Lp' "$1"
        return
    fi
    stat -c '%a' "$1"
}

uid_of() {
    if stat -f '%u' "$1" >/dev/null 2>&1; then
        stat -f '%u' "$1"
        return
    fi
    stat -c '%u' "$1"
}

gid_of() {
    if stat -f '%g' "$1" >/dev/null 2>&1; then
        stat -f '%g' "$1"
        return
    fi
    stat -c '%g' "$1"
}

assert_mount_policy() {
    source_path=$1
    expected_policy=$2
    expected_count=$3
    actual_count=$(awk -v source_path="$source_path" -v expected_policy="$expected_policy" '
        {
            line = $0
            sub(/^[[:space:]]+/, "", line)
        }
        line == "source: ./" source_path {
            matched_source = 1
            next
        }
        matched_source && line == "create_host_path: " expected_policy {
            count++
            matched_source = 0
            next
        }
        matched_source && index(line, "source: ") == 1 {
            matched_source = 0
        }
        END { print count + 0 }
    ' "$COMPOSE_FILE")
    [ "$actual_count" -eq "$expected_count" ] || {
        printf '%s\n' "MOUNT_POLICY_INVALID:${source_path}:${expected_policy}:${actual_count}" >&2
        exit 1
    }
}

mkdir -p "$FIXTURE/upgrade/config" "$FIXTURE/upgrade/run"
printf '%s\n' existing-state > "$FIXTURE/upgrade/config/instance.json"
chmod 0600 "$FIXTURE/upgrade/config/instance.json"

WORKDIR=$FIXTURE \
DATA_UID=$TEST_UID \
DATA_GID=$TEST_GID \
MALLBASE_DEV_UID=$TEST_UID \
MALLBASE_DEV_GID=$TEST_GID \
    sh -x "$PREPARE_SCRIPT" >"$TRACE_FILE" 2>&1

expected_mode=2770
[ "$(uname -s)" = Darwin ] && expected_mode=770
for directory in \
    upgrade/config \
    upgrade/run \
    upgrade/run/requests \
    upgrade/jobs \
    upgrade/backups; do
    path=$FIXTURE/$directory
    [ -d "$path" ]
    [ "$(uid_of "$path")" = "$TEST_UID" ]
    [ "$(gid_of "$path")" = "$TEST_GID" ]
    [ "$(mode_of "$path")" = "$expected_mode" ]
    grep -F "chown ${TEST_UID}:${TEST_GID} ${path}" "$TRACE_FILE" >/dev/null
done

grep -Fx existing-state "$FIXTURE/upgrade/config/instance.json" >/dev/null
[ "$(mode_of "$FIXTURE/upgrade/config/instance.json")" = 600 ]
if grep -F "chown -R ${TEST_UID}:${TEST_GID} ${FIXTURE}/upgrade" "$TRACE_FILE" >/dev/null; then
    printf '%s\n' UPGRADE_RUNTIME_RECURSIVE_CHOWNED >&2
    exit 1
fi

for directory in config run jobs backups; do
    grep -Fx "/${directory}/" "$UPGRADE_IGNORE" >/dev/null
    assert_mount_policy "upgrade/${directory}" true 2
done
assert_mount_policy upgrade/bin false 2

grep -F 'MALLBASE_DEV_UID: "${MALLBASE_DEV_UID:-10000}"' "$COMPOSE_FILE" >/dev/null
grep -F 'MALLBASE_DEV_GID: "${MALLBASE_DEV_GID:-10000}"' "$COMPOSE_FILE" >/dev/null

if WORKDIR=$FIXTURE MALLBASE_DEV_UID=invalid sh "$PREPARE_SCRIPT" >/dev/null 2>&1; then
    printf '%s\n' INVALID_UID_ACCEPTED >&2
    exit 1
fi

printf '%s\n' PREPARE_DATA_DIRS_TEST_OK
