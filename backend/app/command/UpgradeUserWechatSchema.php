<?php

declare(strict_types=1);

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\facade\Db;

/**
 * 历史兼容修复：mb_user 微信 openid 拆列 + username 列 + 微信登录设置项
 *
 * 背景：
 *  - 旧 schema 只有一个 wx_openid 列，现实里同一用户在小程序与公众号会拿到不同 openid，
 *    单列存不下两端 openid。本命令新增 wx_miniapp_openid / wx_official_openid 两列。
 *  - mb_user 新增 username 列（账号密码登录使用）。
 *  - 微信设置新增：小程序"强制获取手机号 / 强制获取头像昵称"、
 *    公众号"必须绑定手机号 / 强制获取头像昵称"。
 *
 * 数据迁移策略：
 *  - 历史 wx_openid 全部来自小程序（之前只接了小程序），统一复制到 wx_miniapp_openid。
 *  - 旧 wx_openid 列 **保留** 不删除，下个里程碑再 DROP；本命令只 DROP 它的 UNIQUE 索引
 *    避免与新列约束冲突。
 *
 * 幂等：
 *  - 列存在性、索引存在性、设置项存在性均做检测后再 DDL/DML，重复执行返回 affected=0。
 *
 * 使用：
 *   php think upgrade:user-wechat-schema
 */
class UpgradeUserWechatSchema extends Command
{
    protected function configure(): void
    {
        $this->setName('upgrade:user-wechat-schema')
            ->setDescription('历史兼容修复：mb_user 微信 openid 拆列、username 列、微信登录设置项');
    }

    protected function execute(Input $input, Output $output): int
    {
        $prefix = config('database.connections.mysql.prefix', 'mb_');
        $userTable = $prefix . 'user';
        $groupTable = $prefix . 'setting_group';
        $settingTable = $prefix . 'setting';

        try {
            $this->upgradeUserTable($output, $userTable);
            $this->upgradeSettings($output, $groupTable, $settingTable);

            $output->info('升级完成');
            return 0;
        } catch (\Throwable $e) {
            $output->error('升级失败：' . $e->getMessage());
            return 1;
        }
    }

    private function upgradeUserTable(Output $output, string $table): void
    {
        // 1. 加 username 列
        if (!$this->columnExists($table, 'username')) {
            Db::execute(sprintf(
                "ALTER TABLE `%s` ADD COLUMN `username` varchar(60) DEFAULT NULL COMMENT '用户名（账号密码登录使用，可选）' AFTER `id`",
                $table,
            ));
            Db::execute(sprintf("ALTER TABLE `%s` ADD UNIQUE KEY `uk_username` (`username`)", $table));
            $output->writeln('<info>+ ' . $table . '.username 列已添加</info>');
        }

        // 2. 加 wx_miniapp_openid 列
        if (!$this->columnExists($table, 'wx_miniapp_openid')) {
            Db::execute(sprintf(
                "ALTER TABLE `%s` ADD COLUMN `wx_miniapp_openid` varchar(100) DEFAULT NULL COMMENT '微信小程序 openid' AFTER `avatar`",
                $table,
            ));
            $output->writeln('<info>+ ' . $table . '.wx_miniapp_openid 列已添加</info>');
        }

        // 3. 加 wx_official_openid 列
        if (!$this->columnExists($table, 'wx_official_openid')) {
            Db::execute(sprintf(
                "ALTER TABLE `%s` ADD COLUMN `wx_official_openid` varchar(100) DEFAULT NULL COMMENT '微信公众号 openid' AFTER `wx_miniapp_openid`",
                $table,
            ));
            $output->writeln('<info>+ ' . $table . '.wx_official_openid 列已添加</info>');
        }

        // 4. 把历史 wx_openid 数据复制到 wx_miniapp_openid（仅当目标列为空时）
        if ($this->columnExists($table, 'wx_openid')) {
            $copied = Db::execute(sprintf(
                "UPDATE `%s` SET `wx_miniapp_openid` = `wx_openid` WHERE `wx_openid` IS NOT NULL AND `wx_miniapp_openid` IS NULL",
                $table,
            ));
            if ($copied > 0) {
                $output->writeln(sprintf('<info>· %d 行 wx_openid 数据已复制至 wx_miniapp_openid</info>', $copied));
            }
        }

        // 5. 给新列加 UNIQUE
        if (!$this->indexExists($table, 'uk_wx_miniapp_openid')) {
            Db::execute(sprintf(
                "ALTER TABLE `%s` ADD UNIQUE KEY `uk_wx_miniapp_openid` (`wx_miniapp_openid`)",
                $table,
            ));
            $output->writeln('<info>+ ' . $table . '.uk_wx_miniapp_openid 索引已添加</info>');
        }
        if (!$this->indexExists($table, 'uk_wx_official_openid')) {
            Db::execute(sprintf(
                "ALTER TABLE `%s` ADD UNIQUE KEY `uk_wx_official_openid` (`wx_official_openid`)",
                $table,
            ));
            $output->writeln('<info>+ ' . $table . '.uk_wx_official_openid 索引已添加</info>');
        }

        // 6. 旧 wx_openid 的 UNIQUE 索引若存在则去掉，避免与新列约束冲突；列本身保留兼容
        if ($this->indexExists($table, 'uk_wx_openid')) {
            Db::execute(sprintf("ALTER TABLE `%s` DROP INDEX `uk_wx_openid`", $table));
            $output->writeln('<comment>· 旧 ' . $table . '.uk_wx_openid 索引已移除（列保留兼容，下版本删除）</comment>');
        }
    }

