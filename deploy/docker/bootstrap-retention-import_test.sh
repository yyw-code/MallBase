#!/bin/sh
set -eu

SCRIPT_DIR=$(CDPATH= cd -P "$(dirname "$0")" && pwd)
IMAGE=${MALLBASE_BOOTSTRAP_TEST_IMAGE:-mallbase-backend:dev}
FIXTURE=$(mktemp -d "${TMPDIR:-/tmp}/mallbase-bootstrap-import.XXXXXX")
AGENT_UID=2000
APP_UID=10000
SHARED_GID=3000
OPERATION=018f5d35-3f42-7a31-a731-9e45df3356c2
NAMESPACE=mbs_bootstrap_import_test

cleanup() {
    if [ -d "$FIXTURE" ]; then
        docker run --rm --network none --entrypoint sh -v "$FIXTURE:/fixture" alpine:3.20 \
            -c 'find /fixture -mindepth 1 -delete' >/dev/null 2>&1 || true
        rmdir "$FIXTURE" 2>/dev/null || true
    fi
}
trap cleanup EXIT HUP INT TERM

fail() {
    printf '%s\n' "$1" >&2
    exit 1
}

root_fixture() {
    docker run --rm --network none --entrypoint sh -v "$FIXTURE:/fixture" alpine:3.20 -c "$1"
}

content_root() {
    php "$SCRIPT_DIR/validate-bootstrap-adoption.php" content-root "$1"
}

docker image inspect "$IMAGE" >/dev/null 2>&1 || fail BOOTSTRAP_ADOPT_TEST_IMAGE_MISSING
mkdir -p "$FIXTURE/runtime/install" "$FIXTURE/runtime/storage" "$FIXTURE/runtime/backup" \
    "$FIXTURE/uploads/original" "$FIXTURE/targets/cert" "$FIXTURE/targets/demo" \
    "$FIXTURE/targets/public-storage" "$FIXTURE/env" "$FIXTURE/results/normalization" \
    "$FIXTURE/results/import" "$FIXTURE/retention/cert/nested" "$FIXTURE/retention/demo" \
    "$FIXTURE/retention/public-storage" "$FIXTURE/retention/custom-upload/custom" \
    "$FIXTURE/retention/env" "$FIXTURE/expected"
printf '%s' install > "$FIXTURE/runtime/install/install.lock"
printf '%s' local > "$FIXTURE/runtime/storage/local.txt"
printf '%s' backup > "$FIXTURE/runtime/backup/backup.txt"
printf '%s' original > "$FIXTURE/uploads/original/existing.txt"
printf '%s' cert > "$FIXTURE/retention/cert/nested/cert.pem"
printf '%s' demo > "$FIXTURE/retention/demo/demo.txt"
printf '%s' public > "$FIXTURE/retention/public-storage/public.txt"
printf '%s' custom > "$FIXTURE/retention/custom-upload/custom/imported.txt"
printf '%s\n' 'DB_HOST=mysql' 'REDIS_HOST=redis' > "$FIXTURE/retention/env/backend.env"
: > "$FIXTURE/env/backend.env"
chmod 0600 "$FIXTURE/env/backend.env" "$FIXTURE/retention/env/backend.env"
chmod 02770 "$FIXTURE/results" "$FIXTURE/results/normalization" "$FIXTURE/results/import"

