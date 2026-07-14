#!/bin/sh
set -eu

SCRIPT_DIR=$(CDPATH= cd -P "$(dirname "$0")" && pwd)
PROJECT_ROOT=$(CDPATH= cd -P "$SCRIPT_DIR/../.." && pwd)
CONTAINER_ID=
OPERATION_ID=
DATA_NETWORK=${MALLBASE_BOOTSTRAP_DATA_NETWORK-}
HOST_TEMP=
EXPORT_WORK=
LAYOUT_STATE=absent
LAYOUT_PHASE=-
LAYOUT_REVISION=0
LAYOUT_OPERATION=-
IMAGE_ID=
IMAGE_RECEIPT_ID=

fail() {
    printf '%s\n' "$1" >&2
    exit 1
}

usage() {
    printf '%s\n' 'usage: bootstrap-retention-export.sh [--project-root PATH] [--data-network NAME] CONTAINER_ID OPERATION_ID' >&2
    exit 2
}

cleanup() {
    for path in "$EXPORT_WORK" "$HOST_TEMP"; do
        if [ -n "$path" ] && [ -d "$path" ] && [ ! -L "$path" ]; then
            rm -rf "$path"
        fi
    done
}
trap cleanup 0
trap 'exit 1' HUP INT TERM

while [ "$#" -gt 0 ]; do
    case "$1" in
        --project-root)
            [ "$#" -ge 2 ] || usage
            PROJECT_ROOT=$2
            shift 2
            ;;
        --data-network)
            [ "$#" -ge 2 ] || usage
            DATA_NETWORK=$2
            shift 2
            ;;
        --*) usage ;;
        *)
            if [ -z "$CONTAINER_ID" ]; then
                CONTAINER_ID=$1
            elif [ -z "$OPERATION_ID" ]; then
                OPERATION_ID=$1
            else
                usage
            fi
            shift
            ;;
    esac
done

printf '%s\n' "$CONTAINER_ID" | grep -Eq '^[0-9a-f]{12,64}$' \
    || fail BOOTSTRAP_RETENTION_CONTAINER_ID_INVALID
printf '%s\n' "$OPERATION_ID" | grep -Eq '^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$' \
    || fail BOOTSTRAP_RETENTION_OPERATION_ID_INVALID
[ "$(uname -s)" = Linux ] || fail BOOTSTRAP_RETENTION_HOST_OS_UNSUPPORTED
[ -d "$PROJECT_ROOT" ] && [ ! -L "$PROJECT_ROOT" ] || fail BOOTSTRAP_RETENTION_PROJECT_ROOT_INVALID
PROJECT_ROOT=$(CDPATH= cd -P "$PROJECT_ROOT" && pwd)

PROBE=$SCRIPT_DIR/bootstrap-retention-probe.php
VALIDATOR=$SCRIPT_DIR/validate-bootstrap-adoption.php
ATTESTATION_VALIDATOR=$SCRIPT_DIR/validate-sealed-attestation.php
for path in "$PROBE" "$VALIDATOR" "$ATTESTATION_VALIDATOR" \
    "$SCRIPT_DIR/host-preflight.sh" "$SCRIPT_DIR/build-sealed-image.sh" \
    "$SCRIPT_DIR/bootstrap-retention-verify.sh" \
    "$PROJECT_ROOT/docker-compose.yml" "$PROJECT_ROOT/docker-compose.storage-adoption.yml"; do
    [ -f "$path" ] && [ ! -L "$path" ] || fail BOOTSTRAP_RETENTION_REQUIRED_FILE_INVALID
done
command -v docker >/dev/null 2>&1 || fail BOOTSTRAP_RETENTION_DOCKER_UNAVAILABLE
command -v php >/dev/null 2>&1 || fail BOOTSTRAP_RETENTION_PHP_UNAVAILABLE

run_php() {
    php -d opcache.enable_cli=0 -d opcache.jit_buffer_size=0 "$@"
}

fsync_path() {
    run_php -r '
$handle = @fopen($argv[1], "rb");
if (!is_resource($handle) || !fsync($handle)) exit(1);
fclose($handle);
' "$1"
}

case "$(uname -m)" in
    x86_64|amd64) architecture=amd64 ;;
    aarch64|arm64) architecture=arm64 ;;
    *) fail AGENT_BINARY_ARCH_UNSUPPORTED ;;
esac
AGENT=$PROJECT_ROOT/upgrade/bin/mallbase-agent-linux-$architecture
CHECKSUMS=$PROJECT_ROOT/upgrade/bin/checksums.sha256
[ -x "$AGENT" ] && [ ! -L "$AGENT" ] || fail AGENT_BINARY_MISSING
[ -f "$CHECKSUMS" ] && [ ! -L "$CHECKSUMS" ] \
    && [ "$(stat -c %a "$CHECKSUMS")" = 444 ] && [ "$(stat -c %h "$CHECKSUMS")" = 1 ] \
    || fail AGENT_BINARY_CHECKSUM_INVALID
