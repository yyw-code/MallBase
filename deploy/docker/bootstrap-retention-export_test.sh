#!/bin/sh
set -eu

SCRIPT_DIR=$(CDPATH= cd -P "$(dirname "$0")" && pwd)
FIXTURE=$(mktemp -d "${TMPDIR:-/tmp}/mallbase-bootstrap-export-contract.XXXXXX")

cleanup() {
    if [ -d "$FIXTURE" ] && [ ! -L "$FIXTURE" ]; then
        rm -rf "$FIXTURE"
    fi
}
trap cleanup 0
trap 'exit 1' HUP INT TERM

fail() {
    printf 'bootstrap retention export test failed: %s\n' "$1" >&2
    exit 1
}

mkdir -p "$FIXTURE/project/upgrade/bin" "$FIXTURE/tools" "$FIXTURE/empty"
cp "$SCRIPT_DIR/../../docker-compose.yml" "$FIXTURE/project/docker-compose.yml"
cp "$SCRIPT_DIR/../../docker-compose.storage-adoption.yml" "$FIXTURE/project/docker-compose.storage-adoption.yml"
cat > "$FIXTURE/project/upgrade/bin/mallbase-agent-linux-amd64" <<'SH'
#!/bin/sh
if [ "$*" = 'storage inspect' ]; then
    printf '%s\n' 'storage failed: STORAGE_FAILED' >&2
    exit 1
fi
printf 'unexpected Agent invocation: %s\n' "$*" >&2
exit 91
SH
chmod 0555 "$FIXTURE/project/upgrade/bin/mallbase-agent-linux-amd64"
agent_sha=$(sha256sum "$FIXTURE/project/upgrade/bin/mallbase-agent-linux-amd64" | awk '{print $1}')
printf '%s  %s\n' "$agent_sha" mallbase-agent-linux-amd64 \
    > "$FIXTURE/project/upgrade/bin/checksums.sha256"
chmod 0444 "$FIXTURE/project/upgrade/bin/checksums.sha256"
cat > "$FIXTURE/tools/uname" <<'SH'
#!/bin/sh
case "${1-}" in
    -s) printf '%s\n' Linux ;;
    -m) printf '%s\n' x86_64 ;;
    *) printf '%s\n' Linux ;;
esac
SH
cat > "$FIXTURE/tools/docker" <<'SH'
#!/bin/sh
case "${1-} ${2-}" in
    'container inspect')
        printf '[{"Id":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","Image":"sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb","State":{"Running":true,"Paused":false},"Mounts":[{"Type":"volume","Name":"legacy_runtime","Destination":"/app/runtime","RW":true},{"Type":"volume","Name":"legacy_uploads","Destination":"/app/public/uploads","RW":true}]}]\n'
        ;;
    'exec -i')
        printf '%s\n' BOOTSTRAP_RETENTION_LOCAL_ROOT_UNSUPPORTED >&2
        exit 1
        ;;
    *)
        printf 'unexpected docker invocation: %s\n' "$*" >&2
        exit 90
        ;;
esac
SH
cat > "$FIXTURE/tools/stat" <<'SH'
#!/bin/sh
[ "$1" = -c ] || exit 2
case "$2" in
    %a) /usr/bin/stat -f %Lp "$3" ;;
    %h) /usr/bin/stat -f %l "$3" ;;
    %u) /usr/bin/stat -f %u "$3" ;;
    %g) /usr/bin/stat -f %g "$3" ;;
    *) exit 2 ;;
esac
SH
chmod 0555 "$FIXTURE/tools/uname" "$FIXTURE/tools/docker" "$FIXTURE/tools/stat"

operation=018f5d35-3f42-7a31-a731-9e45df3356c2
if PATH="$FIXTURE/tools:$PATH" sh "$SCRIPT_DIR/bootstrap-retention-export.sh" \
    --project-root "$FIXTURE/project" aaaaaaaaaaaa "$operation" \
    > "$FIXTURE/absolute.out" 2> "$FIXTURE/absolute.err"; then
    fail absolute-root-was-accepted
