#!/bin/sh
set -eu

SCRIPT_DIR=$(CDPATH= cd -P "$(dirname "$0")" && pwd)
PROJECT_ROOT=$(CDPATH= cd -P "$SCRIPT_DIR/../../.." && pwd)
CLEANUP_SCRIPT=$PROJECT_ROOT/deploy/docker/cleanup-dev.sh
TEST_ROOT=$(mktemp -d "${TMPDIR:-/tmp}/mallbase-cleanup-dev-test.XXXXXX")
FIXTURE=$TEST_ROOT/project
trap 'chmod -R u+rwx "$TEST_ROOT" 2>/dev/null || true; rm -rf "$TEST_ROOT"' 0 HUP INT TERM

mkdir -p \
    "$FIXTURE/deploy/docker" \
    "$FIXTURE/backend/.mallbase-env" \
    "$FIXTURE/backend/runtime/install" \
    "$FIXTURE/backend/public/static/demo/nested"
cp "$CLEANUP_SCRIPT" "$FIXTURE/deploy/docker/cleanup-dev.sh"
cp "$PROJECT_ROOT/backend/public/static/demo/README.md" \
    "$FIXTURE/backend/public/static/demo/README.md"
readme_hash=$(cksum "$FIXTURE/backend/public/static/demo/README.md")

printf '%s\n' ROOT_VALUE=1 > "$FIXTURE/.env"
printf '%s\n' LEGACY_VALUE=1 > "$FIXTURE/backend/.env"
printf '%s\n' legacy-lock > "$FIXTURE/backend/.backend-env.lock"
printf '%s\n' RUNTIME_VALUE=1 > "$FIXTURE/backend/.mallbase-env/backend.env"
printf '%s\n' runtime-lock > "$FIXTURE/backend/.mallbase-env/.backend-env.lock"
printf '%s\n' install-lock > "$FIXTURE/backend/runtime/install/install.lock"
printf '%s\n' install-guard > "$FIXTURE/backend/runtime/install/.install.lock.guard"
printf '%s\n' demo-file > "$FIXTURE/backend/public/static/demo/generated.txt"
printf '%s\n' nested-demo-file > "$FIXTURE/backend/public/static/demo/nested/generated.txt"

MALLBASE_CONTAINER_PREFIX="cleanup-basic-$$" \
    sh "$FIXTURE/deploy/docker/cleanup-dev.sh" --basic >/dev/null

[ ! -e "$FIXTURE/.env" ]
[ ! -e "$FIXTURE/backend/.env" ]
[ ! -e "$FIXTURE/backend/.backend-env.lock" ]
[ ! -e "$FIXTURE/backend/.mallbase-env" ]
[ ! -e "$FIXTURE/backend/runtime/install/install.lock" ]
[ ! -e "$FIXTURE/backend/runtime/install/.install.lock.guard" ]
[ -f "$FIXTURE/backend/public/static/demo/README.md" ]
[ "$readme_hash" = "$(cksum "$FIXTURE/backend/public/static/demo/README.md")" ]
[ ! -e "$FIXTURE/backend/public/static/demo/generated.txt" ]
[ ! -e "$FIXTURE/backend/public/static/demo/nested" ]

SYMLINK_FIXTURE=$TEST_ROOT/symlink-project
OUTSIDE_ENV=$TEST_ROOT/outside-env
mkdir -p \
    "$SYMLINK_FIXTURE/deploy/docker" \
    "$SYMLINK_FIXTURE/backend/runtime/install" \
    "$SYMLINK_FIXTURE/backend/public/static/demo" \
    "$OUTSIDE_ENV"
cp "$CLEANUP_SCRIPT" "$SYMLINK_FIXTURE/deploy/docker/cleanup-dev.sh"
printf '%s\n' outside-config > "$OUTSIDE_ENV/backend.env"
ln -s "$OUTSIDE_ENV" "$SYMLINK_FIXTURE/backend/.mallbase-env"
MALLBASE_CONTAINER_PREFIX="cleanup-symlink-$$" \
    sh "$SYMLINK_FIXTURE/deploy/docker/cleanup-dev.sh" --basic >/dev/null
[ ! -e "$SYMLINK_FIXTURE/backend/.mallbase-env" ]
grep -Fx outside-config "$OUTSIDE_ENV/backend.env" >/dev/null