agent_name=${AGENT##*/}
expected_sha=$(awk -v name="$agent_name" '$2 == name && NF == 2 { print $1 }' "$CHECKSUMS")
[ "$(printf '%s\n' "$expected_sha" | awk 'NF { count++ } END { print count + 0 }')" -eq 1 ] \
    && printf '%s\n' "$expected_sha" | grep -Eq '^[0-9a-f]{64}$' \
    && [ "$(sha256sum "$AGENT" | awk '{ print $1 }')" = "$expected_sha" ] \
    && [ "$(stat -c %a "$AGENT")" = 555 ] && [ "$(stat -c %h "$AGENT")" = 1 ] \
    || fail AGENT_BINARY_CHECKSUM_INVALID
agent_uid=$(stat -c %u "$AGENT")
shared_gid=$(stat -c %g "$AGENT")
[ "$agent_uid" -gt 0 ] && [ "$shared_gid" -gt 0 ] && [ "$(id -u)" = "$agent_uid" ] \
    || fail BOOTSTRAP_RETENTION_CALLER_IDENTITY_INVALID
case " $(id -G) " in
    *" $shared_gid "*) ;;
    *) fail BOOTSTRAP_RETENTION_CALLER_IDENTITY_INVALID ;;
esac

umask 077
HOST_TEMP=$(mktemp -d "${TMPDIR:-/tmp}/mallbase-bootstrap-retention.XXXXXX") \
    || fail BOOTSTRAP_RETENTION_TEMP_UNAVAILABLE
chmod 0700 "$HOST_TEMP"

refresh_layout() {
    if (cd "$PROJECT_ROOT" && printf '{}\n' | "$AGENT" storage inspect) \
        > "$HOST_TEMP/layout.json" 2> "$HOST_TEMP/layout.err"; then
        LAYOUT_STATE=$(run_php "$VALIDATOR" layout-summary-field "$HOST_TEMP/layout.json" state) \
            || fail BOOTSTRAP_RETENTION_LAYOUT_INVALID
        LAYOUT_PHASE=$(run_php "$VALIDATOR" layout-summary-field "$HOST_TEMP/layout.json" phase) \
            || fail BOOTSTRAP_RETENTION_LAYOUT_INVALID
        LAYOUT_REVISION=$(run_php "$VALIDATOR" layout-summary-field "$HOST_TEMP/layout.json" authority_revision) \
            || fail BOOTSTRAP_RETENTION_LAYOUT_INVALID
        LAYOUT_OPERATION=$(run_php "$VALIDATOR" layout-summary-field "$HOST_TEMP/layout.json" operation_id) \
            || fail BOOTSTRAP_RETENTION_LAYOUT_INVALID
        return
    fi
    if [ ! -e "$PROJECT_ROOT/upgrade/agent-private/storage-layout.json" ] \
        && [ ! -L "$PROJECT_ROOT/upgrade/agent-private/storage-layout.json" ]; then
        LAYOUT_STATE=absent
        LAYOUT_PHASE=-
        LAYOUT_REVISION=0
        LAYOUT_OPERATION=-
        return
    fi
    fail BOOTSTRAP_RETENTION_LAYOUT_INSPECT_FAILED
}

agent_mutation() {
    mutation=$1
    revision=$2
    if ! printf '{"expected_authority_revision":%s,"operation_id":"%s"}\n' \
        "$revision" "$OPERATION_ID" \
        | (cd "$PROJECT_ROOT" && "$AGENT" storage bootstrap-adopt "$mutation") \
            > "$HOST_TEMP/$mutation.out" 2> "$HOST_TEMP/$mutation.err"; then
        return 1
    fi
}

SOURCE_ROOT=$PROJECT_ROOT/upgrade/legacy-import/bootstrap-adopt/$OPERATION_ID
SOURCE_AUTHORITY=$SOURCE_ROOT/source.json
OPERATION_ROOT=$PROJECT_ROOT/upgrade/bootstrap-retention/operations/$OPERATION_ID
RETENTION_ROOT=$OPERATION_ROOT/retention
RESULT_ROOT=$PROJECT_ROOT/upgrade/legacy-results/bootstrap-adopt/$OPERATION_ID
IMAGE_RECORD=$OPERATION_ROOT/image.env