fi
grep -Fx BOOTSTRAP_RETENTION_LOCAL_ROOT_UNSUPPORTED "$FIXTURE/absolute.err" >/dev/null \
    || fail absolute-root-error-was-not-stable
[ ! -e "$FIXTURE/project/upgrade/agent-private" ] || fail absolute-root-mutated-agent-authority
[ ! -e "$FIXTURE/project/upgrade/legacy-import" ] || fail absolute-root-left-import-output
[ ! -e "$FIXTURE/project/upgrade/bootstrap-retention" ] || fail absolute-root-left-retention-output

empty_root=$(php "$SCRIPT_DIR/validate-bootstrap-adoption.php" content-root "$FIXTURE/empty")
PROBE_ROOT="$empty_root" php <<'PHP' > "$FIXTURE/canonical.json"
<?php
$root = getenv('PROBE_ROOT');
$artifact = static fn (bool $present, string $path): array => [
    'present' => $present, 'path' => $path, 'content_root' => $root,
];
$value = [
    'schema_version' => 1,
    'purpose' => 'storage_bootstrap_retention_probe',
    'configured_local_root' => 'uploads',
    'local_root_classification' => 'canonical',
    'build_context_relative_root' => null,
    'local_source_path' => null,
    'local_source_content_root' => null,
    'environment_source_path' => '/app/.env',
    'environment_sha256' => 'sha256:' . str_repeat('a', 64),
    'artifacts' => [
        'cert' => $artifact(false, '/app/storage/cert'),
        'demo' => $artifact(false, '/app/public/static/demo'),
        'public_storage' => $artifact(false, '/app/public/storage'),
    ],
    'source_artifacts' => [
        'install' => $artifact(false, '/app/runtime/install'),
        'local_storage' => $artifact(false, '/app/runtime/storage'),
        'runtime_backup' => $artifact(false, '/app/runtime/backup'),
        'uploads' => $artifact(true, '/app/public/uploads'),
    ],
    'source_content_roots' => [
        'install' => $root, 'local_storage' => $root, 'runtime_backup' => $root, 'uploads' => $root,
    ],
    'expected_uploads_content_root' => $root,
    'old_app_uid' => 0,
    'old_app_gid' => 0,
];
echo json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), "\n";
PHP
chmod 0600 "$FIXTURE/canonical.json"
[ "$(php "$SCRIPT_DIR/validate-bootstrap-adoption.php" probe-field \
    "$FIXTURE/canonical.json" classification -)" = canonical ] || fail canonical-probe-rejected
[ "$(php "$SCRIPT_DIR/validate-bootstrap-adoption.php" probe-field \
    "$FIXTURE/canonical.json" build-context-root -)" = - ] || fail canonical-probe-created-partition

for root in uploads uploads/nested storage storage/cert static static/demo static/demo/nested; do
    PROBE_ROOT="$root" php -r '
