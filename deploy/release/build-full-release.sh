#!/bin/sh
set -eu

SCRIPT_DIR=$(CDPATH= cd -P "$(dirname "$0")" && pwd)
PROJECT_ROOT=$(CDPATH= cd -P "$SCRIPT_DIR/../.." && pwd)
VERSION=
OUTPUT_DIR=
SOURCE_REF=
RELEASE_PACKAGER_BIN=${RELEASE_PACKAGER_BIN:-}
WORK_ROOT=
SOURCE_TREE=
ADMIN_COMPOSE_ACTIVE=0
H5_COMPOSE_ACTIVE=0
ADMIN_PROJECT=
H5_PROJECT=
ZIP_TEMP=
CHECKSUM_TEMP=

fail() {
    printf '%s\n' "$1" >&2
    exit 1
}

usage() {
    printf '%s\n' 'usage: build-full-release.sh --version X.Y.Z --output-dir PATH [--source-ref REF]' >&2
    exit 2
}

cleanup() {
    if [ -n "$SOURCE_TREE" ] && [ -d "$SOURCE_TREE" ]; then
        if [ "$ADMIN_COMPOSE_ACTIVE" -eq 1 ]; then
            (
                cd "$SOURCE_TREE"
                MALLBASE_COMPOSE_PROJECT_NAME=$ADMIN_PROJECT \
                    MALLBASE_CONTAINER_PREFIX=$ADMIN_PROJECT \
                    docker compose -f docker-compose.frontend-build.yml down -v --remove-orphans
            ) >/dev/null 2>&1 || true
        fi
        if [ "$H5_COMPOSE_ACTIVE" -eq 1 ]; then
            (
                cd "$SOURCE_TREE"
                MALLBASE_COMPOSE_PROJECT_NAME=$H5_PROJECT \
                    MALLBASE_CONTAINER_PREFIX=$H5_PROJECT \
                    docker compose -f docker-compose.uniapp-build.yml down -v --remove-orphans
            ) >/dev/null 2>&1 || true
        fi
    fi
    [ -z "$ZIP_TEMP" ] || rm -f "$ZIP_TEMP"
    [ -z "$CHECKSUM_TEMP" ] || rm -f "$CHECKSUM_TEMP"
    [ -z "$WORK_ROOT" ] || rm -rf "$WORK_ROOT"
}
trap cleanup 0
trap 'exit 130' HUP INT TERM

while [ "$#" -gt 0 ]; do
    case "$1" in
        --version)
            [ "$#" -ge 2 ] || usage
            VERSION=$2
            shift 2
            ;;
        --output-dir)
            [ "$#" -ge 2 ] || usage
            OUTPUT_DIR=$2
            shift 2
            ;;
        --source-ref)
            [ "$#" -ge 2 ] || usage
            SOURCE_REF=$2
            shift 2
            ;;
        *) usage ;;
    esac
done

