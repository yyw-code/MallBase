#!/bin/sh
set -eu

SCRIPT_DIR=$(CDPATH= cd -P "$(dirname "$0")" && pwd)
PROJECT_ROOT=$(CDPATH= cd -P "$SCRIPT_DIR/../.." && pwd)
VALIDATOR=$SCRIPT_DIR/validate-bootstrap-adoption.php
OPERATION_ID=

fail() {
    printf '%s\n' "$1" >&2
    exit 1
}

usage() {
    printf '%s\n' 'usage: bootstrap-retention-verify.sh [--project-root PATH] --operation-id UUID' >&2
    exit 2
}

while [ "$#" -gt 0 ]; do
    case "$1" in
        --project-root)
            [ "$#" -ge 2 ] || usage
            PROJECT_ROOT=$2
            shift 2
            ;;
        --operation-id)
            [ "$#" -ge 2 ] || usage
            OPERATION_ID=$2
            shift 2
            ;;
        *) usage ;;
    esac
done

printf '%s\n' "$OPERATION_ID" \
    | grep -Eq '^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$' \
    || fail BOOTSTRAP_RETENTION_OPERATION_INVALID
[ -d "$PROJECT_ROOT" ] && [ ! -L "$PROJECT_ROOT" ] || fail BOOTSTRAP_RETENTION_PROJECT_ROOT_INVALID
PROJECT_ROOT=$(CDPATH= cd -P "$PROJECT_ROOT" && pwd)
[ -f "$VALIDATOR" ] && [ ! -L "$VALIDATOR" ] || fail BOOTSTRAP_RETENTION_VALIDATOR_INVALID
command -v php >/dev/null 2>&1 || fail BOOTSTRAP_RETENTION_PHP_UNAVAILABLE
[ "$(uname -s)" = Linux ] || fail BOOTSTRAP_RETENTION_HOST_OS_UNSUPPORTED

if ! sh "$SCRIPT_DIR/host-preflight.sh" --check --project-root "$PROJECT_ROOT" \
    --prepare-bootstrap-adopt "$OPERATION_ID" >/dev/null; then
    fail BOOTSTRAP_RETENTION_HOST_PREFLIGHT_REQUIRED
fi

case "$(uname -m)" in
    x86_64|amd64) architecture=amd64 ;;
    aarch64|arm64) architecture=arm64 ;;
    *) fail AGENT_BINARY_ARCH_UNSUPPORTED ;;
esac
AGENT=$PROJECT_ROOT/upgrade/bin/mallbase-agent-linux-$architecture
[ -f "$AGENT" ] && [ ! -L "$AGENT" ] || fail AGENT_BINARY_MISSING

stat_uid() {
    stat -f '%u' "$1" 2>/dev/null || stat -c '%u' "$1" 2>/dev/null \
        || fail BOOTSTRAP_RETENTION_STAT_UNAVAILABLE
}
stat_gid() {
    stat -f '%g' "$1" 2>/dev/null || stat -c '%g' "$1" 2>/dev/null \
        || fail BOOTSTRAP_RETENTION_STAT_UNAVAILABLE
}

agent_uid=$(stat_uid "$AGENT")
shared_gid=$(stat_gid "$AGENT")
[ "$agent_uid" -gt 0 ] && [ "$shared_gid" -gt 0 ] \
    || fail BOOTSTRAP_RETENTION_AGENT_IDENTITY_INVALID
[ "$(id -u)" = "$agent_uid" ] || fail BOOTSTRAP_RETENTION_CALLER_IDENTITY_INVALID
case " $(id -G) " in
    *" $shared_gid "*) : ;;
    *) fail BOOTSTRAP_RETENTION_CALLER_IDENTITY_INVALID ;;
esac

source_root=$PROJECT_ROOT/upgrade/bootstrap-retention/operations/$OPERATION_ID/target-output
destination_root=$PROJECT_ROOT/upgrade/legacy-results/bootstrap-adopt/$OPERATION_ID/target
authorization=$PROJECT_ROOT/upgrade/staging/bootstrap-target-authority.json
public_key=$PROJECT_ROOT/upgrade/staging/storage-ready.pub

php "$VALIDATOR" publish-target \
    "$source_root" "$destination_root" "$authorization" "$public_key" "$OPERATION_ID" \
    "$agent_uid" 10000 "$shared_gid" \
    || fail BOOTSTRAP_RETENTION_TARGET_OUTPUT_INVALID

printf 'MALLBASE_BOOTSTRAP_TARGET_CONFIRMATION=%s\n' \
    "$destination_root/confirmation.json"
