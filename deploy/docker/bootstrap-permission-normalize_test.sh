#!/bin/sh
set -eu

SCRIPT_DIR=$(CDPATH= cd -P "$(dirname "$0")" && pwd)
IMAGE=${MALLBASE_BOOTSTRAP_TEST_IMAGE:-mallbase-backend:dev}
FIXTURE=$(mktemp -d "${TMPDIR:-/tmp}/mallbase-bootstrap-normalize.XXXXXX")
CONTAINER=mallbase-bootstrap-normalize-$(php -r 'echo bin2hex(random_bytes(6));')
AGENT_UID=2000
APP_UID=10000
SHARED_GID=3000
OPERATION=018f5d35-3f42-7a31-a731-9e45df3356c2

cleanup() {
    docker rm -f "$CONTAINER" >/dev/null 2>&1 || true
    if [ -d "$FIXTURE" ]; then
        docker run --rm --network none --entrypoint sh \
            -v "$FIXTURE:/fixture" alpine:3.20 \
            -c 'find /fixture -mindepth 1 -delete' >/dev/null 2>&1 || true
        rmdir "$FIXTURE" 2>/dev/null || true
    fi
}
trap cleanup EXIT HUP INT TERM

fail() {
    printf '%s\n' "$1" >&2
    exit 1
}

docker image inspect "$IMAGE" >/dev/null 2>&1 || fail BOOTSTRAP_ADOPT_TEST_IMAGE_MISSING
mkdir -p "$FIXTURE/runtime/install/nested" "$FIXTURE/runtime/storage" "$FIXTURE/runtime/backup" \
    "$FIXTURE/runtime/cache" "$FIXTURE/uploads/nested" "$FIXTURE/results/normalization"
printf '%s' retained-install > "$FIXTURE/runtime/install/nested/canary.txt"
printf '%s' retained-local > "$FIXTURE/runtime/storage/local.txt"
printf '%s' retained-backup > "$FIXTURE/runtime/backup/backup.txt"
printf '%s' retained-upload > "$FIXTURE/uploads/nested/upload.txt"
chmod 0755 "$FIXTURE/runtime" "$FIXTURE/runtime/install" "$FIXTURE/runtime/install/nested" \
    "$FIXTURE/runtime/storage" "$FIXTURE/runtime/backup" "$FIXTURE/runtime/cache" \
    "$FIXTURE/uploads" "$FIXTURE/uploads/nested"
chmod 0644 "$FIXTURE/runtime/install/nested/canary.txt" "$FIXTURE/runtime/storage/local.txt" \
    "$FIXTURE/runtime/backup/backup.txt" "$FIXTURE/uploads/nested/upload.txt"
chmod 02770 "$FIXTURE/results" "$FIXTURE/results/normalization"

content_root() {
    php "$SCRIPT_DIR/validate-bootstrap-adoption.php" content-root "$1"
}

