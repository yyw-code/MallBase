#!/bin/sh
set -eu

SCRIPT_DIR=$(CDPATH= cd -P "$(dirname "$0")" && pwd)
PROJECT_ROOT=$(CDPATH= cd -P "$SCRIPT_DIR/../.." && pwd)
TARGET=$PROJECT_ROOT/deploy/docker/start-sealed-image.sh
FIXTURE=

fail() {
    printf 'start-sealed-image test failed: %s\n' "$1" >&2
    exit 1
}

cleanup() {
    if [ -n "$FIXTURE" ] && [ -d "$FIXTURE" ]; then
        chmod -R u+rwX "$FIXTURE" 2>/dev/null || true
        rm -rf "$FIXTURE"
    fi
}
trap cleanup EXIT HUP INT TERM

sha256_file() {
    if command -v sha256sum >/dev/null 2>&1; then
        sha256sum "$1" | awk '{print $1}'
    else
        shasum -a 256 "$1" | awk '{print $1}'
    fi
}

prepare_fixture() {
    FIXTURE=$(mktemp -d "${TMPDIR:-/tmp}/mallbase-sealed-start.XXXXXX")
    mkdir -p "$FIXTURE/upgrade/bin" "$FIXTURE/tools"
    printf '1.0.0\n' > "$FIXTURE/.version"
    printf 'services: {}\n' > "$FIXTURE/docker-compose.yml"

    cat > "$FIXTURE/tools/uname" <<'SH'
#!/bin/sh
case "${1-}" in
    -s) printf '%s\n' "${FAKE_HOST_OS-Linux}" ;;
    *) printf '%s\n' x86_64 ;;
esac
SH

    cat > "$FIXTURE/upgrade/bin/mallbase-agent-linux-amd64" <<'SH'
#!/bin/sh
set -eu
command="${1-} ${2-} ${3-}"
printf '%s\n' "$command" >> "${AGENT_LOG-/dev/null}"
case "$command" in
    'seal-build-context verify-image-receipt ')
        cat >/dev/null
        image=sha256:2222222222222222222222222222222222222222222222222222222222222222
        if [ "${FAKE_MUTABLE_IMAGE-0}" = 1 ]; then
            image=mallbase/backend:latest
        fi
        printf '{"schema_version":1,"receipt_id":"bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb","seal_id":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","image_id":"%s","config_digest":"sha256:2222222222222222222222222222222222222222222222222222222222222222"}\n' "$image"
        ;;
    'storage inspect '|'storage bootstrap-adopt finalize')
        cat >/dev/null
        hash=sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
        operation=018f5d35-3f42-7a31-a731-9e45df3356c2
        case "${FAKE_STORAGE_MODE-}" in
            adoption_ready)
                printf '{"schema_version":1,"installation_storage_namespace":"mbs_test","authority_revision":7,"next_layout_generation":2,"state":"ready","active":{"finalize_receipt_sha256":"%s","boot_eligible":true},"migration_id":"%s","adoption":{"operation_id":"%s","target_confirmation_sha256":"%s","host_inspection_sha256":"%s","finalize_evidence_sha256":"%s"}}\n' "$hash" "$operation" "$operation" "$hash" "$hash" "$hash"
                ;;
            adoption_target_confirmed)
                printf '{"schema_version":1,"installation_storage_namespace":"mbs_test","authority_revision":6,"next_layout_generation":2,"state":"provisioning","adoption_phase":"target_confirmed","migration_id":"%s","adoption":{"operation_id":"%s"}}\n' "$operation" "$operation"
                ;;
            *) exit 1 ;;
        esac
        ;;
    *) exit 1 ;;
esac
SH
    cp "$FIXTURE/upgrade/bin/mallbase-agent-linux-amd64" "$FIXTURE/upgrade/bin/mallbase-agent-linux-arm64"
    chmod 0555 "$FIXTURE/tools/uname" "$FIXTURE/upgrade/bin/mallbase-agent-linux-amd64" \
        "$FIXTURE/upgrade/bin/mallbase-agent-linux-arm64"
    amd64_hash=$(sha256_file "$FIXTURE/upgrade/bin/mallbase-agent-linux-amd64")
    arm64_hash=$(sha256_file "$FIXTURE/upgrade/bin/mallbase-agent-linux-arm64")
    printf '%s  mallbase-agent-linux-amd64\n%s  mallbase-agent-linux-arm64\n' "$amd64_hash" "$arm64_hash" \
        > "$FIXTURE/upgrade/bin/checksums.sha256"
    chmod 0444 "$FIXTURE/upgrade/bin/checksums.sha256"

    cat > "$FIXTURE/tools/docker" <<'SH'
#!/bin/sh
set -eu
printf 'env=%s args=%s\n' "${MALLBASE_BACKEND_IMAGE_ID-}" "$*" >> "$DOCKER_LOG"
image=sha256:2222222222222222222222222222222222222222222222222222222222222222
case "${1-} ${2-}" in
    'image inspect')
        if [ "${FAKE_IMAGE_TAMPER-0}" = 1 ]; then
            printf 'sha256:3333333333333333333333333333333333333333333333333333333333333333\n'
        else
            printf '%s\n' "$image"
        fi
        ;;
    'compose --project-directory')
        case " $* " in
            *' up '*) exit 0 ;;
            *' ps -q backend '*) printf '%s\n' container-backend ;;
            *' ps -q queue '*) printf '%s\n' container-queue ;;
            *' ps -q cron '*) printf '%s\n' container-cron ;;
            *' stop '*) exit 0 ;;
            *) exit 1 ;;
        esac
        ;;
    'inspect --format')
        if [ "${FAKE_CONTAINER_TAMPER-0}" = 1 ] && [ "${4-}" = container-queue ]; then
            printf 'sha256:4444444444444444444444444444444444444444444444444444444444444444\n'
        else
            printf '%s\n' "$image"
        fi
        ;;
    *)
        printf '%s\n' DOCKER_UNEXPECTED_COMMAND >&2
        exit 1
        ;;
