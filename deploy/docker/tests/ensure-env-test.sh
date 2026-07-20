#!/bin/sh
set -eu

SCRIPT_DIR=$(CDPATH= cd -P "$(dirname "$0")" && pwd)
PROJECT_ROOT=$(CDPATH= cd -P "$SCRIPT_DIR/../../.." && pwd)
ENSURE_SCRIPT=$PROJECT_ROOT/deploy/docker/ensure-env.sh
ENTRYPOINT=$PROJECT_ROOT/deploy/docker/docker-entrypoint.sh
COMPOSE_FILE=$PROJECT_ROOT/docker-compose.dev.yml
TEST_ROOT=$(mktemp -d "${TMPDIR:-/tmp}/mallbase-ensure-env-test.XXXXXX")
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

mkdir -p "$FIXTURE/backend" "$FIXTURE/deploy/docker"
cp "$PROJECT_ROOT/backend/.example.env" "$FIXTURE/backend/.example.env"
cp "$PROJECT_ROOT/deploy/docker/.example.env" "$FIXTURE/deploy/docker/.example.env"

cat > "$FIXTURE/backend/.env" <<'EOF'
APP_DEBUG=false
CRON_ENABLE=true
DB_HOST=legacy-db
DB_NAME=legacy_name
DB_USER=legacy_user
DB_PASS=legacy-pass
JWT_SECRET=legacy-jwt-secret
SITE_URL=https://legacy.example.test
EOF
chmod 0600 "$FIXTURE/backend/.env"
legacy_hash=$(cksum "$FIXTURE/backend/.env")

output=$(WORKDIR=$FIXTURE \
    MALLBASE_DEV_UID=$TEST_UID \
    MALLBASE_DEV_GID=$TEST_GID \
    sh "$ENSURE_SCRIPT")

printf '%s\n' "$output" | grep -F '已将旧 backend/.env 复制迁移到 backend/.mallbase-env/backend.env' >/dev/null
[ -f "$FIXTURE/backend/.env" ]
[ "$legacy_hash" = "$(cksum "$FIXTURE/backend/.env")" ]
[ -f "$FIXTURE/backend/.mallbase-env/backend.env" ]
[ ! -L "$FIXTURE/backend/.mallbase-env" ]
[ ! -L "$FIXTURE/backend/.mallbase-env/backend.env" ]
[ "$(mode_of "$FIXTURE/backend/.mallbase-env/backend.env")" = 600 ]
[ "$(uid_of "$FIXTURE/backend/.mallbase-env/backend.env")" = "$TEST_UID" ]
[ "$(gid_of "$FIXTURE/backend/.mallbase-env/backend.env")" = "$TEST_GID" ]
grep -Fx 'APP_DEBUG=false' "$FIXTURE/backend/.mallbase-env/backend.env" >/dev/null
grep -Fx 'CRON_ENABLE=true' "$FIXTURE/backend/.mallbase-env/backend.env" >/dev/null
grep -Fx 'DB_PASS=legacy-pass' "$FIXTURE/backend/.mallbase-env/backend.env" >/dev/null
grep -Fx 'JWT_SECRET=legacy-jwt-secret' "$FIXTURE/backend/.mallbase-env/backend.env" >/dev/null
grep -Fx 'DB_PASS=legacy-pass' "$FIXTURE/.env" >/dev/null
grep -Fx 'JWT_SECRET=legacy-jwt-secret' "$FIXTURE/.env" >/dev/null

sed \
    -e 's/^CRON_ENABLE=.*/CRON_ENABLE=false/' \
    -e 's/^INSTALL_RUNTIME_MARKER=.*/INSTALL_RUNTIME_MARKER=installed-runtime-marker/' \
    "$FIXTURE/backend/.mallbase-env/backend.env" > "$FIXTURE/backend/.mallbase-env/backend.env.next"
