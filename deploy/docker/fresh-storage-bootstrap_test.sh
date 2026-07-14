#!/bin/sh
set -eu

SCRIPT_DIR=$(CDPATH= cd -P "$(dirname "$0")" && pwd)
PROJECT_ROOT=$(CDPATH= cd -P "$SCRIPT_DIR/../.." && pwd)
IMAGE=alpine:3.20@sha256:d9e853e87e55526f6b2917df91a2115c36dd7c696a35be12163d44e6e2a4b6bc
FIXTURE=
RUNTIME_VOLUME=
UPLOADS_VOLUME=

fail() {
    printf 'fresh-storage-bootstrap test failed: %s\n' "$1" >&2
    exit 1
}

cleanup() {
    [ -z "$RUNTIME_VOLUME" ] || docker volume rm "$RUNTIME_VOLUME" >/dev/null 2>&1 || true
    [ -z "$UPLOADS_VOLUME" ] || docker volume rm "$UPLOADS_VOLUME" >/dev/null 2>&1 || true
    if [ -n "$FIXTURE" ] && [ -d "$FIXTURE" ]; then
        chmod -R u+rwX "$FIXTURE" 2>/dev/null || true
        rm -rf "$FIXTURE"
    fi
}
trap cleanup EXIT HUP INT TERM

command -v docker >/dev/null 2>&1 || fail docker-unavailable
docker image inspect "$IMAGE" >/dev/null 2>&1 || fail pinned-helper-image-missing
for file in fresh-storage-bootstrap.sh fresh-storage-inspect.sh fresh-storage-stamp.sh validate-fresh-storage.php; do
    [ -f "$SCRIPT_DIR/$file" ] || fail "$file-missing"
done

suffix=$(printf '%s' "$$-$(date +%s)" | sha256sum | cut -c1-20)
namespace=mbs_$suffix
operation_id=11111111-1111-4111-8111-${suffix}000000000000
operation_id=$(printf '%s' "$operation_id" | cut -c1-36)
agent_uid=$(id -u)
shared_gid=$(id -g)
RUNTIME_VOLUME=${namespace}_runtime
UPLOADS_VOLUME=${namespace}_uploads
FIXTURE=$(mktemp -d "${TMPDIR:-/tmp}/mallbase-fresh-storage.XXXXXX")
mkdir -p "$FIXTURE/env" "$FIXTURE/cert" "$FIXTURE/demo" "$FIXTURE/public-storage" "$FIXTURE/results" "$FIXTURE/input/markers"
umask 077
: > "$FIXTURE/env/backend.env"
chmod 0600 "$FIXTURE/env/backend.env"
chmod 2770 "$FIXTURE/cert" "$FIXTURE/demo" "$FIXTURE/public-storage"
chmod 0770 "$FIXTURE/results"
mkdir -p "$FIXTURE/os-root" "$FIXTURE/tools"
cp "$PROJECT_ROOT/docker-compose.storage-bootstrap.yml" "$FIXTURE/os-root/docker-compose.storage-bootstrap.yml"
cat > "$FIXTURE/tools/uname" <<'SH'
#!/bin/sh
case "${1-}" in
    -s) printf '%s\n' Darwin ;;
    *) printf '%s\n' arm64 ;;
esac
SH
chmod 0555 "$FIXTURE/tools/uname"
if PATH="$FIXTURE/tools:$PATH" sh "$SCRIPT_DIR/fresh-storage-bootstrap.sh" --project-root "$FIXTURE/os-root" status \
    > "$FIXTURE/os-rejected.out" 2>&1; then
    fail non-linux-storage-bootstrap-was-accepted
fi
grep -F FRESH_STORAGE_HOST_OS_UNSUPPORTED "$FIXTURE/os-rejected.out" >/dev/null \
    || fail non-linux-storage-bootstrap-did-not-return-stable-error

