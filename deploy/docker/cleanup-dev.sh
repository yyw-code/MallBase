#!/bin/sh
set -eu

# ============================================================
# cleanup-dev.sh —— 分级清理本地安装、前端构建与 Docker 开发状态
# ============================================================
# 默认清理等级：basic
#
# 等级从低到高逐级包含：
#   --basic
#     清理安装运行态和基础生成文件：
#       - .env
#       - backend/.mallbase-env/backend.env 与环境锁
#       - 旧版 backend/.env 与根目录环境锁
#       - backend/runtime/install/install.lock
#       - backend/public/static/demo 中除 README.md 外的运行时素材
#
#   --frontend
#     包含 --basic，并清理前端依赖、构建产物和发布产物：
#       - backend/public/admin
#       - backend/public/client
#       - frontend/admin/node_modules
#       - frontend/admin/apps/web-antd/node_modules
#       - frontend/admin/apps/web-antd/dist
#       - frontend/uniapp/node_modules
#       - frontend/uniapp/dist
#
#   --docker
#     包含 --frontend，并清理 Docker 开发全套资源：
#       - docker compose 开发容器 / 网络 / 命名卷 / 匿名卷
#       - 兜底删除残留开发容器
#       - data/（MySQL / Redis bind mount 数据）
#       - backend/vendor
#
#   --images
#     包含 --docker，并清理本项目构建镜像 mallbase-backend:dev。
#
#   --all-images
#     兼容旧参数，等同于 --images，并额外删除共享基础镜像：
#       mysql:8.0 / redis:7-alpine / node:20-alpine / alpine:3.19
#
# 注意：
#   - --docker 与 --images 会删除数据库、Redis 或镜像状态。
#   - 生产服务器不建议直接使用本脚本清理 Docker 资源。
# ============================================================

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname "$0")" && pwd)
ROOT_DIR=$(CDPATH= cd -- "$SCRIPT_DIR/../.." && pwd)
COMPOSE_FILE="$ROOT_DIR/docker-compose.dev.yml"
FRONTEND_COMPOSE_FILE="$ROOT_DIR/docker-compose.frontend-build.yml"
UNIAPP_COMPOSE_FILE="$ROOT_DIR/docker-compose.uniapp-build.yml"

usage() {
    cat <<'EOF'
用法：
  sh deploy/docker/cleanup-dev.sh [--basic|--frontend|--docker|--images|--all-images]

清理等级：
  --basic       清理基础安装运行态（默认）：.env、后端运行配置、安装锁、演示运行时素材
  --frontend    包含 basic，并清理 Admin / UniApp 前端依赖、构建产物和发布产物
  --docker      包含 frontend，并清理 Docker 开发容器、网络、卷、data/、backend/vendor
  --images      包含 docker，并清理本项目构建镜像 mallbase-backend:dev
  --all-images  包含 images，并额外清理共享基础镜像（可能影响本机其他项目）
  -h, --help    显示帮助
EOF
}

LEVEL="basic"
LEVEL_SET=0
ALL_IMAGES=0

set_level() {
    if [ "$LEVEL_SET" -eq 1 ]; then
        echo "只能指定一个清理等级"
        usage
        exit 1
    fi

    LEVEL="$1"
    LEVEL_SET=1
}

for arg in "$@"; do
    case "$arg" in
        --basic)
            set_level "basic"
            ;;
        --frontend)
            set_level "frontend"
            ;;
        --docker)
            set_level "docker"
            ;;
        --images)
            set_level "images"
            ;;
        --all-images)
            set_level "images"
            ALL_IMAGES=1
            ;;
        -h|--help)
            usage
            exit 0
            ;;
        *)
            echo "未知参数：$arg"
            usage
            exit 1
            ;;
    esac
done

if [ -f "$ROOT_DIR/.env" ]; then
    set -a
    . "$ROOT_DIR/.env"
    set +a
fi

CONTAINER_PREFIX="${MALLBASE_CONTAINER_PREFIX:-mallbase}"

level_value() {
    case "$1" in
        basic) echo 1 ;;
        frontend) echo 2 ;;
        docker) echo 3 ;;
        images) echo 4 ;;
        *)
            echo "未知清理等级：$1" >&2
            exit 1
            ;;
    esac
}

should_run() {
    [ "$(level_value "$LEVEL")" -ge "$(level_value "$1")" ]
}

remove_file() {
    path="$1"
    if [ -f "$path" ] || [ -L "$path" ]; then
        echo ">>> [cleanup-dev] 删除文件：${path#$ROOT_DIR/}"
        rm -f "$path"
    fi
}

