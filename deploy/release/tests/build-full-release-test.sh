#!/bin/sh
set -eu

SCRIPT_DIR=$(CDPATH= cd -P "$(dirname "$0")" && pwd)
PROJECT_ROOT=$(CDPATH= cd -P "$SCRIPT_DIR/../../.." && pwd)
BUILDER=$PROJECT_ROOT/deploy/release/build-full-release.sh
ROOT=$(mktemp -d "${TMPDIR:-/tmp}/mallbase-release-test.XXXXXX")
trap 'rm -rf "$ROOT"' 0 HUP INT TERM

SOURCE=$ROOT/source
MOCK_BIN=$ROOT/mock-bin
OUTPUT_ONE=$ROOT/output-one
OUTPUT_TWO=$ROOT/output-two
RECEIPT_LOG=$ROOT/receipt-log
mkdir -p \
    "$SOURCE/upgrade/bin" \
    "$SOURCE/backend/app" \
    "$SOURCE/backend/public/admin" \
    "$SOURCE/backend/public/client" \
    "$SOURCE/backend/public/static/demo" \
    "$SOURCE/backend/runtime/cache" \
    "$SOURCE/backend/public/uploads" \
    "$SOURCE/frontend/admin/node_modules" \
    "$SOURCE/frontend/admin/.pnpm-store" \
    "$SOURCE/frontend/admin/.turbo" \
    "$SOURCE/frontend/admin/.cache" \
    "$SOURCE/frontend/admin/apps/web-antd/dist" \
    "$SOURCE/frontend/uniapp/node_modules" \
    "$SOURCE/frontend/uniapp/.cache" \
    "$SOURCE/frontend/uniapp/dist/build/h5" \
    "$SOURCE/data/mysql" \
    "$SOURCE/upgrade/backups" \
    "$SOURCE/deploy/docker" \
    "$SOURCE/deploy/release" \
    "$MOCK_BIN" \
    "$OUTPUT_ONE" \
    "$OUTPUT_TWO"

printf '%s\n' '{"version":"1.0.0","released_at":"2026-04-23 12:00:00","notes":[]}' > "$SOURCE/.version"
printf '%s\n' '<?php return [];' > "$SOURCE/backend/app/config.php"
printf '%s\n' 'SECRET=must-not-ship' > "$SOURCE/.env"
printf '%s\n' 'EXAMPLE=true' > "$SOURCE/.env.example"
printf '%s\n' 'SECRET=must-not-ship' > "$SOURCE/frontend/admin/.env.production"
printf '%s\n' stale > "$SOURCE/backend/public/admin/stale.js"
printf '%s\n' stale > "$SOURCE/backend/public/client/stale.js"
printf '%s\n' demo-runtime-data > "$SOURCE/backend/public/static/demo/README.md"
printf '%s\n' runtime > "$SOURCE/backend/runtime/cache/state"
printf '%s\n' upload > "$SOURCE/backend/public/uploads/file"
printf '%s\n' dependency > "$SOURCE/frontend/admin/node_modules/dependency"
printf '%s\n' pnpm-cache > "$SOURCE/frontend/admin/.pnpm-store/state"
printf '%s\n' turbo-cache > "$SOURCE/frontend/admin/.turbo/state"
printf '%s\n' generic-cache > "$SOURCE/frontend/admin/.cache/state"
printf '%s\n' old-dist > "$SOURCE/frontend/admin/apps/web-antd/dist/old.js"
printf '%s\n' old-dist-archive > "$SOURCE/frontend/admin/apps/web-antd/dist.zip"
printf '%s\n' old-dist-archive > "$SOURCE/frontend/admin/apps/web-antd/dist.tar"
printf '%s\n' old-dist-archive > "$SOURCE/frontend/admin/apps/web-antd/dist.tar.gz"
printf '%s\n' old-dist-archive > "$SOURCE/frontend/admin/apps/web-antd/dist.tgz"
printf '%s\n' dependency > "$SOURCE/frontend/uniapp/node_modules/dependency"
printf '%s\n' generic-cache > "$SOURCE/frontend/uniapp/.cache/state"
printf '%s\n' old-dist > "$SOURCE/frontend/uniapp/dist/build/h5/old.js"
printf '%s\n' database > "$SOURCE/data/mysql/state"
printf '%s\n' backup > "$SOURCE/upgrade/backups/state"
printf '%s\n' runtime-control-file > "$SOURCE/upgrade/.gitignore"
cp "$PROJECT_ROOT/deploy/docker/host-preflight.sh" "$SOURCE/deploy/docker/host-preflight.sh"
chmod 0775 "$SOURCE/deploy/docker/host-preflight.sh"
chmod 0775 "$SOURCE/backend" "$SOURCE/backend/app"
chmod 0664 "$SOURCE/backend/app/config.php"
printf '%s\n' release-tool > "$SOURCE/deploy/release/tool"
printf '%s\n' compose > "$SOURCE/docker-compose.frontend-build.yml"
printf '%s\n' compose > "$SOURCE/docker-compose.uniapp-build.yml"