FAKE_BIN=$TEST_ROOT/fake-bin
mkdir -p "$FAKE_BIN"
printf '%s\n' \
    '#!/bin/sh' \
    'set -eu' \
    'case "${MALLBASE_CLEANUP_TEST_MODE:-}" in' \
    '  running)' \
    '    if [ "${1:-}" = ps ]; then printf "%s\n" "${MALLBASE_CONTAINER_PREFIX}-dev"; fi' \
    '    ;;' \
    '  unavailable)' \
    '    exit 1' \
    '    ;;' \
    '  order)' \
    '    if [ -f "$MALLBASE_CLEANUP_TEST_ROOT/.env" ] && [ -f "$MALLBASE_CLEANUP_TEST_ROOT/backend/runtime/install/install.lock" ]; then' \
    '      state=before' \
    '    else' \
    '      state=after' \
    '    fi' \
    '    printf "%s:%s\n" "$state" "$*" >> "$MALLBASE_CLEANUP_TEST_LOG"' \
    '    ;;' \
    'esac' \
    'exit 0' > "$FAKE_BIN/docker"
chmod 0755 "$FAKE_BIN/docker"

DOCKER_FIXTURE=$TEST_ROOT/docker-project
mkdir -p \
    "$DOCKER_FIXTURE/deploy/docker" \
    "$DOCKER_FIXTURE/backend/runtime/install" \
    "$DOCKER_FIXTURE/backend/public/static/demo"
cp "$CLEANUP_SCRIPT" "$DOCKER_FIXTURE/deploy/docker/cleanup-dev.sh"
printf '%s\n' ROOT_VALUE=1 > "$DOCKER_FIXTURE/.env"
printf '%s\n' install-lock > "$DOCKER_FIXTURE/backend/runtime/install/install.lock"
printf '%s\n' install-guard > "$DOCKER_FIXTURE/backend/runtime/install/.install.lock.guard"
printf '%s\n' services: > "$DOCKER_FIXTURE/docker-compose.dev.yml"
DOCKER_LOG=$TEST_ROOT/docker-order.log
PATH="$FAKE_BIN:$PATH" \
MALLBASE_CLEANUP_TEST_MODE=order \
MALLBASE_CLEANUP_TEST_ROOT="$DOCKER_FIXTURE" \
MALLBASE_CLEANUP_TEST_LOG="$DOCKER_LOG" \
MALLBASE_CONTAINER_PREFIX="cleanup-docker-$$" \
    sh "$DOCKER_FIXTURE/deploy/docker/cleanup-dev.sh" --docker >/dev/null
[ -s "$DOCKER_LOG" ]
grep '^before:' "$DOCKER_LOG" >/dev/null
if grep '^after:' "$DOCKER_LOG" >/dev/null; then
    echo "cleanup-dev --docker 在停止容器前删除了安装状态" >&2
    exit 1
fi
[ ! -e "$DOCKER_FIXTURE/.env" ]
[ ! -e "$DOCKER_FIXTURE/backend/runtime/install/install.lock" ]
[ ! -e "$DOCKER_FIXTURE/backend/runtime/install/.install.lock.guard" ]

RUNNING_FIXTURE=$TEST_ROOT/running-project
mkdir -p \
    "$RUNNING_FIXTURE/deploy/docker" \
    "$RUNNING_FIXTURE/backend/runtime/install" \
    "$RUNNING_FIXTURE/backend/public/static/demo"
cp "$CLEANUP_SCRIPT" "$RUNNING_FIXTURE/deploy/docker/cleanup-dev.sh"
printf '%s\n' ROOT_VALUE=1 > "$RUNNING_FIXTURE/.env"
printf '%s\n' install-lock > "$RUNNING_FIXTURE/backend/runtime/install/install.lock"
printf '%s\n' install-guard > "$RUNNING_FIXTURE/backend/runtime/install/.install.lock.guard"
set +e
PATH="$FAKE_BIN:$PATH" \
MALLBASE_CLEANUP_TEST_MODE=running \
MALLBASE_CONTAINER_PREFIX="cleanup-running-$$" \
    sh "$RUNNING_FIXTURE/deploy/docker/cleanup-dev.sh" --basic >/dev/null 2>&1
