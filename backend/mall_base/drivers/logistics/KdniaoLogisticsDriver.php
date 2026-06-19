<?php

declare(strict_types=1);

namespace mall_base\drivers\logistics;

/**
 * 快递鸟即时查询驱动
 *
 * 文档：https://kuaidiniao.apifox.cn/api-72933643
 */
class KdniaoLogisticsDriver extends BaseLogisticsDriver
{
    private const DEFAULT_ENDPOINT = 'https://api.kdniao.com/api/dist';
    private const REQUEST_TYPE_TRACK = '8002';

    public function query(string $companyCode, string $trackingNo, array $options = []): array
    {
        $this->clearError();

        $businessId = trim((string) ($this->getConfig('business_id', '') ?: $this->getConfig('ebusiness_id', '')));
        $appKey = trim((string) ($this->getConfig('key', '') ?: $this->getConfig('app_key', '')));
        if ($businessId === '' || $appKey === '') {
            return $this->failureResult('快递鸟 EBusinessID 或 AppKey 未配置');
        }

        $companyCode = trim($companyCode);
        $trackingNo = trim($trackingNo);
        if ($companyCode === '' || $trackingNo === '') {
            return $this->failureResult('快递公司编码和运单号必填');
        }

        $requestData = [
            'ShipperCode' => $companyCode,
            'LogisticCode' => $trackingNo,
        ];
        $customerName = $this->customerName($options);
        if ($customerName !== '') {
            $requestData['CustomerName'] = $customerName;
        }

        $decoded = $this->requestPlatform(self::REQUEST_TYPE_TRACK, $requestData, $businessId, $appKey);
        if (!is_array($decoded)) {
            return $this->failureResult($this->getError() ?: '快递鸟请求失败');
        }

        if (($decoded['Success'] ?? false) !== true) {
            $message = (string) ($decoded['Reason'] ?? $decoded['Message'] ?? '快递鸟查询失败');
            return $this->failureResult($message, $decoded);
        }

        return $this->successFromDecoded($decoded, $companyCode, $trackingNo);
    }

    /**
     * @param array<string, mixed> $decoded
     * @return array<string, mixed>
     */
    private function successFromDecoded(array $decoded, string $companyCode, string $trackingNo): array
    {
        if (!is_array($decoded['Traces'] ?? null)) {
            return $this->failureResult('快递鸟暂无轨迹', $decoded);
        }

        return $this->successResult([
            'state' => $this->mapState((string) ($decoded['State'] ?? '')),
            'com' => (string) ($decoded['ShipperCode'] ?? $companyCode),
            'nu' => (string) ($decoded['LogisticCode'] ?? $trackingNo),
            'data' => array_reverse($decoded['Traces']),
            'raw' => $decoded,
        ]);
    }

    /**
     * @param array<string, mixed> $requestData
     * @return array<string, mixed>|false
     */
    private function requestPlatform(string $requestType, array $requestData, string $businessId, string $appKey): array|false
    {
        $requestJson = json_encode($requestData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($requestJson === false) {
            $this->setError('快递鸟请求参数编码失败');
            return false;
        }

        $form = [
            'EBusinessID' => $businessId,
            'RequestType' => $requestType,
            'RequestData' => $requestJson,
            'DataSign' => base64_encode(md5($requestJson . $appKey)),
            'DataType' => '2',
        ];

        $response = $this->postForm((string) $this->getConfig('endpoint', self::DEFAULT_ENDPOINT), $form);
        if ($response === false) {
            $this->setError($this->getError() ?: '快递鸟请求失败');
            return false;
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            $this->setError('快递鸟响应解析失败');
            return false;
        }

        return $decoded;
    }

    /**
     * @param array<string, string> $form
     */
    protected function postForm(string $url, array $form): string|false
    {
        $timeout = max(1, (int) $this->getConfig('timeout', 8));
        $body = http_build_query($form);
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => [
                    'Content-Type: application/x-www-form-urlencoded',
                    'Content-Length: ' . strlen($body),
                ],
                'content' => $body,
                'timeout' => $timeout,
                'ignore_errors' => true,
            ],
        ]);

        $result = @file_get_contents($url, false, $context);
        if ($result === false) {
            $this->setError('快递鸟网络请求失败');
            return false;
        }

        return $result;
    }

    private function mapState(string $state): string
    {
        return match ($state) {
            '1' => '1',
            '2' => '0',
            '3' => '3',
            '4' => '2',
            default => 'unknown',
        };
    }

    /**
     * @param array<string, mixed> $options
     */
    private function customerName(array $options): string
    {
        $customerName = trim((string) ($options['customer_name'] ?? ''));
        if ($customerName !== '') {
            return mb_substr($customerName, 0, 64);
        }

        $phone = preg_replace('/\D+/', '', (string) ($options['phone'] ?? ''));
        if ($phone === null || strlen($phone) < 4) {
            return '';
        }

        return substr($phone, -4);
    }
}
