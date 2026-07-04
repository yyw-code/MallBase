<?php
declare(strict_types=1);

namespace app\validate\admin\marketing;

use think\Validate;

/**
 * 积分兑换单验证器
 */
class PointsExchangeOrderValidate extends Validate
{
    protected $rule = [
        'logistics_company' => 'require|max:80',
        'logistics_no' => 'require|max:80',
        'admin_remark' => 'max:255',
    ];

    protected $message = [
        'logistics_company.require' => '物流公司不能为空',
        'logistics_company.max' => '物流公司最多80个字符',
        'logistics_no.require' => '物流单号不能为空',
        'logistics_no.max' => '物流单号最多80个字符',
        'admin_remark.max' => '后台备注最多255个字符',
    ];

    protected $scene = [
        'ship' => ['logistics_company', 'logistics_no', 'admin_remark'],
        'close' => ['admin_remark'],
    ];
}