# A durable same-operation authority is the resume boundary. It is inspected
# before touching the legacy container, so a crash after prepare/import can be
# resumed even when that old container has already been stopped.
refresh_layout
case "$LAYOUT_STATE:$LAYOUT_PHASE:$LAYOUT_OPERATION" in
    ready:-:"$OPERATION_ID")
        # Ready authority may have committed before the signed ready projection
        # was durably published. Replaying finalize with the current revision
        # verifies the evidence and republishes that projection idempotently.
        agent_mutation finalize "$LAYOUT_REVISION" \
            || fail BOOTSTRAP_RETENTION_READY_PROJECTION_FAILED
        refresh_layout
        [ "$LAYOUT_OPERATION:$LAYOUT_STATE:$LAYOUT_PHASE" = "$OPERATION_ID:ready:-" ] \
            || fail BOOTSTRAP_RETENTION_READY_PROJECTION_FAILED
        printf 'MALLBASE_BOOTSTRAP_OPERATION_ID=%s\n' "$OPERATION_ID"
        printf '%s\n' MALLBASE_BOOTSTRAP_STATE=ready
        exit 0
        ;;
    *:*:"$OPERATION_ID")
        sh "$SCRIPT_DIR/host-preflight.sh" --check --project-root "$PROJECT_ROOT" \
            --prepare-bootstrap-adopt "$OPERATION_ID" >/dev/null \
            || fail BOOTSTRAP_RETENTION_HOST_PREFLIGHT_REQUIRED
        ;;
    absent:-:-|fresh:-:-|legacy_required:aborted:*|legacy_required:source_recovery:*)
        if [ -f "$SOURCE_AUTHORITY" ] && [ ! -L "$SOURCE_AUTHORITY" ]; then
            sh "$SCRIPT_DIR/host-preflight.sh" --check --project-root "$PROJECT_ROOT" \
                --prepare-bootstrap-adopt "$OPERATION_ID" >/dev/null \
                || fail BOOTSTRAP_RETENTION_HOST_PREFLIGHT_REQUIRED
        else
            # New adoption: the fixed in-container probe is intentionally
            # before preflight, bootstrap-id, operation directories, or any
            # other durable project/Agent mutation.
            docker container inspect "$CONTAINER_ID" > "$HOST_TEMP/container.json" \
                2> "$HOST_TEMP/container.err" || fail BOOTSTRAP_RETENTION_CONTAINER_INSPECT_FAILED
            FULL_CONTAINER_ID=$(run_php "$VALIDATOR" container-field \
                "$HOST_TEMP/container.json" "$CONTAINER_ID" container_id) \
                || fail BOOTSTRAP_RETENTION_CONTAINER_INVALID
            RUNTIME_VOLUME=$(run_php "$VALIDATOR" container-field \
                "$HOST_TEMP/container.json" "$CONTAINER_ID" runtime_name) \
                || fail BOOTSTRAP_RETENTION_CONTAINER_INVALID
            UPLOADS_VOLUME=$(run_php "$VALIDATOR" container-field \
                "$HOST_TEMP/container.json" "$CONTAINER_ID" uploads_name) \
                || fail BOOTSTRAP_RETENTION_CONTAINER_INVALID
            if ! docker exec -i "$FULL_CONTAINER_ID" php \
                -d opcache.enable_cli=0 -d opcache.jit_buffer_size=0 \
                > "$HOST_TEMP/probe.json" 2> "$HOST_TEMP/probe.err" < "$PROBE"; then
                if grep -Fx 'BOOTSTRAP_RETENTION_LOCAL_ROOT_UNSUPPORTED' "$HOST_TEMP/probe.err" >/dev/null 2>&1; then
                    fail BOOTSTRAP_RETENTION_LOCAL_ROOT_UNSUPPORTED
                fi
                fail BOOTSTRAP_RETENTION_PROBE_FAILED
            fi
            CLASSIFICATION=$(run_php "$VALIDATOR" probe-field "$HOST_TEMP/probe.json" classification -) \
                || fail BOOTSTRAP_RETENTION_PROBE_INVALID
            case "$CLASSIFICATION" in canonical|relative) ;; *) fail BOOTSTRAP_RETENTION_PROBE_INVALID ;; esac

            sh "$SCRIPT_DIR/host-preflight.sh" --project-root "$PROJECT_ROOT" \
                --prepare-bootstrap-adopt "$OPERATION_ID" >/dev/null \
                || fail BOOTSTRAP_RETENTION_HOST_PREFLIGHT_FAILED
            if ! (cd "$PROJECT_ROOT" && printf '{}\n' | "$AGENT" storage bootstrap-id) \
                > "$HOST_TEMP/bootstrap-id.json" 2> "$HOST_TEMP/bootstrap-id.err"; then
                fail BOOTSTRAP_RETENTION_BOOTSTRAP_ID_FAILED
            fi
            bootstrap_agent_uid=$(run_php "$VALIDATOR" bootstrap-id-field \
                "$HOST_TEMP/bootstrap-id.json" agent_uid) || fail BOOTSTRAP_RETENTION_BOOTSTRAP_ID_INVALID
            bootstrap_shared_gid=$(run_php "$VALIDATOR" bootstrap-id-field \
                "$HOST_TEMP/bootstrap-id.json" shared_gid) || fail BOOTSTRAP_RETENTION_BOOTSTRAP_ID_INVALID
            [ "$bootstrap_agent_uid" = "$agent_uid" ] && [ "$bootstrap_shared_gid" = "$shared_gid" ] \
                || fail BOOTSTRAP_RETENTION_BOOTSTRAP_ID_INVALID
            [ -d "$OPERATION_ROOT" ] && [ ! -L "$OPERATION_ROOT" ] \
                && [ -d "$RETENTION_ROOT" ] && [ ! -L "$RETENTION_ROOT" ] \
                || fail BOOTSTRAP_RETENTION_OUTPUT_ROOT_INVALID

            docker volume inspect "$RUNTIME_VOLUME" > "$HOST_TEMP/runtime-volume.json" \
                || fail BOOTSTRAP_RETENTION_VOLUME_INSPECT_FAILED
            docker volume inspect "$UPLOADS_VOLUME" > "$HOST_TEMP/uploads-volume.json" \
                || fail BOOTSTRAP_RETENTION_VOLUME_INSPECT_FAILED
            for artifact in cert demo public_storage; do
                case "$artifact" in
                    cert) bind_root=$PROJECT_ROOT/upgrade/bootstrap-retention/cert ;;
                    demo) bind_root=$PROJECT_ROOT/upgrade/bootstrap-retention/demo ;;
                    public_storage) bind_root=$PROJECT_ROOT/upgrade/bootstrap-retention/public-storage ;;
                esac
                run_php "$VALIDATOR" bind-field "$bind_root" "$artifact" "$agent_uid" "$shared_gid" \
                    docker_volume_id >/dev/null || fail BOOTSTRAP_RETENTION_TARGET_INVALID
            done

            EXPORT_WORK=$(mktemp -d "$RETENTION_ROOT/.export.XXXXXX") \
                || fail BOOTSTRAP_RETENTION_OUTPUT_ROOT_INVALID
            chmod 0700 "$EXPORT_WORK"
            copy_tree() {
                probe_artifact=$1
                destination_name=$2
                source_kind=$3
                if [ "$source_kind" = artifact ]; then
                    present=$(run_php "$VALIDATOR" probe-field "$HOST_TEMP/probe.json" artifact-present "$probe_artifact")
                    source_path=$(run_php "$VALIDATOR" probe-field "$HOST_TEMP/probe.json" artifact-path "$probe_artifact")
                    expected_root=$(run_php "$VALIDATOR" probe-field "$HOST_TEMP/probe.json" artifact-root "$probe_artifact")
                else
                    present=true
                    source_path=$(run_php "$VALIDATOR" probe-field "$HOST_TEMP/probe.json" local-source -)
                    expected_root=$(run_php "$VALIDATOR" probe-field "$HOST_TEMP/probe.json" local-source-root -)
                fi
                destination=$RETENTION_ROOT/$destination_name
                if [ "$present" = false ]; then
                    [ ! -e "$destination" ] && [ ! -L "$destination" ] \
                        || fail BOOTSTRAP_RETENTION_OUTPUT_CONFLICT
                    return
                fi
                if [ -e "$destination" ] || [ -L "$destination" ]; then
                    [ -d "$destination" ] && [ ! -L "$destination" ] \
                        && [ "$(run_php "$VALIDATOR" content-root "$destination")" = "$expected_root" ] \
                        || fail BOOTSTRAP_RETENTION_OUTPUT_CONFLICT
                    return
                fi
                temporary=$EXPORT_WORK/$destination_name
                mkdir "$temporary" && chmod 0700 "$temporary" || fail BOOTSTRAP_RETENTION_COPY_FAILED
                docker cp "$FULL_CONTAINER_ID:$source_path/." "$temporary" \
                    > "$EXPORT_WORK/$destination_name.cp.out" 2> "$EXPORT_WORK/$destination_name.cp.err" \
                    || fail BOOTSTRAP_RETENTION_COPY_FAILED
                [ "$(run_php "$VALIDATOR" content-root "$temporary")" = "$expected_root" ] \
                    || fail BOOTSTRAP_RETENTION_SOURCE_CHANGED
                mv "$temporary" "$destination" || fail BOOTSTRAP_RETENTION_COPY_FAILED
            }
            copy_tree cert cert artifact
            copy_tree demo demo artifact
            copy_tree public_storage public-storage artifact
            uploads_manifest=-
            if [ "$CLASSIFICATION" = relative ]; then
                copy_tree custom_upload custom-upload custom
                uploads_manifest=$HOST_TEMP/uploads.manifest.jsonl
                docker exec -e MALLBASE_BOOTSTRAP_RETENTION_PROBE_MODE=uploads-manifest \
                    -i "$FULL_CONTAINER_ID" php -d opcache.enable_cli=0 -d opcache.jit_buffer_size=0 \
                    > "$uploads_manifest" 2> "$HOST_TEMP/uploads-manifest.err" < "$PROBE" \
                    || fail BOOTSTRAP_RETENTION_UPLOADS_MANIFEST_FAILED
            fi
            if [ ! -e "$RETENTION_ROOT/env" ] && [ ! -L "$RETENTION_ROOT/env" ]; then
                mkdir "$RETENTION_ROOT/env" || fail BOOTSTRAP_RETENTION_OUTPUT_ROOT_INVALID
            fi
            [ -d "$RETENTION_ROOT/env" ] && [ ! -L "$RETENTION_ROOT/env" ] \
                || fail BOOTSTRAP_RETENTION_OUTPUT_ROOT_INVALID
            chmod 0700 "$RETENTION_ROOT/env" || fail BOOTSTRAP_RETENTION_OUTPUT_ROOT_INVALID
            env_source=$(run_php "$VALIDATOR" probe-field "$HOST_TEMP/probe.json" env-source -)
            env_sha=$(run_php "$VALIDATOR" probe-field "$HOST_TEMP/probe.json" env-sha256 -)
            env_destination=$RETENTION_ROOT/env/backend.env
            if [ -e "$env_destination" ] || [ -L "$env_destination" ]; then
                [ -f "$env_destination" ] && [ ! -L "$env_destination" ] \
                    && [ "$(stat -c %a:%h "$env_destination")" = 600:1 ] \
                    && [ "sha256:$(sha256sum "$env_destination" | awk '{ print $1 }')" = "$env_sha" ] \
                    || fail BOOTSTRAP_RETENTION_OUTPUT_CONFLICT
            else
                docker cp "$FULL_CONTAINER_ID:$env_source" "$EXPORT_WORK/backend.env" \
                    || fail BOOTSTRAP_RETENTION_COPY_FAILED
                [ "sha256:$(sha256sum "$EXPORT_WORK/backend.env" | awk '{ print $1 }')" = "$env_sha" ] \
                    || fail BOOTSTRAP_RETENTION_SOURCE_CHANGED
                chmod 0600 "$EXPORT_WORK/backend.env"
                mv "$EXPORT_WORK/backend.env" "$env_destination" || fail BOOTSTRAP_RETENTION_COPY_FAILED
            fi

            # Freeze only if the whole old state and its Docker identities are
            # still exactly the state accepted by the first probe.
            docker exec -i "$FULL_CONTAINER_ID" php \
                -d opcache.enable_cli=0 -d opcache.jit_buffer_size=0 \
                > "$HOST_TEMP/probe-after.json" 2> "$HOST_TEMP/probe-after.err" < "$PROBE" \
                || fail BOOTSTRAP_RETENTION_SOURCE_CHANGED
            cmp -s "$HOST_TEMP/probe.json" "$HOST_TEMP/probe-after.json" \
                || fail BOOTSTRAP_RETENTION_SOURCE_CHANGED
            mv "$HOST_TEMP/probe-after.json" "$HOST_TEMP/probe.json"
            docker container inspect "$FULL_CONTAINER_ID" > "$HOST_TEMP/container.json" \
                || fail BOOTSTRAP_RETENTION_CONTAINER_INSPECT_FAILED
            docker volume inspect "$RUNTIME_VOLUME" > "$HOST_TEMP/runtime-volume.json" \
                || fail BOOTSTRAP_RETENTION_VOLUME_INSPECT_FAILED
            docker volume inspect "$UPLOADS_VOLUME" > "$HOST_TEMP/uploads-volume.json" \
                || fail BOOTSTRAP_RETENTION_VOLUME_INSPECT_FAILED

            rm -rf "$EXPORT_WORK" || fail BOOTSTRAP_RETENTION_OUTPUT_ROOT_INVALID
            EXPORT_WORK=
            run_php "$VALIDATOR" fsync-retention "$RETENTION_ROOT" "$OPERATION_ROOT" \
                || fail BOOTSTRAP_RETENTION_FSYNC_FAILED

            if [ ! -e "$SOURCE_ROOT" ] && [ ! -L "$SOURCE_ROOT" ]; then
                mkdir "$SOURCE_ROOT" && chmod 0700 "$SOURCE_ROOT"
            fi
            [ -d "$SOURCE_ROOT" ] && [ ! -L "$SOURCE_ROOT" ] \
                || fail BOOTSTRAP_RETENTION_OUTPUT_ROOT_INVALID
            run_php "$VALIDATOR" write-source \
                "$PROJECT_ROOT" "$HOST_TEMP/probe.json" "$HOST_TEMP/container.json" \
                "$HOST_TEMP/runtime-volume.json" "$HOST_TEMP/uploads-volume.json" "$uploads_manifest" \
                "$RETENTION_ROOT" "$PROJECT_ROOT/upgrade/bootstrap-retention/cert" \
                "$PROJECT_ROOT/upgrade/bootstrap-retention/demo" \
                "$PROJECT_ROOT/upgrade/bootstrap-retention/public-storage" \
                "$OPERATION_ID" "$agent_uid" 10000 "$shared_gid" "$SOURCE_AUTHORITY" \
                || fail BOOTSTRAP_RETENTION_SOURCE_AUTHORITY_FAILED
        fi

        # Stage is non-mutating and idempotent. The current terminal/absent
        # revision, not a hard-coded zero, is the only accepted CAS input.
        if ! agent_mutation stage-authority "$LAYOUT_REVISION"; then
            fail BOOTSTRAP_RETENTION_STAGE_AUTHORITY_FAILED
        fi
        if ! agent_mutation prepare "$LAYOUT_REVISION"; then
            refresh_layout
            [ "$LAYOUT_OPERATION:$LAYOUT_PHASE" = "$OPERATION_ID:prepared" ] \
                || fail BOOTSTRAP_RETENTION_PREPARE_FAILED
        fi
        refresh_layout
        ;;
    *) fail BOOTSTRAP_RETENTION_OPERATION_CONFLICT ;;
