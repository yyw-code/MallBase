<?php

declare(strict_types=1);

namespace Tests\Unit\Upload;

use mall_base\drivers\upload\OssUploadDriver;
use OSS\OssClient;
use PHPUnit\Framework\TestCase;

final class OssUploadDriverTest extends TestCase
{
    public function testRejectsMissingRequiredConfig(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('OSS 配置缺失');
        $this->expectExceptionMessage('AccessKeySecret');
        $this->expectExceptionMessage('Bucket');
        $this->expectExceptionMessage('Endpoint');

        new TestableOssUploadDriver([
            'access_key_id' => 'test-id',
        ]);
    }

    public function testUploadDeleteExistsAndFileInfoUseOssClient(): void
    {
        $driver = new TestableOssUploadDriver($this->validConfig([
            'url_prefix' => 'https://cdn.example.com/assets',
        ]));
        $tmpFile = tempnam(sys_get_temp_dir(), 'mb-oss-');
        $this->assertIsString($tmpFile);
        file_put_contents($tmpFile, 'image-content');

        try {
            $object = 'images/review/a.jpg';

            $url = $driver->upload($tmpFile, $object);
            $this->assertSame('https://cdn.example.com/assets/images/review/a.jpg', $url);
            $this->assertSame([
                ['bucket' => 'mall-base', 'object' => $object, 'file' => $tmpFile],
            ], $driver->fakeClient()->uploads);

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
        }
    }

    /**
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    private function validConfig(array $override = []): array
    {
        return array_merge([
            'access_key_id' => 'test-id',
            'access_key_secret' => 'test-secret',
            'bucket' => 'mall-base',
            'endpoint' => 'oss-cn-hangzhou.aliyuncs.com',
        ], $override);
    }
}

final class TestableOssUploadDriver extends OssUploadDriver
{
    private ?FakeOssClient $fakeClient = null;

    public function fakeClient(): FakeOssClient
    {
        if ($this->fakeClient === null) {
            $this->fakeClient = new FakeOssClient();
        }

        return $this->fakeClient;
    }

    protected function createClient(): OssClient
    {
        return $this->fakeClient();
    }
}

final class FakeOssClient extends OssClient
{
    /**
     * @var array<int, array{bucket: string, object: string, file: string}>
     */
    public array $uploads = [];

    /**
     * @var array<int, array{bucket: string, object: string}>
     */
    public array $deletes = [];

    public function __construct()
    {
    }

    public function uploadFile($bucket, $object, $file, $options = null)
    {
        $this->uploads[] = [
            'bucket' => (string) $bucket,
            'object' => (string) $object,
            'file' => (string) $file,
        ];

        return null;
    }

    public function deleteObject($bucket, $object, $options = null)
    {
        $this->deletes[] = [
            'bucket' => (string) $bucket,
            'object' => (string) $object,
        ];

        return null;
    }

    public function doesObjectExist($bucket, $object, $options = null)
    {
        return true;
    }

    public function getObjectMeta($bucket, $object, $options = null)
    {
        return [
            'Content-Length' => 1234,
            'Content-Type' => 'image/jpeg',
            'Last-Modified' => 'Mon, 01 Jun 2026 08:30:00 GMT',
        ];
    }
}
