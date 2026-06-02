<?php
declare(strict_types=1);

namespace app\service\client\recharge;

use app\model\marketing\RechargePackage;
use mall_base\base\BaseService;

/**
 * 客户端充值套餐服务
 *
 * @extends BaseService<RechargePackage>
 */
class RechargePackageService extends BaseService
{
    protected string $modelClass = RechargePackage::class;

    /**
     * @return array<int,array<string,mixed>>
     */
    public function list(): array
    {
        $rows = $this->model()
            ->where('status', 1)
            ->order('sort', 'asc')
            ->order('id', 'asc')
            ->select()
            ->toArray();

        return array_map(fn (array $row): array => $this->formatRow($row), $rows);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function formatRow(array $row): array
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'name' => (string) ($row['name'] ?? ''),
            'pay_amount' => $this->centsToYuan((int) ($row['pay_amount_cents'] ?? 0)),
            'gift_amount' => $this->centsToYuan((int) ($row['gift_amount_cents'] ?? 0)),
            'balance_amount' => $this->centsToYuan((int) ($row['balance_amount_cents'] ?? 0)),
            'background_image' => (string) ($row['background_image'] ?? ''),
            'background_image_full_url' => buildUploadUrl((string) ($row['background_image'] ?? '')),
            'remark' => (string) ($row['remark'] ?? ''),
        ];
    }

    private function centsToYuan(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