esac

layout_volume_name() {
    run_php "$VALIDATOR" layout-volume-field "$HOST_TEMP/layout.json" "$OPERATION_ID" "$1" volume_name
}

assert_source_stopped() {
    source_runtime=$1
    source_uploads=$2
    docker ps --quiet --filter "volume=$source_runtime" > "$HOST_TEMP/runtime-users.txt" \
        || fail BOOTSTRAP_RETENTION_SOURCE_STOP_CHECK_FAILED
    docker ps --quiet --filter "volume=$source_uploads" > "$HOST_TEMP/uploads-users.txt" \
        || fail BOOTSTRAP_RETENTION_SOURCE_STOP_CHECK_FAILED
    if [ -s "$HOST_TEMP/runtime-users.txt" ] || [ -s "$HOST_TEMP/uploads-users.txt" ]; then
        fail BOOTSTRAP_RETENTION_SOURCE_STOP_REQUIRED
    fi
}

ensure_image() {
    if [ ! -f "$IMAGE_RECORD" ] || [ -L "$IMAGE_RECORD" ]; then
        [ "$LAYOUT_PHASE" = prepared ] || fail BOOTSTRAP_RETENTION_IMAGE_RECORD_MISSING
        if [ ! -e "$PROJECT_ROOT/.mallbase-deployment.json" ]; then
            if ! (cd "$PROJECT_ROOT" && printf '{"release_id":"bootstrap-%s"}\n' "$OPERATION_ID" \
                | "$AGENT" provenance initialize) > "$HOST_TEMP/provenance.json" \
                2> "$HOST_TEMP/provenance.err"; then
                fail BOOTSTRAP_RETENTION_PROVENANCE_INITIALIZE_FAILED
            fi
        fi
        sh "$SCRIPT_DIR/build-sealed-image.sh" --project-root "$PROJECT_ROOT" \
            > "$HOST_TEMP/image.env" || fail BOOTSTRAP_RETENTION_IMAGE_BUILD_FAILED
        run_php "$VALIDATOR" build-output-field "$HOST_TEMP/image.env" receipt_id >/dev/null \
            || fail BOOTSTRAP_RETENTION_IMAGE_RECORD_INVALID
        temporary=$(mktemp "$OPERATION_ROOT/.image.env.XXXXXX") \
            || fail BOOTSTRAP_RETENTION_IMAGE_RECORD_INVALID
        cp "$HOST_TEMP/image.env" "$temporary" && chown "$agent_uid:$shared_gid" "$temporary" \
            && chmod 0600 "$temporary" && fsync_path "$temporary" \
            && mv "$temporary" "$IMAGE_RECORD" && fsync_path "$OPERATION_ROOT" \
            || fail BOOTSTRAP_RETENTION_IMAGE_RECORD_INVALID
    fi
    IMAGE_RECEIPT_ID=$(run_php "$VALIDATOR" build-output-field "$IMAGE_RECORD" receipt_id) \
        || fail BOOTSTRAP_RETENTION_IMAGE_RECORD_INVALID
    IMAGE_ID=$(run_php "$VALIDATOR" build-output-field "$IMAGE_RECORD" image_id) \
        || fail BOOTSTRAP_RETENTION_IMAGE_RECORD_INVALID
    printf '{"receipt_id":"%s"}\n' "$IMAGE_RECEIPT_ID" \
        | (cd "$PROJECT_ROOT" && "$AGENT" seal-build-context verify-image-receipt) \
            > "$HOST_TEMP/image-receipt.json" \
        || fail BOOTSTRAP_RETENTION_IMAGE_RECEIPT_INVALID
    verified_image=$(run_php "$ATTESTATION_VALIDATOR" image-receipt-field \
        "$HOST_TEMP/image-receipt.json" "$IMAGE_RECEIPT_ID" image_id)
    verified_config=$(run_php "$ATTESTATION_VALIDATOR" image-receipt-field \
        "$HOST_TEMP/image-receipt.json" "$IMAGE_RECEIPT_ID" config_digest)
    docker image inspect --format '{{.Id}}' "$IMAGE_ID" > "$HOST_TEMP/image-id.txt" \
        || fail BOOTSTRAP_RETENTION_IMAGE_MISSING
    local_image=$(run_php "$ATTESTATION_VALIDATOR" oci-id "$HOST_TEMP/image-id.txt")
    [ "$IMAGE_ID" = "$verified_image" ] && [ "$IMAGE_ID" = "$verified_config" ] \
        && [ "$IMAGE_ID" = "$local_image" ] || fail BOOTSTRAP_RETENTION_IMAGE_CHANGED
}