remove_dir() {
    path="$1"
    if [ -d "$path" ]; then
        echo ">>> [cleanup-dev] 删除目录：${path#$ROOT_DIR/}"
        rm -rf "$path"
    fi
}

remove_runtime_env_dir() {
    path="$ROOT_DIR/backend/.mallbase-env"
    if [ -L "$path" ]; then
        echo ">>> [cleanup-dev] 删除运行配置目录符号链接：${path#$ROOT_DIR/}"
        rm -f "$path"
        return
    fi
    remove_dir "$path"
}

clear_demo_runtime() {
    path="$ROOT_DIR/backend/public/static/demo"
    if [ -L "$path" ]; then
        echo ">>> [cleanup-dev] 删除符号链接：${path#$ROOT_DIR/}"
        rm -f "$path"
        return
    fi
    if [ -d "$path" ]; then
        echo ">>> [cleanup-dev] 清理演示运行时素材并保留 README.md：${path#$ROOT_DIR/}"
        find "$path" -mindepth 1 -maxdepth 1 ! -name README.md -exec rm -rf -- {} +
    fi
}

down_compose() {
    file="$1"
    label="$2"
    if [ -f "$file" ]; then
        echo ">>> [cleanup-dev] 停止并删除 $label 的 Docker 资源"
        docker compose -f "$file" --profile tools down -v --remove-orphans || true
    fi
}

echo ">>> [cleanup-dev] 项目目录：$ROOT_DIR"
echo ">>> [cleanup-dev] 容器名前缀：$CONTAINER_PREFIX"
echo ">>> [cleanup-dev] 清理等级：$LEVEL"
if [ "$ALL_IMAGES" -eq 1 ]; then
    echo ">>> [cleanup-dev] 已启用 --all-images：会额外删除共享基础镜像"
fi

cd "$ROOT_DIR"

echo ">>> [cleanup-dev] 执行基础清理"
remove_file "$ROOT_DIR/.env"
remove_file "$ROOT_DIR/backend/.env"
remove_file "$ROOT_DIR/backend/.backend-env.lock"
remove_runtime_env_dir
remove_file "$ROOT_DIR/backend/runtime/install/install.lock"
clear_demo_runtime

if should_run frontend; then
    echo ">>> [cleanup-dev] 执行前端清理"
    remove_dir "$ROOT_DIR/backend/public/admin"
    remove_dir "$ROOT_DIR/backend/public/client"
    remove_dir "$ROOT_DIR/frontend/admin/node_modules"
    remove_dir "$ROOT_DIR/frontend/admin/apps/web-antd/node_modules"
    remove_dir "$ROOT_DIR/frontend/admin/apps/web-antd/dist"
    remove_dir "$ROOT_DIR/frontend/uniapp/node_modules"
    remove_dir "$ROOT_DIR/frontend/uniapp/dist"
fi

if should_run docker; then
    echo ">>> [cleanup-dev] 执行 Docker 开发资源清理"
    down_compose "$COMPOSE_FILE" "Docker 开发全套"
    down_compose "$FRONTEND_COMPOSE_FILE" "Admin 前端打包"
    down_compose "$UNIAPP_COMPOSE_FILE" "UniApp H5 打包"

    echo ">>> [cleanup-dev] 兜底删除可能残留的开发容器"
    for name in \
        "$CONTAINER_PREFIX-dev" \
        "$CONTAINER_PREFIX-install-auto" \
        "$CONTAINER_PREFIX-check-db-auth" \
        "$CONTAINER_PREFIX-frontend-build" \
        "$CONTAINER_PREFIX-uniapp-build" \
        "$CONTAINER_PREFIX-ensure-env" \
        "$CONTAINER_PREFIX-prepare-data-dirs" \
        "$CONTAINER_PREFIX-mysql" \
        "$CONTAINER_PREFIX-redis" \
        "$CONTAINER_PREFIX-rotate-db-password"
    do
        docker rm -f "$name" >/dev/null 2>&1 || true
    done

    remove_dir "$ROOT_DIR/data/mysql"
    remove_dir "$ROOT_DIR/data/redis"
    remove_dir "$ROOT_DIR/backend/vendor"
fi

if should_run images; then
    echo ">>> [cleanup-dev] 删除本项目构建镜像 mallbase-backend:dev"
    docker image rm -f mallbase-backend:dev >/dev/null 2>&1 || true
fi

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

echo ">>> [cleanup-dev] 完成"
