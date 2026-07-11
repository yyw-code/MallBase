<?php
declare(strict_types=1);

namespace app\extension\registry;

use app\extension\contracts\OrderEventListenerInterface;
use app\extension\contracts\OrderPriceContributorInterface;
use app\service\distribution\DistributionOrderEventListener;
use app\service\user\UserMemberOrderEventListener;
use app\service\user\UserMemberPriceContributor;
use app\service\user\UserPointsOrderEventListener;
use app\service\user\UserPointsDeductionPriceContributor;
use app\service\user\UserPointsRewardPriceContributor;

class ExtensionRegistry
{
    /**
     * @var array<int,class-string<OrderEventListenerInterface>|OrderEventListenerInterface>
     */
    private array $orderEventListeners;

    /**
     * @var array<int,class-string<OrderPriceContributorInterface>|OrderPriceContributorInterface>
     */
    private array $orderPriceContributors;

    /**
     * @param array<int,class-string<OrderEventListenerInterface>|OrderEventListenerInterface>|null $orderEventListeners
     * @param array<int,class-string<OrderPriceContributorInterface>|OrderPriceContributorInterface>|null $orderPriceContributors
     */
    public function __construct(?array $orderEventListeners = null, ?array $orderPriceContributors = null)
    {
        $this->orderEventListeners = $orderEventListeners ?? [
            UserPointsOrderEventListener::class,
            UserMemberOrderEventListener::class,
            DistributionOrderEventListener::class,
        ];
        $this->orderPriceContributors = $orderPriceContributors ?? [
            UserMemberPriceContributor::class,
            UserPointsDeductionPriceContributor::class,
            UserPointsRewardPriceContributor::class,
        ];
    }

    /**
     * @return array<int,OrderEventListenerInterface>
     */
    public function orderEventListeners(string $event): array
    {
        $listeners = [];
        foreach ($this->orderEventListeners as $listener) {
            $instance = $this->resolveOrderEventListener($listener);
            if ($instance->supports($event)) {
                $listeners[] = $instance;
            }
        }

        usort(
            $listeners,
            static function (OrderEventListenerInterface $left, OrderEventListenerInterface $right) use ($event): int {
                $priorityCompare = $left->priority($event) <=> $right->priority($event);
                if ($priorityCompare !== 0) {
                    return $priorityCompare;
                }
                return strcmp($left->code(), $right->code());
            }
        );

        return $listeners;
    }

    /**
     * @return array<int,OrderPriceContributorInterface>
     */
    public function orderPriceContributors(): array
    {
        $contributors = [];
        foreach ($this->orderPriceContributors as $contributor) {
            $contributors[] = $this->resolveOrderPriceContributor($contributor);
        }

        usort(
            $contributors,
            static function (OrderPriceContributorInterface $left, OrderPriceContributorInterface $right): int {
                $priorityCompare = $left->priority() <=> $right->priority();
                if ($priorityCompare !== 0) {
                    return $priorityCompare;
                }
                return strcmp($left->code(), $right->code());
            }
        );

        return $contributors;
    }

    /**
     * @param class-string<OrderEventListenerInterface>|OrderEventListenerInterface $listener
     */
    private function resolveOrderEventListener(string|OrderEventListenerInterface $listener): OrderEventListenerInterface
    {
        if ($listener instanceof OrderEventListenerInterface) {
            return $listener;
        }

        $instance = app()->make($listener);
        if (!$instance instanceof OrderEventListenerInterface) {
            throw new \InvalidArgumentException(sprintf('%s 必须实现 %s', $listener, OrderEventListenerInterface::class));
        }

        return $instance;
    }

    /**
     * @param class-string<OrderPriceContributorInterface>|OrderPriceContributorInterface $contributor
     */
    private function resolveOrderPriceContributor(string|OrderPriceContributorInterface $contributor): OrderPriceContributorInterface
    {
        if ($contributor instanceof OrderPriceContributorInterface) {
            return $contributor;
        }

        $instance = app()->make($contributor);
        if (!$instance instanceof OrderPriceContributorInterface) {
            throw new \InvalidArgumentException(sprintf('%s 必须实现 %s', $contributor, OrderPriceContributorInterface::class));
        }

        return $instance;
    }
}