$value = json_decode(file_get_contents($argv[1]), true, 64, JSON_THROW_ON_ERROR);
$value["configured_local_root"] = getenv("PROBE_ROOT");
$value["local_root_classification"] = "relative";
$value["build_context_relative_root"] = getenv("PROBE_ROOT");
$value["local_source_path"] = "/app/public/" . getenv("PROBE_ROOT");
$value["local_source_content_root"] = $value["source_content_roots"]["uploads"];
file_put_contents($argv[2], json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n");
' "$FIXTURE/canonical.json" "$FIXTURE/overlap.json"
    chmod 0600 "$FIXTURE/overlap.json"
    if php "$SCRIPT_DIR/validate-bootstrap-adoption.php" probe-field \
        "$FIXTURE/overlap.json" classification - > /dev/null 2>&1; then
        fail "overlapping-root-was-accepted:$root"
    fi
done

PROBE_ROOT=customer-media php -r '
$value = json_decode(file_get_contents($argv[1]), true, 64, JSON_THROW_ON_ERROR);
$value["configured_local_root"] = getenv("PROBE_ROOT");
$value["local_root_classification"] = "relative";
$value["build_context_relative_root"] = getenv("PROBE_ROOT");
$value["local_source_path"] = "/app/public/" . getenv("PROBE_ROOT");
$value["local_source_content_root"] = $value["source_content_roots"]["uploads"];
file_put_contents($argv[2], json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n");
' "$FIXTURE/canonical.json" "$FIXTURE/relative.json"
chmod 0600 "$FIXTURE/relative.json"
[ "$(php "$SCRIPT_DIR/validate-bootstrap-adoption.php" probe-field \
    "$FIXTURE/relative.json" classification -)" = relative ] || fail safe-relative-probe-rejected

mkdir -p "$FIXTURE/source/upgrade/legacy-import/bootstrap-adopt/$operation" \
    "$FIXTURE/source/retention/env" "$FIXTURE/source/target-cert" \
    "$FIXTURE/source/target-demo" "$FIXTURE/source/target-public"
chmod 03770 "$FIXTURE/source/target-cert" "$FIXTURE/source/target-demo" "$FIXTURE/source/target-public"
printf '%s\n' 'APP_ENV=production' > "$FIXTURE/source/retention/env/backend.env"
chmod 0600 "$FIXTURE/source/retention/env/backend.env"
ENV_SHA="sha256:$(php -r 'echo hash_file("sha256", $argv[1]);' "$FIXTURE/source/retention/env/backend.env")" \
    php -r '
$probe = json_decode(file_get_contents($argv[1]), true, 64, JSON_THROW_ON_ERROR);
$probe["environment_sha256"] = getenv("ENV_SHA");
file_put_contents($argv[2], json_encode($probe, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n");
' "$FIXTURE/canonical.json" "$FIXTURE/source/probe.json"
chmod 0600 "$FIXTURE/source/probe.json"
php -r '
$payload = json_encode([
    "schema_version" => 1,
    "signing_key_id" => "release-test",
    "app_code" => "mallbase",
    "version" => "1.0.0",
    "entries" => [["path" => ".version", "sha256" => str_repeat("a", 64), "size" => 1, "mode" => 420]],
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
$envelope = [
    "schema_version" => 1,
    "payload_sha256" => hash("sha256", $payload),
    "entry_count" => 1,
    "signing_key_id" => "release-test",
    "signature" => base64_encode(str_repeat("s", 64)),
    "payload_base64" => base64_encode($payload),
];
file_put_contents($argv[1], json_encode($envelope, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
' "$FIXTURE/source/.mallbase-release-inventory.json"
printf '%s\n' '[{"Id":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","Image":"sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb","State":{"Running":true,"Paused":false},"Mounts":[{"Type":"volume","Name":"legacy_runtime","Destination":"/app/runtime","RW":true},{"Type":"volume","Name":"legacy_uploads","Destination":"/app/public/uploads","RW":true}]}]' \
    > "$FIXTURE/source/container.json"
printf '%s\n' '[{"Name":"legacy_runtime","Driver":"local","Scope":"local","Options":null,"Labels":null}]' \
    > "$FIXTURE/source/runtime.json"
printf '%s\n' '[{"Name":"legacy_uploads","Driver":"local","Scope":"local","Options":null,"Labels":null}]' \
    > "$FIXTURE/source/uploads.json"
agent_uid=$(id -u)
shared_gid=$(id -g)
app_uid=10000
[ "$agent_uid" -ne "$app_uid" ] || app_uid=10001
php "$SCRIPT_DIR/validate-bootstrap-adoption.php" write-source \
    "$FIXTURE/source" "$FIXTURE/source/probe.json" "$FIXTURE/source/container.json" \
    "$FIXTURE/source/runtime.json" "$FIXTURE/source/uploads.json" - "$FIXTURE/source/retention" \
    "$FIXTURE/source/target-cert" "$FIXTURE/source/target-demo" "$FIXTURE/source/target-public" \
    "$operation" "$agent_uid" "$app_uid" "$shared_gid" \
    "$FIXTURE/source/upgrade/legacy-import/bootstrap-adopt/$operation/source.json"
php -r '
$source = json_decode(file_get_contents($argv[1]), true, 64, JSON_THROW_ON_ERROR);
if (($source["purpose"] ?? null) !== "storage_bootstrap_adopt_source"
    || ($source["evidence"]["prepare"]["installation_storage_namespace"] ?? null) !== ""
    || ($source["evidence"]["prepare"]["candidate"]["layout_generation"] ?? null) !== 0
    || ($source["evidence"]["prepare"]["candidate"]["volumes"]["uploads"]["source_mode"] ?? null) !== "legacy_broad"
    || ($source["evidence"]["retention_partition"]["partition_kind"] ?? null) !== "canonical_volume") {
    exit(1);
}
' "$FIXTURE/source/upgrade/legacy-import/bootstrap-adopt/$operation/source.json" \
    || fail source-authority-writer-contract-invalid

# The custom-upload sidecar is raw canonical JSONL and is validated as a
# stream with the exact Agent bounds. No whole-manifest read is allowed here.
printf '%s\n' '["directory","."]' '["file","same.txt",3,"sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"]' \
    > "$FIXTURE/uploads.manifest.jsonl"
uploads_manifest_root="sha256:$(sha256sum "$FIXTURE/uploads.manifest.jsonl" | awk '{print $1}')"
php "$SCRIPT_DIR/validate-bootstrap-adoption.php" validate-uploads-manifest \
    "$FIXTURE/uploads.manifest.jsonl" "$uploads_manifest_root" >/dev/null \
    || fail uploads-manifest-stream-rejected-valid-input
php -r '
$path = str_repeat("a", 4097);
$row = json_encode(["file", $path, 0, "sha256:" . str_repeat("b", 64)], JSON_THROW_ON_ERROR) . "\n";
file_put_contents($argv[1], "[\"directory\",\".\"]\n" . $row);
' "$FIXTURE/uploads.invalid.jsonl"
invalid_root="sha256:$(sha256sum "$FIXTURE/uploads.invalid.jsonl" | awk '{print $1}')"
if php "$SCRIPT_DIR/validate-bootstrap-adoption.php" validate-uploads-manifest \
    "$FIXTURE/uploads.invalid.jsonl" "$invalid_root" \
    > "$FIXTURE/manifest-invalid.out" 2> "$FIXTURE/manifest-invalid.err"; then
    fail uploads-manifest-accepted-oversized-path
fi
grep -Fx BOOTSTRAP_ADOPT_UPLOADS_MANIFEST_INVALID "$FIXTURE/manifest-invalid.err" >/dev/null \
    || fail uploads-manifest-invalid-error-was-not-stable

mkdir -p "$FIXTURE/fsync/operation/retention/nested"
printf '%s' durable > "$FIXTURE/fsync/operation/retention/nested/evidence.bin"
fsync_operation=$(CDPATH= cd -P "$FIXTURE/fsync/operation" && pwd)
php "$SCRIPT_DIR/validate-bootstrap-adoption.php" fsync-retention \
    "$fsync_operation/retention" "$fsync_operation" \
    || fail retention-recursive-fsync-rejected-valid-tree
ln -s /tmp "$fsync_operation/retention/escape"
if php "$SCRIPT_DIR/validate-bootstrap-adoption.php" fsync-retention \
    "$fsync_operation/retention" "$fsync_operation" \
    > "$FIXTURE/fsync-invalid.out" 2> "$FIXTURE/fsync-invalid.err"; then
    fail retention-recursive-fsync-followed-symlink
fi
grep -Fx BOOTSTRAP_ADOPT_RETENTION_FSYNC_FAILED "$FIXTURE/fsync-invalid.err" >/dev/null \
    || fail retention-recursive-fsync-error-was-not-stable
rm "$fsync_operation/retention/escape"

# A durable importing operation must resume without inspecting, executing, or
# copying from the legacy container. The fake Agent advances only through the
# fixed stage-import -> two-phase target confirmation -> finalize sequence.
mkdir -p "$FIXTURE/resume/upgrade/bin" \
    "$FIXTURE/resume/upgrade/bootstrap-retention/operations/$operation/retention" \
    "$FIXTURE/resume/upgrade/legacy-results/bootstrap-adopt/$operation" \
    "$FIXTURE/resume/upgrade/agent-private"
cp "$SCRIPT_DIR/../../docker-compose.yml" "$FIXTURE/resume/docker-compose.yml"
cp "$SCRIPT_DIR/../../docker-compose.storage-adoption.yml" "$FIXTURE/resume/docker-compose.storage-adoption.yml"
printf '%s\n' importing > "$FIXTURE/resume/state"
cat > "$FIXTURE/resume/upgrade/bin/mallbase-agent-linux-amd64" <<'SH'
#!/bin/sh
state=$(cat "$FAKE_STATE_FILE")
operation=018f5d35-3f42-7a31-a731-9e45df3356c2
hash=sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
case "$*" in
    'storage inspect')
        STATE="$state" OPERATION="$operation" HASH="$hash" php -r '
$state = getenv("STATE");
$op = getenv("OPERATION");
$hash = getenv("HASH");
$revision = ["prepared" => 1, "importing" => 2, "authorized" => 3, "target" => 4, "ready" => 5][$state];
$volumes = [];
foreach (["cert", "demo", "install", "local_storage", "public_storage", "runtime_backup", "uploads"] as $artifact) {
    $volumes[$artifact] = ["volume_name" => in_array($artifact, ["install", "local_storage", "runtime_backup"], true)
        ? "legacy_runtime" : ($artifact === "uploads" ? "legacy_uploads" : "bind_" . $artifact)];
}
$adoption = ["operation_id" => $op, "layout_generation" => 1];
if ($state !== "importing") $adoption["target_authorization_sha256"] = $hash;
$layout = [
    "schema_version" => 1,
    "installation_storage_namespace" => "mbs_resumecontract",
    "authority_revision" => $revision,
    "next_layout_generation" => 2,
    "state" => $state === "target" ? "provisioned" : ($state === "ready" ? "ready" : "provisioning"),
];
if ($state !== "ready") {
    $layout["adoption_phase"] = $state === "prepared" ? "prepared" : ($state === "target" ? "target_confirmed" : "importing");
    $layout["candidate"] = ["layout_generation" => 1, "volumes" => $volumes];
}
$layout["migration_id"] = $op;
$layout["adoption"] = $adoption;
echo json_encode($layout, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), "\n";
'
        ;;
    'seal-build-context verify-image-receipt')
        printf '%s\n' '{"schema_version":1,"receipt_id":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","seal_id":"bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb","image_id":"sha256:cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc","config_digest":"sha256:cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc"}'
        ;;
    'storage bootstrap-adopt stage-import') printf '%s\n' '{}' ;;
    'storage bootstrap-adopt begin')
        [ "$state" = prepared ] || exit 97
        printf '%s\n' importing > "$FAKE_STATE_FILE"
        printf '%s\n' '{}'
        ;;
    'storage bootstrap-adopt confirm-target')
        if [ "$state" = importing ]; then printf '%s\n' authorized > "$FAKE_STATE_FILE"; else printf '%s\n' target > "$FAKE_STATE_FILE"; fi
        printf '%s\n' '{}'
        ;;
    'storage bootstrap-adopt finalize')
        printf '%s\n' ready > "$FAKE_STATE_FILE"
        printf '%s\n' '{}'
        ;;
    *) printf 'unexpected Agent invocation: %s\n' "$*" >&2; exit 92 ;;
esac
SH
cp "$FIXTURE/resume/upgrade/bin/mallbase-agent-linux-amd64" \
    "$FIXTURE/resume/upgrade/bin/mallbase-agent-linux-arm64"
chmod 0555 "$FIXTURE/resume/upgrade/bin/mallbase-agent-linux-amd64" \
    "$FIXTURE/resume/upgrade/bin/mallbase-agent-linux-arm64"
resume_amd64_sha=$(sha256sum "$FIXTURE/resume/upgrade/bin/mallbase-agent-linux-amd64" | awk '{print $1}')
resume_arm64_sha=$(sha256sum "$FIXTURE/resume/upgrade/bin/mallbase-agent-linux-arm64" | awk '{print $1}')
printf '%s  %s\n%s  %s\n' \
    "$resume_amd64_sha" mallbase-agent-linux-amd64 \
    "$resume_arm64_sha" mallbase-agent-linux-arm64 \
    > "$FIXTURE/resume/upgrade/bin/checksums.sha256"
chmod 0444 "$FIXTURE/resume/upgrade/bin/checksums.sha256"
printf '%s\n' placeholder > "$FIXTURE/resume/upgrade/agent-private/storage-layout.json"
chmod 0600 "$FIXTURE/resume/upgrade/agent-private/storage-layout.json"
printf '%s\n' \
    MALLBASE_IMAGE_RECEIPT_ID=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa \
    MALLBASE_BACKEND_IMAGE_ID=sha256:cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc \
    MALLBASE_IMAGE_DISPLAY_TAG=mallbase-backend:resume-contract \
    > "$FIXTURE/resume/upgrade/bootstrap-retention/operations/$operation/image.env"
chmod 0600 "$FIXTURE/resume/upgrade/bootstrap-retention/operations/$operation/image.env"
cat > "$FIXTURE/tools/sh" <<'SH'
#!/bin/sh
case "$1" in
    */host-preflight.sh|*/bootstrap-retention-verify.sh) exit 0 ;;
    *) exec /bin/sh "$@" ;;
