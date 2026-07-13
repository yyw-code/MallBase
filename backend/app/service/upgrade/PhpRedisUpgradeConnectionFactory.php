<?php

declare(strict_types=1);

namespace app\service\upgrade;

use Redis;
use Throwable;

final readonly class PhpRedisUpgradeConnectionFactory implements UpgradeRedisConnectionFactory
{
    public function __construct(
        private string $host,
        private int $port,
        private string $password,
        private int $database,
        private float $connectTimeout = 2.0,
        private float $readTimeout = 2.0,
    ) {
        if ($this->host === '' || str_contains($this->host, "\0")
            || $this->port < 1 || $this->port > 65535
            || $this->database < 0 || $this->database > 1_000_000
            || $this->connectTimeout <= 0 || $this->connectTimeout > 60
            || $this->readTimeout <= 0 || $this->readTimeout > 60) {
            throw new UpgradeStateConflict('UPGRADE_REDIS_CONFIG_INVALID');
        }
    }

    public function create(): object
    {
        if (!class_exists(Redis::class)
            || !defined(Redis::class . '::OPT_MAX_RETRIES')
            || !defined(Redis::class . '::OPT_SERIALIZER')
            || !defined(Redis::class . '::SERIALIZER_NONE')
            || !defined(Redis::class . '::OPT_PREFIX')) {
            throw new UpgradeStateConflict('UPGRADE_REDIS_CLIENT_UNAVAILABLE');
        }

        $redis = new Redis();
        try {
            // 固定使用 connect，禁止 pconnect；retry_interval=0 且 max_retries=0，
            // 连接断开时由当前升级操作失败关闭，不跨请求透明重连。
            if (!$redis->connect(
                $this->host,
                $this->port,
                $this->connectTimeout,
                null,
                0,
                $this->readTimeout,
            )) {
                throw new UpgradeStateConflict('UPGRADE_REDIS_CONNECTION_UNAVAILABLE');
            }
            if (!$redis->setOption(Redis::OPT_MAX_RETRIES, 0)
                || !$redis->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_NONE)
                || !$redis->setOption(Redis::OPT_PREFIX, '')) {
                throw new UpgradeStateConflict('UPGRADE_REDIS_CLIENT_UNAVAILABLE');
            }
            if ($this->password !== '' && !$redis->auth($this->password)) {
                throw new UpgradeStateConflict('UPGRADE_REDIS_AUTH_FAILED');
            }
            if ($this->database !== 0 && !$redis->select($this->database)) {
                throw new UpgradeStateConflict('UPGRADE_REDIS_DATABASE_UNAVAILABLE');
            }

            return $redis;
        } catch (Throwable $exception) {
            try {
                $redis->close();
            } catch (Throwable) {
            }
            if ($exception instanceof UpgradeStateConflict) {
                throw $exception;
            }
            throw new UpgradeStateConflict('UPGRADE_REDIS_CONNECTION_UNAVAILABLE');
        }
    }
}
