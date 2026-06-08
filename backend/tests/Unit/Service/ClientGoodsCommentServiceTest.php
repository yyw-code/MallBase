<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use app\service\client\goods\ClientGoodsCommentService;
use mall_base\exception\BusinessException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ClientGoodsCommentServiceTest extends TestCase
{
    private ClientGoodsCommentService $service;

    protected function setUp(): void
    {
        $this->service = (new ReflectionClass(ClientGoodsCommentService::class))->newInstanceWithoutConstructor();
    }

    public function testReviewImagesMustUseUploadedPathsOrUrls(): void
    {
        $images = $this->invoke('normalizeReviewImages', [[
            '/uploads/client/review/a.jpg',
            'uploads/client/review/b.jpg',
            'https://cdn.example.com/review/c.jpg',
            '',
        ]]);

        $this->assertSame([
            '/uploads/client/review/a.jpg',
            'uploads/client/review/b.jpg',
            'https://cdn.example.com/review/c.jpg',
        ], $images);
    }

    public function testRejectsLocalTemporaryReviewImagePath(): void
    {
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('评价图片请先上传后再提交');

        $this->invoke('normalizeReviewImages', [[
            'wxfile://tmp/review-a.jpg',
        ]]);
    }

    public function testRejectsTooManyReviewImages(): void
    {
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('评价图片最多上传 6 张');

        $this->invoke('normalizeReviewImages', [[
            '/uploads/client/review/1.jpg',
            '/uploads/client/review/2.jpg',
            '/uploads/client/review/3.jpg',
            '/uploads/client/review/4.jpg',
            '/uploads/client/review/5.jpg',
            '/uploads/client/review/6.jpg',
            '/uploads/client/review/7.jpg',
        ]]);
    }

    /**
     * @param array<int, mixed> $arguments
     */
    private function invoke(string $methodName, array $arguments): mixed
    {
        $method = (new ReflectionClass(ClientGoodsCommentService::class))->getMethod($methodName);
        $method->setAccessible(true);

        return $method->invokeArgs($this->service, $arguments);
    }
}
