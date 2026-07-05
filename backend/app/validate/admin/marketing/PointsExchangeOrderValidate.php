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
        'delivery_type' => 'in:physical,virtual',
        'delivery_note' => 'max:255',
        'logistics_platform' => 'max:32',
        'logistics_company_id' => 'integer|egt:0',
        'logistics_company_code' => 'max:64',
        'logistics_company' => 'max:80',
        'logistics_no' => 'max:80',
        'admin_remark' => 'max:255',
    ];

    protected $message = [
        'delivery_type.in' => '发货类型不合法',
        'delivery_note.max' => '发货说明最多255个字符',
        'logistics_platform.max' => '物流平台最多32个字符',
        'logistics_company_id.integer' => '物流公司ID不合法',
        'logistics_company_id.egt' => '物流公司ID不合法',
        'logistics_company_code.max' => '物流公司编码最多64个字符',
        'logistics_company.max' => '物流公司最多80个字符',
        'logistics_no.max' => '物流单号最多80个字符',
        'admin_remark.max' => '后台备注最多255个字符',
    ];

    protected $scene = [
        'ship' => [
            'delivery_type',
            'delivery_note',
            'logistics_platform',
            'logistics_company_id',
            'logistics_company_code',
            'logistics_company',
            'logistics_no',
            'admin_remark',
        ],
        'close' => ['admin_remark'],
    ];
}
