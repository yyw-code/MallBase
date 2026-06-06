<?php

declare(strict_types=1);

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\facade\Db;

/**
 * 历史兼容修复：为已装环境补齐 mb_admin.password_changed_at 列
 *
 * 使用场景：
 * - 在 password_changed_at 列引入前已完成 install 的环境
 * - 新装环境不需要运行（install SQL 已包含该列）
 *
 * 判定策略（基于密码哈希）：
 * - 对每一行 password_changed_at=NULL 的账号，用 password_verify 逐一检查密码是否仍命中
 *   公开默认密码清单（DEFAULT_PASSWORDS，当前为 admin123 + password）：
 *     - 任一命中 → 保留 NULL，下次登录必须改密
 *     - 全部不中 → 回填 password_changed_at = NOW()
 * - 不使用"登录过"这种代理指标：legacy 环境里天天拿默认密码登录的账号必须被识别出来。
 * - 为什么是清单：老版本 schema 里种子哈希对应明文是 'password'；新版本 createSuperAdmin
 *   用 admin123 并走 ON DUPLICATE KEY UPDATE 覆写种子哈希。两种 legacy 残留都要拦住。
 *
 * 幂等与重试安全：
 * - ALTER 段：列缺失则添加，已存在则跳过 DDL；不会二次 ALTER 报错。
 * - 回填段：SELECT 只拿 password_changed_at=NULL 的行，完成回填的行下次不再被命中。
 * - ALTER 成功但回填中断的"半升级"状态可以直接重跑补齐。
 *
 * 使用示例：
 * ```bash
 * # 宿主直跑
 * php think upgrade:admin-schema
 *
 * # Docker 环境
 * docker compose -f docker-compose.dev.yml exec -T backend php think upgrade:admin-schema
 * ```
 */
class UpgradeAdminSchema extends Command
{
    /**
     * 历史上公开出现过的默认超管密码清单。
     *
     * - 'admin123'：当前 install:auto / install 向导 / createSuperAdmin 使用的默认
     * - 'password'：backend/install/data/schema/01_mb_auth.sql 里硬编码种子哈希对应的明文；
     *               在 createSuperAdmin ON DUPLICATE 覆写引入前，老环境可能还在用
     *
     * 只要 password_verify 任意命中其中一个，就判定"仍在用公开默认密码" → 保留 NULL
     * 强制下次登录改密。
     */
    private const DEFAULT_PASSWORDS = ['admin123', 'password'];

    /**
     * 预览仍在用默认密码的账号时最多打印的用户名数
     */
    private const PREVIEW_USERNAMES = 10;

    protected function configure(): void
    {
        $this->setName('upgrade:admin-schema')
            ->setDescription('历史兼容修复：补齐 mb_admin.password_changed_at 列（幂等 + 重试安全 + 基于哈希判定）');
    }

    protected function execute(Input $input, Output $output): int
    {
        $prefix = config('database.connections.mysql.prefix', 'mb_');
        $table = $prefix . 'admin';

        try {
            // 1) 列存在性检测（INFORMATION_SCHEMA 兼容 MySQL 5.7 / 8.0）
            $exists = Db::query(
                'SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.COLUMNS '
                . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$table, 'password_changed_at']
            );
            $columnExists = ((int) ($exists[0]['c'] ?? 0)) > 0;

            // 2) DDL（仅在列缺失时执行；DDL 不在事务里，和回填段天然分离，需要各自幂等）
            if (!$columnExists) {
                Db::execute(
                    "ALTER TABLE `{$table}` "
                    . "ADD COLUMN `password_changed_at` datetime DEFAULT NULL "
                    . "COMMENT '最近改密时间，NULL=从未改过（首次登录需强制改密）' "
                    . "AFTER `last_login_ip`"
                );
                $output->writeln("<info>[upgrade:admin-schema] 已为 {$table} 添加 password_changed_at 列</info>");
            } else {
                $output->writeln("<info>[upgrade:admin-schema] {$table}.password_changed_at 列已存在，跳过 ALTER</info>");
            }

            // 3) 基于密码哈希的回填
            $rows = Db::query(
                "SELECT `id`, `username`, `password` FROM `{$table}` WHERE `password_changed_at` IS NULL"
            );

            $backfilled = 0;
            $defaultHits = 0;
            $defaultUsernames = [];

            foreach ($rows as $row) {
                $hash = (string) ($row['password'] ?? '');
                $username = (string) ($row['username'] ?? '');

                $isDefault = false;
                if ($hash !== '') {
                    foreach (self::DEFAULT_PASSWORDS as $candidate) {
                        if (password_verify($candidate, $hash)) {
                            $isDefault = true;
                            break;
                        }
                    }
                }

                if ($isDefault) {
                    // 密码仍是公开默认密码之一 → 保留 NULL，下次登录必须改密
                    $defaultHits++;
                    if (count($defaultUsernames) < self::PREVIEW_USERNAMES) {
                        $defaultUsernames[] = $username;
                    }
                    continue;
                }

                // 密码已不是任何已知公开默认 → 回填为 NOW()
                Db::execute(
                    "UPDATE `{$table}` SET `password_changed_at` = NOW() WHERE `id` = ?",
                    [$row['id']]
                );
                $backfilled++;
            }

            $defaultsList = implode(' / ', self::DEFAULT_PASSWORDS);
            $output->writeln("<comment>[upgrade:admin-schema] 回填 {$backfilled} 行（密码已不在公开默认清单 [{$defaultsList}] 中，视为已改密）</comment>");

            if ($defaultHits > 0) {
                $preview = implode(', ', $defaultUsernames)
                    . ($defaultHits > count($defaultUsernames) ? ', …' : '');
                $output->writeln("<comment>[upgrade:admin-schema] 保留 {$defaultHits} 行 NULL（密码仍命中公开默认 [{$defaultsList}]：{$preview}），下次登录将强制改密</comment>");
            }

            $output->writeln('<comment>[upgrade:admin-schema] 如需额外强制某账号改密，手动 UPDATE 将该行 password_changed_at 置 NULL</comment>');
            return 0;
        } catch (\Throwable $e) {
            $output->writeln('<error>[upgrade:admin-schema] 失败: ' . $e->getMessage() . '</error>');
            $output->writeln('<error>[upgrade:admin-schema] 本命令可安全重试：再次执行会跳过已完成步骤，补齐剩余工作</error>');
            return 1;
        }
    }
}