printf '%s\n' amd64 > "$SOURCE/upgrade/bin/mallbase-agent-linux-amd64"
printf '%s\n' arm64 > "$SOURCE/upgrade/bin/mallbase-agent-linux-arm64"
mkdir -p "$SOURCE/upgrade/bin/active"
printf '%s\n' must-not-ship > "$SOURCE/upgrade/bin/active/mallbase-agent"
chmod 0755 "$SOURCE/upgrade/bin/mallbase-agent-linux-amd64" "$SOURCE/upgrade/bin/mallbase-agent-linux-arm64"
chmod 0755 "$SOURCE/upgrade/bin/active/mallbase-agent"
AMD64_SHA=$(shasum -a 256 "$SOURCE/upgrade/bin/mallbase-agent-linux-amd64" | awk '{print $1}')
ARM64_SHA=$(shasum -a 256 "$SOURCE/upgrade/bin/mallbase-agent-linux-arm64" | awk '{print $1}')
AMD64_SIZE=$(wc -c < "$SOURCE/upgrade/bin/mallbase-agent-linux-amd64" | tr -d ' ')
ARM64_SIZE=$(wc -c < "$SOURCE/upgrade/bin/mallbase-agent-linux-arm64" | tr -d ' ')
printf '%s  %s\n%s  %s\n' \
    "$AMD64_SHA" mallbase-agent-linux-amd64 \
    "$ARM64_SHA" mallbase-agent-linux-arm64 \
    > "$SOURCE/upgrade/bin/checksums.sha256"

cat > "$MOCK_BIN/git" <<'SH'
#!/bin/sh
set -eu
while [ "$#" -gt 0 ]; do
    case "$1" in
        -C) shift 2 ;;
        archive)
            tar -C "$MALLBASE_RELEASE_TEST_SOURCE" -cf - .
            exit 0
            ;;
        rev-parse)
            printf '%s\n' bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb
            exit 0
            ;;
        show)
            printf '%s\n' 1713830400
            exit 0
            ;;
        *) shift ;;
    esac
done
exit 2
SH

cat > "$MOCK_BIN/docker" <<'SH'
#!/bin/sh
set -eu
case "$*" in
    *down*) ;;
    *frontend-build*)
        mkdir -p backend/public/admin/assets
        printf '%s\n' '<html>fresh admin</html>' > backend/public/admin/index.html
        printf '%s\n' fresh > backend/public/admin/assets/app.js
        ;;
    *uniapp-build*)
        mkdir -p backend/public/client/assets
        printf '%s\n' '<html>fresh h5</html>' > backend/public/client/index.html
        printf '%s\n' fresh > backend/public/client/assets/app.js
        ;;
    *) exit 2 ;;
esac
SH
chmod 0755 "$MOCK_BIN/git" "$MOCK_BIN/docker"

cat > "$MOCK_BIN/release-packager" <<'SH'
#!/bin/sh
set -eu
[ "$#" -eq 5 ] && [ "$1" = frontend-receipt ] && [ "$2" = -root ] && [ "$4" = -artifact ] \
    || exit 2
