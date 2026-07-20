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
printf '%s\n' demo-file > "$FIXTURE/backend/public/static/demo/generated.txt"
printf '%s\n' nested-demo-file > "$FIXTURE/backend/public/static/demo/nested/generated.txt"

sh "$FIXTURE/deploy/docker/cleanup-dev.sh" --basic >/dev/null

[ ! -e "$FIXTURE/.env" ]
[ ! -e "$FIXTURE/backend/.env" ]
[ ! -e "$FIXTURE/backend/.backend-env.lock" ]
[ ! -e "$FIXTURE/backend/.mallbase-env" ]
[ ! -e "$FIXTURE/backend/runtime/install/install.lock" ]
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
sh "$SYMLINK_FIXTURE/deploy/docker/cleanup-dev.sh" --basic >/dev/null
[ ! -e "$SYMLINK_FIXTURE/backend/.mallbase-env" ]
grep -Fx outside-config "$OUTSIDE_ENV/backend.env" >/dev/null

printf '%s\n' CLEANUP_DEV_TEST_OK