esac
SH
    chmod 0555 "$FIXTURE/tools/docker"
    : > "$FIXTURE/docker.log"
    : > "$FIXTURE/agent.log"
}

run_start() {
    PATH="$FIXTURE/tools:$PATH" DOCKER_LOG="$FIXTURE/docker.log" AGENT_LOG="$FIXTURE/agent.log" \
        FAKE_STORAGE_MODE="${FAKE_STORAGE_MODE-}" \
        sh "$TARGET" --project-root "$FIXTURE" "$@"
}

[ -f "$TARGET" ] || fail 'target script is missing'
prepare_fixture

run_start bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb >/dev/null || fail 'valid image receipt was rejected'
grep -F -- 'up -d --pull never --no-build backend queue cron' "$FIXTURE/docker.log" >/dev/null \
    || fail 'Compose immutable start flags are missing'
grep -F 'env=sha256:2222222222222222222222222222222222222222222222222222222222222222' \
    "$FIXTURE/docker.log" >/dev/null || fail 'Compose did not receive the immutable image ID'

# A completed adoption replays the adoption finalize authority and never enters fresh finalize.
printf '{}\n' > "$FIXTURE/.mallbase-deployment.json"
: > "$FIXTURE/docker.log"
: > "$FIXTURE/agent.log"
FAKE_STORAGE_MODE=adoption_ready run_start bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb >/dev/null \
    || fail 'ready adoption was rejected'
grep -F 'storage inspect ' "$FIXTURE/agent.log" >/dev/null \
    || fail 'ready adoption layout was not inspected'
grep -F 'storage bootstrap-adopt finalize' "$FIXTURE/agent.log" >/dev/null \
    || fail 'ready adoption authority was not replayed'
rm "$FIXTURE/.mallbase-deployment.json"

# Target confirmation without host finalize remains fenced and never creates business containers.
printf '{}\n' > "$FIXTURE/.mallbase-deployment.json"
: > "$FIXTURE/docker.log"
: > "$FIXTURE/agent.log"
if FAKE_STORAGE_MODE=adoption_target_confirmed run_start bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb \
    > "$FIXTURE/rejected.out" 2>&1; then
    fail 'target-confirmed adoption started before host finalize'
fi
grep -F SEALED_STORAGE_NOT_READY "$FIXTURE/rejected.out" >/dev/null \
    || fail 'target-confirmed adoption did not return the stable fence error'
grep -F ' up ' "$FIXTURE/docker.log" >/dev/null 2>&1 \
    && fail 'business Compose ran before adoption host finalize'
rm "$FIXTURE/.mallbase-deployment.json"

: > "$FIXTURE/docker.log"
if run_start mallbase/backend:latest > "$FIXTURE/rejected.out" 2>&1; then
    fail 'mutable tag was accepted as a receipt identifier'
fi
[ ! -s "$FIXTURE/docker.log" ] || fail 'Docker ran for an invalid receipt identifier'

: > "$FIXTURE/docker.log"
if FAKE_MUTABLE_IMAGE=1 run_start bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb > "$FIXTURE/rejected.out" 2>&1; then
    fail 'mutable image authority from Agent was accepted'
fi
unset FAKE_MUTABLE_IMAGE
[ ! -s "$FIXTURE/docker.log" ] || fail 'Docker ran for mutable image authority'

: > "$FIXTURE/docker.log"
if FAKE_IMAGE_TAMPER=1 run_start bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb > "$FIXTURE/rejected.out" 2>&1; then
    fail 'changed local image identity was accepted'
fi
unset FAKE_IMAGE_TAMPER
grep -F ' up ' "$FIXTURE/docker.log" >/dev/null 2>&1 && fail 'Compose ran after local image identity changed'

: > "$FIXTURE/docker.log"
if FAKE_CONTAINER_TAMPER=1 run_start bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb > "$FIXTURE/rejected.out" 2>&1; then
    fail 'changed container image identity was accepted'
fi
unset FAKE_CONTAINER_TAMPER
grep -F ' stop ' "$FIXTURE/docker.log" >/dev/null \
    || fail 'mismatched containers were not stopped'

: > "$FIXTURE/docker.log"
if FAKE_HOST_OS=Darwin run_start bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb > "$FIXTURE/rejected.out" 2>&1; then
    fail 'non-Linux sealed start was accepted'
fi
unset FAKE_HOST_OS
grep -F SEALED_HOST_OS_UNSUPPORTED "$FIXTURE/rejected.out" >/dev/null \
    || fail 'non-Linux sealed start did not return the stable error'
[ ! -s "$FIXTURE/docker.log" ] || fail 'Docker ran for a non-Linux sealed start'

printf '%s\n' 'start-sealed-image tests passed'
