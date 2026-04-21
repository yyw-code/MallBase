#!/bin/sh
set -eu

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname "$0")" && pwd)
ROOT_DIR=$(CDPATH= cd -- "$SCRIPT_DIR/.." && pwd)
SOURCE_DIR="$ROOT_DIR/backend/public/admin"

SSH_HOST=""
SSH_PORT="22"
REMOTE_ROOT=""
REMOTE_DIR=""
KEEP_EXTRA="0"

usage() {
    cat <<'EOF'
用法：
  sh deploy/upload-public-admin.sh --host user@server --remote-dir /var/www/mallbase/admin
  sh deploy/upload-public-admin.sh --host user@server --remote-root /www/wwwroot/example.com/mall-base

参数：
  --host         必填，SSH 目标，例如 user@server
  --remote-dir   直接指定服务器上的最终目录
  --remote-root  指定服务器上的项目根目录，脚本会自动拼成 backend/public/admin
  --port         可选，SSH 端口，默认 22
  --keep-extra   可选，保留服务器目标目录中多余旧文件；默认会先清空目标目录再解压

说明：
  1. 本地源目录固定为 backend/public/admin
  2. --remote-dir 和 --remote-root 二选一
  3. 默认行为是覆盖服务器目标目录内容，避免旧静态资源残留
EOF
}

while [ "$#" -gt 0 ]; do
    case "$1" in
        --host)
            SSH_HOST=${2:-}
            shift 2
            ;;
        --remote-root)
            REMOTE_ROOT=${2:-}
            shift 2
            ;;
        --remote-dir)
            REMOTE_DIR=${2:-}
            shift 2
            ;;
        --port)
            SSH_PORT=${2:-}
            shift 2
            ;;
        --keep-extra)
            KEEP_EXTRA="1"
            shift
            ;;
        -h|--help)
            usage
            exit 0
            ;;
        *)
            echo "未知参数：$1"
            echo
            usage
            exit 1
            ;;
    esac
done

if [ -z "$SSH_HOST" ]; then
    echo "缺少必填参数：--host"
    echo
    usage
    exit 1
fi

if [ -n "$REMOTE_ROOT" ] && [ -n "$REMOTE_DIR" ]; then
    echo "--remote-root 和 --remote-dir 不能同时使用"
    exit 1
fi

if [ -z "$REMOTE_ROOT" ] && [ -z "$REMOTE_DIR" ]; then
    echo "--remote-root 和 --remote-dir 需要二选一"
    exit 1
fi

if [ -n "$REMOTE_ROOT" ]; then
    REMOTE_DIR="$REMOTE_ROOT/backend/public/admin"
fi

if [ ! -d "$SOURCE_DIR" ]; then
    echo "本地目录不存在：$SOURCE_DIR"
    echo "请先确认后台前端资源已经构建到 backend/public/admin"
    exit 1
fi

if [ ! -f "$SOURCE_DIR/index.html" ]; then
    echo "本地目录缺少 index.html：$SOURCE_DIR/index.html"
    echo "请先确认 backend/public/admin 是一份完整的前端构建产物"
    exit 1
fi

if ! command -v tar >/dev/null 2>&1; then
    echo "缺少命令：tar"
    exit 1
fi

if ! command -v scp >/dev/null 2>&1; then
    echo "缺少命令：scp"
    exit 1
fi

if ! command -v ssh >/dev/null 2>&1; then
    echo "缺少命令：ssh"
    exit 1
fi

TIMESTAMP=$(date +%Y%m%d%H%M%S)
ARCHIVE_NAME="mallbase-public-admin-${TIMESTAMP}.tar.gz"
LOCAL_ARCHIVE="/tmp/${ARCHIVE_NAME}"
REMOTE_ARCHIVE="/tmp/${ARCHIVE_NAME}"

cleanup_local() {
    rm -f "$LOCAL_ARCHIVE"
}

trap cleanup_local EXIT INT TERM

echo ">>> [upload-public-admin] 本地源目录：$SOURCE_DIR"
echo ">>> [upload-public-admin] 服务器目标：$SSH_HOST:$REMOTE_DIR"
echo ">>> [upload-public-admin] 打包本地资源"

tar -C "$SOURCE_DIR" -czf "$LOCAL_ARCHIVE" .

echo ">>> [upload-public-admin] 上传归档文件"
scp -P "$SSH_PORT" "$LOCAL_ARCHIVE" "$SSH_HOST:$REMOTE_ARCHIVE"

REMOTE_SHELL=$(cat <<EOF
set -eu
mkdir -p '$REMOTE_DIR'
if [ '$KEEP_EXTRA' != '1' ]; then
    find '$REMOTE_DIR' -mindepth 1 -maxdepth 1 -exec rm -rf {} +
fi
tar -xzf '$REMOTE_ARCHIVE' -C '$REMOTE_DIR'
rm -f '$REMOTE_ARCHIVE'
EOF
)

echo ">>> [upload-public-admin] 解压到服务器目录"
ssh -p "$SSH_PORT" "$SSH_HOST" "$REMOTE_SHELL"

echo ">>> [upload-public-admin] 完成"
echo ">>> [upload-public-admin] 已同步到：$SSH_HOST:$REMOTE_DIR"
