#!/bin/sh
set -eu

SCRIPT_DIR=$(CDPATH= cd -P "$(dirname "$0")" && pwd)
PROJECT_ROOT=$(CDPATH= cd -P "$SCRIPT_DIR/../.." && pwd)
TARGET=$PROJECT_ROOT/deploy/docker/build-sealed-image.sh
FIXTURE=

fail() {
    printf 'build-sealed-image test failed: %s\n' "$1" >&2
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
    FIXTURE=$(mktemp -d "${TMPDIR:-/tmp}/mallbase-sealed-build.XXXXXX")
    mkdir -p "$FIXTURE/upgrade/bin" "$FIXTURE/upgrade/agent-private" "$FIXTURE/tools"
    printf '1.0.0\n' > "$FIXTURE/.version"
    printf 'signed source placeholder\n' > "$FIXTURE/context.source"

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
receipt=bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb
seal=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
prefix=upgrade/agent-private/build-contexts/$receipt
case "${1-} ${2-}" in
    'seal-build-context create')
        mkdir -p "$prefix"
        printf 'sealed tar bytes\n' > "$prefix/context.tar"
        printf '%s' '0123456789abcdef0123456789abcdef' > "$prefix/challenge.secret"
        chmod 0400 "$prefix/context.tar" "$prefix/challenge.secret"
        tar_hash=$(if command -v sha256sum >/dev/null 2>&1; then sha256sum "$prefix/context.tar"; else shasum -a 256 "$prefix/context.tar"; fi | awk '{print $1}')
        tar_size=$(wc -c < "$prefix/context.tar" | tr -d ' ')
        attestation_hash=cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc
        receipt_json=$(printf '{"schema_version":1,"seal_id":"%s","receipt_id":"%s","tar_sha256":"%s","tar_size":%s,"attestation_sha256":"%s","deployment_id":"019f5b62-c6f0-7f1d-9b50-7cf79f3ec3a3","app_version":"1.0.0","release_inventory_sha256":"dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd","release_inventory_envelope_sha256":"sha256:eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee","deployment_marker_sha256":"sha256:ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff","active_provenance_sha256":"sha256:1111111111111111111111111111111111111111111111111111111111111111","active_revision":1,"storage_layout_version":1,"storage_layout_generation":1,"source_date_epoch":1,"entry_count":3}' "$seal" "$receipt" "$tar_hash" "$tar_size" "$attestation_hash")
        printf '%s' "$receipt_json" > "$prefix/receipt.json"
        chmod 0400 "$prefix/receipt.json"
        if [ "${FAKE_TAMPER_TAR-0}" = 1 ]; then
            chmod 0600 "$prefix/context.tar"
            printf 'tamper\n' >> "$prefix/context.tar"
            chmod 0400 "$prefix/context.tar"
        fi
        tar_name=build-contexts/$receipt/context.tar
        if [ "${FAKE_ESCAPE_PATH-0}" = 1 ]; then
            tar_name=../../context.source
        fi
        challenge=MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=
        lease=QUJDREVGR0hJSktMTU5PUFFSU1RVVldYWVowMTIzNDU=
        printf '{"seal_id":"%s","receipt_id":"%s","tar_name":"%s","receipt_name":"build-contexts/%s/receipt.json","challenge_name":"build-contexts/%s/challenge.secret","challenge":"%s","lease_token":"%s","receipt":%s}\n' \
            "$seal" "$receipt" "$tar_name" "$receipt" "$receipt" "$challenge" "$lease" "$receipt_json"
        ;;
    'seal-build-context record-image')
        input=$(cat)
        printf '%s' "$input" > "$prefix/record-input.json"
        chmod 0400 "$prefix/record-input.json"
        rm -f "$prefix/challenge.secret"
        printf '{"schema_version":1,"receipt_id":"%s","seal_id":"%s","image_id":"sha256:%s","config_digest":"sha256:%s"}\n' \
            "$receipt" "$seal" "$(printf '2%.0s' $(jot 64 1 64 2>/dev/null || seq 64))" "$(printf '2%.0s' $(jot 64 1 64 2>/dev/null || seq 64))"
        ;;
    *)
        printf '%s\n' AGENT_UNEXPECTED_COMMAND >&2
        exit 1
        ;;
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
case "${1-} ${2-}" in
    'build '*)
        cat >/dev/null
        ;;
    'image inspect')
        printf 'sha256:%s\n' "$(printf '2%.0s' $(jot 64 1 64 2>/dev/null || seq 64))"
        ;;
    *)
        printf '%s\n' DOCKER_UNEXPECTED_COMMAND >&2
        exit 1
        ;;
esac
SH
    chmod 0555 "$FIXTURE/tools/docker"
    : > "$FIXTURE/docker.log"
}

run_build() {
    PATH="$FIXTURE/tools:$PATH" DOCKER_LOG="$FIXTURE/docker.log" SOURCE_DATE_EPOCH=1 \
        sh "$TARGET" --project-root "$FIXTURE" "$@"
}