compose() {
    docker compose --project-directory "$PROJECT_ROOT" \
        --file "$PROJECT_ROOT/docker-compose.yml" \
        --file "$PROJECT_ROOT/docker-compose.storage-adoption.yml" "$@"
}

if [ "$LAYOUT_OPERATION" != "$OPERATION_ID" ]; then
    fail BOOTSTRAP_RETENTION_OPERATION_CONFLICT
fi
[ -d "$OPERATION_ROOT" ] && [ ! -L "$OPERATION_ROOT" ] \
    && [ -d "$RETENTION_ROOT" ] && [ ! -L "$RETENTION_ROOT" ] \
    || fail BOOTSTRAP_RETENTION_OUTPUT_ROOT_INVALID
if [ "$LAYOUT_PHASE" = prepared ]; then
    ensure_image
    if ! agent_mutation begin "$LAYOUT_REVISION"; then
        refresh_layout
        [ "$LAYOUT_OPERATION:$LAYOUT_PHASE" = "$OPERATION_ID:importing" ] \
            || fail BOOTSTRAP_RETENTION_BEGIN_FAILED
    fi
    refresh_layout
    [ "$LAYOUT_OPERATION:$LAYOUT_PHASE" = "$OPERATION_ID:importing" ] \
        || fail BOOTSTRAP_RETENTION_BEGIN_FAILED
    fail BOOTSTRAP_RETENTION_SOURCE_STOP_REQUIRED
