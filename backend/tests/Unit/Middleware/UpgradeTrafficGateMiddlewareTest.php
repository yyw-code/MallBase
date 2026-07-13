<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware;

use app\middleware\admin\UpgradeAdminGateMiddleware;
use app\middleware\UpgradeTrafficGateMiddleware;
use app\service\upgrade\ExternalCallbackMaintenancePolicy;
use app\service\upgrade\UpgradeActivityLease;
use app\service\upgrade\UpgradeActivitySnapshot;
use app\service\upgrade\UpgradeActivityTracker;
use app\service\upgrade\UpgradeGateRepository;
use app\service\upgrade\UpgradeGateSnapshot;
use app\service\upgrade\UpgradeMaintenanceResponse;
use app\service\upgrade\UpgradeQueueInventory;
use app\service\upgrade\UpgradeRuntimeContext;
use app\service\upgrade\UpgradeRuntimeIdentity;
use app\service\upgrade\UpgradeRuntimeInstance;
use app\service\upgrade\UpgradeRuntimeOwnerLiveness;
use app\service\upgrade\UpgradeState;
use Closure;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use think\Request;
use think\Response;
use think\Config;
use think\Container;

final class UpgradeTrafficGateMiddlewareTest extends TestCase
{
    private bool $previousUpgradeEnabled;
    private object $previousContainer;

    protected function setUp(): void
    {
        parent::setUp();
        if (!function_exists('config')) {
            require_once dirname(__DIR__, 3) . '/vendor/topthink/framework/src/helper.php';
        }
        $this->previousContainer = Container::getInstance();
        $container = new Container();
        $container->instance('config', new Config());
        Container::setInstance($container);
        $this->previousUpgradeEnabled = (bool) \config('upgrade.enabled', false);
        \config(['enabled' => true], 'upgrade');
    }