    /**
     * 同步微信登录相关设置项
     */
    private function upgradeSettings(Output $output, string $groupTable, string $settingTable): void
    {
        // 新增 4 项微信设置（小程序 2 项 + 公众号 2 项）
        $rows = [
            // 1031 微信小程序
            [1031, '强制获取手机号', 'wechat_mini_force_mobile', '0', 'switch', '开启后小程序首次登录必须授权手机号（getPhoneNumber），用于跨端账号合并；关闭后走"绑定手机号"中间步骤', 60],
            [1031, '强制获取头像昵称', 'wechat_mini_force_userinfo', '0', 'switch', '开启后必须用户主动选取头像与昵称（chooseAvatar + nickname 输入框）；关闭后允许默认昵称', 70],
            // 1032 公众号
            [1032, '必须绑定手机号', 'wechat_offi_force_mobile_bind', '0', 'switch', '开启后公众号 OAuth 注册的用户必须走短信验证码绑定手机号（公众号 OAuth 本身无法直接获取手机号）', 50],
            [1032, '强制获取头像昵称', 'wechat_offi_force_userinfo', '0', 'switch', '开启后 OAuth scope 使用 snsapi_userinfo 强制获取头像/昵称；关闭后使用 snsapi_base 仅取 openid', 60],
        ];

        $added = 0;
        foreach ($rows as [$groupId, $name, $code, $value, $type, $remark, $sort]) {
            $existing = Db::name('setting')
                ->where('group_id', $groupId)
                ->where('code', $code)
                ->find();
            if ($existing !== null) {
                continue;
            }
            Db::name('setting')->insert([
                'group_id'    => $groupId,
                'name'        => $name,
                'code'        => $code,
                'value'       => $value,
                'type'        => $type,
                'options'     => null,
                'rules'       => null,
                'placeholder' => null,
                'remark'      => $remark,
                'sort'        => $sort,
            ]);
            $added++;
        }

        if ($added > 0) {
            $output->writeln(sprintf('<info>+ %d 项微信新设置已添加</info>', $added));
        }
    }

    private function columnExists(string $table, string $column): bool
    {
        $result = Db::query(
            'SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.COLUMNS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        );
        return ((int) ($result[0]['c'] ?? 0)) > 0;
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $result = Db::query(
            'SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.STATISTICS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
            [$table, $indexName]
        );
        return ((int) ($result[0]['c'] ?? 0)) > 0;
    }
}
