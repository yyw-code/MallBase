#!/bin/sh
set -eu

SCRIPT_DIR=$(CDPATH= cd -P "$(dirname "$0")" && pwd)
PROJECT_ROOT=$(CDPATH= cd -P "$SCRIPT_DIR/../.." && pwd)
ACTION=
JOB_ID=
RECEIPT_ID=
WORK_ROOT=
TAB=$(printf '\t')

fail() {
    printf '%s\n' "$1" >&2
    exit 1
}

usage() {
    printf '%s\n' 'usage: storage-cutover.sh [--project-root PATH] ACTION JOB_ID [IMAGE_RECEIPT_ID]' >&2
    exit 2
}

while [ "$#" -gt 0 ]; do
    case "$1" in
        --project-root)
            [ "$#" -ge 2 ] || usage
            PROJECT_ROOT=$2
            shift 2
            ;;
        --*) usage ;;
        *)
            if [ -z "$ACTION" ]; then ACTION=$1
            elif [ -z "$JOB_ID" ]; then JOB_ID=$1
            elif [ -z "$RECEIPT_ID" ]; then RECEIPT_ID=$1
            else usage
            fi
            shift
            ;;
    esac
done
case "$ACTION" in
    verify-export|import|target-verify|promote|start|rollback|recover-source|inspect) ;;
    *) usage ;;
esac
case "$ACTION" in
    verify-export|import|target-verify|start) NEEDS_IMAGE=1 ;;
    *) NEEDS_IMAGE=0 ;;
esac
printf '%s\n' "$JOB_ID" | grep -Eq '^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$' \
    || fail CUTOVER_JOB_ID_INVALID
if [ "$NEEDS_IMAGE" -eq 1 ]; then
    printf '%s\n' "$RECEIPT_ID" | grep -Eq '^[0-9a-f]{32}$' || fail CUTOVER_IMAGE_RECEIPT_ID_INVALID
elif [ -n "$RECEIPT_ID" ]; then
    usage
fi
[ "$(uname -s)" = Linux ] || fail CUTOVER_HOST_OS_UNSUPPORTED
[ -d "$PROJECT_ROOT" ] && [ ! -L "$PROJECT_ROOT" ] || fail CUTOVER_PROJECT_ROOT_INVALID
PROJECT_ROOT=$(CDPATH= cd -P "$PROJECT_ROOT" && pwd)
if [ "$NEEDS_IMAGE" -eq 1 ]; then
    for path in docker-compose.yml docker-compose.storage-cutover.yml; do
        [ -f "$PROJECT_ROOT/$path" ] && [ ! -L "$PROJECT_ROOT/$path" ] || fail CUTOVER_COMPOSE_FILE_INVALID
    done
    command -v docker >/dev/null 2>&1 || fail CUTOVER_DOCKER_UNAVAILABLE
fi

case "$(uname -m)" in
    x86_64|amd64) architecture=amd64 ;;
    aarch64|arm64) architecture=arm64 ;;
    *) fail AGENT_BINARY_ARCH_UNSUPPORTED ;;
esac
AGENT=$PROJECT_ROOT/upgrade/bin/mallbase-agent-linux-$architecture
SELECTION=$PROJECT_ROOT/upgrade/staging/storage-cutover/$JOB_ID/selection.json
TRUST=$PROJECT_ROOT/upgrade/staging/storage-ready.pub
RESULT_ROOT=$PROJECT_ROOT/upgrade/legacy-results/$JOB_ID
VALIDATOR=$SCRIPT_DIR/validate-storage-cutover.php
ATTESTATION_VALIDATOR=$SCRIPT_DIR/validate-sealed-attestation.php
if [ "$ACTION" != inspect ]; then
    command -v php >/dev/null 2>&1 || fail CUTOVER_PHP_UNAVAILABLE
    [ -f "$VALIDATOR" ] && [ ! -L "$VALIDATOR" ] || fail CUTOVER_VALIDATOR_INVALID
fi

run_php() {
    php -d opcache.enable_cli=0 -d opcache.jit_buffer_size=0 "$@"
}

cleanup() {
    if [ -n "$WORK_ROOT" ] && [ -d "$WORK_ROOT" ] && [ ! -L "$WORK_ROOT" ]; then
        rm -rf "$WORK_ROOT"
    fi
}
trap cleanup 0
trap 'exit 1' HUP INT TERM
umask 077
WORK_ROOT=$(mktemp -d "${TMPDIR:-/tmp}/mallbase-cutover-host.XXXXXX") || fail CUTOVER_TEMP_UNAVAILABLE
chmod 0700 "$WORK_ROOT"

