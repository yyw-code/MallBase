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
 * 回填策略：
 * - 对 password_changed_at=NULL 的历史账号统一回填 NOW()
 * - 该字段仅表示最近改密时间的记录状态，不再驱动登录后的独立改密页
 *
 * 幂等与重试安全：
 * - ALTER 段：列缺失则添加，已存在则跳过 DDL；不会二次 ALTER 报错。
 * - 回填段：只更新 password_changed_at=NULL 的行，完成回填的行下次不再被命中。
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
    protected function configure(): void
    {
        $this->setName('upgrade:admin-schema')
            ->setDescription('历史兼容修复：补齐 mb_admin.password_changed_at 列（幂等 + 重试安全）');
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
                    . "COMMENT '最近改密时间，NULL=未知或从未改过' "
                    . "AFTER `last_login_ip`"
                );
                $output->writeln("<info>[upgrade:admin-schema] 已为 {$table} 添加 password_changed_at 列</info>");
            } else {
                $output->writeln("<info>[upgrade:admin-schema] {$table}.password_changed_at 列已存在，跳过 ALTER</info>");
            }

            // 3) 初始化历史账号的最近改密时间记录
            $backfilled = Db::execute(
                "UPDATE `{$table}` SET `password_changed_at` = NOW() WHERE `password_changed_at` IS NULL"
            );

            $output->writeln("<comment>[upgrade:admin-schema] 回填 {$backfilled} 行 password_changed_at=NULL 的历史账号</comment>");
            return 0;
        } catch (\Throwable $e) {
            $output->writeln('<error>[upgrade:admin-schema] 失败: ' . $e->getMessage() . '</error>');
            $output->writeln('<error>[upgrade:admin-schema] 本命令可安全重试：再次执行会跳过已完成步骤，补齐剩余工作</error>');
            return 1;
        }
    }
}
