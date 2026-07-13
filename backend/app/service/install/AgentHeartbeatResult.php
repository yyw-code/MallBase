<?php

declare(strict_types=1);

namespace app\service\install;

/**
 * 单次 Agent 心跳的无请求态结果。
 */
final readonly class AgentHeartbeatResult
{
    public function __construct(
        public bool $ok,
        public string $instanceId = '',
        public string $token = '',
        public int $nextReportAfterSeconds = 0,
        public string $skipped = '',
        public string $error = '',
    ) {
    }

    public static function failure(string $error): self
    {
        return new self(false, error: $error);
    }
}