create_volume() {
    role=$1
    name=$2
    docker volume create --driver local \
        --label "com.mallbase.storage.namespace=$namespace" \
        --label "com.mallbase.storage.role=$role" \
        --label com.mallbase.storage.layout-version=1 \
        --label com.mallbase.storage.layout-generation=1 \
        --label com.mallbase.storage.managed=true "$name" >/dev/null
}
create_volume runtime "$RUNTIME_VOLUME"
create_volume uploads "$UPLOADS_VOLUME"
docker volume inspect "$RUNTIME_VOLUME" > "$FIXTURE/runtime.json"
docker volume inspect "$UPLOADS_VOLUME" > "$FIXTURE/uploads.json"
runtime_identity=$(php "$SCRIPT_DIR/validate-fresh-storage.php" docker-field "$FIXTURE/runtime.json" "$namespace" runtime mount_identity)
runtime_policy=$(php "$SCRIPT_DIR/validate-fresh-storage.php" docker-field "$FIXTURE/runtime.json" "$namespace" runtime policy_sha256)
uploads_identity=$(php "$SCRIPT_DIR/validate-fresh-storage.php" docker-field "$FIXTURE/uploads.json" "$namespace" uploads mount_identity)
uploads_policy=$(php "$SCRIPT_DIR/validate-fresh-storage.php" docker-field "$FIXTURE/uploads.json" "$namespace" uploads policy_sha256)
php -r '$v=json_decode(file_get_contents($argv[1]),true,64,JSON_THROW_ON_ERROR);$v[0]["Labels"]["unexpected"]="label";file_put_contents($argv[2],json_encode($v,JSON_THROW_ON_ERROR));' \
    "$FIXTURE/runtime.json" "$FIXTURE/runtime-extra-label.json"
if php "$SCRIPT_DIR/validate-fresh-storage.php" docker-field "$FIXTURE/runtime-extra-label.json" "$namespace" runtime mount_identity \
    > "$FIXTURE/rejected.out" 2>&1; then
    fail extra-docker-label-was-accepted
fi

common_run() {
    docker run --rm --pull never --network none --read-only --cap-drop ALL --cap-add DAC_READ_SEARCH --cap-add CHOWN --cap-add FOWNER \
        --security-opt no-new-privileges:true --user "0:$shared_gid" \
        --tmpfs /tmp:rw,nosuid,nodev,noexec,size=8m,mode=0700 \
        -e "MALLBASE_STORAGE_OPERATION_ID=$operation_id" \
        -e "MALLBASE_STORAGE_NAMESPACE=$namespace" -e "MALLBASE_AGENT_UID=$agent_uid" \
        -e MALLBASE_APP_UID=10000 \
        -e "MALLBASE_UPGRADE_SHARED_GID=$shared_gid" \
        -e "MALLBASE_RUNTIME_VOLUME_NAME=$RUNTIME_VOLUME" \
        -e "MALLBASE_RUNTIME_MOUNT_IDENTITY=$runtime_identity" \
        -e "MALLBASE_RUNTIME_POLICY_SHA256=$runtime_policy" \
        -e "MALLBASE_UPLOADS_VOLUME_NAME=$UPLOADS_VOLUME" \
        -e "MALLBASE_UPLOADS_MOUNT_IDENTITY=$uploads_identity" \
        -e "MALLBASE_UPLOADS_POLICY_SHA256=$uploads_policy" \
        -v "$RUNTIME_VOLUME:/storage/runtime" -v "$UPLOADS_VOLUME:/storage/uploads" \
        -v "$FIXTURE/env:/storage/env" \
        -v "$FIXTURE/cert:/storage/cert" -v "$FIXTURE/demo:/storage/demo" \
        -v "$FIXTURE/public-storage:/storage/public-storage" -v "$FIXTURE/results:/storage-init-results" \
        "$@"
}

common_run -v "$SCRIPT_DIR/fresh-storage-inspect.sh:/bootstrap/inspect.sh:ro" "$IMAGE" /bootstrap/inspect.sh \
    || fail valid-inspection-rejected
common_run "$IMAGE" sh -c \
    '[ "$(stat -c %u /storage/env/backend.env)" = 10000 ] && [ "$(stat -c %a /storage/env/backend.env)" = 600 ]' \
    || fail backend-env-policy-not-adopted
[ -f "$FIXTURE/results/fresh-inspection.json" ] || fail inspection-result-missing
php -r '
    $v=json_decode(file_get_contents($argv[1]),true,64,JSON_THROW_ON_ERROR);
    if (($v["purpose"]??null)!=="fresh_storage_inspection" || count($v["artifacts"]??[])!==7) exit(1);
    foreach ($v["artifacts"] as $a) if (($a["empty"]??false)!==true || ($a["root_mode"]??null)!=="03770") exit(1);
' "$FIXTURE/results/fresh-inspection.json" || fail inspection-schema-invalid

