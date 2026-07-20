#!/bin/sh
set -eu

SCRIPT_DIR=$(CDPATH= cd -P "$(dirname "$0")" && pwd)
PREFLIGHT=$SCRIPT_DIR/../host-preflight.sh
ROOT=$(mktemp -d "${TMPDIR:-/tmp}/mallbase-host-preflight-test.XXXXXX")
MOCK_BIN=$ROOT/mock-bin
CHOWN_LOG=$ROOT/chown.log
mkdir -p "$MOCK_BIN"
trap 'chmod -R u+rwx "$ROOT" 2>/dev/null || true; rm -rf "$ROOT"' 0 HUP INT TERM

cat > "$MOCK_BIN/chown" <<'SH'
#!/bin/sh
set -eu
: "${MALLBASE_PREFLIGHT_CHOWN_LOG:?}"
printf '%s\n' "$@" >> "$MALLBASE_PREFLIGHT_CHOWN_LOG"
SH
chmod 0755 "$MOCK_BIN/chown"

sha256_file() {
    if command -v sha256sum >/dev/null 2>&1; then
        sha256sum "$1" | awk '{print $1}'
        return
    fi
    shasum -a 256 "$1" | awk '{print $1}'
}

mode_of() {
    if stat -f '%Lp' "$1" >/dev/null 2>&1; then
        stat -f '%Lp' "$1"
        return
    fi
    stat -c '%a' "$1"
}

write_release_inventory() {
    project=$1
    : > "$project/release-files.sha256"
    for relative in \
        .version \
        backend/app/release.php \
        backend/app/worker.sh \
        upgrade/bin/checksums.sha256 \
        upgrade/bin/mallbase-agent-linux-amd64 \
        upgrade/bin/mallbase-agent-linux-arm64; do
        printf '%s  %s\n' "$(sha256_file "$project/$relative")" "$relative" \
            >> "$project/release-files.sha256"
    done
}

make_fixture() {
    project=$1
    mkdir -p \
        "$project/backend/app" \
        "$project/backend/runtime" \
        "$project/backend/public/uploads" \
        "$project/data/mysql" \
        "$project/upgrade/bin" \
        "$project/upgrade/backups"
    printf '%s\n' '{"version":"1.0.0"}' > "$project/.version"
    printf '%s\n' managed-v1 > "$project/backend/app/release.php"
    printf '%s\n' '#!/bin/sh' 'exit 0' > "$project/backend/app/worker.sh"
    printf '%s\n' runtime-state > "$project/backend/runtime/user-state"
    printf '%s\n' upload-state > "$project/backend/public/uploads/user-file"
    printf '%s\n' database-state > "$project/data/mysql/user.db"
    printf '%s\n' backup-state > "$project/upgrade/backups/user.sql"
    printf '%s\n' amd64 > "$project/upgrade/bin/mallbase-agent-linux-amd64"
    printf '%s\n' arm64 > "$project/upgrade/bin/mallbase-agent-linux-arm64"
    printf '%s  %s\n%s  %s\n' \
        "$(sha256_file "$project/upgrade/bin/mallbase-agent-linux-amd64")" \
        mallbase-agent-linux-amd64 \
        "$(sha256_file "$project/upgrade/bin/mallbase-agent-linux-arm64")" \
        mallbase-agent-linux-arm64 \
        > "$project/upgrade/bin/checksums.sha256"
    chmod 0755 \
        "$project/upgrade/bin/mallbase-agent-linux-amd64" \
        "$project/upgrade/bin/mallbase-agent-linux-arm64"
    chmod 0644 "$project/upgrade/bin/checksums.sha256"
    write_release_inventory "$project"
    chmod 0775 "$project" "$project/backend" "$project/backend/app"
    chmod 0664 "$project/backend/app/release.php"
    chmod 0775 "$project/backend/app/worker.sh"
    chmod 0600 \
        "$project/backend/runtime/user-state" \
        "$project/backend/public/uploads/user-file" \
        "$project/data/mysql/user.db" \
        "$project/upgrade/backups/user.sql"
}

run_preflight() {
    project=$1
    PATH="$MOCK_BIN:$PATH" \
    MALLBASE_PREFLIGHT_CHOWN_LOG="$CHOWN_LOG" \
        sh "$PREFLIGHT" --project-root "$project"
}

expect_failure() {
    expected=$1
    project=$2
    if output=$(run_preflight "$project" 2>&1); then
        printf '%s\n' "EXPECTED_FAILURE:$expected" >&2
        exit 1
    fi
    printf '%s\n' "$output" | grep -Fx "$expected" >/dev/null
}

PROJECT=$ROOT/valid
make_fixture "$PROJECT"
mkdir -p "$PROJECT/upgrade/bin/active"
printf '%s\n' legacy-agent > "$PROJECT/upgrade/bin/active/mallbase-agent"
chmod 0755 "$PROJECT/upgrade/bin/active/mallbase-agent"
PROJECT=$(CDPATH= cd -P "$PROJECT" && pwd)
runtime_hash=$(sha256_file "$PROJECT/backend/runtime/user-state")
upload_hash=$(sha256_file "$PROJECT/backend/public/uploads/user-file")
database_hash=$(sha256_file "$PROJECT/data/mysql/user.db")
backup_hash=$(sha256_file "$PROJECT/upgrade/backups/user.sql")
: > "$CHOWN_LOG"
[ "$(mode_of "$PROJECT/upgrade/bin/checksums.sha256")" = 644 ]
[ "$(mode_of "$PROJECT/upgrade/bin/mallbase-agent-linux-amd64")" = 755 ]
[ "$(mode_of "$PROJECT/upgrade/bin/mallbase-agent-linux-arm64")" = 755 ]
run_preflight "$PROJECT" >/dev/null

