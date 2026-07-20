<?php

declare(strict_types=1);

namespace Tests\Unit\Upgrade;

use app\cron\CronManager;
use app\middleware\admin\UpgradeAdminGateMiddleware;
use app\middleware\UpgradeTrafficGateMiddleware;
use app\queue\UpgradeAwareWorker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class SimpleUpgradeIntegrationContractTest extends TestCase
{
    #[DataProvider('simpleGateConsumerProvider')]
    public function testRuntimeConsumerAcceptsSimpleGateDependency(string $class): void
    {
        $constructor = (new ReflectionClass($class))->getConstructor();
        self::assertNotNull($constructor);
        $parameters = array_map(
            static fn(\ReflectionParameter $parameter): string => $parameter->getName(),
            $constructor->getParameters(),
        );

        self::assertContains('simpleGate', $parameters, $class . ' does not accept SimpleUpgradeGate');
    }

    /** @return array<string,array{class-string}> */
    public static function simpleGateConsumerProvider(): array
    {
        return [
            'business middleware' => [UpgradeTrafficGateMiddleware::class],
            'admin middleware' => [UpgradeAdminGateMiddleware::class],
            'Cron manager' => [CronManager::class],
            'queue worker' => [UpgradeAwareWorker::class],
        ];
    }
}
