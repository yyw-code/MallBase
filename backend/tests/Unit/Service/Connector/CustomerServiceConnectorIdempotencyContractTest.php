<?php

declare(strict_types=1);

namespace Tests\Unit\Service\Connector;

use app\service\connector\CustomerServiceIdempotencyService;
use app\service\connector\CustomerServiceIdempotencyStore;
use mall_base\exception\BusinessException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CustomerServiceConnectorIdempotencyContractTest extends TestCase
{
    public function testConnectorWriteEndpointsReadAndForwardTheSignedIdempotencyKey(): void
    {
        $root = dirname(__DIR__, 4) . '/app';
        $controller = file_get_contents($root . '/controller/connector/CustomerServiceController.php');
        $this->assertIsString($controller);

        $this->assertSame(4, substr_count($controller, "header('X-CS-Idempotency-Key'"));

        $service = new \ReflectionClass(\app\service\connector\CustomerServiceConnectorService::class);
        foreach (['addOrderRemark', 'shipOrder', 'approveRefund', 'rejectRefund'] as $methodName) {
            $parameters = $service->getMethod($methodName)->getParameters();
            $this->assertNotEmpty($parameters);
            $this->assertSame('idempotencyKey', $parameters[array_key_last($parameters)]->getName());
        }
    }

    public function testMissingIdempotencyKeyIsRejectedBeforeClaimOrBusinessExecution(): void
    {
        $store = new InMemoryCustomerServiceIdempotencyStore();
        $service = new CustomerServiceIdempotencyService($store);
        $executed = false;

        try {
            $service->execute(
                'customer-service:order-remark:42',
                '',
                ['remark' => '已联系用户'],
                function () use (&$executed): array {
                    $executed = true;
                    return [];
                }
            );
            self::fail('Missing idempotency key was accepted.');
        } catch (BusinessException $error) {
            $this->assertSame(400, $error->getCode());
        }

        $this->assertFalse($executed);
        $this->assertSame(0, $store->claimCalls);
    }

    public function testFirstRequestSucceedsAndEquivalentRetryReplaysWithoutExecutingAgain(): void
    {
        $store = new InMemoryCustomerServiceIdempotencyStore();
        $service = new CustomerServiceIdempotencyService($store);
        $executions = 0;
        $scope = 'customer-service:order-ship:42';
        $key = 'execution-42';

        $first = $service->execute(
            $scope,
            $key,
            [
                'delivery' => ['company' => '顺丰', 'sn' => 'SF001'],
                'items' => [['sku_id' => 2, 'quantity' => 1]],
            ],
            function () use (&$executions): array {
                $executions++;
                return ['id' => 42, 'message' => '发货成功'];
            }
        );
        $replayed = $service->execute(
            $scope,
            $key,
            [
                'items' => [['quantity' => 1, 'sku_id' => 2]],
                'delivery' => ['sn' => 'SF001', 'company' => '顺丰'],
            ],
            function () use (&$executions): array {
                $executions++;
                return ['id' => 42, 'message' => '不应执行'];
            }
        );

        $this->assertSame(['id' => 42, 'message' => '发货成功'], $first);
        $this->assertSame($first, $replayed);
        $this->assertSame(1, $executions);
        $this->assertSame(2, $store->claimCalls);
        $this->assertSame(1, $store->transitionCalls);
        $this->assertSame([86400, 86400, 86400], $store->ttls);

        $logicalKey = 'customer_service_connector_idem:' . hash('sha256', $scope . "\n" . $key);
        $this->assertArrayHasKey($logicalKey, $store->records);
        $this->assertStringNotContainsString($key, $logicalKey);
        $record = $this->decodeRecord($store->records[$logicalKey]);
        $this->assertSame('succeeded', $record['state']);
        $this->assertSame($first, $record['result']);
    }

    public function testSameKeyWithDifferentRequestFingerprintReturnsConflict(): void
    {
        $store = new InMemoryCustomerServiceIdempotencyStore();
        $service = new CustomerServiceIdempotencyService($store);
        $executions = 0;

        $service->execute(
            'customer-service:refund-reject:7',
            'execution-7',
            ['admin_remark' => '凭证不足'],
            function () use (&$executions): array {
                $executions++;
                return ['id' => 7, 'message' => '已驳回'];
            }
        );

        try {
            $service->execute(
                'customer-service:refund-reject:7',
                'execution-7',
                ['admin_remark' => '重复申请'],
                function () use (&$executions): array {
                    $executions++;
                    return [];
                }
            );
            self::fail('A reused key with a different request was accepted.');
        } catch (BusinessException $error) {
            $this->assertSame(409, $error->getCode());
            $this->assertSame('客服连接器幂等键已用于其他请求', $error->getMessage());
        }

        $this->assertSame(1, $executions);
    }

    public function testConcurrentEquivalentRequestSeesProcessingAndDoesNotExecute(): void
    {
        $store = new InMemoryCustomerServiceIdempotencyStore();
        $service = new CustomerServiceIdempotencyService($store);
        $outerExecutions = 0;
        $innerExecutions = 0;

        $result = $service->execute(
            'customer-service:order-remark:42',
            'execution-processing',
            ['remark' => '已联系用户'],
            function () use ($service, &$outerExecutions, &$innerExecutions): array {
                $outerExecutions++;

                try {
                    $service->execute(
                        'customer-service:order-remark:42',
                        'execution-processing',
                        ['remark' => '已联系用户'],
                        function () use (&$innerExecutions): array {
                            $innerExecutions++;
                            return [];
                        }
                    );
                    self::fail('A concurrent request was allowed to execute.');
                } catch (BusinessException $error) {
                    self::assertSame(409, $error->getCode());
                    self::assertSame('客服连接器操作正在处理中', $error->getMessage());
                }

                return ['id' => 42];
            }
        );

        $this->assertSame(['id' => 42], $result);
        $this->assertSame(1, $outerExecutions);
        $this->assertSame(0, $innerExecutions);
    }

    public function testOperationExceptionTransitionsToUncertainAndRetryNeverExecutesAgain(): void
    {
        $store = new InMemoryCustomerServiceIdempotencyStore();
        $service = new CustomerServiceIdempotencyService($store);
        $executions = 0;
        $failure = new RuntimeException('外部退款成功后本地状态写入失败');

        try {
            $service->execute(
                'customer-service:refund-approve:8',
                'execution-uncertain',
                ['admin_remark' => '同意退款'],
                function () use (&$executions, $failure): array {
                    $executions++;
                    throw $failure;
                }
            );
            self::fail('The operation exception was swallowed.');
        } catch (RuntimeException $error) {
            $this->assertSame($failure, $error);
        }

        $this->assertCount(1, $store->records);
        $record = $this->decodeRecord(array_values($store->records)[0]);
        $this->assertSame('uncertain', $record['state']);
        $this->assertArrayNotHasKey('owner', $record);

        try {
            $service->execute(
                'customer-service:refund-approve:8',
                'execution-uncertain',
                ['admin_remark' => '同意退款'],
                function () use (&$executions): array {
                    $executions++;
                    return ['id' => 8];
                }
            );
            self::fail('An uncertain request was executed again.');
        } catch (BusinessException $error) {
            $this->assertSame(503, $error->getCode());
            $this->assertSame('客服连接器上次操作结果不确定，请人工核验', $error->getMessage());
        }

        $this->assertSame(1, $executions);
    }

    #[DataProvider('storageFailureProvider')]
    public function testClaimAndReadFailuresFailClosedBeforeBusinessExecution(string $failurePoint): void
    {
        $store = new InMemoryCustomerServiceIdempotencyStore();
        $store->claimThrows = $failurePoint === 'claim';
        $store->claimAlwaysMisses = $failurePoint === 'read';
        $store->readThrows = $failurePoint === 'read';
        $service = new CustomerServiceIdempotencyService($store);
        $executed = false;

        try {
            $service->execute(
                'customer-service:order-ship:9',
                'execution-cache-error',
                ['logistics_sn' => 'SF009'],
                function () use (&$executed): array {
                    $executed = true;
                    return ['id' => 9];
                }
            );
            self::fail('A request ran while the idempotency store was unavailable.');
        } catch (BusinessException $error) {
            $this->assertSame(503, $error->getCode());
            $this->assertSame('客服连接器幂等存储不可用，请稍后重试', $error->getMessage());
        }

        $this->assertFalse($executed);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function storageFailureProvider(): array
    {
        return [
            'claim exception' => ['claim'],
            'read exception' => ['read'],
        ];
    }

    #[DataProvider('damagedRecordProvider')]
    public function testDamagedExistingRecordFailsClosed(string $rawRecord): void
    {
        $store = new InMemoryCustomerServiceIdempotencyStore();
        $store->claimAlwaysMisses = true;
        $store->hasReadOverride = true;
        $store->readOverride = $rawRecord;
        $service = new CustomerServiceIdempotencyService($store);
        $executed = false;

        try {
            $service->execute(
                'customer-service:order-remark:10',
                'execution-damaged',
                ['remark' => '测试'],
                function () use (&$executed): array {
                    $executed = true;
                    return [];
                }
            );
            self::fail('A damaged idempotency record was accepted.');
        } catch (BusinessException $error) {
            $this->assertSame(503, $error->getCode());
            $this->assertSame('客服连接器幂等存储不可用，请稍后重试', $error->getMessage());
        }

        $this->assertFalse($executed);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function damagedRecordProvider(): array
    {
        return [
            'invalid json' => ['{'],
            'unknown version' => [json_encode([
                'version' => 2,
                'state' => 'processing',
                'fingerprint' => str_repeat('a', 64),
            ], JSON_THROW_ON_ERROR)],
            'invalid fingerprint' => [json_encode([
                'version' => 1,
                'state' => 'processing',
                'fingerprint' => 'invalid',
            ], JSON_THROW_ON_ERROR)],
        ];
    }

    public function testUnknownTerminalStateFailsClosed(): void
    {
        $store = new InMemoryCustomerServiceIdempotencyStore();
        $service = new CustomerServiceIdempotencyService($store);
        $scope = 'customer-service:order-remark:11';
        $key = 'execution-unknown-state';
        $input = ['remark' => '测试'];

        $service->execute($scope, $key, $input, static fn(): array => ['id' => 11]);
        $logicalKey = array_key_first($store->records);
        $this->assertIsString($logicalKey);
        $record = $this->decodeRecord($store->records[$logicalKey]);
        $record['state'] = 'unknown';
        unset($record['result']);
        $store->records[$logicalKey] = json_encode($record, JSON_THROW_ON_ERROR);

        $this->expectException(BusinessException::class);
        $this->expectExceptionCode(503);
        $this->expectExceptionMessage('客服连接器幂等存储不可用，请稍后重试');

        $service->execute($scope, $key, $input, static fn(): array => ['id' => 999]);
    }

    public function testSuccessfulOperationWithTransitionExceptionStaysProcessingAndNeverRunsAgain(): void
    {
        $store = new InMemoryCustomerServiceIdempotencyStore();
        $store->transitionThrows = true;
        $service = new CustomerServiceIdempotencyService($store);
        $executions = 0;

        try {
            $service->execute(
                'customer-service:order-ship:12',
                'execution-bind-error',
                ['logistics_sn' => 'SF012'],
                function () use (&$executions): array {
                    $executions++;
                    return ['id' => 12, 'message' => '发货成功'];
                }
            );
            self::fail('A result was returned although its terminal state could not be saved.');
        } catch (BusinessException $error) {
            $this->assertSame(503, $error->getCode());
            $this->assertSame('客服连接器幂等存储不可用，请稍后重试', $error->getMessage());
        }

        $record = $this->decodeRecord(array_values($store->records)[0]);
        $this->assertSame('processing', $record['state']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/D', $record['owner']);

        $store->transitionThrows = false;
        try {
            $service->execute(
                'customer-service:order-ship:12',
                'execution-bind-error',
                ['logistics_sn' => 'SF012'],
                function () use (&$executions): array {
                    $executions++;
                    return [];
                }
            );
            self::fail('A request with an unresolved result was executed again.');
        } catch (BusinessException $error) {
            $this->assertSame(409, $error->getCode());
            $this->assertSame('客服连接器操作正在处理中', $error->getMessage());
        }

        $this->assertSame(1, $executions);
    }

    public function testSuccessfulOperationWhoseOwnerCasLosesFailsClosedAndNeverRunsAgain(): void
    {
        $store = new InMemoryCustomerServiceIdempotencyStore();
        $store->transitionAlwaysFails = true;
        $service = new CustomerServiceIdempotencyService($store);
        $executions = 0;

        try {
            $service->execute(
                'customer-service:order-ship:14',
                'execution-owner-cas-lost',
                ['logistics_sn' => 'SF014'],
                function () use (&$executions): array {
                    $executions++;
                    return ['id' => 14, 'message' => '发货成功'];
                }
            );
            self::fail('A result was returned after the processing owner lost its CAS.');
        } catch (BusinessException $error) {
            $this->assertSame(503, $error->getCode());
            $this->assertSame('客服连接器幂等结果保存失败，请核验操作结果', $error->getMessage());
        }

        $record = $this->decodeRecord(array_values($store->records)[0]);
        $this->assertSame('processing', $record['state']);

        $store->transitionAlwaysFails = false;
        try {
            $service->execute(
                'customer-service:order-ship:14',
                'execution-owner-cas-lost',
                ['logistics_sn' => 'SF014'],
                function () use (&$executions): array {
                    $executions++;
                    return [];
                }
            );
            self::fail('A request whose prior owner lost CAS was executed again.');
        } catch (BusinessException $error) {
            $this->assertSame(409, $error->getCode());
            $this->assertSame('客服连接器操作正在处理中', $error->getMessage());
        }

        $this->assertSame(1, $executions);
    }

    public function testOperationExceptionWithUnpersistedUncertainStateFailsClosedAndNeverRunsAgain(): void
    {
        $store = new InMemoryCustomerServiceIdempotencyStore();
        $store->transitionThrows = true;
        $service = new CustomerServiceIdempotencyService($store);
        $executions = 0;

        try {
            $service->execute(
                'customer-service:refund-approve:13',
                'execution-uncertain-bind-error',
                ['admin_remark' => '同意'],
                function () use (&$executions): array {
                    $executions++;
                    throw new RuntimeException('operation failed');
                }
            );
            self::fail('An operation with an unpersisted outcome did not fail closed.');
        } catch (BusinessException $error) {
            $this->assertSame(503, $error->getCode());
            $this->assertSame('客服连接器幂等存储不可用，请稍后重试', $error->getMessage());
        }

        $store->transitionThrows = false;
        try {
            $service->execute(
                'customer-service:refund-approve:13',
                'execution-uncertain-bind-error',
                ['admin_remark' => '同意'],
                function () use (&$executions): array {
                    $executions++;
                    return [];
                }
            );
            self::fail('The unresolved operation was executed again.');
        } catch (BusinessException $error) {
            $this->assertSame(409, $error->getCode());
        }

        $this->assertSame(1, $executions);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeRecord(string $record): array
    {
        $decoded = json_decode($record, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}

final class InMemoryCustomerServiceIdempotencyStore extends CustomerServiceIdempotencyStore
{
    /** @var array<string, string> */
    public array $records = [];

    /** @var array<int, int> */
    public array $ttls = [];

    public int $claimCalls = 0;

    public int $readCalls = 0;

    public int $transitionCalls = 0;

    public bool $claimThrows = false;

    public bool $claimAlwaysMisses = false;

    public bool $readThrows = false;

    public bool $transitionThrows = false;

    public bool $transitionAlwaysFails = false;

    public bool $hasReadOverride = false;

    public ?string $readOverride = null;

    public function claim(string $logicalKey, string $processingState, int $ttl): bool
    {
        $this->claimCalls++;
        $this->ttls[] = $ttl;
        if ($this->claimThrows) {
            throw new RuntimeException('claim failed');
        }
        if ($this->claimAlwaysMisses || array_key_exists($logicalKey, $this->records)) {
            return false;
        }

        $this->records[$logicalKey] = $processingState;
        return true;
    }

    public function read(string $logicalKey): ?string
    {
        $this->readCalls++;
        if ($this->readThrows) {
            throw new RuntimeException('read failed');
        }
        if ($this->hasReadOverride) {
            return $this->readOverride;
        }

        return $this->records[$logicalKey] ?? null;
    }

    public function transition(
        string $logicalKey,
        string $expectedProcessingState,
        string $terminalState,
        int $ttl
    ): bool {
        $this->transitionCalls++;
        $this->ttls[] = $ttl;
        if ($this->transitionThrows) {
            throw new RuntimeException('transition failed');
        }
        if ($this->transitionAlwaysFails
            || ($this->records[$logicalKey] ?? null) !== $expectedProcessingState) {
            return false;
        }

        $this->records[$logicalKey] = $terminalState;
        return true;
    }
}