verify_attestation_gate() {
    challenge_file=$FIXTURE/challenge.secret
    marker_file=$FIXTURE/deployment.json
    attestation_file=$FIXTURE/attestation.json
    output_file=$FIXTURE/validated.ok
    printf '%s' '0123456789abcdef0123456789abcdef' > "$challenge_file"
    chmod 0600 "$challenge_file"
    CHALLENGE_FILE="$challenge_file" MARKER_FILE="$marker_file" ATTESTATION_FILE="$attestation_file" php -r '
        $marker = [
            "schema_version" => 1,
            "provenance_kind" => "fresh",
            "app_version" => "1.0.0",
            "deployment_id" => "019f5b62-c6f0-7f1d-9b50-7cf79f3ec3a3",
            "release_inventory_sha256" => str_repeat("d", 64),
            "storage_layout_version" => 1,
            "storage_layout_generation" => 1,
        ];
        $markerJson = json_encode($marker, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        file_put_contents(getenv("MARKER_FILE"), $markerJson);
        $payload = [
            "schema_version" => 1,
            "seal_id" => str_repeat("a", 32),
            "receipt_id" => str_repeat("b", 32),
            "app_version" => "1.0.0",
            "deployment_id" => "019f5b62-c6f0-7f1d-9b50-7cf79f3ec3a3",
            "release_inventory_sha256" => str_repeat("d", 64),
            "release_inventory_envelope_sha256" => "sha256:" . str_repeat("e", 64),
            "deployment_marker_sha256" => "sha256:" . hash("sha256", $markerJson),
            "active_provenance_sha256" => "sha256:" . str_repeat("f", 64),
            "active_revision" => 1,
            "source_date_epoch" => 1,
        ];
        $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $payload["challenge_hmac"] = hash_hmac("sha256", $payloadJson, file_get_contents(getenv("CHALLENGE_FILE")));
        file_put_contents(getenv("ATTESTATION_FILE"), json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    '
    php "$PROJECT_ROOT/deploy/docker/validate-sealed-attestation.php" verify-attestation \
        "$attestation_file" "$challenge_file" "$marker_file" \
        bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa "$output_file" \
        || fail 'valid attestation was rejected'
    [ -f "$output_file" ] && [ "$(stat -f '%Lp' "$output_file" 2>/dev/null || stat -c '%a' "$output_file")" = 444 ] \
        || fail 'validation marker mode is invalid'

    rm -f "$output_file"
    printf '%s' 'fedcba9876543210fedcba9876543210' > "$challenge_file"
    if php "$PROJECT_ROOT/deploy/docker/validate-sealed-attestation.php" verify-attestation \
        "$attestation_file" "$challenge_file" "$marker_file" \
        bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa "$output_file" \
        > "$FIXTURE/rejected.out" 2>&1; then
        fail 'wrong BuildKit challenge was accepted'
    fi
    [ ! -e "$output_file" ] || fail 'wrong challenge published a validation marker'
}

[ -f "$TARGET" ] || fail 'target script is missing'
prepare_fixture
verify_attestation_gate

output=$(run_build --tag mallbase/backend:test) || fail 'valid sealed build was rejected'
printf '%s\n' "$output" | grep -Eq '^MALLBASE_IMAGE_RECEIPT_ID=[0-9a-f]{32}$' \
    || fail 'receipt identifier was not returned'
printf '%s\n' "$output" | grep -F 'MDEyMzQ1' >/dev/null 2>&1 && fail 'challenge leaked to stdout'
grep -F -- '--secret id=mallbase_context_seal,src=' "$FIXTURE/docker.log" >/dev/null \
    || fail 'BuildKit secret was not used'
grep -F -- '--tag mallbase/backend:test' "$FIXTURE/docker.log" >/dev/null \
    || fail 'literal display tag was not passed to Docker'
grep -F 'MDEyMzQ1' "$FIXTURE/docker.log" >/dev/null 2>&1 && fail 'challenge leaked to Docker arguments'

: > "$FIXTURE/docker.log"
if run_build --tag 'mallbase/backend:test;touch-x' > "$FIXTURE/rejected.out" 2>&1; then
    fail 'unsafe tag was accepted'
fi
[ ! -s "$FIXTURE/docker.log" ] || fail 'Docker ran for an unsafe tag'

cleanup
FIXTURE=
prepare_fixture
if FAKE_TAMPER_TAR=1 run_build --tag mallbase/backend:tamper > "$FIXTURE/rejected.out" 2>&1; then
    fail 'tampered sealed tar was accepted'
fi
unset FAKE_TAMPER_TAR
[ ! -s "$FIXTURE/docker.log" ] || fail 'Docker ran for a tampered tar'

cleanup
FIXTURE=
prepare_fixture
if FAKE_ESCAPE_PATH=1 run_build --tag mallbase/backend:path > "$FIXTURE/rejected.out" 2>&1; then
    fail 'escaping Agent-private path was accepted'
fi
unset FAKE_ESCAPE_PATH
[ ! -s "$FIXTURE/docker.log" ] || fail 'Docker ran for an escaping path'

: > "$FIXTURE/docker.log"
if FAKE_HOST_OS=Darwin run_build --tag mallbase/backend:darwin > "$FIXTURE/rejected.out" 2>&1; then
    fail 'non-Linux sealed build was accepted'
fi
unset FAKE_HOST_OS
grep -F SEALED_HOST_OS_UNSUPPORTED "$FIXTURE/rejected.out" >/dev/null \
    || fail 'non-Linux sealed build did not return the stable error'
[ ! -s "$FIXTURE/docker.log" ] || fail 'Docker ran for a non-Linux sealed build'

printf '%s\n' 'build-sealed-image tests passed'
