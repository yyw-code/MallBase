<?php
declare(strict_types=1);

namespace Tests\Unit\Extension;

use app\extension\contracts\OrderEventListenerInterface;
use app\extension\order\OrderEvent;
use app\extension\order\OrderEventContext;
use app\extension\pipeline\OrderEventDispatcher;
use app\extension\registry\ExtensionRegistry;
use app\service\distribution\DistributionOrderEventListener;
use app\service\user\UserMemberOrderEventListener;
use app\service\user\UserPointsOrderEventListener;
use PHPUnit\Framework\TestCase;

final class OrderEventDispatcherTest extends TestCase
{
    public function testDispatchRunsEnabledListenersByEventPriority(): void
    {
        $calls = [];
        $dispatcher = new OrderEventDispatcher(new ExtensionRegistry([
            new FakeOrderEventListener('late', [OrderEvent::ORDER_COMPLETED], 30, true, $this->record($calls)),
            new FakeOrderEventListener('early', [OrderEvent::ORDER_COMPLETED], 10, true, $this->record($calls)),
            new FakeOrderEventListener('middle', [OrderEvent::ORDER_COMPLETED], 20, true, $this->record($calls)),
        ]));

        $dispatcher->dispatch($this->context(OrderEvent::ORDER_COMPLETED));

        $this->assertSame(['early', 'middle', 'late'], $calls);
    }

    public function testDispatchSkipsUnsupportedAndDisabledListeners(): void
    {
        $calls = [];
        $dispatcher = new OrderEventDispatcher(new ExtensionRegistry([
            new FakeOrderEventListener('unsupported', [OrderEvent::ORDER_PAID], 10, true, $this->record($calls)),
            new FakeOrderEventListener('disabled', [OrderEvent::ORDER_COMPLETED], 20, false, $this->record($calls)),
            new FakeOrderEventListener('enabled', [OrderEvent::ORDER_COMPLETED], 30, true, $this->record($calls)),
        ]));

        $dispatcher->dispatch($this->context(OrderEvent::ORDER_COMPLETED));

        $this->assertSame(['enabled'], $calls);
    }

    public function testDispatchKeepsCodeOrderWhenPriorityIsSame(): void
    {
        $calls = [];
        $dispatcher = new OrderEventDispatcher(new ExtensionRegistry([
            new FakeOrderEventListener('b_listener', [OrderEvent::ORDER_COMPLETED], 10, true, $this->record($calls)),
            new FakeOrderEventListener('a_listener', [OrderEvent::ORDER_COMPLETED], 10, true, $this->record($calls)),
        ]));

        $dispatcher->dispatch($this->context(OrderEvent::ORDER_COMPLETED));

        $this->assertSame(['a_listener', 'b_listener'], $calls);
    }

    public function testDispatchPropagatesListenerException(): void
    {
        $dispatcher = new OrderEventDispatcher(new ExtensionRegistry([
            new FakeOrderEventListener(
                'broken',
                [OrderEvent::ORDER_COMPLETED],
                10,
                true,
                static fn (): never => throw new \RuntimeException('listener failed')
            ),
        ]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('listener failed');

        $dispatcher->dispatch($this->context(OrderEvent::ORDER_COMPLETED));
    }

    public function testBuiltInOrderEventListenersKeepCurrentBusinessOrder(): void
    {
        $registry = new ExtensionRegistry([
            new DistributionOrderEventListener(),
            new UserMemberOrderEventListener(),
            new UserPointsOrderEventListener(),
        ]);

        $this->assertSame(
            ['user_points.order_event', 'user_member.order_event', 'distribution.order_event'],
            $this->listenerCodes($registry, OrderEvent::ORDER_COMPLETED)
        );
        $this->assertSame(
            ['user_points.order_event', 'distribution.order_event'],
            $this->listenerCodes($registry, OrderEvent::REFUND_COMPLETED)
        );
        $this->assertSame(
            ['distribution.order_event', 'user_points.order_event'],
            $this->listenerCodes($registry, OrderEvent::ORDER_CLOSED)
        );
    }

    /**
     * @param array<int,string> $calls
     */
    private function record(array &$calls): \Closure
    {
        return static function (string $code) use (&$calls): void {
            $calls[] = $code;
        };
    }

    private function context(string $event): OrderEventContext
    {
        $ref = new \ReflectionClass(OrderEventContext::class);
        /** @var OrderEventContext $context */
        $context = $ref->newInstanceWithoutConstructor();

        foreach ([
            'event' => $event,
            'order' => null,
            'refund' => null,
            'fromStatus' => null,
            'toStatus' => null,
            'payload' => [],
        ] as $name => $value) {
            $prop = new \ReflectionProperty(OrderEventContext::class, $name);
            $prop->setAccessible(true);
            $prop->setValue($context, $value);
        }

        return $context;
    }

    /**
     * @return array<int,string>
     */
    private function listenerCodes(ExtensionRegistry $registry, string $event): array
    {
        return array_map(
            static fn (OrderEventListenerInterface $listener): string => $listener->code(),
            $registry->orderEventListeners($event)
        );
    }
}

final class FakeOrderEventListener implements OrderEventListenerInterface
{
    /**
     * @param array<int,string> $events
     */
    public function __construct(
        private readonly string $code,
        private readonly array $events,
        private readonly int $priority,
        private readonly bool $enabled,
        private readonly \Closure $handler,
    ) {
    }

    public function code(): string
    {
        return $this->code;
    }

    public function supports(string $event): bool
    {
        return in_array($event, $this->events, true);
    }

    public function priority(string $event): int
    {
        return $this->priority;
    }

    public function enabled(OrderEventContext $context): bool
    {
        return $this->enabled;
    }

    public function handle(OrderEventContext $context): void
    {
        ($this->handler)($this->code, $context);
    }
}
