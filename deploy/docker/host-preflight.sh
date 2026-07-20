#!/bin/sh
set -eu
set -f

SCRIPT_DIR=$(CDPATH= cd -P "$(dirname "$0")" && pwd)
PROJECT_ROOT=$(CDPATH= cd -P "$SCRIPT_DIR/../.." && pwd)
CHECK_ONLY=0

fail() {
    printf '%s\n' "$1" >&2
    exit 1
}

usage() {
    printf '%s\n' 'usage: host-preflight.sh [--check] [--project-root PATH]' >&2
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
        *) usage ;;
    esac
done

[ -d "$PROJECT_ROOT" ] && [ ! -L "$PROJECT_ROOT" ] || fail HOST_PREFLIGHT_PROJECT_ROOT_INVALID
PROJECT_ROOT=$(CDPATH= cd -P "$PROJECT_ROOT" && pwd)
UPGRADE_ROOT=$PROJECT_ROOT/upgrade
BIN_ROOT=$UPGRADE_ROOT/bin
ACTIVE_BIN_ROOT=$BIN_ROOT/active
BACKEND_DATA_ROOT=$PROJECT_ROOT/data/backend
ROOT_ENV=$PROJECT_ROOT/.env
MANAGED_RELEASE_FILES=$PROJECT_ROOT/release-files.sha256
AGENT_USER=${MALLBASE_AGENT_USER:-}
if [ -n "$AGENT_USER" ]; then
    AGENT_UID=$(id -u "$AGENT_USER" 2>/dev/null) || fail HOST_PREFLIGHT_AGENT_USER_INVALID
    SHARED_GID=$(id -g "$AGENT_USER" 2>/dev/null) || fail HOST_PREFLIGHT_AGENT_USER_INVALID
else
    AGENT_UID=$(id -u)
    SHARED_GID=$(id -g)
fi
SHARED_DIRECTORY_MODE=2770
[ "$(uname -s)" = Darwin ] && SHARED_DIRECTORY_MODE=770
case "$(uname -m)" in
    x86_64|amd64) AGENT_ARCHITECTURE=amd64 ;;
    aarch64|arm64) AGENT_ARCHITECTURE=arm64 ;;
    *) fail AGENT_ARCHITECTURE_UNSUPPORTED ;;
esac
AGENT_BINARY_NAME=mallbase-agent-linux-$AGENT_ARCHITECTURE
AGENT_LAUNCHER=$ACTIVE_BIN_ROOT/mallbase-agent

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

uid_of() {
    if stat -f '%u' "$1" >/dev/null 2>&1; then
        stat -f '%u' "$1"
        return
    fi
    stat -c '%u' "$1" 2>/dev/null || fail HOST_PREFLIGHT_STAT_UNAVAILABLE
}

gid_of() {
    if stat -f '%g' "$1" >/dev/null 2>&1; then
        stat -f '%g' "$1"
        return
    fi
    stat -c '%g' "$1" 2>/dev/null || fail HOST_PREFLIGHT_STAT_UNAVAILABLE
}

nlink_of() {
    if stat -f '%l' "$1" >/dev/null 2>&1; then
        stat -f '%l' "$1"
        return
    fi
    stat -c '%h' "$1" 2>/dev/null || fail HOST_PREFLIGHT_STAT_UNAVAILABLE
}

