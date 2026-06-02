<?php
declare(strict_types=1);

namespace app\service\admin\marketing;

use app\model\marketing\RechargePackage;
use mall_base\base\BaseService;
use mall_base\exception\BusinessException;

/**
 * 充值套餐服务
 *
 * @extends BaseService<RechargePackage>
 */
class RechargePackageService extends BaseService
{
    protected string $modelClass = RechargePackage::class;

    protected function buildListQuery(array $where)
    {
        return $this->model()
            ->when(($where['name'] ?? '') !== '', function ($q) use ($where) {
                $q->whereLike('name', '%' . $where['name'] . '%');
            })
            ->when(($where['status'] ?? null) !== null && $where['status'] !== '', function ($q) use ($where) {
                $q->where('status', (int) $where['status']);
            });
    }

    /**
     * @return array{total:int,list:array<int,array<string,mixed>>}
     */
    public function getList(array $where, int $page, int $limit): array
    {
        $rows = $this->buildListQuery($where)
            ->order('sort', 'asc')
            ->order('id', 'desc')
            ->page($page, $limit)
            ->select()
            ->toArray();

        $total = (int) $this->buildListQuery($where)->count();
        $list = array_map(fn (array $row): array => $this->formatRow($row), $rows);

        return compact('total', 'list');
    }

    /**
     * @return array<string,mixed>
     */
    public function getInfo(int $id): array
    {
        $info = $this->model()->find($id);
        if (!$info) {
            throw new BusinessException('充值套餐不存在');
        }

        return $this->formatRow($info->toArray());
    }

    public function create(array $data): int
    {
        $payload = $this->normalizePayload($data);
        $this->validatePayload($payload);

        /** @var RechargePackage $package */
        $package = $this->model();
        $package->save($payload);

        return (int) $package->id;
    }

    public function update(int $id, array $data): bool
    {
        /** @var RechargePackage|null $package */
        $package = $this->model()->find($id);
        if (!$package) {
            throw new BusinessException('充值套餐不存在');
        }

        $payload = $this->normalizePayload($data);
        $this->validatePayload($payload);

        $package->save($payload);

        return true;
    }

    public function delete(int $id): bool
    {
        /** @var RechargePackage|null $package */
        $package = $this->model()->find($id);
        if (!$package) {
            throw new BusinessException('充值套餐不存在');
        }

        $package->delete();

        return true;
    }

    public function updateStatus(int $id, int $status): bool
    {
        if (!in_array($status, [0, 1], true)) {
            throw new BusinessException('状态不合法');
        }

        /** @var RechargePackage|null $package */
        $package = $this->model()->find($id);
        if (!$package) {
            throw new BusinessException('充值套餐不存在');
        }

        $package->save(['status' => $status]);

        return true;
    }

    /**
     * @return array<string,mixed>
     */
    private function normalizePayload(array $data): array
    {
        $payAmountCents = $this->decimalToCents((string) ($data['pay_amount'] ?? '0'));
        $giftAmountCents = $this->decimalToCents((string) ($data['gift_amount'] ?? '0'));

        return [
            'name' => trim((string) ($data['name'] ?? '')),
            'pay_amount_cents' => $payAmountCents,
            'gift_amount_cents' => $giftAmountCents,
            'balance_amount_cents' => $payAmountCents + $giftAmountCents,
            'background_image' => trim((string) ($data['background_image'] ?? '')),
            'sort' => (int) ($data['sort'] ?? 0),
            'status' => (int) ($data['status'] ?? 1),
            'remark' => mb_substr(trim((string) ($data['remark'] ?? '')), 0, 255),
        ];
    }

    private function validatePayload(array $payload): void
    {
        if ($payload['name'] === '') {
            throw new BusinessException('套餐名称不能为空');
        }
        if ($payload['pay_amount_cents'] <= 0) {
            throw new BusinessException('支付金额必须大于 0');
        }
        if ($payload['gift_amount_cents'] < 0) {
            throw new BusinessException('赠送金额不能小于 0');
        }
        if (!in_array($payload['status'], [0, 1], true)) {
            throw new BusinessException('状态不合法');
        }
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
            'sort' => (int) ($row['sort'] ?? 0),
            'status' => (int) ($row['status'] ?? 0),
            'remark' => (string) ($row['remark'] ?? ''),
            'create_time' => (string) ($row['create_time'] ?? ''),
            'update_time' => (string) ($row['update_time'] ?? ''),
        ];
    }

    private function decimalToCents(string $amount): int
    {
        $amount = trim($amount);
        if ($amount === '') {
            return 0;
        }
        if (!preg_match('/^\d+(\.\d{1,2})?$/', $amount)) {
            throw new BusinessException('金额格式不合法');
        }

        [$yuan, $cent] = array_pad(explode('.', $amount, 2), 2, '0');
        return ((int) $yuan * 100) + (int) str_pad(substr($cent, 0, 2), 2, '0');
    }

    private function centsToYuan(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