esac
SH
chmod u+w "$FIXTURE/tools/docker"
cat > "$FIXTURE/tools/docker" <<'SH'
#!/bin/sh
printf '%s\n' "$*" >> "$FAKE_DOCKER_LOG"
case "$1 $2" in
    'image inspect') printf '%s\n' sha256:cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc ;;
    'network inspect') printf '%s\n' '[]' ;;
    'volume inspect')
        printf '[{"Name":"%s","Driver":"local","Scope":"local","Options":null,"Labels":null}]\n' "$3"
        ;;
    'ps --quiet')
        if [ "${FAKE_RUNNING_SOURCE-}" = 1 ]; then printf '%s\n' ffffffffffff; fi
        ;;
    'compose --project-directory') exit 0 ;;
    'run --rm') exit 0 ;;
    'container inspect'|'exec -e'|'exec -i'|'cp '*) exit 93 ;;
    *) exit 0 ;;
esac
SH
chmod 0555 "$FIXTURE/tools/sh" "$FIXTURE/tools/docker"
: > "$FIXTURE/resume/docker.log"

# begin is the durable hand-off boundary. The wrapper must stop before any
# normalization/import and require the operator to stop every old role.
printf '%s\n' prepared > "$FIXTURE/resume/state"
if FAKE_STATE_FILE="$FIXTURE/resume/state" FAKE_DOCKER_LOG="$FIXTURE/resume/docker.log" \
    PATH="$FIXTURE/tools:$PATH" /bin/sh "$SCRIPT_DIR/bootstrap-retention-export.sh" \
    --project-root "$FIXTURE/resume" --data-network resume_network \
    dddddddddddd "$operation" > "$FIXTURE/begin.out" 2> "$FIXTURE/begin.err"; then
    fail begin-did-not-require-source-stop
