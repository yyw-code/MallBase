#!/bin/sh
set -eu

# ============================================================
# cleanup-dev.sh —— 清理 Docker 开发全套模式产生的本地状态
# ============================================================
# 默认清理内容：
#   1. docker compose 创建的容器 / 网络 / 匿名卷 / 命名卷
#   2. 本项目构建镜像：mallbase-backend:dev
#   3. 宿主机 bind mount 产生的文件：
#      - data/
#      - .env
#      - backend/.env
#      - deploy/install/install.lock
#      - backend/public/admin
#      - frontend/admin/node_modules
#      - frontend/admin/apps/web-antd/node_modules
#      - frontend/admin/apps/web-antd/dist
#
# 可选额外清理：
#   --all-images
#     连 mysql:8.0 / redis:7-alpine / node:20-alpine / alpine:3.19
#     这些共享基础镜像也一起删掉。
#     注意：这会影响本机其他项目，下次使用时还要重新拉取。
# ============================================================

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname "$0")" && pwd)
ROOT_DIR=$(CDPATH= cd -- "$SCRIPT_DIR/../.." && pwd)
COMPOSE_FILE="$ROOT_DIR/docker-compose.dev.yml"

ALL_IMAGES=0

for arg in "$@"; do
    case "$arg" in
        --all-images)
            ALL_IMAGES=1
            ;;
        *)
            echo "未知参数：$arg"
            echo "用法：sh deploy/docker/cleanup-dev.sh [--all-images]"
            exit 1
            ;;
    esac
done

echo ">>> [cleanup-dev] 项目目录：$ROOT_DIR"
echo ">>> [cleanup-dev] 将执行项目级彻底清理"
if [ "$ALL_IMAGES" -eq 1 ]; then
    echo ">>> [cleanup-dev] 已启用 --all-images：会额外删除共享基础镜像"
fi

cd "$ROOT_DIR"

echo ">>> [cleanup-dev] 停止并删除 compose 资源（容器 / 网络 / 卷 / orphan）"
docker compose -f "$COMPOSE_FILE" --profile build --profile tools down -v --remove-orphans || true

echo ">>> [cleanup-dev] 兜底删除可能残留的容器"
for name in \
    mallbase-dev \
    mallbase-install-auto \
    mallbase-check-db-auth \
    mallbase-frontend-build \
    mallbase-ensure-env \
    mallbase-mysql \
    mallbase-redis \
    mallbase-rotate-db-password
do
    docker rm -f "$name" >/dev/null 2>&1 || true
done

echo ">>> [cleanup-dev] 删除本项目构建镜像 mallbase-backend:dev"
docker image rm -f mallbase-backend:dev >/dev/null 2>&1 || true

if [ "$ALL_IMAGES" -eq 1 ]; then
    echo ">>> [cleanup-dev] 删除共享基础镜像"
    for image in \
        mysql:8.0 \
        redis:7-alpine \
        node:20-alpine \
        alpine:3.19
    do
        docker image rm -f "$image" >/dev/null 2>&1 || true
    done
fi

echo ">>> [cleanup-dev] 删除宿主机生成文件"
rm -rf "$ROOT_DIR/data"
rm -f "$ROOT_DIR/.env"
rm -f "$ROOT_DIR/backend/.env"
rm -f "$ROOT_DIR/deploy/install/install.lock"
rm -rf "$ROOT_DIR/backend/public/admin"
rm -rf "$ROOT_DIR/frontend/admin/node_modules"
rm -rf "$ROOT_DIR/frontend/admin/apps/web-antd/node_modules"
rm -rf "$ROOT_DIR/frontend/admin/apps/web-antd/dist"

echo ">>> [cleanup-dev] 完成"