[ "$(mode_of "$PROJECT")" = 755 ]
[ "$(mode_of "$PROJECT/backend")" = 755 ]
[ "$(mode_of "$PROJECT/backend/app")" = 755 ]
[ "$(mode_of "$PROJECT/backend/app/release.php")" = 644 ]
[ "$(mode_of "$PROJECT/backend/app/worker.sh")" = 755 ]
[ "$(mode_of "$PROJECT/release-files.sha256")" = 644 ]
[ "$(mode_of "$PROJECT/upgrade")" = 750 ]
[ "$(mode_of "$PROJECT/upgrade/bin")" = 750 ]
[ "$(mode_of "$PROJECT/upgrade/bin/checksums.sha256")" = 444 ]
[ "$(mode_of "$PROJECT/upgrade/bin/mallbase-agent-linux-amd64")" = 555 ]
[ "$(mode_of "$PROJECT/upgrade/bin/mallbase-agent-linux-arm64")" = 555 ]
[ "$(mode_of "$PROJECT/upgrade/bin/active")" = 750 ]
[ "$(mode_of "$PROJECT/upgrade/bin/active/mallbase-agent")" = 755 ]
case "$(uname -m)" in
    x86_64|amd64) expected_agent=mallbase-agent-linux-amd64 ;;
    aarch64|arm64) expected_agent=mallbase-agent-linux-arm64 ;;
    *) exit 1 ;;
esac
[ "$(sha256_file "$PROJECT/upgrade/bin/active/mallbase-agent")" = \
    "$(sha256_file "$PROJECT/upgrade/bin/$expected_agent")" ]

grep -Fx "$PROJECT" "$CHOWN_LOG" >/dev/null
grep -Fx "$PROJECT/backend/app" "$CHOWN_LOG" >/dev/null
grep -Fx "$PROJECT/backend/app/release.php" "$CHOWN_LOG" >/dev/null
for user_file in \
    "$PROJECT/backend/runtime/user-state" \
    "$PROJECT/backend/public/uploads/user-file" \
    "$PROJECT/data/mysql/user.db" \
    "$PROJECT/upgrade/backups/user.sql"; do
    if grep -Fx "$user_file" "$CHOWN_LOG" >/dev/null; then
        printf '%s\n' "RUNTIME_FILE_CHOWNED:$user_file" >&2
        exit 1
    fi
    [ "$(mode_of "$user_file")" = 600 ]
done
[ "$runtime_hash" = "$(sha256_file "$PROJECT/backend/runtime/user-state")" ]
[ "$upload_hash" = "$(sha256_file "$PROJECT/backend/public/uploads/user-file")" ]
[ "$database_hash" = "$(sha256_file "$PROJECT/data/mysql/user.db")" ]
[ "$backup_hash" = "$(sha256_file "$PROJECT/upgrade/backups/user.sql")" ]

PATH="$MOCK_BIN:$PATH" MALLBASE_PREFLIGHT_CHOWN_LOG="$CHOWN_LOG" \
    sh "$PREFLIGHT" --check --project-root "$PROJECT" >/dev/null

temporary=$(mktemp "$PROJECT/backend/app/.release.XXXXXX")
printf '%s\n' managed-v2 > "$temporary"
mv "$temporary" "$PROJECT/backend/app/release.php"
grep -Fx managed-v2 "$PROJECT/backend/app/release.php" >/dev/null

MISSING=$ROOT/missing
make_fixture "$MISSING"
chmod u+w "$MISSING"
rm "$MISSING/release-files.sha256"
expect_failure HOST_PREFLIGHT_RELEASE_INVENTORY_MISSING "$MISSING"

TRAVERSAL=$ROOT/traversal
make_fixture "$TRAVERSAL"
chmod u+w "$TRAVERSAL"
printf '%064d  %s\n' 0 ../escape > "$TRAVERSAL/release-files.sha256"
expect_failure HOST_PREFLIGHT_RELEASE_INVENTORY_INVALID "$TRAVERSAL"

RUNTIME=$ROOT/runtime-entry
make_fixture "$RUNTIME"
chmod u+w "$RUNTIME"
printf '%s  %s\n' "$(sha256_file "$RUNTIME/data/mysql/user.db")" data/mysql/user.db \
    > "$RUNTIME/release-files.sha256"
expect_failure HOST_PREFLIGHT_RELEASE_INVENTORY_INVALID "$RUNTIME"

SYMLINK=$ROOT/symlink
make_fixture "$SYMLINK"
chmod u+w "$SYMLINK" "$SYMLINK/backend/app"
ln -s ../../.version "$SYMLINK/backend/app/link"
printf '%s  %s\n' "$(sha256_file "$SYMLINK/.version")" backend/app/link \
    > "$SYMLINK/release-files.sha256"
expect_failure HOST_PREFLIGHT_RELEASE_INVENTORY_INVALID "$SYMLINK"

ACTIVE_HARDLINK=$ROOT/active-hardlink
make_fixture "$ACTIVE_HARDLINK"
mkdir -p "$ACTIVE_HARDLINK/upgrade/bin/active"
printf '%s\n' linked-agent > "$ROOT/external-agent"
ln "$ROOT/external-agent" "$ACTIVE_HARDLINK/upgrade/bin/active/mallbase-agent"
expect_failure AGENT_LAUNCHER_INVALID "$ACTIVE_HARDLINK"

printf '%s\n' HOST_PREFLIGHT_TEST_OK