root=$3
artifact=$5
if find "$root" -type f \( -name .env -o -name '.env.*' \) ! -name .env.example -print \
    | grep -q .; then
    printf '%s\n' FRONTEND_RECEIPT_ENV_NOT_CLEAN >&2
    exit 3
fi
[ -f "$root/.env.example" ] || exit 3
if [ -e "$root/backend/public/static/demo" ] || [ -e "$root/upgrade/.gitignore" ]; then
    printf '%s\n' FRONTEND_RECEIPT_RESERVED_PATH_NOT_CLEAN >&2
    exit 3
fi
if find "$root/frontend" -type d \( \
    -name node_modules -o -name .pnpm-store -o -name .turbo -o \
    -name .cache -o -name dist \
\) -print | grep -q .; then
    printf '%s\n' FRONTEND_RECEIPT_BUILD_DIRECTORY_NOT_CLEAN >&2
    exit 3
fi
if find "$root/frontend" -type f \( \
    -name dist.zip -o -name dist.tar -o -name dist.tar.gz -o -name dist.tgz \
\) -print | grep -q .; then
    printf '%s\n' FRONTEND_RECEIPT_BUILD_ARCHIVE_NOT_CLEAN >&2
    exit 3
fi
case "$artifact" in
    admin) receipt=$root/backend/public/admin/.mallbase-build-receipt.json ;;
    h5) receipt=$root/backend/public/client/.mallbase-build-receipt.json ;;
    *) exit 2 ;;
esac
printf '%s|%s|%s\n' "$1" "$2 $root" "$4 $artifact" >> "$MALLBASE_RELEASE_TEST_RECEIPT_LOG"
printf '%s\n' '{"fixture":"release-packager-owned"}' > "$receipt"
SH
chmod 0755 "$MOCK_BIN/release-packager"

run_builder() {
    output=$1
    PATH="$MOCK_BIN:$PATH" \
    MALLBASE_RELEASE_TEST_SOURCE="$SOURCE" \
    MALLBASE_RELEASE_TEST_RECEIPT_LOG="$RECEIPT_LOG" \
    RELEASE_PACKAGER_BIN="$MOCK_BIN/release-packager" \
        "$BUILDER" --version 1.0.0 --source-ref v1.0.0 --output-dir "$output"
}

if PATH="$MOCK_BIN:$PATH" RELEASE_PACKAGER_BIN= \
    "$BUILDER" --version 1.0.0 --source-ref v1.0.0 --output-dir "$ROOT/missing-packager" \
    >/dev/null 2>&1; then
    printf '%s\n' EXPECTED_RELEASE_PACKAGER_BIN_FAILURE >&2
    exit 1
fi

run_builder "$OUTPUT_ONE"
run_builder "$OUTPUT_TWO"

[ "$(wc -l < "$RECEIPT_LOG" | tr -d ' ')" -eq 4 ]
[ "$(grep -c '^frontend-receipt|-root .\+|-artifact admin$' "$RECEIPT_LOG")" -eq 2 ]
[ "$(grep -c '^frontend-receipt|-root .\+|-artifact h5$' "$RECEIPT_LOG")" -eq 2 ]

ZIP_ONE=$OUTPUT_ONE/mallbase-full-v1.0.0.zip
ZIP_TWO=$OUTPUT_TWO/mallbase-full-v1.0.0.zip
[ -f "$ZIP_ONE" ] && [ -f "$ZIP_ONE.sha256" ]
[ "$(shasum -a 256 "$ZIP_ONE" | awk '{print $1}')" = "$(shasum -a 256 "$ZIP_TWO" | awk '{print $1}')" ]
(cd "$OUTPUT_ONE" && shasum -a 256 -c mallbase-full-v1.0.0.zip.sha256)

LIST=$ROOT/archive-list
unzip -Z1 "$ZIP_ONE" > "$LIST"
python3 - "$ZIP_ONE" <<'PY'
import stat
import sys
import zipfile

archive = zipfile.ZipFile(sys.argv[1])

