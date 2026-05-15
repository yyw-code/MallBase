<?php

declare(strict_types=1);

namespace app\service\admin\sms;

use app\model\sms\SmsConfig;
use mall_base\base\BaseService;

/**
 * 短信全局配置 Service(单行表)
 *
 * @extends BaseService<SmsConfig>
 */
class SmsConfigService extends BaseService
{
    protected string $modelClass = SmsConfig::class;

    public function getConfig(): array
    {
        return SmsConfig::singleton()->toArray();
    }

    public function save(array $data): void
    {
        $payload = [
            'id' => SmsConfig::SINGLETON_ID,
            'code_ttl' => (int) $data['code_ttl'],
            'rate_mobile_daily' => (int) $data['rate_mobile_daily'],
            'rate_ip_minute' => (int) $data['rate_ip_minute'],
        ];

        $row = $this->model()->find(SmsConfig::SINGLETON_ID);
        if ($row === null) {
            $this->model()->save($payload);
        } else {
            $row->save($payload);
        }
    }
}