validate_managed_relative_path() {
    relative=$1
    case "$relative" in
        ''|/*|*\\*|*//*|.|..|./*|*/.|*/..|*/./*|*/../*)
            fail HOST_PREFLIGHT_RELEASE_INVENTORY_INVALID
            ;;
    esac
    if printf '%s' "$relative" | LC_ALL=C grep '[[:cntrl:]]' >/dev/null; then
        fail HOST_PREFLIGHT_RELEASE_INVENTORY_INVALID
    fi
    case "$relative" in
        .env.example|*/.env.example) ;;
        .env|.env.*|*/.env|*/.env.*)
            fail HOST_PREFLIGHT_RELEASE_INVENTORY_INVALID
            ;;
    esac
    case "$relative" in
        .git|.git/*|.github|.github/*|.gitee|.gitee/*|.codex/work|.codex/work/*|\
        output|output/*|data|data/*|\
        backend/vendor|backend/vendor/*|backend/runtime|backend/runtime/*|\
        backend/public/uploads|backend/public/uploads/*|\
        backend/public/storage|backend/public/storage/*|\
        backend/storage/cert|backend/storage/cert/*|\
        upgrade/config|upgrade/config/*|upgrade/run|upgrade/run/*|\
        upgrade/jobs|upgrade/jobs/*|upgrade/packages|upgrade/packages/*|\
        upgrade/staging|upgrade/staging/*|upgrade/backups|upgrade/backups/*|\
        upgrade/agent-private|upgrade/agent-private/*|\
        upgrade/bin/active|upgrade/bin/active/*|\
        deploy/release|deploy/release/*|\
        node_modules|node_modules/*|*/node_modules|*/node_modules/*|\
        .pnpm-store|.pnpm-store/*|*/.pnpm-store|*/.pnpm-store/*|\
        .turbo|.turbo/*|*/.turbo|*/.turbo/*|\
        .cache|.cache/*|*/.cache|*/.cache/*|\
        frontend/*/dist|frontend/*/dist/*|frontend/uniapp/unpackage|frontend/uniapp/unpackage/*)
            fail HOST_PREFLIGHT_RELEASE_INVENTORY_INVALID
            ;;
    esac
}

validate_managed_ancestors() {
    relative=$1
    parent=${relative%/*}
    [ "$parent" != "$relative" ] || return 0
    current=$PROJECT_ROOT
    old_ifs=$IFS
    IFS=/
    for component in $parent; do
        current=$current/$component
        [ -d "$current" ] && [ ! -L "$current" ] \
            || fail HOST_PREFLIGHT_RELEASE_INVENTORY_INVALID
    done
    IFS=$old_ifs
}

validate_managed_entry() {
    digest=$1
    relative=$2
    printf '%s\n' "$digest" | grep -Eq '^[0-9a-f]{64}$' \
        || fail HOST_PREFLIGHT_RELEASE_INVENTORY_INVALID
    validate_managed_relative_path "$relative"
    validate_managed_ancestors "$relative"
    target=$PROJECT_ROOT/$relative
    [ -f "$target" ] && [ ! -L "$target" ] && [ "$(nlink_of "$target")" = 1 ] \
        || fail HOST_PREFLIGHT_RELEASE_INVENTORY_INVALID
    [ "$(sha256_file "$target")" = "$digest" ] \
        || fail HOST_PREFLIGHT_RELEASE_INVENTORY_INVALID
}

managed_directory_mode() {
    candidate_path=$1
    case "$candidate_path" in
        "$UPGRADE_ROOT"|"$BIN_ROOT") printf '%s\n' 0750 ;;
        *) printf '%s\n' 0755 ;;
    esac
}

prepare_managed_directory() {
    path=$1
    [ -d "$path" ] && [ ! -L "$path" ] || fail HOST_PREFLIGHT_RELEASE_INVENTORY_INVALID
    expected_mode=$(managed_directory_mode "$path")
    if [ "$CHECK_ONLY" -eq 0 ]; then
        chown "$AGENT_UID:$SHARED_GID" "$path"
        chmod "$expected_mode" "$path"
    fi
    [ "$(uid_of "$path")" = "$AGENT_UID" ] || fail HOST_PREFLIGHT_OWNER_INVALID
    [ "$(gid_of "$path")" = "$SHARED_GID" ] || fail HOST_PREFLIGHT_GROUP_INVALID
    [ "$(mode_of "$path")" = "${expected_mode#0}" ] || fail HOST_PREFLIGHT_MODE_INVALID
}

managed_file_mode() {
    candidate_path=$1
    case "$candidate_path" in
        "$MANAGED_RELEASE_FILES") printf '%s\n' 0644 ;;
        "$BIN_ROOT/checksums.sha256") printf '%s\n' 0444 ;;
        "$BIN_ROOT/mallbase-agent-linux-amd64"|"$BIN_ROOT/mallbase-agent-linux-arm64")
            printf '%s\n' 0555
            ;;
        *)
            source_mode=$(mode_of "$candidate_path")
            case "$source_mode" in
                ''|*[!0-7]*) fail HOST_PREFLIGHT_MODE_INVALID ;;
            esac
            if [ $((0$source_mode & 0111)) -ne 0 ]; then
                printf '%s\n' 0755
            else
                printf '%s\n' 0644
            fi
            ;;
    esac
}

prepare_managed_file() {
    path=$1
    [ -f "$path" ] && [ ! -L "$path" ] && [ "$(nlink_of "$path")" = 1 ] \
        || fail HOST_PREFLIGHT_RELEASE_INVENTORY_INVALID
    expected_mode=$(managed_file_mode "$path")
    if [ "$CHECK_ONLY" -eq 0 ]; then
        chown "$AGENT_UID:$SHARED_GID" "$path"
        chmod "$expected_mode" "$path"
    fi
    [ "$(uid_of "$path")" = "$AGENT_UID" ] || fail HOST_PREFLIGHT_OWNER_INVALID
    [ "$(gid_of "$path")" = "$SHARED_GID" ] || fail HOST_PREFLIGHT_GROUP_INVALID
    [ "$(mode_of "$path")" = "${expected_mode#0}" ] || fail HOST_PREFLIGHT_MODE_INVALID
}

prepare_managed_ancestors() {
    relative=$1
    parent=${relative%/*}
    [ "$parent" != "$relative" ] || return 0
    current=$PROJECT_ROOT
    old_ifs=$IFS
    IFS=/
    for component in $parent; do
        current=$current/$component
        prepare_managed_directory "$current"
    done
    IFS=$old_ifs
}

prepare_managed_release_tree() {
    [ -e "$MANAGED_RELEASE_FILES" ] || fail HOST_PREFLIGHT_RELEASE_INVENTORY_MISSING
    [ -f "$MANAGED_RELEASE_FILES" ] && [ ! -L "$MANAGED_RELEASE_FILES" ] \
        && [ "$(nlink_of "$MANAGED_RELEASE_FILES")" = 1 ] \
        || fail HOST_PREFLIGHT_RELEASE_INVENTORY_INVALID
    inventory_size=$(wc -c < "$MANAGED_RELEASE_FILES" | tr -d ' ')
    case "$inventory_size" in
        ''|*[!0-9]*) fail HOST_PREFLIGHT_RELEASE_INVENTORY_INVALID ;;
    esac
    [ "$inventory_size" -ge 68 ] && [ "$inventory_size" -le 67108864 ] \
        || fail HOST_PREFLIGHT_RELEASE_INVENTORY_INVALID

    seen=$(mktemp "${TMPDIR:-/tmp}/mallbase-release-files.XXXXXX") \
        || fail HOST_PREFLIGHT_RELEASE_INVENTORY_INVALID
    trap 'rm -f "$seen"' 0
    while IFS= read -r line || [ -n "$line" ]; do
        digest=${line%%  *}
        relative=${line#*  }
        [ "$line" = "$digest  $relative" ] && [ "$relative" != "$line" ] \
            || fail HOST_PREFLIGHT_RELEASE_INVENTORY_INVALID
        if grep -Fqx "./$relative" "$seen"; then
            fail HOST_PREFLIGHT_RELEASE_INVENTORY_INVALID
        fi
        printf './%s\n' "$relative" >> "$seen" \
            || fail HOST_PREFLIGHT_RELEASE_INVENTORY_INVALID
        validate_managed_entry "$digest" "$relative"
    done < "$MANAGED_RELEASE_FILES"
    rm -f "$seen"
    trap - 0

    prepare_managed_directory "$PROJECT_ROOT"
    if [ "$CHECK_ONLY" -eq 0 ]; then
        chmod 0755 "$PROJECT_ROOT"
    fi
    [ "$(uid_of "$PROJECT_ROOT")" = "$AGENT_UID" ] || fail HOST_PREFLIGHT_OWNER_INVALID
    [ "$(gid_of "$PROJECT_ROOT")" = "$SHARED_GID" ] || fail HOST_PREFLIGHT_GROUP_INVALID
    [ "$(mode_of "$PROJECT_ROOT")" = 755 ] || fail HOST_PREFLIGHT_MODE_INVALID
    prepare_managed_file "$MANAGED_RELEASE_FILES"

    while IFS= read -r line || [ -n "$line" ]; do
        digest=${line%%  *}
        relative=${line#*  }
        validate_managed_entry "$digest" "$relative"
        prepare_managed_ancestors "$relative"
        prepare_managed_file "$PROJECT_ROOT/$relative"
    done < "$MANAGED_RELEASE_FILES"
}

prepare_directory() {
    path=$1
    requested_mode=$2
    expected_mode=${requested_mode#0}
    if [ "$expected_mode" = 2770 ]; then
        expected_mode=$SHARED_DIRECTORY_MODE
    fi
    [ ! -L "$path" ] || fail HOST_PREFLIGHT_DIRECTORY_INVALID
    if [ "$CHECK_ONLY" -eq 0 ]; then
        mkdir -p "$path"
        chown "$AGENT_UID:$SHARED_GID" "$path"
        chmod "$requested_mode" "$path"
    fi
    [ -d "$path" ] || fail HOST_PREFLIGHT_DIRECTORY_INVALID
    [ "$(uid_of "$path")" = "$AGENT_UID" ] || fail HOST_PREFLIGHT_OWNER_INVALID
    [ "$(gid_of "$path")" = "$SHARED_GID" ] || fail HOST_PREFLIGHT_GROUP_INVALID
    [ "$(mode_of "$path")" = "$expected_mode" ] || fail HOST_PREFLIGHT_MODE_INVALID
}

prepare_active_binary() {
    [ ! -L "$ACTIVE_BIN_ROOT" ] && { [ ! -e "$ACTIVE_BIN_ROOT" ] || [ -d "$ACTIVE_BIN_ROOT" ]; } \
        || fail AGENT_ACTIVE_DIRECTORY_INVALID
    if [ -e "$AGENT_LAUNCHER" ]; then
        [ -f "$AGENT_LAUNCHER" ] && [ ! -L "$AGENT_LAUNCHER" ] \
            && [ "$(nlink_of "$AGENT_LAUNCHER")" = 1 ] \
            || fail AGENT_LAUNCHER_INVALID
    fi

    if [ "$CHECK_ONLY" -eq 0 ]; then
        chmod 0750 "$BIN_ROOT"
        mkdir -p "$ACTIVE_BIN_ROOT"
        chown "$AGENT_UID:$SHARED_GID" "$ACTIVE_BIN_ROOT"
        chmod 0750 "$ACTIVE_BIN_ROOT"
        temporary=$(mktemp "$ACTIVE_BIN_ROOT/.mallbase-agent.seed.XXXXXX")
        trap 'rm -f "$temporary"' 0
        cp "$BIN_ROOT/$AGENT_BINARY_NAME" "$temporary"
        chown "$AGENT_UID:$SHARED_GID" "$temporary"
        chmod 0755 "$temporary"
        mv -f "$temporary" "$AGENT_LAUNCHER"
        trap - 0
        chown "$AGENT_UID:$SHARED_GID" "$AGENT_LAUNCHER"
        chmod 0755 "$AGENT_LAUNCHER"
    fi

    [ -d "$ACTIVE_BIN_ROOT" ] || fail AGENT_ACTIVE_DIRECTORY_INVALID
    [ -f "$AGENT_LAUNCHER" ] && [ ! -L "$AGENT_LAUNCHER" ] && [ -s "$AGENT_LAUNCHER" ] \
        && [ "$(nlink_of "$AGENT_LAUNCHER")" = 1 ] \
        || fail AGENT_LAUNCHER_INVALID
    [ "$(uid_of "$ACTIVE_BIN_ROOT")" = "$AGENT_UID" ] || fail HOST_PREFLIGHT_OWNER_INVALID
    [ "$(gid_of "$ACTIVE_BIN_ROOT")" = "$SHARED_GID" ] || fail HOST_PREFLIGHT_GROUP_INVALID
    [ "$(mode_of "$ACTIVE_BIN_ROOT")" = 750 ] || fail HOST_PREFLIGHT_MODE_INVALID
    [ "$(uid_of "$AGENT_LAUNCHER")" = "$AGENT_UID" ] || fail HOST_PREFLIGHT_OWNER_INVALID
    [ "$(gid_of "$AGENT_LAUNCHER")" = "$SHARED_GID" ] || fail HOST_PREFLIGHT_GROUP_INVALID
    [ "$(mode_of "$AGENT_LAUNCHER")" = 755 ] || fail HOST_PREFLIGHT_MODE_INVALID
    [ "$(sha256_file "$AGENT_LAUNCHER")" = "$(sha256_file "$BIN_ROOT/$AGENT_BINARY_NAME")" ] \
        || fail AGENT_LAUNCHER_INVALID
}

set_root_env_value() {
    key=$1
    value=$2
    [ ! -L "$ROOT_ENV" ] && { [ ! -e "$ROOT_ENV" ] || [ -f "$ROOT_ENV" ]; } \
        || fail HOST_PREFLIGHT_ROOT_ENV_INVALID
    temporary=$(mktemp "$PROJECT_ROOT/.env.preflight.XXXXXX")
    trap 'rm -f "$temporary"' 0
    if [ -f "$ROOT_ENV" ]; then
        awk -v key="$key" -v value="$value" '
            BEGIN { replaced = 0 }
            index($0, key "=") == 1 {
                if (replaced == 0) print key "=" value
                replaced = 1
                next
            }
            { print }
            END { if (replaced == 0) print key "=" value }
        ' "$ROOT_ENV" > "$temporary"
    else
        printf '%s=%s\n' "$key" "$value" > "$temporary"
    fi
    chmod 0600 "$temporary"
    mv "$temporary" "$ROOT_ENV"
    trap - 0
}

root_env_value() {
    key=$1
    [ -f "$ROOT_ENV" ] || return 0
    awk -F= -v key="$key" '$1 == key { value = substr($0, length(key) + 2) } END { print value }' "$ROOT_ENV"
}

[ -d "$UPGRADE_ROOT" ] && [ ! -L "$UPGRADE_ROOT" ] || fail HOST_PREFLIGHT_UPGRADE_ROOT_INVALID
[ -d "$BIN_ROOT" ] && [ ! -L "$BIN_ROOT" ] || fail AGENT_BINARY_ROOT_MISSING
MANIFEST=$BIN_ROOT/checksums.sha256
[ -f "$MANIFEST" ] && [ ! -L "$MANIFEST" ] || fail AGENT_BINARY_CHECKSUM_MISSING

[ "$(awk 'NF { count++ } END { print count + 0 }' "$MANIFEST")" = 2 ] \
    || fail AGENT_BINARY_CHECKSUM_INVALID
awk 'NF && ($1 !~ /^[0-9a-f]{64}$/ || $2 !~ /^mallbase-agent-linux-(amd64|arm64)$/ || NF != 2) { exit 1 }' "$MANIFEST" \
    || fail AGENT_BINARY_CHECKSUM_INVALID

for architecture in amd64 arm64; do
    name=mallbase-agent-linux-$architecture
    binary=$BIN_ROOT/$name
    [ -f "$binary" ] && [ ! -L "$binary" ] && [ -s "$binary" ] || fail AGENT_BINARY_MISSING
    [ "$(awk -v name="$name" '$2 == name { count++ } END { print count + 0 }' "$MANIFEST")" = 1 ] \
        || fail AGENT_BINARY_CHECKSUM_INVALID
    expected=$(awk -v name="$name" '$2 == name { print $1 }' "$MANIFEST")
    [ "$expected" = "$(sha256_file "$binary")" ] || fail AGENT_BINARY_CHECKSUM_INVALID
done

prepare_managed_release_tree

if [ "$CHECK_ONLY" -eq 0 ]; then
    chown "$AGENT_UID:$SHARED_GID" \
        "$UPGRADE_ROOT" \
        "$BIN_ROOT" \
        "$MANIFEST" \
        "$BIN_ROOT/mallbase-agent-linux-amd64" \
        "$BIN_ROOT/mallbase-agent-linux-arm64"
    chmod 0750 "$UPGRADE_ROOT" "$BIN_ROOT"
    chmod 0555 "$BIN_ROOT/mallbase-agent-linux-amd64" "$BIN_ROOT/mallbase-agent-linux-arm64"
    chmod 0444 "$MANIFEST"
fi

prepare_active_binary

prepare_directory "$UPGRADE_ROOT/config" 2770
prepare_directory "$UPGRADE_ROOT/run" 2770
prepare_directory "$UPGRADE_ROOT/run/requests" 2770
prepare_directory "$UPGRADE_ROOT/jobs" 2770
prepare_directory "$UPGRADE_ROOT/backups" 2770
prepare_directory "$UPGRADE_ROOT/packages" 0700
prepare_directory "$UPGRADE_ROOT/agent-private" 0700
prepare_directory "$UPGRADE_ROOT/staging" 0750

# 后端业务数据与升级工作区分开持久化。
prepare_directory "$PROJECT_ROOT/data" 0750
prepare_directory "$BACKEND_DATA_ROOT" 0750
prepare_directory "$BACKEND_DATA_ROOT/env" 2770
prepare_directory "$BACKEND_DATA_ROOT/cert" 2770
prepare_directory "$BACKEND_DATA_ROOT/demo" 2770
prepare_directory "$BACKEND_DATA_ROOT/public-storage" 2770

if [ "$CHECK_ONLY" -eq 0 ]; then
    set_root_env_value MALLBASE_AGENT_UID "$AGENT_UID"
    set_root_env_value MALLBASE_UPGRADE_SHARED_GID "$SHARED_GID"
    set_root_env_value MALLBASE_DEV_UID "$AGENT_UID"
    set_root_env_value MALLBASE_DEV_GID "$SHARED_GID"
fi

[ "$(uid_of "$PROJECT_ROOT")" = "$AGENT_UID" ] || fail HOST_PREFLIGHT_OWNER_INVALID
[ "$(gid_of "$PROJECT_ROOT")" = "$SHARED_GID" ] || fail HOST_PREFLIGHT_GROUP_INVALID
[ "$(mode_of "$PROJECT_ROOT")" = 755 ] || fail HOST_PREFLIGHT_MODE_INVALID
[ "$(uid_of "$UPGRADE_ROOT")" = "$AGENT_UID" ] || fail HOST_PREFLIGHT_OWNER_INVALID
[ "$(gid_of "$UPGRADE_ROOT")" = "$SHARED_GID" ] || fail HOST_PREFLIGHT_GROUP_INVALID
[ "$(mode_of "$UPGRADE_ROOT")" = 750 ] || fail HOST_PREFLIGHT_MODE_INVALID
[ "$(uid_of "$BIN_ROOT")" = "$AGENT_UID" ] || fail HOST_PREFLIGHT_OWNER_INVALID
[ "$(gid_of "$BIN_ROOT")" = "$SHARED_GID" ] || fail HOST_PREFLIGHT_GROUP_INVALID
[ "$(mode_of "$BIN_ROOT")" = 750 ] || fail HOST_PREFLIGHT_MODE_INVALID
[ "$(uid_of "$MANIFEST")" = "$AGENT_UID" ] || fail HOST_PREFLIGHT_OWNER_INVALID
[ "$(gid_of "$MANIFEST")" = "$SHARED_GID" ] || fail HOST_PREFLIGHT_GROUP_INVALID
[ "$(mode_of "$MANIFEST")" = 444 ] || fail HOST_PREFLIGHT_MODE_INVALID
for architecture in amd64 arm64; do
    binary=$BIN_ROOT/mallbase-agent-linux-$architecture
    [ "$(uid_of "$binary")" = "$AGENT_UID" ] || fail HOST_PREFLIGHT_OWNER_INVALID
    [ "$(gid_of "$binary")" = "$SHARED_GID" ] || fail HOST_PREFLIGHT_GROUP_INVALID
    [ "$(mode_of "$binary")" = 555 ] || fail HOST_PREFLIGHT_MODE_INVALID
done
[ "$(root_env_value MALLBASE_AGENT_UID)" = "$AGENT_UID" ] || fail HOST_PREFLIGHT_ROOT_ENV_INVALID
[ "$(root_env_value MALLBASE_UPGRADE_SHARED_GID)" = "$SHARED_GID" ] || fail HOST_PREFLIGHT_ROOT_ENV_INVALID

printf '%s\n' HOST_PREFLIGHT_OK