def mode(relative):
    info = archive.getinfo(f"mallbase-v1.0.0/{relative}")
    return stat.S_IMODE(info.external_attr >> 16)

assert mode("backend/app/") == 0o755
assert mode("backend/app/config.php") == 0o644
assert mode("deploy/docker/host-preflight.sh") == 0o755
assert mode("upgrade/") == 0o750
assert mode("upgrade/bin/") == 0o750
assert mode("upgrade/bin/mallbase-agent-linux-amd64") == 0o755
assert mode("upgrade/bin/mallbase-agent-linux-arm64") == 0o755
assert mode("upgrade/bin/checksums.sha256") == 0o644
assert mode("release-manifest.json") == 0o644
assert mode("release-files.sha256") == 0o644
PY
for required in \
    mallbase-v1.0.0/release-manifest.json \
    mallbase-v1.0.0/release-files.sha256 \
    mallbase-v1.0.0/backend/public/admin/index.html \
    mallbase-v1.0.0/backend/public/admin/.mallbase-build-receipt.json \
    mallbase-v1.0.0/backend/public/client/index.html \
    mallbase-v1.0.0/backend/public/client/.mallbase-build-receipt.json \
    mallbase-v1.0.0/upgrade/bin/mallbase-agent-linux-amd64 \
    mallbase-v1.0.0/upgrade/bin/mallbase-agent-linux-arm64; do
    grep -Fx "$required" "$LIST" >/dev/null
done
for forbidden in \
    stale.js .env.production backend/runtime backend/public/uploads \
    backend/public/static/demo upgrade/.gitignore \
    node_modules frontend/admin/apps/web-antd/dist frontend/uniapp/dist \
    data/mysql upgrade/backups deploy/release; do
    ! grep -F "$forbidden" "$LIST" >/dev/null
done
for reserved_path in backend/public/static/demo upgrade/.gitignore; do
    if grep -F "$reserved_path" "$LIST" >/dev/null; then
        printf '%s\n' "RESERVED_RELEASE_PATH_MUST_NOT_BE_ARCHIVED:$reserved_path" >&2
        exit 1
    fi
done
grep -Fx 'mallbase-v1.0.0/.env.example' "$LIST" >/dev/null
! grep -Fx 'mallbase-v1.0.0/.env' "$LIST" >/dev/null

EXTRACTED=$ROOT/extracted
mkdir -p "$EXTRACTED"
unzip -q "$ZIP_ONE" -d "$EXTRACTED"
python3 - "$EXTRACTED/mallbase-v1.0.0" <<'PY'
import stat
import sys
from pathlib import Path

root = Path(sys.argv[1])

def mode(relative):
    return stat.S_IMODE((root / relative).stat().st_mode)

assert mode("backend/app") == 0o755
assert mode("backend/app/config.php") == 0o644
assert mode("deploy/docker/host-preflight.sh") == 0o755
assert mode("upgrade/bin") == 0o750
assert mode("upgrade/bin/mallbase-agent-linux-amd64") == 0o755
assert mode("upgrade/bin/mallbase-agent-linux-arm64") == 0o755
assert mode("upgrade/bin/checksums.sha256") == 0o644
assert not (root / "upgrade/bin/active").exists()
PY
(cd "$EXTRACTED/mallbase-v1.0.0" && shasum -a 256 -c release-files.sha256)
for reserved_path in backend/public/static/demo upgrade/.gitignore; do
    if grep -F "$reserved_path" \
        "$EXTRACTED/mallbase-v1.0.0/release-files.sha256" >/dev/null; then
        printf '%s\n' "RESERVED_RELEASE_PATH_MUST_NOT_BE_CHECKSUMMED:$reserved_path" >&2
        exit 1
    fi
done
grep -F '"source_commit": "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb"' \
    "$EXTRACTED/mallbase-v1.0.0/release-manifest.json" >/dev/null
python3 - "$EXTRACTED/mallbase-v1.0.0/release-manifest.json" <<'PY'
import json
import sys
from pathlib import Path

