<?php
declare(strict_types=1);

namespace app\model\client;

use mall_base\base\BaseModel;

/**
 * 客户端装修方案快照模型
 */
class ClientDecorationSnapshot extends BaseModel
{
    protected $name = 'client_decoration_snapshot';

    protected $autoWriteTimestamp = true;

    protected $updateTime = false;

    protected $json = ['schema'];

    protected $jsonAssoc = true;
}