fi
grep -Fx BOOTSTRAP_RETENTION_SOURCE_STOP_REQUIRED "$FIXTURE/begin.err" >/dev/null \
    || fail begin-source-stop-error-was-not-stable
if grep -F 'compose --project-directory' "$FIXTURE/resume/docker.log" >/dev/null; then
    fail begin-ran-normalization
fi

# A retry still refuses to mutate while any running container mounts either
# legacy source volume.
: > "$FIXTURE/resume/docker.log"
if FAKE_STATE_FILE="$FIXTURE/resume/state" FAKE_DOCKER_LOG="$FIXTURE/resume/docker.log" \
    FAKE_RUNNING_SOURCE=1 PATH="$FIXTURE/tools:$PATH" \
    /bin/sh "$SCRIPT_DIR/bootstrap-retention-export.sh" \
    --project-root "$FIXTURE/resume" --data-network resume_network \
    dddddddddddd "$operation" > "$FIXTURE/running.out" 2> "$FIXTURE/running.err"; then
    fail running-source-was-accepted
fi
grep -Fx BOOTSTRAP_RETENTION_SOURCE_STOP_REQUIRED "$FIXTURE/running.err" >/dev/null \
    || fail running-source-stop-error-was-not-stable
if grep -F 'compose --project-directory' "$FIXTURE/resume/docker.log" >/dev/null; then
    fail running-source-ran-normalization
