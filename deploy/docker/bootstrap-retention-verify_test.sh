#!/bin/sh
set -eu

SCRIPT_DIR=$(CDPATH= cd -P "$(dirname "$0")" && pwd)
IMAGE=${MALLBASE_BOOTSTRAP_TEST_IMAGE:-mallbase-backend:dev}
FIXTURE=$(mktemp -d "${TMPDIR:-/tmp}/mallbase-bootstrap-target-publish.XXXXXX")
AGENT_UID=2000
APP_UID=10000
SHARED_GID=3000
OPERATION=018f5d35-3f42-7a31-a731-9e45df3356c2

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

run_publisher() {
    docker run --rm --network none --read-only --user "$AGENT_UID:$SHARED_GID" \
        --security-opt no-new-privileges:true --cap-drop ALL \
        --tmpfs /tmp:rw,nosuid,nodev,noexec,size=8m,mode=1777 \
        -v "$SCRIPT_DIR/validate-bootstrap-adoption.php:/validator.php:ro" \
        -v "$FIXTURE:/fixture" --entrypoint php "$IMAGE" /validator.php publish-target \
        /fixture/source /fixture/destination /fixture/authority/bootstrap-target-authority.json \
        /fixture/authority/storage-ready.pub "$OPERATION" "$AGENT_UID" "$APP_UID" "$SHARED_GID"
}

docker image inspect "$IMAGE" >/dev/null 2>&1 || fail BOOTSTRAP_ADOPT_TEST_IMAGE_MISSING
mkdir -p "$FIXTURE/source" "$FIXTURE/destination" "$FIXTURE/authority"

FIXTURE="$FIXTURE" OPERATION="$OPERATION" php <<'PHP'
<?php
declare(strict_types=1);
$root = (string) getenv('FIXTURE');
$operation = (string) getenv('OPERATION');
$canonical = static fn (array $value): string => json_encode(
    $value,
    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
) . "\n";
$hash = static fn (string $value): string => 'sha256:' . hash('sha256', $value);
$pair = sodium_crypto_sign_keypair();
$publicKey = sodium_crypto_sign_publickey($pair);
$secretKey = sodium_crypto_sign_secretkey($pair);
$keyId = $hash($publicKey);
file_put_contents($root . '/authority/storage-ready.pub', $canonical([
    'schema_version' => 1,
    'key_id' => $keyId,
    'public_key' => base64_encode($publicKey),
]));
$targets = [];
foreach (['cert', 'demo', 'install', 'local_storage', 'public_storage', 'runtime_backup', 'uploads'] as $index => $artifact) {
    $digit = (string) ($index + 1);
    $targets[$artifact] = [
        'artifact' => $artifact,
        'docker_volume_id' => 'docker-' . $artifact,
        'marker_id' => str_repeat($digit, 8) . '-' . str_repeat($digit, 4) . '-4'
            . str_repeat($digit, 3) . '-8' . str_repeat($digit, 3) . '-' . str_repeat($digit, 12),
        'marker_sha256' => 'sha256:' . str_repeat($digit, 64),
        'expected_content_root' => 'sha256:' . str_repeat(dechex($index + 8), 64),
    ];
}
$retentionHash = 'sha256:' . str_repeat('a', 64);
$compositeHash = 'sha256:' . str_repeat('b', 64);
$expectedOldHash = $hash($canonical(['legacy-media']));
$intentBinding = [
    'schema_version' => 1,
    'purpose' => 'storage_bootstrap_local_setting_intent',
    'operation_id' => $operation,
    'retention_receipt_sha256' => $retentionHash,
    'expected_old_value_sha256' => $expectedOldHash,
    'canonical_value' => 'uploads',
];
$intentHash = $hash($canonical($intentBinding));
$authorization = [
    'schema_version' => 1,
    'purpose' => 'bootstrap_target_finalize',
    'key_id' => $keyId,
    'installation_storage_namespace' => 'mbs_target_publish_test',
    'migration_id' => $operation,
    'operation_id' => $operation,
    'layout_generation' => 1,
    'issued_authority_revision' => 3,
    'retention_receipt_sha256' => $retentionHash,
    'composite_receipt_sha256' => $compositeHash,
    'frozen_manifest_sha256' => 'sha256:' . str_repeat('c', 64),
    'target_policy_sha256' => 'sha256:' . str_repeat('d', 64),
    'local_setting_intent_sha256' => $intentHash,
    'targets' => $targets,
    'issued_at' => 1,
];
$authorization['signature'] = base64_encode(sodium_crypto_sign_detached($canonical($authorization), $secretKey));
$authorizationBytes = $canonical($authorization);
file_put_contents($root . '/authority/bootstrap-target-authority.json', $authorizationBytes);
$authorizationHash = $hash($authorizationBytes);
$intent = $intentBinding + [
    'local_setting_intent_sha256' => $intentHash,
    'target_authorization_sha256' => $authorizationHash,
];
file_put_contents($root . '/source/local-setting-intent.json', $canonical($intent));
$local = [
    'schema_version' => 1,
    'purpose' => 'storage_bootstrap_local_setting_receipt',
    'operation_id' => $operation,
    'retention_receipt_sha256' => $retentionHash,
    'local_setting_intent_sha256' => $intentHash,
    'expected_old_value_sha256' => $expectedOldHash,
    'canonical_value' => 'uploads',
    'target_authorization_sha256' => $authorizationHash,
    'effective_value_sha256' => $hash($canonical(['uploads'])),
    'complete' => true,
];
$localBytes = $canonical($local);
file_put_contents($root . '/source/local-setting.json', $localBytes);
$roots = [];
foreach ($targets as $artifact => $target) {
    $roots[$artifact] = $target['expected_content_root'];
}
$confirmationWithoutHash = [
    'operation_id' => $operation,
    'composite_receipt_sha256' => $compositeHash,
    'target_authorization_sha256' => $authorizationHash,
    'verified_target_roots' => $roots,
    'local_setting_receipt_sha256' => $hash($localBytes),
    'complete' => true,
];
$evidence = [
    'operation_id' => $operation,
    'confirmation_sha256' => $hash($canonical($confirmationWithoutHash)),
] + array_slice($confirmationWithoutHash, 1, null, true);
file_put_contents($root . '/source/confirmation.json', $canonical([
    'schema_version' => 1,
    'purpose' => 'storage_bootstrap_adopt_target_confirmation',
    'evidence' => $evidence,
]));
file_put_contents($root . '/source/.finalize.lock', '');
PHP

