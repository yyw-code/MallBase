<?php
declare(strict_types=1);

namespace app\extension\order;

use app\model\order\Order;
use app\model\order\RefundOrder;

/**
 * 订单域扩展事件上下文。
 */
final class OrderEventContext
{
    /**
     * @param array<string,mixed> $payload
     */
    private function __construct(
        private readonly string $event,
        private readonly ?Order $order = null,
        private readonly ?RefundOrder $refund = null,
        private readonly ?int $fromStatus = null,
        private readonly ?int $toStatus = null,
        private readonly array $payload = [],
    ) {
    }

    /**
     * @param array<string,mixed> $payload
     */
    public static function forOrder(
        string $event,
        Order $order,
        ?int $fromStatus = null,
        ?int $toStatus = null,
        array $payload = [],
    ): self {
        return new self(
            event: $event,
            order: $order,
            fromStatus: $fromStatus,
            toStatus: $toStatus,
            payload: $payload,
        );
    }

    /**
     * @param array<string,mixed> $payload
     */
    public static function forRefund(
        string $event,
        RefundOrder $refund,
        ?int $fromStatus = null,
        ?int $toStatus = null,
        array $payload = [],
    ): self {
        return new self(
            event: $event,
            refund: $refund,
            fromStatus: $fromStatus,
            toStatus: $toStatus,
            payload: $payload,
        );
    }

    public function event(): string
    {
        return $this->event;
    }

    public function order(): ?Order
    {
        return $this->order;
    }

    public function requireOrder(): Order
    {
        if ($this->order === null) {
            throw new \InvalidArgumentException(sprintf('事件 %s 缺少订单上下文', $this->event));
        }
        return $this->order;
    }

    public function refund(): ?RefundOrder
    {
        return $this->refund;
    }

    public function requireRefund(): RefundOrder
    {
        if ($this->refund === null) {
            throw new \InvalidArgumentException(sprintf('事件 %s 缺少售后单上下文', $this->event));
        }
        return $this->refund;
    }

    public function fromStatus(): ?int
    {
        return $this->fromStatus;
    }

    public function toStatus(): ?int
    {
        return $this->toStatus;
    }

    /**
     * @return array<string,mixed>
     */
    public function payload(): array
    {
        return $this->payload;
    }
}