running_status=$?
set -e
[ "$running_status" -ne 0 ]
[ -f "$RUNNING_FIXTURE/.env" ]
[ -f "$RUNNING_FIXTURE/backend/runtime/install/install.lock" ]
[ -f "$RUNNING_FIXTURE/backend/runtime/install/.install.lock.guard" ]

set +e
PATH="$FAKE_BIN:$PATH" \
MALLBASE_CLEANUP_TEST_MODE=running \
MALLBASE_CONTAINER_PREFIX="cleanup-running-$$" \
    sh "$RUNNING_FIXTURE/deploy/docker/cleanup-dev.sh" --docker >/dev/null 2>&1
docker_running_status=$?
set -e
[ "$docker_running_status" -ne 0 ]
[ -f "$RUNNING_FIXTURE/.env" ]
[ -f "$RUNNING_FIXTURE/backend/runtime/install/install.lock" ]
[ -f "$RUNNING_FIXTURE/backend/runtime/install/.install.lock.guard" ]

UNAVAILABLE_FIXTURE=$TEST_ROOT/unavailable-project
mkdir -p \
    "$UNAVAILABLE_FIXTURE/deploy/docker" \
    "$UNAVAILABLE_FIXTURE/backend/runtime/install" \
    "$UNAVAILABLE_FIXTURE/backend/public/static/demo"
cp "$CLEANUP_SCRIPT" "$UNAVAILABLE_FIXTURE/deploy/docker/cleanup-dev.sh"
printf '%s\n' ROOT_VALUE=1 > "$UNAVAILABLE_FIXTURE/.env"
printf '%s\n' install-lock > "$UNAVAILABLE_FIXTURE/backend/runtime/install/install.lock"
printf '%s\n' install-guard > "$UNAVAILABLE_FIXTURE/backend/runtime/install/.install.lock.guard"
set +e
PATH="$FAKE_BIN:$PATH" \
MALLBASE_CLEANUP_TEST_MODE=unavailable \
MALLBASE_CONTAINER_PREFIX="cleanup-unavailable-$$" \
    sh "$UNAVAILABLE_FIXTURE/deploy/docker/cleanup-dev.sh" --basic >/dev/null 2>&1
unavailable_status=$?
set -e
[ "$unavailable_status" -ne 0 ]
[ -f "$UNAVAILABLE_FIXTURE/.env" ]
[ -f "$UNAVAILABLE_FIXTURE/backend/runtime/install/install.lock" ]
[ -f "$UNAVAILABLE_FIXTURE/backend/runtime/install/.install.lock.guard" ]

PARENT_LINK_FIXTURE=$TEST_ROOT/parent-link-project
OUTSIDE_RUNTIME=$TEST_ROOT/outside-runtime
mkdir -p \
    "$PARENT_LINK_FIXTURE/deploy/docker" \
    "$PARENT_LINK_FIXTURE/backend/public/static/demo" \
    "$OUTSIDE_RUNTIME/install"
cp "$CLEANUP_SCRIPT" "$PARENT_LINK_FIXTURE/deploy/docker/cleanup-dev.sh"
printf '%s\n' ROOT_VALUE=1 > "$PARENT_LINK_FIXTURE/.env"
printf '%s\n' outside-install-lock > "$OUTSIDE_RUNTIME/install/install.lock"
printf '%s\n' outside-install-guard > "$OUTSIDE_RUNTIME/install/.install.lock.guard"
ln -s "$OUTSIDE_RUNTIME" "$PARENT_LINK_FIXTURE/backend/runtime"
set +e
PATH="$FAKE_BIN:$PATH" \
MALLBASE_CONTAINER_PREFIX="cleanup-parent-link-$$" \
    sh "$PARENT_LINK_FIXTURE/deploy/docker/cleanup-dev.sh" --basic >/dev/null 2>&1
parent_link_status=$?
set -e
[ "$parent_link_status" -ne 0 ]
[ -f "$PARENT_LINK_FIXTURE/.env" ]
grep -Fx outside-install-lock "$OUTSIDE_RUNTIME/install/install.lock" >/dev/null
grep -Fx outside-install-guard "$OUTSIDE_RUNTIME/install/.install.lock.guard" >/dev/null

printf '%s\n' CLEANUP_DEV_TEST_OK