[ -n "$VERSION" ] && [ -n "$OUTPUT_DIR" ] || usage
[ -n "$SOURCE_REF" ] || SOURCE_REF=v$VERSION
[ -n "$RELEASE_PACKAGER_BIN" ] || fail RELEASE_PACKAGER_BIN_REQUIRED
case "$RELEASE_PACKAGER_BIN" in
    /*) ;;
    *) fail RELEASE_PACKAGER_BIN_INVALID ;;
esac
[ -f "$RELEASE_PACKAGER_BIN" ] && [ -x "$RELEASE_PACKAGER_BIN" ] \
    || fail RELEASE_PACKAGER_BIN_INVALID

for command_name in git docker python3 tar; do
    command -v "$command_name" >/dev/null 2>&1 || fail RELEASE_DEPENDENCY_MISSING_$command_name
done

mkdir -p "$OUTPUT_DIR"
[ -d "$OUTPUT_DIR" ] && [ ! -L "$OUTPUT_DIR" ] || fail RELEASE_OUTPUT_DIRECTORY_INVALID
OUTPUT_DIR=$(CDPATH= cd -P "$OUTPUT_DIR" && pwd)

SOURCE_COMMIT=$(git -C "$PROJECT_ROOT" rev-parse --verify "$SOURCE_REF^{commit}") \
    || fail RELEASE_SOURCE_REF_INVALID
SOURCE_EPOCH=$(git -C "$PROJECT_ROOT" show -s --format=%ct "$SOURCE_REF") \
    || fail RELEASE_SOURCE_REF_INVALID

WORK_ROOT=$(mktemp -d "${TMPDIR:-/tmp}/mallbase-full-release.XXXXXX")
SOURCE_TREE=$WORK_ROOT/source
SOURCE_ARCHIVE=$WORK_ROOT/source.tar
PACKAGE_ROOT=$WORK_ROOT/mallbase-v$VERSION
mkdir -p "$SOURCE_TREE"

git -C "$PROJECT_ROOT" archive --format=tar "$SOURCE_REF" > "$SOURCE_ARCHIVE" \
    || fail RELEASE_SOURCE_EXPORT_FAILED
tar -xf "$SOURCE_ARCHIVE" -C "$SOURCE_TREE"
rm -f "$SOURCE_ARCHIVE"

python3 - "$SOURCE_TREE/.version" "$VERSION" "$SOURCE_COMMIT" "$SOURCE_EPOCH" <<'PY'
import json
import re
import sys
from pathlib import Path

version_path = Path(sys.argv[1])
expected_version = sys.argv[2]
source_commit = sys.argv[3]
source_epoch = sys.argv[4]
if re.fullmatch(r"(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)", expected_version) is None:
    raise SystemExit("RELEASE_VERSION_INVALID")
if re.fullmatch(r"[0-9a-f]{40}", source_commit) is None or not source_epoch.isdigit():
    raise SystemExit("RELEASE_SOURCE_METADATA_INVALID")
try:
    document = json.loads(version_path.read_text(encoding="utf-8"))
except (OSError, json.JSONDecodeError):
    raise SystemExit("RELEASE_VERSION_FILE_INVALID")
if not isinstance(document, dict) or document.get("version") != expected_version:
    raise SystemExit("RELEASE_VERSION_MISMATCH")
PY

# 发布目录必须由本次容器构建从空目录写入，不能继承归档或宿主机中的历史静态产物。
rm -rf "$SOURCE_TREE/backend/public/admin" "$SOURCE_TREE/backend/public/client"
mkdir -p "$SOURCE_TREE/backend/public/admin" "$SOURCE_TREE/backend/public/client"

PROJECT_SUFFIX=$(printf '%s' "$SOURCE_COMMIT" | cut -c1-12)
ADMIN_PROJECT=mallbase-release-admin-$PROJECT_SUFFIX-$$
H5_PROJECT=mallbase-release-h5-$PROJECT_SUFFIX-$$

ADMIN_COMPOSE_ACTIVE=1
(
    cd "$SOURCE_TREE"
    MALLBASE_COMPOSE_PROJECT_NAME=$ADMIN_PROJECT \
        MALLBASE_CONTAINER_PREFIX=$ADMIN_PROJECT \
        docker compose -f docker-compose.frontend-build.yml run --rm frontend-build
) || fail RELEASE_ADMIN_BUILD_FAILED

H5_COMPOSE_ACTIVE=1
(
    cd "$SOURCE_TREE"
    MALLBASE_COMPOSE_PROJECT_NAME=$H5_PROJECT \
        MALLBASE_CONTAINER_PREFIX=$H5_PROJECT \
        docker compose -f docker-compose.uniapp-build.yml run --rm uniapp-build
) || fail RELEASE_H5_BUILD_FAILED

for publish_root in "$SOURCE_TREE/backend/public/admin" "$SOURCE_TREE/backend/public/client"; do
    [ -s "$publish_root/index.html" ] || fail RELEASE_FRONTEND_OUTPUT_MISSING
    [ "$(find "$publish_root" -type f | wc -l | tr -d ' ')" -ge 2 ] \
        || fail RELEASE_FRONTEND_OUTPUT_INCOMPLETE
done

# 前端构建允许读取归档内的构建环境文件，但回执必须只绑定最终会发布的源码树。
# 先安全删除普通环境文件；符号链接或其他异常类型保留给 release-packager fail closed。
find "$SOURCE_TREE" -type f \( -name .env -o -name '.env.*' \) ! -name .env.example \
    -exec rm -f -- {} +

# 回执格式与内容哈希由 Platform 的 release-packager 唯一生成，MallBase 只消费固定命令边界。
"$RELEASE_PACKAGER_BIN" frontend-receipt -root "$SOURCE_TREE" -artifact admin \
    || fail RELEASE_ADMIN_RECEIPT_FAILED
"$RELEASE_PACKAGER_BIN" frontend-receipt -root "$SOURCE_TREE" -artifact h5 \
    || fail RELEASE_H5_RECEIPT_FAILED
for receipt_path in \
    "$SOURCE_TREE/backend/public/admin/.mallbase-build-receipt.json" \
    "$SOURCE_TREE/backend/public/client/.mallbase-build-receipt.json"; do
    [ -f "$receipt_path" ] && [ ! -L "$receipt_path" ] && [ -s "$receipt_path" ] \
        || fail RELEASE_FRONTEND_RECEIPT_MISSING
done

(
    cd "$SOURCE_TREE"
    MALLBASE_COMPOSE_PROJECT_NAME=$ADMIN_PROJECT \
        MALLBASE_CONTAINER_PREFIX=$ADMIN_PROJECT \
        docker compose -f docker-compose.frontend-build.yml down -v --remove-orphans
) >/dev/null 2>&1 || true
ADMIN_COMPOSE_ACTIVE=0
(
    cd "$SOURCE_TREE"
    MALLBASE_COMPOSE_PROJECT_NAME=$H5_PROJECT \
        MALLBASE_CONTAINER_PREFIX=$H5_PROJECT \
        docker compose -f docker-compose.uniapp-build.yml down -v --remove-orphans
) >/dev/null 2>&1 || true
H5_COMPOSE_ACTIVE=0

mv "$SOURCE_TREE" "$PACKAGE_ROOT"
SOURCE_TREE=

python3 - "$PACKAGE_ROOT" "$VERSION" "$SOURCE_COMMIT" "$SOURCE_EPOCH" "${MALLBASE_AGENT_VERSION:-$VERSION}" <<'PY'
import datetime as dt
import hashlib
import json
import os
import re
import shutil
import stat
import sys
from pathlib import Path

root = Path(sys.argv[1])
version = sys.argv[2]
source_commit = sys.argv[3]
source_epoch = int(sys.argv[4])
agent_version = sys.argv[5]

excluded = [
    ".git", ".github", ".gitee", ".codex/work", "output", "data",
    "backend/vendor", "backend/runtime", "backend/public/uploads",
    "backend/public/storage", "backend/storage/cert",
    "upgrade/config", "upgrade/run", "upgrade/jobs", "upgrade/packages",
    "upgrade/staging", "upgrade/backups", "upgrade/agent-private", "upgrade/bin/active",
    "frontend/admin/node_modules", "frontend/admin/.pnpm-store", "frontend/admin/.turbo",
    "frontend/uniapp/node_modules", "frontend/uniapp/dist", "frontend/uniapp/unpackage",
    "deploy/release",
]
for relative in excluded:
    path = root / relative
    if path.is_symlink() or path.is_file():
        path.unlink()
    elif path.is_dir():
        shutil.rmtree(path)

for path in sorted(root.rglob("*"), key=lambda item: len(item.parts), reverse=True):
    if path.is_dir() and (
        path.name in {"node_modules", ".pnpm-store", ".turbo", ".cache"}
        or ("frontend" in path.parts and path.name == "dist")
    ):
        shutil.rmtree(path)

for path in list(root.rglob("*")):
    if path.is_file() and "frontend" in path.parts and path.name in {
        "dist.zip", "dist.tar", "dist.tar.gz", "dist.tgz",
    }:
        path.unlink()

for path in list(root.rglob("*")):
    if path.is_file() and path.name != ".env.example" and (
        path.name == ".env" or path.name.startswith(".env.")
    ):
        path.unlink()

for path in root.rglob("*"):
    if path.is_symlink():
        raise SystemExit(f"RELEASE_SYMLINK_FORBIDDEN:{path.relative_to(root).as_posix()}")
    if "release-packager" in path.name.lower():
        raise SystemExit("RELEASE_PACKAGER_FORBIDDEN")

# 完整发布包先收敛为 Agent securefs 可接受的归档模式；宿主安装态由 host-preflight 再收紧。
for path in [root, *root.rglob("*")]:
    relative = path.relative_to(root).as_posix() if path != root else "."
    if path.is_dir():
        mode = 0o750 if relative in {"upgrade", "upgrade/bin"} else 0o755
    elif path.is_file():
        if relative == "upgrade/bin/checksums.sha256":
            mode = 0o644
        elif relative in {
            "upgrade/bin/mallbase-agent-linux-amd64",
            "upgrade/bin/mallbase-agent-linux-arm64",
        }:
            mode = 0o755
        else:
            mode = 0o755 if stat.S_IMODE(path.stat().st_mode) & 0o111 else 0o644
    else:
        raise SystemExit("RELEASE_ENTRY_INVALID")
    path.chmod(mode)

version_path = root / ".version"
try:
    version_document = json.loads(version_path.read_text(encoding="utf-8"))
except (OSError, json.JSONDecodeError):
    raise SystemExit("RELEASE_VERSION_FILE_INVALID")
if not isinstance(version_document, dict) or version_document.get("version") != version:
    raise SystemExit("RELEASE_VERSION_MISMATCH")
released_at = version_document.get("released_at", "")
notes = version_document.get("notes", [])
if not isinstance(released_at, str) or not isinstance(notes, list) or not all(isinstance(note, str) for note in notes):
    raise SystemExit("RELEASE_VERSION_FILE_INVALID")

bin_root = root / "upgrade/bin"
checksums_path = bin_root / "checksums.sha256"
if re.fullmatch(r"(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)", agent_version) is None:
    raise SystemExit("AGENT_VERSION_INVALID")

try:
    checksum_lines = checksums_path.read_text(encoding="utf-8").splitlines()
except OSError:
    raise SystemExit("AGENT_BINARY_CHECKSUM_INVALID")
checksums = {}
for line in checksum_lines:
    match = re.fullmatch(r"([0-9a-f]{64})  (mallbase-agent-linux-(?:amd64|arm64))", line)
    if match is None or match.group(2) in checksums:
        raise SystemExit("AGENT_BINARY_CHECKSUM_INVALID")
    checksums[match.group(2)] = match.group(1)

expected = [
    ("mallbase-agent-linux-amd64", "amd64"),
    ("mallbase-agent-linux-arm64", "arm64"),
]
if list(checksums) != [item[0] for item in expected]:
    raise SystemExit("AGENT_BINARY_CHECKSUM_INVALID")
# 这里只生成完整 ZIP 的外层组装元数据；Agent 运行时使用 Platform schema v2 的 files[]。
agent_artifacts = []
for filename, architecture in expected:
    binary = bin_root / filename
    if not binary.is_file() or binary.is_symlink() or not os.access(binary, os.X_OK):
        raise SystemExit("AGENT_BINARY_MISSING")
    digest = hashlib.sha256(binary.read_bytes()).hexdigest()
    if checksums[filename] != digest:
        raise SystemExit("AGENT_BINARY_CHECKSUM_INVALID")
    agent_artifacts.append({
        "os": "linux",
        "arch": architecture,
        "path": f"upgrade/bin/{filename}",
        "size_bytes": binary.stat().st_size,
        "sha256": digest,
    })

for relative in ["backend/public/admin", "backend/public/client"]:
    output = root / relative
    if not (output / "index.html").is_file() or len([path for path in output.rglob("*") if path.is_file()]) < 2:
        raise SystemExit("RELEASE_FRONTEND_OUTPUT_INCOMPLETE")
for relative in [
    "backend/public/admin/.mallbase-build-receipt.json",
    "backend/public/client/.mallbase-build-receipt.json",
]:
    receipt = root / relative
    if not receipt.is_file() or receipt.is_symlink() or receipt.stat().st_size == 0:
        raise SystemExit("RELEASE_FRONTEND_RECEIPT_MISSING")

created_at = dt.datetime.fromtimestamp(source_epoch, tz=dt.timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")
release_manifest = {
    "schema_version": 1,
    "app_code": "mallbase",
    "version": version,
    "released_at": released_at,
    "release_notes": notes,
    "source_commit": source_commit,
    "source_date_epoch": source_epoch,
    "created_at": created_at,
    "frontend": {
        "admin_path": "backend/public/admin",
        "admin_receipt_path": "backend/public/admin/.mallbase-build-receipt.json",
        "h5_path": "backend/public/client",
        "h5_receipt_path": "backend/public/client/.mallbase-build-receipt.json",
    },
    "agent": {
        "version": agent_version,
        "artifacts": agent_artifacts,
    },
}
release_manifest_path = root / "release-manifest.json"
release_manifest_path.write_text(
    json.dumps(release_manifest, ensure_ascii=False, indent=2, sort_keys=True) + "\n",
    encoding="utf-8",
)
release_manifest_path.chmod(0o644)

files = []
for path in root.rglob("*"):
    if path.is_file() and path.name != "release-files.sha256":
        relative = path.relative_to(root).as_posix()
        if "\n" in relative or "\r" in relative:
            raise SystemExit("RELEASE_PATH_INVALID")
        files.append((relative, hashlib.sha256(path.read_bytes()).hexdigest()))
files.sort(key=lambda item: item[0])
release_files_path = root / "release-files.sha256"
release_files_path.write_text(
    "".join(f"{digest}  {relative}\n" for relative, digest in files),
    encoding="utf-8",
)
release_files_path.chmod(0o644)
PY

ZIP_NAME=mallbase-full-v$VERSION.zip
ZIP_PATH=$OUTPUT_DIR/$ZIP_NAME
ZIP_TEMP=$OUTPUT_DIR/.$ZIP_NAME.$$.tmp

python3 - "$PACKAGE_ROOT" "$ZIP_TEMP" "$SOURCE_EPOCH" <<'PY'
import os
import stat
import sys
import time
import zipfile
from pathlib import Path

root = Path(sys.argv[1])
output = Path(sys.argv[2])
epoch = max(315532800, min(int(sys.argv[3]), 4354819198))
timestamp = time.gmtime(epoch)[:6]

def info(name, mode, is_directory):
    entry = zipfile.ZipInfo(name, timestamp)
    entry.create_system = 3
    entry.compress_type = zipfile.ZIP_STORED
    file_type = stat.S_IFDIR if is_directory else stat.S_IFREG
    entry.external_attr = ((file_type | mode) & 0xFFFF) << 16
    if is_directory:
        entry.external_attr |= 0x10
    return entry

entries = [root] + sorted(root.rglob("*"), key=lambda path: path.relative_to(root.parent).as_posix())
with zipfile.ZipFile(output, "w", allowZip64=True) as archive:
    for path in entries:
        relative = path.relative_to(root.parent).as_posix()
        if path.is_dir():
            mode = stat.S_IMODE(path.stat().st_mode)
            archive.writestr(info(relative.rstrip("/") + "/", mode, True), b"")
            continue
        if path.is_symlink() or not path.is_file():
            raise SystemExit("RELEASE_ENTRY_INVALID")
        mode = stat.S_IMODE(path.stat().st_mode)
        archive.writestr(info(relative, mode, False), path.read_bytes())
PY

mv -f "$ZIP_TEMP" "$ZIP_PATH"
ZIP_TEMP=
CHECKSUM_NAME=$ZIP_NAME.sha256
CHECKSUM_PATH=$OUTPUT_DIR/$CHECKSUM_NAME
CHECKSUM_TEMP=$OUTPUT_DIR/.$CHECKSUM_NAME.$$.tmp
python3 - "$ZIP_PATH" "$ZIP_NAME" "$CHECKSUM_TEMP" <<'PY'
import hashlib
import sys
from pathlib import Path

path = Path(sys.argv[1])
name = sys.argv[2]
output = Path(sys.argv[3])
output.write_text(f"{hashlib.sha256(path.read_bytes()).hexdigest()}  {name}\n", encoding="utf-8")
PY
mv -f "$CHECKSUM_TEMP" "$CHECKSUM_PATH"
CHECKSUM_TEMP=

printf '%s\n' "$ZIP_PATH"
printf '%s\n' "$CHECKSUM_PATH"