write_request() {
    operation=$1
    target=$2
    php -r '
$request = [
    "schema_version" => 1,
    "purpose" => "storage_bootstrap_adopt_normalize",
    "operation_id" => $argv[2],
    "agent_uid" => 2000,
    "app_uid" => 10000,
    "shared_gid" => 3000,
    "target_policy" => ["app_uid" => 10000, "shared_gid" => 3000, "root_mode" => "03770", "dir_mode" => "02770", "file_mode" => "0660"],
    "source_content_roots" => [
        "install" => $argv[3],
        "local_storage" => $argv[4],
        "runtime_backup" => $argv[5],
        "uploads" => $argv[6],
    ],
];
$bytes = json_encode($request, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
if (file_put_contents($argv[1], $bytes) !== strlen($bytes)) exit(1);
' "$target" "$operation" "$(content_root "$FIXTURE/runtime/install")" \
        "$(content_root "$FIXTURE/runtime/storage")" "$(content_root "$FIXTURE/runtime/backup")" \
        "$(content_root "$FIXTURE/uploads")"
    chmod 0444 "$target"
}

write_request "$OPERATION" "$FIXTURE/request.json"
runtime_before=$(content_root "$FIXTURE/runtime")
uploads_before=$(content_root "$FIXTURE/uploads")

run_normalizer_paths() {
    request=$1
    result=$2
    runtime=$3
    uploads=$4
    docker run --rm --network none --read-only --user "0:$SHARED_GID" \
        --security-opt no-new-privileges:true --cap-drop ALL \
        --cap-add DAC_READ_SEARCH --cap-add CHOWN --cap-add FOWNER \
        --tmpfs /tmp:rw,nosuid,nodev,noexec,size=8m,mode=0700 \
        -e "MALLBASE_BOOTSTRAP_OPERATION_ID=$OPERATION" \
        -v "$SCRIPT_DIR/bootstrap-permission-normalize.sh:/bootstrap/bootstrap-permission-normalize.sh:ro" \
        -v "$SCRIPT_DIR/validate-bootstrap-adoption.php:/bootstrap/validate-bootstrap-adoption.php:ro" \
        -v "$request:/bootstrap-input/request.json:ro" \
        -v "$runtime:/storage/runtime" -v "$uploads:/storage/uploads" \
        -v "$result:/bootstrap-results" \
        --entrypoint /bootstrap/bootstrap-permission-normalize.sh "$IMAGE"
}

run_normalizer() {
    run_normalizer_paths "$1" "$2" "$FIXTURE/runtime" "$FIXTURE/uploads"
}

root_fixture() {
    docker run --rm --network none --entrypoint sh -v "$FIXTURE:/fixture" alpine:3.20 -c "$1"
}

root_fixture 'printf "%s\n" "{\"conflict\":true}" > /fixture/results/normalization/runtime.done.json
chown 2000:3000 /fixture/results/normalization/runtime.done.json
chmod 0640 /fixture/results/normalization/runtime.done.json'
if run_normalizer "$FIXTURE/request.json" "$FIXTURE/results" >"$FIXTURE/done-conflict.log" 2>&1; then
    fail BOOTSTRAP_ADOPT_TEST_DONE_CONFLICT_ACCEPTED
fi
grep -q 'BOOTSTRAP_ADOPT_RESULT_CONFLICT' "$FIXTURE/done-conflict.log" \
    || fail BOOTSTRAP_ADOPT_TEST_DONE_CONFLICT_ERROR_INVALID
root_fixture '[ "$(stat -c %a /fixture/runtime/install/nested/canary.txt)" = 644 ]
rm -f /fixture/results/normalization/runtime.done.json'

run_normalizer "$FIXTURE/request.json" "$FIXTURE/results"

# Durable intent without child progress, then intent + first child progress,
# are both resumable for the exact same operation and content tuple.
root_fixture 'rm -f /fixture/results/normalization/runtime.done.json /fixture/results/normalization/uploads.done.json /fixture/results/normalization/receipt.json
find /fixture/runtime /fixture/uploads -type d -exec chown 0:0 {} \; -exec chmod 0755 {} \;
find /fixture/runtime /fixture/uploads -type f -exec chown 0:0 {} \; -exec chmod 0644 {} \;'
run_normalizer "$FIXTURE/request.json" "$FIXTURE/results"
root_fixture 'rm -f /fixture/results/normalization/uploads.done.json /fixture/results/normalization/receipt.json
find /fixture/runtime /fixture/uploads -type d -exec chown 0:0 {} \; -exec chmod 0755 {} \;
find /fixture/runtime /fixture/uploads -type f -exec chown 0:0 {} \; -exec chmod 0644 {} \;'
run_normalizer "$FIXTURE/request.json" "$FIXTURE/results"
[ "$runtime_before" = "$(content_root "$FIXTURE/runtime")" ] || fail BOOTSTRAP_ADOPT_TEST_RUNTIME_CHANGED
[ "$uploads_before" = "$(content_root "$FIXTURE/uploads")" ] || fail BOOTSTRAP_ADOPT_TEST_UPLOADS_CHANGED

docker run --rm --network none --entrypoint sh \
    -v "$FIXTURE/runtime:/runtime:ro" -v "$FIXTURE/uploads:/uploads:ro" -v "$FIXTURE/results:/results:ro" \
    alpine:3.20 -c '
set -eu
[ "$(stat -c %u:%g:%a /runtime)" = "2000:3000:3770" ]
[ "$(stat -c %u:%g:%a /uploads)" = "2000:3000:3770" ]
[ "$(stat -c %u:%g:%a /runtime/install)" = "10000:3000:2770" ]
[ "$(stat -c %u:%g:%a /runtime/install/nested/canary.txt)" = "10000:3000:660" ]
[ "$(stat -c %u:%g:%a /results/normalization/receipt.json)" = "2000:3000:640" ]
' || fail BOOTSTRAP_ADOPT_TEST_POLICY_INVALID

ln "$FIXTURE/uploads/nested/upload.txt" "$FIXTURE/uploads/nested/hardlink.txt"
if run_normalizer "$FIXTURE/request.json" "$FIXTURE/results" >"$FIXTURE/hardlink.log" 2>&1; then
    fail BOOTSTRAP_ADOPT_TEST_HARDLINK_ACCEPTED
fi
grep -Eq 'BOOTSTRAP_ADOPT_(ENTRY_INVALID|SOURCE_CONTENT_CHANGED)' "$FIXTURE/hardlink.log" \
    || fail BOOTSTRAP_ADOPT_TEST_HARDLINK_ERROR_INVALID
rm "$FIXTURE/uploads/nested/hardlink.txt"

ln -s /etc/passwd "$FIXTURE/uploads/nested/escape"
if run_normalizer "$FIXTURE/request.json" "$FIXTURE/results" >"$FIXTURE/symlink.log" 2>&1; then
    fail BOOTSTRAP_ADOPT_TEST_SYMLINK_ACCEPTED
fi
grep -q 'BOOTSTRAP_ADOPT_ENTRY_INVALID' "$FIXTURE/symlink.log" \
    || fail BOOTSTRAP_ADOPT_TEST_SYMLINK_ERROR_INVALID
rm "$FIXTURE/uploads/nested/escape"

write_request 028f5d35-3f42-7a31-a731-9e45df3356c3 "$FIXTURE/wrong-operation.json"
if run_normalizer "$FIXTURE/wrong-operation.json" "$FIXTURE/results" >"$FIXTURE/wrong-operation.log" 2>&1; then
    fail BOOTSTRAP_ADOPT_TEST_WRONG_OPERATION_ACCEPTED
fi
grep -q 'BOOTSTRAP_ADOPT_OPERATION_MISMATCH' "$FIXTURE/wrong-operation.log" \
    || fail BOOTSTRAP_ADOPT_TEST_WRONG_OPERATION_ERROR_INVALID

# A missing logical source is rejected before the first metadata mutation.
root_fixture 'chmod 0644 /fixture/runtime/install/nested/canary.txt
mv /fixture/runtime/storage /fixture/runtime/storage.missing'
if run_normalizer "$FIXTURE/request.json" "$FIXTURE/results" >"$FIXTURE/missing-source.log" 2>&1; then
    fail BOOTSTRAP_ADOPT_TEST_MISSING_SOURCE_ACCEPTED
fi
grep -q 'BOOTSTRAP_ADOPT_SOURCE_ROOT_MISSING' "$FIXTURE/missing-source.log" \
    || fail BOOTSTRAP_ADOPT_TEST_MISSING_SOURCE_ERROR_INVALID
root_fixture '[ "$(stat -c %a /fixture/runtime/install/nested/canary.txt)" = 644 ]
mv /fixture/runtime/storage.missing /fixture/runtime/storage'
run_normalizer "$FIXTURE/request.json" "$FIXTURE/results"

# A logically absent runtime child is represented by the canonical empty-tree
# root. It is created only after durable intent publication, and a replay sees
# the same normalized broad-root tuple rather than inventing a new operation.
mkdir -p "$FIXTURE/runtime-empty/cache" "$FIXTURE/uploads-empty" \
    "$FIXTURE/results-empty/normalization"
chmod 0755 "$FIXTURE/runtime-empty" "$FIXTURE/runtime-empty/cache" "$FIXTURE/uploads-empty"
chmod 02770 "$FIXTURE/results-empty" "$FIXTURE/results-empty/normalization"
empty_root=$(content_root "$FIXTURE/uploads-empty")
php -r '
$request = [
    "schema_version" => 1, "purpose" => "storage_bootstrap_adopt_normalize",
    "operation_id" => $argv[2], "agent_uid" => 2000, "app_uid" => 10000, "shared_gid" => 3000,
    "target_policy" => ["app_uid" => 10000, "shared_gid" => 3000, "root_mode" => "03770", "dir_mode" => "02770", "file_mode" => "0660"],
    "source_content_roots" => ["install" => $argv[3], "local_storage" => $argv[3],
        "runtime_backup" => $argv[3], "uploads" => $argv[3]],
];
file_put_contents($argv[1], json_encode($request, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n");
' "$FIXTURE/request-empty.json" "$OPERATION" "$empty_root"
chmod 0444 "$FIXTURE/request-empty.json"
run_normalizer_paths "$FIXTURE/request-empty.json" "$FIXTURE/results-empty" \
    "$FIXTURE/runtime-empty" "$FIXTURE/uploads-empty"
run_normalizer_paths "$FIXTURE/request-empty.json" "$FIXTURE/results-empty" \
    "$FIXTURE/runtime-empty" "$FIXTURE/uploads-empty"
for child in install storage backup; do
    [ -d "$FIXTURE/runtime-empty/$child" ] || fail BOOTSTRAP_ADOPT_TEST_EMPTY_SOURCE_NOT_CREATED
done
[ -f "$FIXTURE/results-empty/normalization/receipt.json" ] \
    || fail BOOTSTRAP_ADOPT_TEST_EMPTY_SOURCE_RECEIPT_MISSING

# A conflicting terminal receipt is checked before any permission mutation.
root_fixture 'find /fixture/runtime /fixture/uploads -type d -exec chown 0:0 {} \; -exec chmod 0755 {} \;
find /fixture/runtime /fixture/uploads -type f -exec chown 0:0 {} \; -exec chmod 0644 {} \;
printf "%s\n" "{\"conflict\":true}" > /fixture/results/normalization/receipt.json
chown 2000:3000 /fixture/results/normalization/receipt.json
chmod 0640 /fixture/results/normalization/receipt.json'
if run_normalizer "$FIXTURE/request.json" "$FIXTURE/results" >"$FIXTURE/receipt-conflict.log" 2>&1; then
    fail BOOTSTRAP_ADOPT_TEST_RECEIPT_CONFLICT_ACCEPTED
fi
grep -q 'BOOTSTRAP_ADOPT_RESULT_CONFLICT' "$FIXTURE/receipt-conflict.log" \
    || fail BOOTSTRAP_ADOPT_TEST_RECEIPT_CONFLICT_ERROR_INVALID
root_fixture '[ "$(stat -c %a /fixture/runtime/install/nested/canary.txt)" = 644 ]'

php "$SCRIPT_DIR/validate-bootstrap-adoption.php" receipt-vectors >/dev/null
printf '%s\n' 'bootstrap permission normalize tests passed'
