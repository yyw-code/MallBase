# 演示数据静态图（安装期素材源）

本目录是 MallBase 演示数据图片的**源仓库**，由仓库直接 commit。

## 安装时如何被使用

`InstallService::executeInstallation()` 在 `import_demo` 步骤之后会调用 `copyDemoStatics()`：

- 源：`deploy/install/static/demo/`（即本目录）
- 目标：`backend/public/static/demo/`
- 策略：目标已存在同名文件 → 跳过；不存在 → 拷贝并设 `0644`。
- 错误：单文件失败仅记录到步骤详情，不阻断安装。

四种安装方式都汇聚到 `InstallService::executeInstallation()`，因此本目录只维护一份即可：

| 安装方式 | 入口 | 是否走本目录 |
|---|---|---|
| Web 向导 | `InstallController::execute` | 是 |
| `php think install:auto` CLI | `app/command/InstallAuto.php` | 是 |
| Docker 仅后端 | 容器内进 Web 向导/命令 | 是（compose 已挂 `./deploy/install:/app/install`） |
| Docker 全套 | `install-auto` 容器 | 是 |
| Docker 生产 | 镜像中执行 install:auto | 仅当镜像 `COPY deploy/install` 时；当前生产 Dockerfile 不打包此目录，故生产场景默认不拷贝（与 SQL 演示数据一致）。 |

## SQL 引用对应关系

`deploy/install/data/demo/02_demo_goods.sql` 引用 `/static/demo/<file>`；
`deploy/install/data/schema/03_mb_setting.sql` 引用 `/static/demo/banner-*.png`；
`deploy/install/data/schema/12_mb_recharge.sql` 引用 `/static/demo/recharge-dragon-card.png`。

| 文件 | 用途 | SQL 引用位置 |
|---|---|---|
| `cat-{phone,clothes,food,home,smartphone,tablet,menswear,womenswear,snacks,furniture}.png` | 分类卡 | `mb_goods_category.image` |
| `banner-{digital,fashion,home}.png` | 首页轮播 | `mb_setting.client_home_banners` |
| `laptop-01-*.jpg` `camera-01-*.jpg` `jeans-01-*.jpg` `watch-01-main.png` `sofa-01-*.jpg` `vanity-01-*.png` | 演示商品图 / SKU 图 | `mb_goods.main_image` / `mb_goods.images` / `mb_goods_sku.image` |
| `avatars/avatar-{1..5}.png` | 演示评价头像 | `mb_goods_review.avatar` |
| `recharge-dragon-card.png` | 充值套餐背景图 | `mb_recharge_package.background_image` |

## 替换素材时

`backend/public/static/demo/` 在 `backend/.gitignore` 内被忽略（`public/static/demo/*`），**只有本目录是 git 跟踪的唯一源**。替换流程：

1. 在本目录覆盖或新增同名文件。
2. 如本机已安装过 demo：直接清掉 `backend/public/static/demo/` 中对应文件，下次启动时 `InstallService::copyDemoStatics()` 会重新从本目录拷贝过去。
3. 或者跑 `sh deploy/docker/cleanup-dev.sh` 彻底清理运行时目录后重装，由 `InstallService` 重新拷贝。

## 命名规范

文件名直接被 SQL 引用，不要随意改名。如果一定要改名，需要同步修改：
- `deploy/install/data/demo/02_demo_goods.sql`
- `deploy/install/data/demo/04_demo_reviews.sql`
- `deploy/install/data/schema/03_mb_setting.sql`
- `deploy/install/data/schema/12_mb_recharge.sql`