root_fixture 'set -eu
chown 2000:3000 /fixture/source /fixture/destination /fixture/authority
chmod 2770 /fixture/source /fixture/destination
chmod 0700 /fixture/authority
chown 2000:3000 /fixture/authority/*.json /fixture/authority/*.pub
chmod 0444 /fixture/authority/*.json /fixture/authority/*.pub
chown 10000:3000 /fixture/source/*.json
chmod 0640 /fixture/source/*.json
chown 10000:3000 /fixture/source/.finalize.lock
chmod 0640 /fixture/source/.finalize.lock'

run_publisher
run_publisher
root_fixture 'set -eu
for file in confirmation.json local-setting-intent.json local-setting.json; do
 [ "$(stat -c %u:%g:%a /fixture/destination/$file)" = "2000:3000:640" ]
 cmp /fixture/source/$file /fixture/destination/$file
done' || fail BOOTSTRAP_ADOPT_TEST_TARGET_PUBLISH_POLICY_INVALID

# Wrong PHP result ownership fails before any Agent-owned evidence is published.
root_fixture 'rm /fixture/destination/*.json
chown 2000:3000 /fixture/source/confirmation.json'
if run_publisher >"$FIXTURE/wrong-owner.log" 2>&1; then
    fail BOOTSTRAP_ADOPT_TEST_WRONG_TARGET_OWNER_ACCEPTED
fi
grep -q BOOTSTRAP_ADOPT_TARGET_OUTPUT_INVALID "$FIXTURE/wrong-owner.log" \
    || fail BOOTSTRAP_ADOPT_TEST_WRONG_TARGET_OWNER_ERROR_INVALID
[ -z "$(find "$FIXTURE/destination" -mindepth 1 -maxdepth 1 -print -quit)" ] \
    || fail BOOTSTRAP_ADOPT_TEST_WRONG_TARGET_OWNER_PUBLISHED

# Unclassified target output is also rejected before publication.
root_fixture 'chown 10000:3000 /fixture/source/confirmation.json
printf extra > /fixture/source/extra.txt
chown 10000:3000 /fixture/source/extra.txt
chmod 0640 /fixture/source/extra.txt'
if run_publisher >"$FIXTURE/extra.log" 2>&1; then
    fail BOOTSTRAP_ADOPT_TEST_EXTRA_TARGET_OUTPUT_ACCEPTED
fi
grep -q BOOTSTRAP_ADOPT_TARGET_OUTPUT_INVALID "$FIXTURE/extra.log" \
    || fail BOOTSTRAP_ADOPT_TEST_EXTRA_TARGET_OUTPUT_ERROR_INVALID
[ -z "$(find "$FIXTURE/destination" -mindepth 1 -maxdepth 1 -print -quit)" ] \
    || fail BOOTSTRAP_ADOPT_TEST_EXTRA_TARGET_OUTPUT_PUBLISHED

printf '%s\n' 'bootstrap retention target verification tests passed'