fi

: > "$FIXTURE/resume/docker.log"
FAKE_STATE_FILE="$FIXTURE/resume/state" FAKE_DOCKER_LOG="$FIXTURE/resume/docker.log" \
    PATH="$FIXTURE/tools:$PATH" \
    /bin/sh "$SCRIPT_DIR/bootstrap-retention-export.sh" \
    --project-root "$FIXTURE/resume" --data-network resume_network \
    dddddddddddd "$operation" \
    > "$FIXTURE/resume.out" 2> "$FIXTURE/resume.err" \
    || fail importing-resume-failed
grep -Fx MALLBASE_BOOTSTRAP_STATE=ready "$FIXTURE/resume.out" >/dev/null \
    || fail importing-resume-did-not-finalize
if grep -E '^(container inspect|exec |cp )' "$FIXTURE/resume/docker.log" >/dev/null; then
    fail importing-resume-touched-stopped-container
fi
resume_root=$(CDPATH= cd -P "$FIXTURE/resume" && pwd)
for mount in \
    'type=volume,src=legacy_runtime,dst=/storage/runtime,readonly' \
    'type=volume,src=legacy_uploads,dst=/storage/uploads,readonly' \
    "type=bind,src=$resume_root/upgrade/bootstrap-retention/cert,dst=/storage/cert,readonly" \
    "type=bind,src=$resume_root/upgrade/bootstrap-retention/demo,dst=/storage/demo,readonly" \
    "type=bind,src=$resume_root/upgrade/bootstrap-retention/public-storage,dst=/storage/public-storage,readonly"; do
    grep -F -- "--mount $mount" "$FIXTURE/resume/docker.log" >/dev/null \
        || fail host-finalize-source-mount-was-not-readonly
