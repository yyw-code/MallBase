<?php
declare(strict_types=1);

namespace app\model\setting;

use mall_base\base\BaseModel;

/**
 * 运费模板规则模型
 *
 * @property int $id
 * @property int $template_id
 * @property array<int, int> $region_ids           区域ID集合（省/市/区/街道任意层级）
 * @property array<int, string> $region_codes      区域编码集合
 * @property array<int, string> $region_names      区域名称集合
 * @property array<int, string> $region_path_texts 区域路径快照集合
 * @property int $match_level                      规则最精确层级：1省 2市 3区 4街道
 * @property float $first_amount
 * @property float $first_fee
 * @property float $continue_amount
 * @property float $continue_fee
 * @property int $region_status                    区域状态：0失效 1有效
 * @property string|null $region_invalid_reason
 * @property int $sort
 */
class FreightTemplateRule extends BaseModel
{
    protected $name = 'freight_template_rule';
    protected $json = ['region_ids', 'region_codes', 'region_names', 'region_path_texts'];
    protected $jsonAssoc = true;
}
