<?php
declare(strict_types=1);

namespace app\controller\admin\distribution;

use app\service\admin\distribution\DistributionManagementService;
use mall_base\base\BaseController;

/**
 * 后台分销概览与设置控制器
 *
 * @extends BaseController<DistributionManagementService>
 */
class DistributionController extends BaseController
{
    protected string $serviceClass = DistributionManagementService::class;

    public function overview()
    {
        return $this->success($this->service()->overview(), '获取成功');
    }

    public function settings()
    {
        return $this->success($this->service()->settings(), '获取成功');
    }

    public function saveSettings()
    {
        $data = $this->request->param([
            'distribution_enabled',
            'distributor_open_mode',
            'auto_open_level_id',
            'second_level_enabled',
            'self_purchase_enabled',
            'relation_valid_days',
            'settlement_days',
            'min_withdraw_cents',
            'global_first_rate',
            'global_second_rate',
            'amount_open_threshold_cents',
            'invite_reward_enabled',
            'invite_reward_trigger',
            'invite_reward_amount_cents',
            'attribution_enabled',
        ]);
        $this->service()->saveSettings($data);

        return $this->success(null, '保存成功');
    }

    public function releaseDue()
    {
        $limit = (int) $this->request->param('limit', 500);
        return $this->success($this->service()->releaseDue($limit), '处理完成');
    }
}