[ -x "$AGENT" ] && [ ! -L "$AGENT" ] || fail AGENT_BINARY_MISSING
MANIFEST=$PROJECT_ROOT/upgrade/bin/checksums.sha256
[ -f "$MANIFEST" ] && [ ! -L "$MANIFEST" ] && [ "$(stat -c %a "$MANIFEST")" = 444 ] \
    && [ "$(stat -c %h "$MANIFEST")" = 1 ] || fail AGENT_BINARY_CHECKSUM_INVALID
agent_name=${AGENT##*/}
expected_agent_sha=$(awk -v name="$agent_name" '$2 == name && NF == 2 { print $1 }' "$MANIFEST")
[ "$(printf '%s\n' "$expected_agent_sha" | awk 'NF { count++ } END { print count + 0 }')" -eq 1 ] \
    && printf '%s\n' "$expected_agent_sha" | grep -Eq '^[0-9a-f]{64}$' \
    || fail AGENT_BINARY_CHECKSUM_INVALID
[ "$(sha256sum "$AGENT" | awk '{print $1}')" = "$expected_agent_sha" ] \
    && [ "$(stat -c %a "$AGENT")" = 555 ] && [ "$(stat -c %h "$AGENT")" = 1 ] \
    || fail AGENT_BINARY_CHECKSUM_INVALID
agent_uid=$(stat -c %u "$AGENT")
agent_gid=$(stat -c %g "$AGENT")
printf '%s:%s\n' "$agent_uid" "$agent_gid" | grep -Eq '^[0-9]+:[0-9]+$' \
    || fail CUTOVER_CALLER_IDENTITY_INVALID
[ "$(id -u):$(id -g)" = "$agent_uid:$agent_gid" ] || fail CUTOVER_CALLER_IDENTITY_INVALID

image_id=
if [ "$NEEDS_IMAGE" -eq 1 ]; then
    [ -f "$ATTESTATION_VALIDATOR" ] && [ ! -L "$ATTESTATION_VALIDATOR" ] \
        || fail CUTOVER_VALIDATOR_INVALID
    sh "$SCRIPT_DIR/host-preflight.sh" --project-root "$PROJECT_ROOT" --prepare-cutover "$JOB_ID" >/dev/null \
        || fail CUTOVER_HOST_PREFLIGHT_FAILED
    printf '{"receipt_id":"%s"}' "$RECEIPT_ID" > "$WORK_ROOT/image-request.json"
    if ! (cd "$PROJECT_ROOT" && "$AGENT" seal-build-context verify-image-receipt \
        < "$WORK_ROOT/image-request.json") > "$WORK_ROOT/image-receipt.json"; then
        fail CUTOVER_IMAGE_RECEIPT_VERIFY_FAILED
    fi
    image_id=$(run_php "$ATTESTATION_VALIDATOR" image-receipt-field \
        "$WORK_ROOT/image-receipt.json" "$RECEIPT_ID" image_id)
    config_digest=$(run_php "$ATTESTATION_VALIDATOR" image-receipt-field \
        "$WORK_ROOT/image-receipt.json" "$RECEIPT_ID" config_digest)
    docker image inspect --format '{{.Id}}' "$image_id" > "$WORK_ROOT/image-inspect.txt" \
        || fail CUTOVER_IMAGE_INSPECT_FAILED
    local_image_id=$(run_php "$ATTESTATION_VALIDATOR" oci-id "$WORK_ROOT/image-inspect.txt")
    [ "$local_image_id" = "$image_id" ] && [ "$local_image_id" = "$config_digest" ] \
        || fail CUTOVER_IMAGE_IDENTITY_CHANGED
fi

agent_inspect() {
    printf '{"job_id":"%s"}' "$JOB_ID" \
        | (cd "$PROJECT_ROOT" && "$AGENT" storage cutover inspect) > "$WORK_ROOT/agent-inspect.json"
}

selection_plan() {
    phase=$1
    agent_inspect || fail CUTOVER_AGENT_INSPECT_FAILED
    run_php "$VALIDATOR" selection-plan "$SELECTION" "$TRUST" "$JOB_ID" "$phase" \
        > "$WORK_ROOT/selection.plan" || fail CUTOVER_SELECTION_INVALID
}

selection_revision() {
    awk -F "$TAB" '$1 == "selection" { print $7 }' "$WORK_ROOT/selection.plan"
}

agent_mutation() {
    mutation=$1
    expected_phase=$2
    revision=$(selection_revision)
    printf '%s\n' "$revision" | grep -Eq '^[1-9][0-9]*$' || fail CUTOVER_SELECTION_REVISION_INVALID
    if ! printf '{"expected_authority_revision":%s,"job_id":"%s"}' "$revision" "$JOB_ID" \
        | (cd "$PROJECT_ROOT" && "$AGENT" storage cutover "$mutation") > "$WORK_ROOT/agent-mutation.json"; then
        selection_plan "$expected_phase" >/dev/null 2>&1 \
            || fail "CUTOVER_AGENT_MUTATION_FAILED:$mutation"
        return
    fi
    selection_plan "$expected_phase"
}

export_compose_environment() {
    phase=$(awk -F "$TAB" '$1 == "selection" { print $3 }' "$WORK_ROOT/selection.plan")
    docker run --rm --network none --read-only --security-opt no-new-privileges=true \
        --cap-drop ALL --user "10000:$(id -g)" \
        --mount "type=bind,src=$VALIDATOR,dst=/cutover/validate-storage-cutover.php,readonly" \
        --mount "type=bind,src=$SELECTION,dst=/cutover/selection.json,readonly" \
        --mount "type=bind,src=$TRUST,dst=/cutover/storage-ready.pub,readonly" \
        --entrypoint php "$image_id" -d opcache.enable_cli=0 -d opcache.jit_buffer_size=0 \
        /cutover/validate-storage-cutover.php verify-image-runtime \
        /cutover/selection.json /cutover/storage-ready.pub "$JOB_ID" "$phase" \
        /.version /.mallbase-deployment.json || fail CUTOVER_IMAGE_RUNTIME_INVALID
    source_runtime=$(awk -F "$TAB" '$1 == "artifact" && ($2 == "install" || $2 == "local_storage" || $2 == "runtime_backup") { if ($5 != "-") print $5 }' "$WORK_ROOT/selection.plan" | sort -u)
    [ "$(printf '%s\n' "$source_runtime" | awk 'NF { count++ } END { print count + 0 }')" -eq 1 ] \
        || fail CUTOVER_SOURCE_RUNTIME_VOLUME_INVALID
    export MALLBASE_CUTOVER_SOURCE_RUNTIME_VOLUME_NAME=$source_runtime
    for artifact in cert demo install local_storage public_storage runtime_backup uploads; do
        volume=$(awk -F "$TAB" -v artifact="$artifact" '$1 == "artifact" && $2 == artifact { print $10 }' "$WORK_ROOT/selection.plan")
        [ -n "$volume" ] || fail CUTOVER_TARGET_VOLUME_INVALID
        key=$(printf '%s' "$artifact" | tr '[:lower:]' '[:upper:]')
        eval "export MALLBASE_CUTOVER_${key}_VOLUME_NAME=\$volume"
    done
    export MALLBASE_UPGRADE_JOB_ID=$JOB_ID
    export MALLBASE_BACKEND_IMAGE_ID=$image_id
    export MALLBASE_AGENT_UID=$agent_uid
    export MALLBASE_UPGRADE_SHARED_GID=$agent_gid
}

compose() {
    docker compose --project-directory "$PROJECT_ROOT" \
        --file "$PROJECT_ROOT/docker-compose.yml" \
        --file "$PROJECT_ROOT/docker-compose.storage-cutover.yml" \
        --profile storage-cutover "$@"
}

publish_host_file() {
    source=$1
    destination=$2
    parent=${destination%/*}
    [ -d "$parent" ] && [ ! -L "$parent" ] || fail CUTOVER_RESULT_WRITE_FAILED
    if [ -e "$destination" ] || [ -L "$destination" ]; then
        [ -f "$destination" ] && [ ! -L "$destination" ] \
            && [ "$(stat -c %h "$destination")" = 1 ] \
            && [ "$(stat -c %u:%g:%a "$destination")" = "$(id -u):$(id -g):640" ] \
            && cmp -s "$source" "$destination" || fail CUTOVER_RESULT_CONFLICT
        return
    fi
    temp=$(mktemp "$parent/.cutover-host-$JOB_ID.tmp.XXXXXX") || fail CUTOVER_RESULT_WRITE_FAILED
    cp "$source" "$temp" || fail CUTOVER_RESULT_WRITE_FAILED
    chmod 0640 "$temp" || fail CUTOVER_RESULT_WRITE_FAILED
    sync "$temp"
    mv "$temp" "$destination" || fail CUTOVER_RESULT_WRITE_FAILED
    sync "$parent"
}

write_host_inspection() {
    : > "$WORK_ROOT/observations.tsv"
    for artifact in cert demo install local_storage public_storage runtime_backup uploads; do
        row=$(awk -F "$TAB" -v artifact="$artifact" '$1 == "artifact" && $2 == artifact { print }' "$WORK_ROOT/selection.plan")
        [ -n "$row" ] || fail CUTOVER_SELECTION_ARTIFACT_INVALID
        source_name=$(printf '%s\n' "$row" | awk -F "$TAB" '{ print $5 }')
        target_name=$(printf '%s\n' "$row" | awk -F "$TAB" '{ print $10 }')
        source_inspect=-
        if [ "$source_name" != - ]; then
            source_inspect=$WORK_ROOT/source-$artifact.json
            docker volume inspect "$source_name" > "$source_inspect" || fail CUTOVER_SOURCE_VOLUME_INSPECT_FAILED
        fi
        target_inspect=$WORK_ROOT/target-$artifact.json
        docker volume inspect "$target_name" > "$target_inspect" || fail CUTOVER_TARGET_VOLUME_INSPECT_FAILED
        run_php "$VALIDATOR" docker-volume-observation "$SELECTION" "$TRUST" "$JOB_ID" importing \
            "$artifact" "$source_inspect" "$target_inspect" >> "$WORK_ROOT/observations.tsv" \
            || fail CUTOVER_DOCKER_IDENTITY_MISMATCH
    done
    run_php "$VALIDATOR" write-host-inspection "$SELECTION" "$TRUST" "$JOB_ID" \
        "$RESULT_ROOT/import/receipt.json" "$WORK_ROOT/observations.tsv" \
        "$WORK_ROOT/host-inspection.json" || fail CUTOVER_HOST_INSPECTION_INVALID
    publish_host_file "$WORK_ROOT/host-inspection.json" "$RESULT_ROOT/import/host-inspection.json"
}

selection_current_plan() {
    agent_inspect || fail CUTOVER_AGENT_INSPECT_FAILED
    for phase in "$@"; do
        if run_php "$VALIDATOR" selection-plan "$SELECTION" "$TRUST" "$JOB_ID" "$phase" \
            > "$WORK_ROOT/selection.plan" 2>/dev/null; then
            return
        fi
    done
    fail CUTOVER_SELECTION_PHASE_INVALID
}

selection_phase() {
    awk -F "$TAB" '$1 == "selection" { print $3 }' "$WORK_ROOT/selection.plan"
}

case "$ACTION" in
    verify-export)
        selection_current_plan prepared export_verified
        if [ "$(selection_phase)" = prepared ]; then
            export_compose_environment
            compose run --rm --no-deps legacy-state-import verify-export
            agent_mutation export-verified export_verified
        fi
        ;;
    import)
        selection_current_plan export_verified importing provisioned
        phase=$(selection_phase)
        if [ "$phase" = export_verified ]; then
            agent_mutation begin-import importing
            phase=importing
        fi
        if [ "$phase" = importing ]; then
            export_compose_environment
            compose run --rm --no-deps legacy-state-import import
            write_host_inspection
            agent_mutation confirm-target provisioned
        fi
        ;;
    target-verify)
        selection_current_plan provisioned target_confirmed
        if [ "$(selection_phase)" = provisioned ]; then
            export_compose_environment
            compose run --rm --no-deps target-state-verify
            agent_mutation confirm-target target_confirmed
        fi
        ;;
    promote)
        selection_current_plan target_confirmed promoted
        if [ "$(selection_phase)" = target_confirmed ]; then
            agent_mutation promote promoted
        fi
        ;;
    start)
        selection_plan promoted
        export_compose_environment
        compose up -d --pull never --no-build backend queue cron
        ;;
    rollback)
        selection_current_plan prepared export_verified importing provisioned target_confirmed rolled_back
        if [ "$(selection_phase)" != rolled_back ]; then
            agent_mutation rollback rolled_back
        fi
        ;;
    recover-source)
        selection_current_plan recovery_required rolled_back
        if [ "$(selection_phase)" = recovery_required ]; then
            agent_mutation recover-source rolled_back
        fi
        ;;
    inspect)
        agent_inspect
        cat "$WORK_ROOT/agent-inspect.json"
        ;;
esac

printf 'MALLBASE_STORAGE_CUTOVER_ACTION=%s\n' "$ACTION"
printf 'MALLBASE_STORAGE_CUTOVER_JOB_ID=%s\n' "$JOB_ID"