document = json.loads(Path(sys.argv[1]).read_text(encoding="utf-8"))
assert document["frontend"].keys() == {
    "admin_path", "admin_receipt_path", "h5_path", "h5_receipt_path"
}
assert "miniapp" not in json.dumps(document).lower()
assembly_artifacts = document["agent"]["artifacts"]
assert [(item["os"], item["arch"]) for item in assembly_artifacts] == [
    ("linux", "amd64"), ("linux", "arm64")
]
assert [item["path"] for item in assembly_artifacts] == [
    "upgrade/bin/mallbase-agent-linux-amd64",
    "upgrade/bin/mallbase-agent-linux-arm64",
]
assert all(
    set(item) == {"os", "arch", "path", "size_bytes", "sha256"}
    for item in assembly_artifacts
)
assert "manifest_path" not in document["agent"]
PY

if sh "$EXTRACTED/mallbase-v1.0.0/deploy/docker/host-preflight.sh" \
    --check --project-root "$EXTRACTED/mallbase-v1.0.0" \
    >"$ROOT/preflight-check-before.out" 2>&1; then
    printf '%s\n' PREFLIGHT_CHECK_MUST_REJECT_ARCHIVE_MODES >&2
    exit 1
fi
grep -Fx HOST_PREFLIGHT_MODE_INVALID "$ROOT/preflight-check-before.out" >/dev/null

cat > "$MOCK_BIN/chown" <<'SH'
#!/bin/sh
set -eu
: "${MALLBASE_RELEASE_PREFLIGHT_CHOWN_LOG:?}"
printf '%s\n' "$@" >> "$MALLBASE_RELEASE_PREFLIGHT_CHOWN_LOG"
SH
chmod 0755 "$MOCK_BIN/chown"
PREFLIGHT_CHOWN_LOG=$ROOT/preflight-chown.log
: > "$PREFLIGHT_CHOWN_LOG"
PATH="$MOCK_BIN:$PATH" \
MALLBASE_RELEASE_PREFLIGHT_CHOWN_LOG="$PREFLIGHT_CHOWN_LOG" \
    sh "$EXTRACTED/mallbase-v1.0.0/deploy/docker/host-preflight.sh" \
        --project-root "$EXTRACTED/mallbase-v1.0.0" \
        | grep -Fx HOST_PREFLIGHT_OK >/dev/null
python3 - "$EXTRACTED/mallbase-v1.0.0" <<'PY'
import stat
import sys
from pathlib import Path

root = Path(sys.argv[1])

def mode(relative):
    return stat.S_IMODE((root / relative).stat().st_mode)

assert mode("backend/app") == 0o755
assert mode("backend/app/config.php") == 0o644
assert mode("deploy/docker/host-preflight.sh") == 0o755
assert mode("upgrade/bin") == 0o750
assert mode("upgrade/bin/mallbase-agent-linux-amd64") == 0o555
assert mode("upgrade/bin/mallbase-agent-linux-arm64") == 0o555
assert mode("upgrade/bin/checksums.sha256") == 0o444
assert mode("upgrade/bin/active") == 0o750
assert mode("upgrade/bin/active/mallbase-agent") == 0o755
PY
PATH="$MOCK_BIN:$PATH" \
MALLBASE_RELEASE_PREFLIGHT_CHOWN_LOG="$PREFLIGHT_CHOWN_LOG" \
    sh "$EXTRACTED/mallbase-v1.0.0/deploy/docker/host-preflight.sh" \
        --check --project-root "$EXTRACTED/mallbase-v1.0.0" \
        | grep -Fx HOST_PREFLIGHT_OK >/dev/null

! grep -F 'agent-manifest.json' "$LIST" >/dev/null
if grep -F 'upgrade/bin/active/' "$LIST" >/dev/null; then
    printf '%s\n' ACTIVE_AGENT_MUST_NOT_BE_RELEASED >&2
    exit 1
fi
! grep -E 'mp-weixin|mini[_-]?program' "$LIST" >/dev/null

printf '%s\n' BUILD_FULL_RELEASE_TEST_OK