    protected function tearDown(): void
    {
        \config(['enabled' => $this->previousUpgradeEnabled], 'upgrade');
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    public function testNormalBusinessTrafficIsCountedAndReleasedInFinally(): void
    {
        $events = [];
        [$middleware, $activity] = $this->trafficMiddleware(UpgradeState::Normal, events: $events);
        $request = $this->request('client/api/goods/list', 'GET', ['X-Request-Id' => 'request-1']);

        try {
            $middleware->handle($request, static function () use (&$events): Response {
                $events[] = 'next';
                throw new RuntimeException('downstream failed');
            });
            self::fail('downstream exception was swallowed');
        } catch (RuntimeException $exception) {
            self::assertSame('downstream failed', $exception->getMessage());
        }

        self::assertSame(1, $activity->httpBegins);
        self::assertSame(1, $activity->releases);
        self::assertSame(['http.begin:request-1', 'next', 'lease.release:http'], $events);
    }

    public function testDefaultDisabledModeBypassesUnboundUpgradeInfrastructure(): void
    {
        \config(['enabled' => false], 'upgrade');
        $request = $this->request('client/api/goods/list');

        $global = (new UpgradeTrafficGateMiddleware())->handle(
            $request,
            static fn(): Response => response('global-disabled', 200),
        );
        $admin = (new UpgradeAdminGateMiddleware())->handle(
            $request,
            static fn(): Response => response('admin-disabled', 200),
        );

        self::assertSame(200, $global->getCode());
        self::assertSame('global-disabled', $global->getContent());
        self::assertSame(200, $admin->getCode());
        self::assertSame('admin-disabled', $admin->getContent());
    }

    #[DataProvider('businessAllowedStateProvider')]
    public function testPreparingAndReadyToDrainBusinessTrafficRemainsTracked(UpgradeState $state): void
    {
        [$middleware, $activity] = $this->trafficMiddleware($state);

        $response = $middleware->handle(
            $this->request('client/api/goods/list'),
            static fn(): Response => response('allowed', 200),
        );

        self::assertSame(200, $response->getCode());
        self::assertSame(1, $activity->httpBegins);
        self::assertSame(1, $activity->releases);
    }

    /** @return array<string,array{UpgradeState}> */
    public static function businessAllowedStateProvider(): array
    {
        return [
            'preparing' => [UpgradeState::Preparing],
            'ready to drain' => [UpgradeState::ReadyToDrain],
        ];
    }

    #[DataProvider('businessBlockedStateProvider')]
    public function testDrainingAndLaterBusinessTrafficReturns503(UpgradeState $state): void
    {
        [$middleware, $activity] = $this->trafficMiddleware($state);
        $nextCalled = false;

        $response = $middleware->handle(
            $this->request('client/api/goods/list'),
            static function () use (&$nextCalled): Response {
                $nextCalled = true;

                return response('unexpected', 200);
            },
        );

        self::assertSame(503, $response->getCode());
        self::assertFalse($nextCalled);
        self::assertSame(0, $activity->httpBegins);
        self::assertSame('SYSTEM_MAINTENANCE', $this->jsonBody($response)['data']['reason'] ?? null);
    }

    /** @return array<string,array{UpgradeState}> */
    public static function businessBlockedStateProvider(): array
    {
        return [
            'draining' => [UpgradeState::Draining],
            'paused' => [UpgradeState::Paused],
            'applying' => [UpgradeState::Applying],
            'awaiting deployment' => [UpgradeState::AwaitingDeployment],
            'reconciling' => [UpgradeState::Reconciling],
            'failed maintenance' => [UpgradeState::FailedMaintenance],
        ];
    }

    #[DataProvider('callbackAllowedStateProvider')]
    public function testCallbacksEnterDuringDrainingAndReconciliationAndRemainTracked(UpgradeState $state): void
    {
        [$middleware, $activity] = $this->trafficMiddleware($state);
        $request = $this->request(
            'api/notify/wechat/pay',
            'POST',
            ['Wechatpay-Request-Id' => 'wechat-request-1'],
        );

        $response = $middleware->handle($request, static fn(): Response => response('wechat-ok', 200));

        self::assertSame(200, $response->getCode());
        self::assertSame(1, $activity->callbackBegins);
        self::assertSame(1, $activity->releases);
        self::assertSame('wechat-request-1', $activity->lastCallbackRequestId);
    }

    /** @return array<string,array{UpgradeState}> */
    public static function callbackAllowedStateProvider(): array
    {
        return [
            'draining' => [UpgradeState::Draining],
            'reconciling' => [UpgradeState::Reconciling],
        ];
    }

    #[DataProvider('callbackBlockedStateProvider')]
    public function testPausedSwitchingAndFailedCallbacksReturnWechat500(UpgradeState $state): void
    {
        [$middleware, $activity] = $this->trafficMiddleware($state);
        $nextCalled = false;

        $response = $middleware->handle(
            $this->request('api/notify/wechat/refund', 'POST'),
            static function () use (&$nextCalled): Response {
                $nextCalled = true;

                return response('unexpected', 200);
            },
        );

        self::assertSame(500, $response->getCode());
        self::assertFalse($nextCalled);
        self::assertSame(0, $activity->callbackBegins);
        self::assertSame('FAIL', $this->jsonBody($response)['code'] ?? null);
    }

    /** @return array<string,array{UpgradeState}> */
    public static function callbackBlockedStateProvider(): array
    {
        return [
            'paused' => [UpgradeState::Paused],
            'switching deployment' => [UpgradeState::AwaitingDeployment],
            'failed maintenance' => [UpgradeState::FailedMaintenance],
        ];
    }

    public function testRuntimeIdentityMismatchIsFencedBeforeBusinessTracking(): void
    {
        [$middleware, $activity] = $this->trafficMiddleware(
            UpgradeState::Normal,
            owner: $this->owner(self::WRONG_DEPLOYMENT_ID),
        );

        $response = $middleware->handle(
            $this->request('client/api/goods/list'),
            static fn(): Response => response('unexpected', 200),
        );
        $body = $this->jsonBody($response);

        self::assertSame(503, $response->getCode());
        self::assertSame('RUNTIME_IDENTITY_FENCED', $body['data']['cause'] ?? null);
        self::assertSame(0, $activity->httpBegins);
    }

    #[DataProvider('bypassPathProvider')]
    public function testUpgradeAndHealthPathsBypassGateAndTracking(string $path): void
    {
        [$middleware, $activity, $gate] = $this->trafficMiddleware(
            UpgradeState::Paused,
            owner: $this->owner(self::WRONG_DEPLOYMENT_ID),
        );

        $response = $middleware->handle(
            $this->request($path),
            static fn(): Response => response('bypass', 200),
        );

        self::assertSame(200, $response->getCode());
        self::assertSame(0, $gate->snapshotCalls);
        self::assertSame(0, $activity->httpBegins);
    }

    /** @return array<string,array{string}> */
    public static function bypassPathProvider(): array
    {
        return [
            'upgrade root' => ['upgrade'],
            'upgrade child' => ['upgrade/status'],
            'api health' => ['api/health'],
            'health' => ['health'],
        ];
    }

    public function testAdminApiIsTrackedOnlyByRouteMiddleware(): void
    {
        $events = [];
        $snapshot = $this->snapshot(UpgradeState::Normal);
        $gate = new MiddlewareGateRepository($snapshot);
        $activity = new MiddlewareActivityTracker($events);
        $runtime = new MiddlewareRuntimeContext($this->owner());
        $maintenance = new UpgradeMaintenanceResponse();
        $callbacks = new ExternalCallbackMaintenancePolicy($activity, $runtime, $maintenance);
        $global = new UpgradeTrafficGateMiddleware($gate, $activity, $runtime, $maintenance, $callbacks);
        $admin = new UpgradeAdminGateMiddleware($gate, $activity, $runtime, $maintenance);
        $request = $this->request('admin/api/system/info', 'GET', ['X-Request-Id' => 'admin-request-1']);

        $response = $global->handle(
            $request,
            static fn(Request $request): Response => $admin->handle(
                $request,
                static fn(): Response => response('admin-ok', 200),
            ),
        );

        self::assertSame(200, $response->getCode());
        self::assertSame(1, $activity->httpBegins, 'global middleware must not duplicate admin route tracking');
        self::assertSame(1, $activity->releases);
        self::assertSame('admin-request-1', $activity->lastHttpRequestId);
        self::assertSame(['http.begin:admin-request-1', 'lease.release:http'], $events);
    }

    public function testExternalCallbackPolicyMatchesOnlyExactPostRoutes(): void
    {
        $activity = new MiddlewareActivityTracker();
        $policy = new ExternalCallbackMaintenancePolicy(
            $activity,
            new MiddlewareRuntimeContext($this->owner()),
            new UpgradeMaintenanceResponse(),
        );

        self::assertTrue($policy->matches($this->request('api/notify/wechat/pay', 'POST')));
        self::assertTrue($policy->matches($this->request('/api/notify/wechat/refund/', 'POST')));
        self::assertFalse($policy->matches($this->request('api/notify/wechat/pay', 'GET')));
        self::assertFalse($policy->matches($this->request('api/notify/wechat/pay/extra', 'POST')));
    }

    /** @param list<string> $events @return array{UpgradeTrafficGateMiddleware,MiddlewareActivityTracker,MiddlewareGateRepository} */
    private function trafficMiddleware(
        UpgradeState $state,
        ?UpgradeRuntimeInstance $owner = null,
        array &$events = [],
    ): array {
        $gate = new MiddlewareGateRepository($this->snapshot($state));
        $activity = new MiddlewareActivityTracker($events);
        $runtime = new MiddlewareRuntimeContext($owner ?? $this->owner());
        $maintenance = new UpgradeMaintenanceResponse();
        $callbacks = new ExternalCallbackMaintenancePolicy($activity, $runtime, $maintenance);

        return [
            new UpgradeTrafficGateMiddleware($gate, $activity, $runtime, $maintenance, $callbacks),
            $activity,
            $gate,
        ];
    }

    private function request(string $path, string $method = 'GET', array $headers = []): Request
    {
        return (new Request())
            ->setPathinfo($path)
            ->setMethod($method)
            ->withHeader($headers);
    }

    private function snapshot(UpgradeState $state): UpgradeGateSnapshot
    {
        return new UpgradeGateSnapshot(
            $state,
            7,
            $state === UpgradeState::Normal ? null : self::JOB_ID,
            '1.2.0',
            self::DEPLOYMENT_ID,
            1,
            4,
            2,
            11,
            str_repeat('a', 40),
            false,
            [],
            false,
            null,
            1_000,
        );
    }

    private function owner(string $deploymentId = self::DEPLOYMENT_ID): UpgradeRuntimeInstance
    {
        return new UpgradeRuntimeInstance(
            self::RUNTIME_ID,
            self::BOOT_ID,
            'http',
            new UpgradeRuntimeIdentity('1.2.0', $deploymentId, 1, 4),
            2,
        );
    }

    /** @return array<string,mixed> */
    private function jsonBody(Response $response): array
    {
        $body = json_decode($response->getContent(), true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($body);

        return $body;
    }

    private const JOB_ID = '018f5d35-3f42-7a31-a731-9e45df3356c2';
    private const RUNTIME_ID = '218f5d35-3f42-7a31-a731-9e45df3356c2';
    private const BOOT_ID = '318f5d35-3f42-7a31-a731-9e45df3356c2';
    private const DEPLOYMENT_ID = 'a475c0b8-ae6a-4df8-a1c9-f3dfa6af9ef7';
    private const WRONG_DEPLOYMENT_ID = 'b475c0b8-ae6a-4df8-a1c9-f3dfa6af9ef7';
}

final class MiddlewareGateRepository implements UpgradeGateRepository
{
    public int $snapshotCalls = 0;

    public function __construct(private readonly UpgradeGateSnapshot $snapshot)
    {
    }

    public function snapshot(): UpgradeGateSnapshot
    {
        $this->snapshotCalls++;

        return $this->snapshot;
    }

    public function compareAndSet(int $expectedRevision, UpgradeState $expectedState, UpgradeState $nextState, string $jobId): UpgradeGateSnapshot
    {
        throw new LogicException('not used');
    }

    public function returnToNormal(int $expectedRevision, UpgradeState $terminalState, string $jobId, bool $platformSyncPending): UpgradeGateSnapshot
    {
        throw new LogicException('not used');
    }

    public function advanceRuntimeFence(int $expectedRevision, UpgradeRuntimeIdentity $current, UpgradeRuntimeIdentity $target, string $jobId): UpgradeGateSnapshot
    {
        throw new LogicException('not used');
    }

    public function forceMaintenance(int $expectedRevision, string $jobId, string $failureCode): UpgradeGateSnapshot
    {
        throw new LogicException('not used');
    }

    public function recordActivityUncertainty(int $expectedRevision, array $taintedBoots): UpgradeGateSnapshot
    {
        throw new LogicException('not used');
    }

    public function acknowledgeRuntimeRegistration(int $expectedRevision, array $runtimeRecord): UpgradeGateSnapshot
    {
        throw new LogicException('not used');
    }

    public function beginActivityRecovery(int $expectedRevision, string $redisIncarnation): UpgradeGateSnapshot
    {
        throw new LogicException('not used');
    }

    public function recordRetiredTaintedOwner(int $expectedRevision, string $ownerKey): UpgradeGateSnapshot
    {
        throw new LogicException('not used');
    }

    public function clearActivityUncertainty(int $expectedRevision, array $requiredRoles, array $cleanRoleRecords): UpgradeGateSnapshot
    {
        throw new LogicException('not used');
    }
}

final readonly class MiddlewareRuntimeContext implements UpgradeRuntimeContext
{
    public function __construct(private UpgradeRuntimeInstance $owner)
    {
    }

    public function owner(string $role): UpgradeRuntimeInstance
    {
        if ($role !== 'http') {
            throw new LogicException('unexpected role');
        }

        return $this->owner;
    }
}

final class MiddlewareActivityTracker implements UpgradeActivityTracker
{
    public int $httpBegins = 0;
    public int $callbackBegins = 0;
    public int $releases = 0;
    public ?string $lastHttpRequestId = null;
    public ?string $lastCallbackRequestId = null;

    /** @param list<string> $events */
    public function __construct(private array &$events = [])
    {
    }

    public function tryBeginHttp(string $requestId, UpgradeRuntimeInstance $owner): ?UpgradeActivityLease
    {
        $this->httpBegins++;
        $this->lastHttpRequestId = $requestId;
        $this->events[] = 'http.begin:' . $requestId;

        return $this->lease('http');
    }

    public function tryBeginExternalCallback(string $requestId, UpgradeRuntimeInstance $owner): ?UpgradeActivityLease
    {
        $this->callbackBegins++;
        $this->lastCallbackRequestId = $requestId;
        $this->events[] = 'callback.begin:' . $requestId;

        return $this->lease('callback');
    }

    private function lease(string $kind): UpgradeActivityLease
    {
        return new UpgradeActivityLease(
            $kind . ':entry',
            str_repeat('a', 32),
            function () use ($kind): void {
                $this->releases++;
                $this->events[] = 'lease.release:' . $kind;
            },
        );
    }

    public function tryBeginCron(string $taskId, UpgradeRuntimeInstance $owner): ?UpgradeActivityLease
    {
        throw new LogicException('not used');
    }

    public function beginQueuePop(string $workerId, string $connectorType, array $queues, string $executionAttemptId, UpgradeRuntimeInstance $owner): ?UpgradeActivityLease
    {
        throw new LogicException('not used');
    }

    public function bindQueueJob(UpgradeActivityLease $popLease, string $connection, string $queue, string $jobId): UpgradeActivityLease
    {
        throw new LogicException('not used');
    }

    public function snapshot(): UpgradeActivitySnapshot
    {
        throw new LogicException('not used');
    }

    public function heartbeatWorker(string $workerId, string $connectorType, array $queues, UpgradeRuntimeInstance $owner, int $ttl): void
    {
        throw new LogicException('not used');
    }

    public function ackPaused(string $workerId, UpgradeRuntimeInstance $owner, int $revision, int $ttl): void
    {
        throw new LogicException('not used');
    }

    public function liveWorkers(): array
    {
        throw new LogicException('not used');
    }

    public function reconcileQueueLeases(UpgradeQueueInventory $inventory, UpgradeRuntimeOwnerLiveness $owners): void
    {
        throw new LogicException('not used');
    }

    public function reconcileOrphanActivityLeases(UpgradeRuntimeOwnerLiveness $owners): void
    {
        throw new LogicException('not used');
    }
}