fi

if [ "$LAYOUT_PHASE" = importing ]; then
    RUNTIME_VOLUME=$(layout_volume_name install) || fail BOOTSTRAP_RETENTION_LAYOUT_INVALID
    UPLOADS_VOLUME=$(layout_volume_name uploads) || fail BOOTSTRAP_RETENTION_LAYOUT_INVALID
    assert_source_stopped "$RUNTIME_VOLUME" "$UPLOADS_VOLUME"
    printf '%s\n' "$DATA_NETWORK" | grep -Eq '^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$' \
        || fail BOOTSTRAP_RETENTION_DATA_NETWORK_REQUIRED
    docker network inspect "$DATA_NETWORK" >/dev/null \
        || fail BOOTSTRAP_RETENTION_DATA_NETWORK_INVALID
    ensure_image
    export MALLBASE_BACKEND_IMAGE_ID=$IMAGE_ID
    export MALLBASE_BOOTSTRAP_OPERATION_ID=$OPERATION_ID
    export MALLBASE_BOOTSTRAP_RUNTIME_VOLUME_NAME=$RUNTIME_VOLUME
    export MALLBASE_BOOTSTRAP_UPLOADS_VOLUME_NAME=$UPLOADS_VOLUME
    export MALLBASE_AGENT_UID=$agent_uid
    export MALLBASE_UPGRADE_SHARED_GID=$shared_gid
    export MALLBASE_BOOTSTRAP_DATA_NETWORK=$DATA_NETWORK

    compose run --rm --no-deps bootstrap-permission-normalize \
        || fail BOOTSTRAP_RETENTION_NORMALIZATION_FAILED
    if ! agent_mutation stage-import "$LAYOUT_REVISION"; then
        fail BOOTSTRAP_RETENTION_STAGE_IMPORT_FAILED
    fi
    compose run --rm --no-deps bootstrap-retention-import \
        || fail BOOTSTRAP_RETENTION_IMPORT_FAILED

    target_authorization=$(run_php "$VALIDATOR" layout-field \
        "$HOST_TEMP/layout.json" "$OPERATION_ID" target_authorization_sha256)
    if [ "$target_authorization" = - ]; then
        if ! agent_mutation confirm-target "$LAYOUT_REVISION"; then
            refresh_layout
            target_authorization=$(run_php "$VALIDATOR" layout-field \
                "$HOST_TEMP/layout.json" "$OPERATION_ID" target_authorization_sha256)
            [ "$target_authorization" != - ] || fail BOOTSTRAP_RETENTION_TARGET_AUTHORITY_FAILED
        fi
        refresh_layout
    fi
    compose run --rm --no-deps bootstrap-target-confirm \
        || fail BOOTSTRAP_RETENTION_TARGET_CONFIRM_HELPER_FAILED
    sh "$SCRIPT_DIR/bootstrap-retention-verify.sh" --project-root "$PROJECT_ROOT" \
        --operation-id "$OPERATION_ID" >/dev/null \
        || fail BOOTSTRAP_RETENTION_TARGET_OUTPUT_FAILED
    if ! agent_mutation confirm-target "$LAYOUT_REVISION"; then
        refresh_layout
        [ "$LAYOUT_OPERATION:$LAYOUT_PHASE" = "$OPERATION_ID:target_confirmed" ] \
            || fail BOOTSTRAP_RETENTION_TARGET_CONFIRM_FAILED
    fi
    refresh_layout