mv "$FIXTURE/backend/.mallbase-env/backend.env.next" "$FIXTURE/backend/.mallbase-env/backend.env"
chmod 0600 "$FIXTURE/backend/.mallbase-env/backend.env"
output=$(WORKDIR=$FIXTURE \
    MALLBASE_DEV_UID=$TEST_UID \
    MALLBASE_DEV_GID=$TEST_GID \
    sh "$ENSURE_SCRIPT")
printf '%s\n' "$output" | grep -F '检测到新旧两份运行配置；保留旧 backend/.env，不覆盖新路径' >/dev/null
grep -Fx 'CRON_ENABLE=false' "$FIXTURE/backend/.mallbase-env/backend.env" >/dev/null
grep -Fx 'INSTALL_RUNTIME_MARKER=installed-runtime-marker' "$FIXTURE/backend/.mallbase-env/backend.env" >/dev/null
grep -Fx 'CRON_ENABLE=true' "$FIXTURE/backend/.env" >/dev/null

sed 's/^INSTALL_RUNTIME_MARKER=.*/INSTALL_RUNTIME_MARKER=/' \
    "$FIXTURE/backend/.mallbase-env/backend.env" > "$FIXTURE/backend/.mallbase-env/backend.env.next"
mv "$FIXTURE/backend/.mallbase-env/backend.env.next" "$FIXTURE/backend/.mallbase-env/backend.env"
chmod 0600 "$FIXTURE/backend/.mallbase-env/backend.env"
mkdir -p "$FIXTURE/backend/runtime/install"
touch "$FIXTURE/backend/runtime/install/install.lock"
WORKDIR=$FIXTURE \
    MALLBASE_DEV_UID=$TEST_UID \
    MALLBASE_DEV_GID=$TEST_GID \
    sh "$ENSURE_SCRIPT" >/dev/null
recovered_marker=$(sed -n 's/^INSTALL_RUNTIME_MARKER=//p' "$FIXTURE/backend/.mallbase-env/backend.env")
case "$recovered_marker" in
    [0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f]) ;;
    *)
        printf '%s\n' INSTALL_RUNTIME_MARKER_RECOVERY_FAILED >&2
        exit 1
        ;;
esac

SYMLINK_FIXTURE=$TEST_ROOT/symlink
mkdir -p "$SYMLINK_FIXTURE/backend" "$SYMLINK_FIXTURE/deploy/docker" "$TEST_ROOT/outside-env"
cp "$PROJECT_ROOT/backend/.example.env" "$SYMLINK_FIXTURE/backend/.example.env"
cp "$PROJECT_ROOT/deploy/docker/.example.env" "$SYMLINK_FIXTURE/deploy/docker/.example.env"
ln -s "$TEST_ROOT/outside-env" "$SYMLINK_FIXTURE/backend/.mallbase-env"
if WORKDIR=$SYMLINK_FIXTURE \
    MALLBASE_DEV_UID=$TEST_UID \
    MALLBASE_DEV_GID=$TEST_GID \
    sh "$ENSURE_SCRIPT" >/dev/null 2>&1; then
    printf '%s\n' BACKEND_ENV_DIRECTORY_SYMLINK_ACCEPTED >&2
    exit 1
fi

grep -F 'BACKEND_ENV=${MALLBASE_BACKEND_ENV_PATH:-/app/.mallbase-env/backend.env}' "$ENTRYPOINT" >/dev/null
grep -F '/app/public/static/demo' "$ENTRYPOINT" >/dev/null
awk '
    /^  rotate-db-password:$/ { service = 1; next }
    service && /^  [a-zA-Z0-9_-]+:$/ { service = 0 }
    service && /MALLBASE_DEV_UID: "\$\{MALLBASE_DEV_UID:-10000\}"/ { uid = 1 }
    service && /MALLBASE_DEV_GID: "\$\{MALLBASE_DEV_GID:-10000\}"/ { gid = 1 }
    END { exit !(uid && gid) }
' "$COMPOSE_FILE"

printf '%s\n' ENSURE_ENV_TEST_OK
