<?php

declare(strict_types=1);

namespace Tests\Unit\Logistics;

use mall_base\drivers\logistics\KdniaoLogisticsDriver;
use PHPUnit\Framework\TestCase;

final class KdniaoLogisticsDriverTest extends TestCase
{
    public function testQueryBuildsSignedFormAndNormalizesTracks(): void
    {
        $driver = new FakeKdniaoLogisticsDriver([
            'business_id' => 'test_id',
            'key'         => 'secret',
        ]);
        $driver->response = json_encode([
            'EBusinessID'  => 'test_id',
            'Success'      => true,
            'ShipperCode'  => 'SF',
            'LogisticCode' => 'SF1234567890',
            'State'        => '3',
            'Traces'       => [
                ['AcceptTime' => '2026-06-15 08:00:00', 'AcceptStation' => '快件已揽收'],
                ['AcceptTime' => '2026-06-15 10:00:00', 'AcceptStation' => '快件已签收', 'Remark' => '签收'],
            ],
        ], JSON_UNESCAPED_UNICODE);

        $result = $driver->query('SF', 'SF1234567890');

        $this->assertTrue($result['success']);
        $this->assertSame('已签收', $result['status']);
        $this->assertSame('快件已签收', $result['latest_desc']);
        $this->assertSame('2026-06-15 10:00:00', $result['latest_time']);
        $this->assertSame([
            ['content' => '快件已签收', 'time' => '2026-06-15 10:00:00', 'status' => '签收'],
            ['content' => '快件已揽收', 'time' => '2026-06-15 08:00:00', 'status' => ''],
        ], $result['tracks']);
        $this->assertArrayNotHasKey('map', $result);

        $this->assertSame('https://api.kdniao.com/api/dist', $driver->capturedUrl);
        $requestData = json_decode((string) $driver->capturedForm['RequestData'], true);
        $this->assertSame(['ShipperCode' => 'SF', 'LogisticCode' => 'SF1234567890'], $requestData);
        $this->assertSame('test_id', $driver->capturedForm['EBusinessID']);
        $this->assertSame('8002', $driver->capturedForm['RequestType']);
        $this->assertSame('2', $driver->capturedForm['DataType']);
        $this->assertSame(
            base64_encode(md5((string) $driver->capturedForm['RequestData'] . 'secret')),
            $driver->capturedForm['DataSign']
        );
    }

    public function testQueryReturnsFailureWhenPlatformReportsError(): void
    {
        $driver = new FakeKdniaoLogisticsDriver([
            'business_id' => 'test_id',
            'key'         => 'secret',
        ]);
        $driver->response = json_encode([
            'Success' => false,
            'Reason'  => '业务账号错误',
        ], JSON_UNESCAPED_UNICODE);

        $result = $driver->query('SF', 'SF1234567890');

        $this->assertFalse($result['success']);
        $this->assertSame('业务账号错误', $result['message']);
        $this->assertSame('业务账号错误', $driver->getError());
    }

    public function testQueryUsesPhoneTailAsCustomerName(): void
    {
        $driver = new FakeKdniaoLogisticsDriver([
            'business_id' => 'test_id',
            'key'         => 'secret',
        ]);
        $driver->response = json_encode([
            'Success'      => true,
            'ShipperCode'  => 'ZTO',
            'LogisticCode' => '78594819892462',
            'State'        => '3',
            'Traces'       => [],
        ], JSON_UNESCAPED_UNICODE);

        $driver->query('ZTO', '78594819892462', ['phone' => '17693447942']);

        $requestData = json_decode((string) $driver->capturedForm['RequestData'], true);
        $this->assertSame('7942', $requestData['CustomerName']);
    }

    public function testQueryIgnoresLegacyMapConfigAndUsesTrackQuery(): void
    {
        $driver = new FakeKdniaoLogisticsDriver([
            'business_id' => 'test_id',
            'key' => 'secret',
            'request_type' => '8003',
            'map_fallback' => false,
        ]);
        $driver->response = json_encode([
            'Success' => true,
            'ShipperCode' => 'ZTO',
            'LogisticCode' => '78594819892462',
            'State' => '3',
            'Traces' => [
                ['AcceptTime' => '2026-04-13 10:00:00', 'AcceptStation' => '已签收'],
            ],
        ], JSON_UNESCAPED_UNICODE);

        $result = $driver->query('ZTO', '78594819892462', ['phone' => '17693447942']);

        $this->assertTrue($result['success']);
        $this->assertCount(1, $driver->capturedForms);
        $this->assertSame('8002', $driver->capturedForms[0]['RequestType']);
        $requestData = json_decode((string) $driver->capturedForms[0]['RequestData'], true);
        $this->assertSame('7942', $requestData['CustomerName']);
        $this->assertArrayNotHasKey('IsReturnRouteMap', $requestData);
        $this->assertArrayNotHasKey('IsReturnCoordinates', $requestData);
        $this->assertArrayNotHasKey('SenderCityName', $requestData);
        $this->assertArrayNotHasKey('ReceiverCityName', $requestData);
        $this->assertArrayNotHasKey('map', $result);
    }

    public function testQueryFailsFastWhenCredentialsAreMissing(): void
    {
        $driver = new FakeKdniaoLogisticsDriver();

        $result = $driver->query('SF', 'SF1234567890');

        $this->assertFalse($result['success']);
        $this->assertSame('快递鸟 EBusinessID 或 AppKey 未配置', $result['message']);
        $this->assertNull($driver->capturedForm);
    }
}

final class FakeKdniaoLogisticsDriver extends KdniaoLogisticsDriver
{
    public ?string $capturedUrl = null;

    /**
     * @var array<string, string>|null
     */
    public ?array $capturedForm = null;

    /**
     * @var array<int, array<string, string>>
     */
    public array $capturedForms = [];

    public string|false $response = false;

    /**
     * @var array<int, string|false>
     */
    public array $responses = [];

    /**
     * @param array<string, string> $form
     */
    protected function postForm(string $url, array $form): string|false
    {
        $this->capturedUrl = $url;
        $this->capturedForm = $form;
        $this->capturedForms[] = $form;

        if ($this->responses !== []) {
            return array_shift($this->responses);
        }

        return $this->response;
    }
}