php -r '
$request = [
 "schema_version"=>1,"purpose"=>"storage_bootstrap_adopt_normalize","operation_id"=>$argv[2],
 "agent_uid"=>2000,"app_uid"=>10000,"shared_gid"=>3000,
 "target_policy"=>["app_uid"=>10000,"shared_gid"=>3000,"root_mode"=>"03770","dir_mode"=>"02770","file_mode"=>"0660"],
 "source_content_roots"=>["install"=>$argv[3],"local_storage"=>$argv[4],"runtime_backup"=>$argv[5],"uploads"=>$argv[6]],
];
file_put_contents($argv[1], json_encode($request, JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n");
' "$FIXTURE/normalize.json" "$OPERATION" "$(content_root "$FIXTURE/runtime/install")" \
    "$(content_root "$FIXTURE/runtime/storage")" "$(content_root "$FIXTURE/runtime/backup")" \
    "$(content_root "$FIXTURE/uploads")"
chmod 0444 "$FIXTURE/normalize.json"

docker run --rm --network none --read-only --user "0:$SHARED_GID" \
    --security-opt no-new-privileges:true --cap-drop ALL \
    --cap-add DAC_READ_SEARCH --cap-add CHOWN --cap-add FOWNER \
    --tmpfs /tmp:rw,nosuid,nodev,noexec,size=8m,mode=0700 \
    -e "MALLBASE_BOOTSTRAP_OPERATION_ID=$OPERATION" \
    -v "$SCRIPT_DIR/bootstrap-permission-normalize.sh:/bootstrap/bootstrap-permission-normalize.sh:ro" \
    -v "$SCRIPT_DIR/validate-bootstrap-adoption.php:/bootstrap/validate-bootstrap-adoption.php:ro" \
    -v "$FIXTURE/normalize.json:/bootstrap-input/request.json:ro" \
    -v "$FIXTURE/runtime:/storage/runtime" -v "$FIXTURE/uploads:/storage/uploads" \
    -v "$FIXTURE/results:/bootstrap-results" \
    --entrypoint /bootstrap/bootstrap-permission-normalize.sh "$IMAGE"

normalization_hash=$(php -r '$d=json_decode(file_get_contents($argv[1]),true,32,JSON_THROW_ON_ERROR);echo $d["evidence"]["receipt_sha256"];' \
    "$FIXTURE/results/normalization/receipt.json")

for artifact in cert demo install local_storage public_storage runtime_backup uploads; do
    mkdir -p "$FIXTURE/expected/$artifact"
done
cp -R "$FIXTURE/retention/cert/." "$FIXTURE/expected/cert/"
cp -R "$FIXTURE/retention/demo/." "$FIXTURE/expected/demo/"
cp -R "$FIXTURE/runtime/install/." "$FIXTURE/expected/install/"
cp -R "$FIXTURE/runtime/storage/." "$FIXTURE/expected/local_storage/"
cp -R "$FIXTURE/retention/public-storage/." "$FIXTURE/expected/public_storage/"
cp -R "$FIXTURE/runtime/backup/." "$FIXTURE/expected/runtime_backup/"
cp -R "$FIXTURE/uploads/." "$FIXTURE/expected/uploads/"
cp -R "$FIXTURE/retention/custom-upload/." "$FIXTURE/expected/uploads/"

php -r '
$artifacts=["cert","demo","install","local_storage","public_storage","runtime_backup","uploads"];
$roots=array_slice($argv,5,7); $ids=[]; $volumes=[];
foreach($artifacts as $index=>$artifact){
 $digit=(string)($index+1); $markerId=str_repeat($digit,8)."-".str_repeat($digit,4)."-4".str_repeat($digit,3)."-8".str_repeat($digit,3)."-".str_repeat($digit,12);
 $marker=["schema_version"=>1,"installation_storage_namespace"=>$argv[3],"artifact"=>$artifact,"storage_layout_version"=>1,"layout_generation"=>1,"marker_id"=>$markerId];
 $markerBytes=json_encode($marker,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
 $candidate=in_array($artifact,["cert","demo","public_storage"],true);
 $runtime=in_array($artifact,["install","local_storage","runtime_backup"],true);
 $volumes[$artifact]=["artifact"=>$artifact,"source_mode"=>$candidate?"candidate":"legacy_broad","volume_name"=>$runtime?"mb_runtime":($artifact==="uploads"?"mb_uploads":"mb_".$artifact),"docker_volume_id"=>$runtime?"docker-runtime":($artifact==="uploads"?"docker-uploads":"docker-".$artifact),"labels_sha256"=>"sha256:".str_repeat($candidate?"b":"a",64),"marker_id"=>$markerId,"marker_sha256"=>"sha256:".hash("sha256",$markerBytes),"expected_content_root"=>$roots[$index],"empty_at_prepare"=>$candidate];
}
$request=["schema_version"=>1,"purpose"=>"storage_bootstrap_adopt_import","operation_id"=>$argv[2],"installation_storage_namespace"=>$argv[3],"layout_generation"=>1,"agent_uid"=>2000,"app_uid"=>10000,"shared_gid"=>3000,"target_policy"=>["app_uid"=>10000,"shared_gid"=>3000,"root_mode"=>"03770","dir_mode"=>"02770","file_mode"=>"0660"],"normalization_receipt_sha256"=>$argv[4],"frozen_manifest_sha256"=>"sha256:".str_repeat("c",64),"candidate_volumes"=>$volumes,"imports"=>["cert"=>["present"=>true,"content_root"=>$argv[12]],"custom_upload"=>["present"=>true,"content_root"=>$argv[13]],"demo"=>["present"=>true,"content_root"=>$argv[14]],"env"=>["present"=>true,"sha256"=>$argv[15]],"public_storage"=>["present"=>true,"content_root"=>$argv[16]]]];
file_put_contents($argv[1],json_encode($request,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n");
' "$FIXTURE/import.json" "$OPERATION" "$NAMESPACE" "$normalization_hash" \
    "$(content_root "$FIXTURE/expected/cert")" "$(content_root "$FIXTURE/expected/demo")" \
    "$(content_root "$FIXTURE/expected/install")" "$(content_root "$FIXTURE/expected/local_storage")" \
    "$(content_root "$FIXTURE/expected/public_storage")" "$(content_root "$FIXTURE/expected/runtime_backup")" \
    "$(content_root "$FIXTURE/expected/uploads")" "$(content_root "$FIXTURE/retention/cert")" \
    "$(content_root "$FIXTURE/retention/custom-upload")" "$(content_root "$FIXTURE/retention/demo")" \
    "sha256:$(sha256sum "$FIXTURE/retention/env/backend.env" | awk '{print $1}')" \
    "$(content_root "$FIXTURE/retention/public-storage")"
chmod 0444 "$FIXTURE/import.json"
cp "$FIXTURE/import.json" "$FIXTURE/import.good.json"
chmod 0444 "$FIXTURE/import.good.json"

run_importer() {
    docker run --rm --network none --read-only --user "0:$SHARED_GID" \
        --security-opt no-new-privileges:true --cap-drop ALL \
        --cap-add DAC_READ_SEARCH --cap-add CHOWN --cap-add FOWNER \
        --tmpfs /tmp:rw,nosuid,nodev,noexec,size=8m,mode=0700 \
        -e "MALLBASE_BOOTSTRAP_OPERATION_ID=$OPERATION" \
        -v "$SCRIPT_DIR/bootstrap-retention-import.sh:/bootstrap/bootstrap-retention-import.sh:ro" \
        -v "$SCRIPT_DIR/validate-bootstrap-adoption.php:/bootstrap/validate-bootstrap-adoption.php:ro" \
        -v "$FIXTURE/import.json:/bootstrap-input/import.json:ro" \
        -v "$FIXTURE/retention:/bootstrap-retention:ro" -v "$FIXTURE/results:/bootstrap-results" \
        -v "$FIXTURE/runtime:/storage/runtime" -v "$FIXTURE/uploads:/storage/uploads" \
        -v "$FIXTURE/targets/cert:/storage/cert" -v "$FIXTURE/targets/demo:/storage/demo" \
        -v "$FIXTURE/targets/public-storage:/storage/public-storage" -v "$FIXTURE/env:/storage/env" \
        --entrypoint /bootstrap/bootstrap-retention-import.sh "$IMAGE"
}

restore_request() {
    cp -f "$FIXTURE/import.good.json" "$FIXTURE/import.json"
    chmod 0444 "$FIXTURE/import.json"
}

mutate_request() {
    chmod 0644 "$FIXTURE/import.json"
    php -r '
$path=$argv[1]; $mutation=$argv[2]; $value=$argv[3]??"";
$request=json_decode(file_get_contents($path),true,64,JSON_THROW_ON_ERROR);
switch($mutation){
 case "runtime_mismatch": $request["candidate_volumes"]["local_storage"]["docker_volume_id"]="docker-runtime-other"; break;
 case "uploads_reuse": $request["candidate_volumes"]["uploads"]["docker_volume_id"]="docker-runtime"; break;
 case "candidate_duplicate": $request["candidate_volumes"]["demo"]["docker_volume_id"]=$request["candidate_volumes"]["cert"]["docker_volume_id"]; break;
 case "candidate_reuse": $request["candidate_volumes"]["cert"]["docker_volume_id"]="docker-uploads"; break;
 case "wrong_root": $request["candidate_volumes"]["uploads"]["expected_content_root"]="sha256:".str_repeat("d",64); break;
 case "absent_cert":
  $request["imports"]["cert"]=["present"=>false,"content_root"=>$value];
  $request["candidate_volumes"]["cert"]["expected_content_root"]=$value;
  break;
 default: exit(2);
}
file_put_contents($path,json_encode($request,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n");
' "$FIXTURE/import.json" "$1" "${2:-}"
    chmod 0444 "$FIXTURE/import.json"
}

state_snapshot() {
    docker run --rm --network none --entrypoint sh -v "$FIXTURE:/fixture:ro" alpine:3.20 \
        -c 'cd /fixture && tar -cf - runtime uploads targets env' | shasum -a 256 | awk '{print $1}'
}

assert_zero_mutation() {
    label=$1
    before=$2
    [ "$(state_snapshot)" = "$before" ] || fail "BOOTSTRAP_ADOPT_TEST_${label}_MUTATED_TARGET"
    [ ! -e "$FIXTURE/results/import/intent.json" ] || fail "BOOTSTRAP_ADOPT_TEST_${label}_PUBLISHED_INTENT"
}

# Invalid physical topology is rejected before any target or result mutation.
for mutation in runtime_mismatch uploads_reuse candidate_duplicate candidate_reuse; do
    restore_request
    mutate_request "$mutation"
    before=$(state_snapshot)
    if run_importer >"$FIXTURE/$mutation.log" 2>&1; then fail BOOTSTRAP_ADOPT_TEST_TOPOLOGY_ACCEPTED; fi
    grep -q BOOTSTRAP_ADOPT_IMPORT_TOPOLOGY_INVALID "$FIXTURE/$mutation.log" \
        || fail BOOTSTRAP_ADOPT_TEST_TOPOLOGY_ERROR_INVALID
    assert_zero_mutation TOPOLOGY "$before"
done

# A bad expected root is detected from the predicted post-import tree before intent/copy/policy writes.
restore_request
mutate_request wrong_root
before=$(state_snapshot)
if run_importer >"$FIXTURE/wrong-root.log" 2>&1; then fail BOOTSTRAP_ADOPT_TEST_WRONG_ROOT_ACCEPTED; fi
grep -q BOOTSTRAP_ADOPT_IMPORT_TARGET_CONTENT_INVALID "$FIXTURE/wrong-root.log" \
    || fail BOOTSTRAP_ADOPT_TEST_WRONG_ROOT_ERROR_INVALID
assert_zero_mutation WRONG_ROOT "$before"

# An absent candidate import cannot hide unexpected existing target content.
restore_request
empty_cert_root=$(content_root "$FIXTURE/targets/cert")
root_fixture 'mv /fixture/retention/cert /fixture/retention/cert.saved
printf unexpected > /fixture/targets/cert/unexpected.txt'
mutate_request absent_cert "$empty_cert_root"
before=$(state_snapshot)
if run_importer >"$FIXTURE/absent-cert.log" 2>&1; then fail BOOTSTRAP_ADOPT_TEST_ABSENT_TARGET_ACCEPTED; fi
grep -q BOOTSTRAP_ADOPT_IMPORT_TARGET_CONTENT_INVALID "$FIXTURE/absent-cert.log" \
    || fail BOOTSTRAP_ADOPT_TEST_ABSENT_TARGET_ERROR_INVALID
assert_zero_mutation ABSENT_TARGET "$before"
root_fixture 'rm /fixture/targets/cert/unexpected.txt
mv /fixture/retention/cert.saved /fixture/retention/cert'
restore_request

# Conflicting target content is discovered before any bridge/custom mutation.
root_fixture 'mkdir -p /fixture/uploads/custom
printf wrong > /fixture/uploads/custom/imported.txt'
if run_importer >"$FIXTURE/collision.log" 2>&1; then fail BOOTSTRAP_ADOPT_TEST_COLLISION_ACCEPTED; fi
grep -q BOOTSTRAP_ADOPT_IMPORT_TARGET_CONFLICT "$FIXTURE/collision.log" || fail BOOTSTRAP_ADOPT_TEST_COLLISION_ERROR_INVALID
[ ! -e "$FIXTURE/targets/cert/nested/cert.pem" ] || fail BOOTSTRAP_ADOPT_TEST_COLLISION_MUTATED_TARGET
root_fixture 'rm -rf /fixture/uploads/custom'

run_importer
run_importer

for artifact in cert demo install local_storage public_storage runtime_backup uploads; do
    case "$artifact" in
        cert) target=$FIXTURE/targets/cert ;;
        demo) target=$FIXTURE/targets/demo ;;
        install) target=$FIXTURE/runtime/install ;;
        local_storage) target=$FIXTURE/runtime/storage ;;
        public_storage) target=$FIXTURE/targets/public-storage ;;
        runtime_backup) target=$FIXTURE/runtime/backup ;;
        uploads) target=$FIXTURE/uploads ;;
    esac
    [ "$(content_root "$target")" = "$(content_root "$FIXTURE/expected/$artifact")" ] \
        || fail BOOTSTRAP_ADOPT_TEST_TARGET_ROOT_INVALID