done
grep -F -- "--mount type=bind,src=$resume_root/upgrade/legacy-results/bootstrap-adopt/$operation,dst=/results" \
    "$FIXTURE/resume/docker.log" >/dev/null || fail host-finalize-result-mount-missing

operation2=018f5d35-3f42-7a31-a731-9e45df3356c3
mkdir -p "$FIXTURE/resume/upgrade/legacy-import/bootstrap-adopt/$operation2"
mkdir -p "$FIXTURE/resume/upgrade/bootstrap-retention/operations/$operation2/retention"
printf '%s\n' '{}' > "$FIXTURE/resume/upgrade/legacy-import/bootstrap-adopt/$operation2/source.json"
chmod 0600 "$FIXTURE/resume/upgrade/legacy-import/bootstrap-adopt/$operation2/source.json"
printf '%s\n' aborted > "$FIXTURE/resume/state"
chmod u+w "$FIXTURE/resume/upgrade/bin/mallbase-agent-linux-amd64" \
    "$FIXTURE/resume/upgrade/bin/mallbase-agent-linux-arm64" \
    "$FIXTURE/resume/upgrade/bin/checksums.sha256"
cat > "$FIXTURE/resume/upgrade/bin/mallbase-agent-linux-amd64" <<'SH'
#!/bin/sh
state=$(cat "$FAKE_STATE_FILE")
old=018f5d35-3f42-7a31-a731-9e45df3356c2
next=018f5d35-3f42-7a31-a731-9e45df3356c3
case "$*" in
    'storage inspect')
        if [ "$state" = aborted ]; then
            printf '{"schema_version":1,"installation_storage_namespace":"mbs_resumecontract","authority_revision":7,"next_layout_generation":2,"state":"legacy_required","adoption_phase":"aborted","migration_id":"%s","adoption":{"operation_id":"%s","layout_generation":1}}\n' "$old" "$old"
        else
            printf '{"schema_version":1,"installation_storage_namespace":"mbs_resumecontract","authority_revision":8,"next_layout_generation":3,"state":"ready","migration_id":"%s","adoption":{"operation_id":"%s","layout_generation":2}}\n' "$next" "$next"
        fi
        ;;
    'storage bootstrap-adopt stage-authority')
        input=$(cat)
        printf '%s' "$input" | grep -F '"expected_authority_revision":7' >/dev/null || exit 94
        printf '%s\n' '{}'
        ;;
    'storage bootstrap-adopt prepare')
        input=$(cat)
        printf '%s' "$input" | grep -F '"expected_authority_revision":7' >/dev/null || exit 95
        printf '%s\n' ready > "$FAKE_STATE_FILE"
        printf '%s\n' '{}'
        ;;
    'storage bootstrap-adopt finalize')
        input=$(cat)
        printf '%s' "$input" | grep -F '"expected_authority_revision":8' >/dev/null || exit 97
        printf '%s\n' '{}'
        ;;
    *) printf 'unexpected Agent invocation: %s\n' "$*" >&2; exit 96 ;;
