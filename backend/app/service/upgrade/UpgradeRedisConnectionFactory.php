<?php

declare(strict_types=1);

namespace app\service\upgrade;

interface UpgradeRedisConnectionFactory
{
    /**
     * 创建仅供一次升级状态原子操作使用的非持久连接。
     *
     * 调用方不得把返回值写入进程级或请求级共享状态。
     */
    public function create(): object;
}