INSPECTION="$FIXTURE/results/fresh-inspection.json" INPUT="$FIXTURE/input" NS="$namespace" OP="$operation_id" UID_VALUE="$agent_uid" GID_VALUE="$shared_gid" php <<'PHP'
<?php
$inspection = json_decode(file_get_contents(getenv('INSPECTION')), true, 64, JSON_THROW_ON_ERROR);
$markers = [];
$index = 1;
foreach (array_keys($inspection['artifacts']) as $artifact) {
    $markerId = sprintf('22222222-2222-4222-8222-%012d', $index++);
    $marker = ['schema_version'=>1,'installation_storage_namespace'=>getenv('NS'),'artifact'=>$artifact,
        'storage_layout_version'=>1,'layout_generation'=>1,'marker_id'=>$markerId];
    $bytes = json_encode($marker, JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
    $path = getenv('INPUT').'/markers/'.$artifact.'.json';
    file_put_contents($path, $bytes);
    chmod($path, 0444);
    $source = $inspection['artifacts'][$artifact];
    $markers[$artifact] = ['artifact'=>$artifact,'storage_kind'=>$source['storage_kind'],'volume_name'=>$source['volume_name'],
        'mount_identity'=>$source['mount_identity'],'policy_sha256'=>$source['policy_sha256'],'content_sha256'=>$source['content_sha256'],
        'marker_id'=>$markerId,'marker_sha256'=>'sha256:'.hash('sha256',$bytes)];
}
$request = ['schema_version'=>1,'purpose'=>'fresh_storage_init','operation_id'=>getenv('OP'),
    'installation_storage_namespace'=>getenv('NS'),'app_version'=>'1.0.0','deployment_id'=>'33333333-3333-4333-8333-333333333333',
    'release_inventory_sha256'=>'sha256:'.str_repeat('a',64),'storage_layout_version'=>1,'layout_generation'=>1,
    'frozen_prepare_sha256'=>'sha256:'.str_repeat('b',64),'fresh_inspection_sha256'=>'sha256:'.hash_file('sha256',getenv('INSPECTION')),
    'agent_uid'=>(int)getenv('UID_VALUE'),'shared_gid'=>(int)getenv('GID_VALUE'),'root_mode'=>'03770','marker_mode'=>'0444',
    'directory_mode'=>'0770','file_mode'=>'0660','artifacts'=>$markers];
file_put_contents(getenv('INPUT').'/request.json', json_encode($request,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n");
chmod(getenv('INPUT').'/request.json',0444);
PHP

request_hash=sha256:$(sha256sum "$FIXTURE/input/request.json" | awk '{print $1}')
stamp_run() {
    common_run -e MALLBASE_STORAGE_LAYOUT_GENERATION=1 -e "MALLBASE_STORAGE_INIT_REQUEST_SHA256=$request_hash" \
        -v "$FIXTURE/input/request.json:/storage-input/request.json:ro" \
        -v "$FIXTURE/input/markers:/storage-input/markers:ro" \
        -v "$SCRIPT_DIR/fresh-storage-stamp.sh:/bootstrap/stamp.sh:ro" "$IMAGE" /bootstrap/stamp.sh
}
stamp_run || fail valid-stamp-rejected
[ -f "$FIXTURE/results/$operation_id.json" ] || fail finalize-result-missing

# Simulate a hard stop after a prefix of markers was committed. The second run
# must accept matching markers and finish only the absent suffix.
rm -f "$FIXTURE/results/$operation_id.json"
docker run --rm --pull never --network none --cap-drop ALL --cap-add FOWNER --user "0:$shared_gid" \
    -v "$RUNTIME_VOLUME:/runtime" -v "$UPLOADS_VOLUME:/uploads" \
    -v "$FIXTURE/public-storage:/public-storage" "$IMAGE" sh -c \
    'rm -f /runtime/backup/.mallbase-layout-marker.json /runtime/storage/.mallbase-layout-marker.json /uploads/.mallbase-layout-marker.json /public-storage/.mallbase-layout-marker.json'
stamp_run || fail partial-stamp-retry-rejected

docker run --rm --pull never --network none --read-only --cap-drop ALL --user "10000:$shared_gid" \
    -v "$RUNTIME_VOLUME:/runtime" "$IMAGE" sh -c \
    'printf first > /runtime/live.tmp && printf second > /runtime/live.tmp && rm /runtime/live.tmp' \
    || fail nonroot-runtime-root-not-writable

printf '%s\n' 'fresh-storage-bootstrap tests passed'
