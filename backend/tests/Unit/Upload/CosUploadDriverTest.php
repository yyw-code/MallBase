<?php

declare(strict_types=1);

namespace Tests\Unit\Upload;

use mall_base\drivers\upload\CosUploadDriver;
use PHPUnit\Framework\TestCase;

final class CosUploadDriverTest extends TestCase
{
    public function testRejectsMissingRequiredConfig(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('COS 配置缺失');
        $this->expectExceptionMessage('SecretKey');
        $this->expectExceptionMessage('Bucket');
        $this->expectExceptionMessage('Region');

        new TestableCosUploadDriver([
            'secret_id' => 'test-id',
        ]);
    }

    public function testUploadDownloadDeleteExistsAndFileInfoUseCosClient(): void
    {
        $driver = new TestableCosUploadDriver($this->validConfig([
            'url_prefix' => 'https://cdn.example.com/assets',
        ]));
        $tmpFile = tempnam(sys_get_temp_dir(), 'mb-cos-');
        $downloadFile = tempnam(sys_get_temp_dir(), 'mb-cos-download-');
        $this->assertIsString($tmpFile);
        $this->assertIsString($downloadFile);
        @unlink($downloadFile);
        file_put_contents($tmpFile, 'image-content');

        try {
            $object = 'images/review/a.jpg';

            $url = $driver->upload($tmpFile, '/' . $object);
            $this->assertSame('https://cdn.example.com/assets/images/review/a.jpg', $url);
            $this->assertSame([
                [
                    'Bucket' => 'mall-base-1250000000',
                    'Key' => $object,
                    'BodyContent' => 'image-content',
                ],
            ], $driver->fakeClient()->puts);

            $this->assertTrue($driver->download($object, $downloadFile));
            $this->assertSame('download-content', file_get_contents($downloadFile));
            $this->assertSame([
                [
                    'Bucket' => 'mall-base-1250000000',
                    'Key' => $object,
                    'SaveAs' => $downloadFile,
                ],
            ], $driver->fakeClient()->gets);

            $this->assertTrue($driver->exists($object));
            $this->assertTrue($driver->delete($object));

            $info = $driver->getFileInfo('/' . $object);
            $this->assertSame([
                'name' => 'a.jpg',
                'path' => $object,
                'url' => 'https://cdn.example.com/assets/images/review/a.jpg',
                'full_url' => 'https://cdn.example.com/assets/images/review/a.jpg',
                'size' => 1234,
                'mime' => 'image/jpeg',
                'modified' => date('Y-m-d H:i:s', strtotime('Mon, 01 Jun 2026 08:30:00 GMT')),
            ], $info);
        } finally {
            @unlink($tmpFile);
            @unlink($downloadFile);
        }
    }

    public function testDefaultUrlUsesTencentCosDomain(): void
    {
        $driver = new TestableCosUploadDriver($this->validConfig());

        $this->assertSame(
            'https://mall-base-1250000000.cos.ap-shanghai.myqcloud.com/images/a.jpg',
            $driver->getUrl('/images/a.jpg')
        );
    }

    public function testUploadDoesNotFailWhenSdkClosesBodyStream(): void
    {
        $driver = new TestableCosUploadDriver($this->validConfig());
        $driver->fakeClient()->closeBodyAfterRead = true;
        $tmpFile = tempnam(sys_get_temp_dir(), 'mb-cos-closed-body-');
        $this->assertIsString($tmpFile);
        file_put_contents($tmpFile, 'image-content');

        try {
            $this->assertSame(
                'https://mall-base-1250000000.cos.ap-shanghai.myqcloud.com/images/closed.jpg',
                $driver->upload($tmpFile, 'images/closed.jpg')
            );
        } finally {
            @unlink($tmpFile);
        }
    }

    /**
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    private function validConfig(array $override = []): array
    {
        return array_merge([
            'secret_id' => 'test-id',
            'secret_key' => 'test-secret',
            'bucket' => 'mall-base-1250000000',
            'region' => 'ap-shanghai',
        ], $override);
    }
}

final class TestableCosUploadDriver extends CosUploadDriver
{
    private ?FakeCosClient $fakeClient = null;

    public function fakeClient(): FakeCosClient
    {
        if ($this->fakeClient === null) {
            $this->fakeClient = new FakeCosClient();
        }

        return $this->fakeClient;
    }

    protected function createClient(): object
    {
        return $this->fakeClient();
    }
}

final class FakeCosClient
{
    public bool $closeBodyAfterRead = false;

    /**
     * @var array<int, array{Bucket: string, Key: string, BodyContent: string}>
     */
    public array $puts = [];

    /**
     * @var array<int, array{Bucket: string, Key: string, SaveAs: string}>
     */
    public array $gets = [];

    /**
     * @var array<int, array{Bucket: string, Key: string}>
     */
    public array $deletes = [];

    /**
     * @param array{Bucket: string, Key: string, Body: resource} $args
     */
    public function putObject(array $args): void
    {
        $this->puts[] = [
            'Bucket' => $args['Bucket'],
            'Key' => $args['Key'],
            'BodyContent' => stream_get_contents($args['Body']) ?: '',
        ];

        if ($this->closeBodyAfterRead) {
            fclose($args['Body']);
        }
    }

    /**
     * @param array{Bucket: string, Key: string, SaveAs: string} $args
     */
    public function getObject(array $args): void
    {
        file_put_contents($args['SaveAs'], 'download-content');
        $this->gets[] = $args;
    }

    /**
     * @param array{Bucket: string, Key: string} $args
     */
    public function deleteObject(array $args): void
    {
        $this->deletes[] = $args;
    }

    /**
     * @param array{Bucket: string, Key: string} $args
     */
    public function headObject(array $args): FakeCosHeadResult
    {
        return new FakeCosHeadResult();
    }
}

final class FakeCosHeadResult
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'ContentLength' => 1234,
            'ContentType' => 'image/jpeg',
            'LastModified' => 'Mon, 01 Jun 2026 08:30:00 GMT',
        ];
    }
}