fi

if [ "$LAYOUT_PHASE" = target_confirmed ]; then
    RUNTIME_VOLUME=$(layout_volume_name install) || fail BOOTSTRAP_RETENTION_LAYOUT_INVALID
    UPLOADS_VOLUME=$(layout_volume_name uploads) || fail BOOTSTRAP_RETENTION_LAYOUT_INVALID
    assert_source_stopped "$RUNTIME_VOLUME" "$UPLOADS_VOLUME"
    ensure_image
    docker volume inspect "$RUNTIME_VOLUME" > "$HOST_TEMP/runtime-final.json" \
        || fail BOOTSTRAP_RETENTION_VOLUME_INSPECT_FAILED
    docker volume inspect "$UPLOADS_VOLUME" > "$HOST_TEMP/uploads-final.json" \
        || fail BOOTSTRAP_RETENTION_VOLUME_INSPECT_FAILED
    [ -d "$RESULT_ROOT" ] && [ ! -L "$RESULT_ROOT" ] \
        || fail BOOTSTRAP_RETENTION_RESULT_ROOT_INVALID
    docker run --rm --network none --read-only --security-opt no-new-privileges=true \
        --cap-drop ALL --cap-add DAC_READ_SEARCH --cap-add CHOWN --cap-add FOWNER \
        --user "0:$shared_gid" --tmpfs /tmp:rw,nosuid,nodev,noexec,size=16m,mode=0700 \
        --mount "type=volume,src=$RUNTIME_VOLUME,dst=/storage/runtime,readonly" \
        --mount "type=volume,src=$UPLOADS_VOLUME,dst=/storage/uploads,readonly" \
        --mount "type=bind,src=$PROJECT_ROOT/upgrade/bootstrap-retention/cert,dst=/storage/cert,readonly" \
        --mount "type=bind,src=$PROJECT_ROOT/upgrade/bootstrap-retention/demo,dst=/storage/demo,readonly" \
        --mount "type=bind,src=$PROJECT_ROOT/upgrade/bootstrap-retention/public-storage,dst=/storage/public-storage,readonly" \
        --mount "type=bind,src=$PROJECT_ROOT/upgrade/agent-private/storage-layout.json,dst=/input/layout.json,readonly" \
        --mount "type=bind,src=$OPERATION_ROOT/import.json,dst=/input/import.json,readonly" \
        --mount "type=bind,src=$HOST_TEMP/runtime-final.json,dst=/input/runtime.json,readonly" \
        --mount "type=bind,src=$HOST_TEMP/uploads-final.json,dst=/input/uploads.json,readonly" \
        --mount "type=bind,src=$RESULT_ROOT,dst=/results" \
        --mount "type=bind,src=$VALIDATOR,dst=/validator.php,readonly" \
        --entrypoint php "$IMAGE_ID" -d opcache.enable_cli=0 -d opcache.jit_buffer_size=0 \
        /validator.php write-host-finalize /input/layout.json /input/import.json \
        /input/runtime.json /input/uploads.json /storage/runtime /storage/uploads \
        /storage/cert /storage/demo /storage/public-storage /results "$OPERATION_ID" \
        "$agent_uid" 10000 "$shared_gid" \
        || fail BOOTSTRAP_RETENTION_HOST_FINALIZE_FAILED
    if ! agent_mutation finalize "$LAYOUT_REVISION"; then
        refresh_layout
        [ "$LAYOUT_OPERATION:$LAYOUT_STATE:$LAYOUT_PHASE" = "$OPERATION_ID:ready:-" ] \
            || fail BOOTSTRAP_RETENTION_FINALIZE_FAILED
    fi
    refresh_layout
fi

[ "$LAYOUT_OPERATION:$LAYOUT_STATE:$LAYOUT_PHASE" = "$OPERATION_ID:ready:-" ] \
    || fail BOOTSTRAP_RETENTION_INCOMPLETE
printf 'MALLBASE_BOOTSTRAP_OPERATION_ID=%s\n' "$OPERATION_ID"
printf '%s\n' MALLBASE_BOOTSTRAP_STATE=ready
if [ -n "$IMAGE_RECEIPT_ID" ]; then
    printf 'MALLBASE_IMAGE_RECEIPT_ID=%s\n' "$IMAGE_RECEIPT_ID"
    printf 'MALLBASE_BACKEND_IMAGE_ID=%s\n' "$IMAGE_ID"
fi