esac
SH
cp "$FIXTURE/resume/upgrade/bin/mallbase-agent-linux-amd64" \
    "$FIXTURE/resume/upgrade/bin/mallbase-agent-linux-arm64"
chmod 0555 "$FIXTURE/resume/upgrade/bin/mallbase-agent-linux-amd64" \
    "$FIXTURE/resume/upgrade/bin/mallbase-agent-linux-arm64"
resume_amd64_sha=$(sha256sum "$FIXTURE/resume/upgrade/bin/mallbase-agent-linux-amd64" | awk '{print $1}')
resume_arm64_sha=$(sha256sum "$FIXTURE/resume/upgrade/bin/mallbase-agent-linux-arm64" | awk '{print $1}')
printf '%s  %s\n%s  %s\n' \
    "$resume_amd64_sha" mallbase-agent-linux-amd64 \
    "$resume_arm64_sha" mallbase-agent-linux-arm64 \
    > "$FIXTURE/resume/upgrade/bin/checksums.sha256"
chmod 0444 "$FIXTURE/resume/upgrade/bin/checksums.sha256"
: > "$FIXTURE/resume/docker.log"
FAKE_STATE_FILE="$FIXTURE/resume/state" FAKE_DOCKER_LOG="$FIXTURE/resume/docker.log" \
    PATH="$FIXTURE/tools:$PATH" /bin/sh "$SCRIPT_DIR/bootstrap-retention-export.sh" \
    --project-root "$FIXTURE/resume" eeeeeeeeeeee "$operation2" \
    > "$FIXTURE/op2.out" 2> "$FIXTURE/op2.err" || fail terminal-op2-resume-failed
grep -Fx MALLBASE_BOOTSTRAP_STATE=ready "$FIXTURE/op2.out" >/dev/null \
    || fail terminal-op2-did-not-finish
[ ! -s "$FIXTURE/resume/docker.log" ] || fail terminal-op2-touched-legacy-container

# A response loss after the ready authority commit must replay finalize instead
# of trusting state=ready without repairing/verifying the signed projection.
: > "$FIXTURE/resume/docker.log"
FAKE_STATE_FILE="$FIXTURE/resume/state" FAKE_DOCKER_LOG="$FIXTURE/resume/docker.log" \
    PATH="$FIXTURE/tools:$PATH" /bin/sh "$SCRIPT_DIR/bootstrap-retention-export.sh" \
    --project-root "$FIXTURE/resume" eeeeeeeeeeee "$operation2" \
    > "$FIXTURE/ready-replay.out" 2> "$FIXTURE/ready-replay.err" \
    || fail ready-projection-replay-failed
grep -Fx MALLBASE_BOOTSTRAP_STATE=ready "$FIXTURE/ready-replay.out" >/dev/null \
    || fail ready-projection-replay-did-not-finish
[ ! -s "$FIXTURE/resume/docker.log" ] || fail ready-projection-replay-touched-docker

printf '%s\n' 'bootstrap retention export contract tests passed'
