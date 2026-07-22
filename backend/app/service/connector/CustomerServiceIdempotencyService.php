<?php
declare(strict_types=1);

namespace app\service\connector;

use mall_base\exception\BusinessException;

/**
 * 客服连接器写操作的严格幂等状态机。
 *
 * Service 不保存请求态；处理中状态的 owner token 只存在于当前方法局部变量。
 */
class CustomerServiceIdempotencyService
{
    private const CACHE_PREFIX = 'customer_service_connector_idem:';

    private const RECORD_VERSION = 1;

    private const RESULT_TTL = 86400;

    public function __construct(private readonly CustomerServiceIdempotencyStore $store)
    {
    }

    /**
     * @param array<string, mixed> $normalizedInput
     * @param callable(): array<string, mixed> $operation
     * @return array<string, mixed>
     */
    public function execute(
        string $scope,
        string $idempotencyKey,
        array $normalizedInput,
        callable $operation
    ): array {
        $scope = trim($scope);
        $idempotencyKey = trim($idempotencyKey);
        if (!preg_match('/^[a-z0-9._:-]{1,160}$/D', $scope)) {
            throw new BusinessException('客服连接器幂等作用域无效', 500);
        }
        if (!preg_match('/^[\x21-\x7E]{1,120}$/D', $idempotencyKey)) {
            throw new BusinessException('客服连接器写操作缺少有效幂等键', 400);
        }

        try {
            $fingerprint = $this->fingerprint($scope, $normalizedInput);
            $owner = bin2hex(random_bytes(16));
            $processingState = $this->encodeRecord([
                'version' => self::RECORD_VERSION,
                'state' => 'processing',
                'fingerprint' => $fingerprint,
                'owner' => $owner,
            ]);
        } catch (\Throwable $error) {
            throw new BusinessException('客服连接器幂等请求摘要生成失败', 500);
        }

        $logicalKey = self::CACHE_PREFIX . hash('sha256', $scope . "\n" . $idempotencyKey);
        $acquired = false;
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $acquired = $this->store->claim($logicalKey, $processingState, self::RESULT_TTL);
                if ($acquired) {
                    break;
                }

                $existing = $this->store->read($logicalKey);
            } catch (\Throwable $error) {
                throw $this->storageUnavailable();
            }

            if ($existing !== null) {
                return $this->resolveExisting($existing, $fingerprint);
            }
        }

        if (!$acquired) {
            throw $this->storageUnavailable();
        }

        try {
            $result = $operation();
        } catch (\Throwable $error) {
            $uncertainState = $this->encodeRecord([
                'version' => self::RECORD_VERSION,
                'state' => 'uncertain',
                'fingerprint' => $fingerprint,
            ]);
            try {
                $transitioned = $this->store->transition(
                    $logicalKey,
                    $processingState,
                    $uncertainState,
                    self::RESULT_TTL
                );
            } catch (\Throwable $transitionError) {
                throw $this->storageUnavailable();
            }
            if (!$transitioned) {
                throw new BusinessException('客服连接器幂等状态已变化，请核验操作结果', 503);
            }

            throw $error;
        }

        if (!is_array($result)) {
            throw new BusinessException('客服连接器幂等结果无效，请核验操作结果', 503);
        }

        try {
            $succeededState = $this->encodeRecord([
                'version' => self::RECORD_VERSION,
                'state' => 'succeeded',
                'fingerprint' => $fingerprint,
                'result' => $result,
            ]);
            $transitioned = $this->store->transition(
                $logicalKey,
                $processingState,
                $succeededState,
                self::RESULT_TTL
            );
        } catch (\Throwable $error) {
            throw $this->storageUnavailable();
        }
        if (!$transitioned) {
            throw new BusinessException('客服连接器幂等结果保存失败，请核验操作结果', 503);
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveExisting(string $rawState, string $fingerprint): array
    {
        try {
            $record = json_decode($rawState, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $error) {
            throw $this->storageUnavailable();
        }
        if (!is_array($record)
            || ($record['version'] ?? null) !== self::RECORD_VERSION
            || !is_string($record['state'] ?? null)
            || !is_string($record['fingerprint'] ?? null)
            || !preg_match('/^[a-f0-9]{64}$/D', $record['fingerprint'])) {
            throw $this->storageUnavailable();
        }
        if (!hash_equals($record['fingerprint'], $fingerprint)) {
            throw new BusinessException('客服连接器幂等键已用于其他请求', 409);
        }

        if ($record['state'] === 'processing') {
            if (!is_string($record['owner'] ?? null)
                || !preg_match('/^[a-f0-9]{32}$/D', $record['owner'])) {
                throw $this->storageUnavailable();
            }
            throw new BusinessException('客服连接器操作正在处理中', 409);
        }
        if ($record['state'] === 'uncertain') {
            throw new BusinessException('客服连接器上次操作结果不确定，请人工核验', 503);
        }
        if ($record['state'] !== 'succeeded' || !is_array($record['result'] ?? null)) {
            throw $this->storageUnavailable();
        }

        return $record['result'];
    }

    /**
     * @param array<string, mixed> $normalizedInput
     */
    private function fingerprint(string $scope, array $normalizedInput): string
    {
        $identity = $this->canonicalize([
            'scope' => $scope,
            'input' => $normalizedInput,
        ]);

        return hash('sha256', json_encode(
            $identity,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        ));
    }

    /**
     * @return mixed
     */
    private function canonicalize($value)
    {
        if (!is_array($value)) {
            if ($value === null || is_scalar($value)) {
                return $value;
            }

            throw new \InvalidArgumentException('幂等请求摘要只允许标量、数组和 null');
        }

        if (array_is_list($value)) {
            return array_map(fn($item) => $this->canonicalize($item), $value);
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $record
     */
    private function encodeRecord(array $record): string
    {
        return json_encode(
            $record,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        );
    }

    private function storageUnavailable(): BusinessException
    {
        return new BusinessException('客服连接器幂等存储不可用，请稍后重试', 503);
    }
}
