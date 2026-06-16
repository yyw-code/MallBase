<?php

declare(strict_types=1);

namespace mall_base\drivers\logistics;

use mall_base\base\BaseDriver;

/**
 * 物流查询驱动基类
 *
 * 驱动只负责平台请求和响应标准化，订单归属、缓存、展示字段等业务逻辑放在 app/service/logistics。
 */
abstract class BaseLogisticsDriver extends BaseDriver
{
    private const STATE_TEXT_MAP = [
        '0' => '运输中',
        '1' => '已揽收',
        '2' => '疑难',
        '3' => '已签收',
        '4' => '退签',
        '5' => '派件中',
        '8' => '清关中',
        '14' => '已拒签',
    ];

    /**
     * 查询物流轨迹。
     *
     * @param array<string, mixed> $options 平台扩展参数，如 phone
     * @return array{
     *   success: bool,
     *   state: string,
     *   status: string,
     *   is_signed: bool,
     *   company_code: string,
     *   tracking_no: string,
     *   latest_desc: string,
     *   latest_time: string|null,
     *   tracks: array<int, array{content:string,time:string,status:string}>,
     *   raw: array<string, mixed>,
     *   message?: string
     * }
     */
    abstract public function query(string $companyCode, string $trackingNo, array $options = []): array;

    protected function successResult(array $payload): array
    {
        $state = trim((string) ($payload['state'] ?? ''));
        $tracks = $this->normalizeTracks(is_array($payload['data'] ?? null) ? $payload['data'] : []);

        return [
            'success' => true,
            'state' => $state !== '' ? $state : 'unknown',
            'status' => $this->stateText($state),
            'is_signed' => $state === '3',
            'company_code' => (string) ($payload['com'] ?? ''),
            'tracking_no' => (string) ($payload['nu'] ?? ''),
            'latest_desc' => (string) ($tracks[0]['content'] ?? ''),
            'latest_time' => isset($tracks[0]['time']) && $tracks[0]['time'] !== '' ? (string) $tracks[0]['time'] : null,
            'tracks' => $tracks,
            'raw' => $payload,
        ];
    }

    protected function failureResult(string $message, array $raw = []): array
    {
        $this->setError($message);

        return [
            'success' => false,
            'state' => 'unknown',
            'status' => '查询失败',
            'is_signed' => false,
            'company_code' => '',
            'tracking_no' => '',
            'latest_desc' => '',
            'latest_time' => null,
            'tracks' => [],
            'raw' => $raw,
            'message' => $message,
        ];
    }

    protected function stateText(string $state): string
    {
        return self::STATE_TEXT_MAP[$state] ?? '暂无轨迹';
    }

    /**
     * @param array<int, mixed> $rows
     * @return array<int, array{content:string,time:string,status:string}>
     */
    private function normalizeTracks(array $rows): array
    {
        $tracks = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $content = trim((string) ($row['context'] ?? $row['content'] ?? $row['desc'] ?? $row['AcceptStation'] ?? ''));
            $time = trim((string) ($row['time'] ?? $row['ftime'] ?? $row['AcceptTime'] ?? ''));
            if ($content === '' && $time === '') {
                continue;
            }

            $tracks[] = [
                'content' => $content,
                'time' => $time,
                'status' => (string) ($row['status'] ?? $row['statusName'] ?? $row['Remark'] ?? ''),
            ];
        }

        return $tracks;
    }
}