done

docker run --rm --network none --entrypoint sh \
    -v "$FIXTURE:/fixture:ro" alpine:3.20 -c '
set -eu
for root in /fixture/targets/cert /fixture/targets/demo /fixture/runtime/install /fixture/runtime/storage /fixture/targets/public-storage /fixture/runtime/backup /fixture/uploads; do
 [ "$(stat -c %u:%g:%a "$root")" = "2000:3000:3770" ]
 [ "$(stat -c %u:%g:%a "$root/.mallbase-layout-marker.json")" = "2000:3000:444" ]
done
[ "$(stat -c %u:%g:%a /fixture/uploads/custom/imported.txt)" = "10000:3000:660" ]
[ "$(stat -c %u:%g:%a /fixture/env/backend.env)" = "10000:3000:600" ]
[ "$(stat -c %u:%g:%a /fixture/results/import/receipt.json)" = "2000:3000:640" ]
[ "$(stat -c %u:%g:%a /fixture/results/import/composite.json)" = "2000:3000:640" ]
' || fail BOOTSTRAP_ADOPT_TEST_IMPORT_POLICY_INVALID

php "$SCRIPT_DIR/validate-bootstrap-adoption.php" receipt-vectors >/dev/null
printf '%s\n' 'bootstrap retention import tests passed'
