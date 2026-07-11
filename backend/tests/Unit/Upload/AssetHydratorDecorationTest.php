<?php
declare(strict_types=1);

namespace Tests\Unit\Upload;

use app\service\upload\AssetHydrator;
use app\service\upload\AssetIdNormalizer;
use app\service\upload\AssetResolver;
use PHPUnit\Framework\TestCase;

final class AssetHydratorDecorationTest extends TestCase
{
    public function testHydratesSharedStaticPathsWithoutChangingSemanticIcons(): void
    {
        $resolver = $this->createMock(AssetResolver::class);
        $resolver->expects($this->once())
            ->method('resolve')
            ->with([])
            ->willReturn([]);

        $hydrator = $this->getMockBuilder(AssetHydrator::class)
            ->setConstructorArgs([$resolver, new AssetIdNormalizer()])
            ->onlyMethods(['fullUrl'])
            ->getMock();
        $hydrator->expects($this->exactly(2))
            ->method('fullUrl')
            ->willReturnCallback(
                static fn(mixed $value): string => 'https://preview.example.com/' . ltrim((string) $value, '/')
            );

        $schema = $hydrator->hydrateDecorationSchema([
            'profile' => [
                'image' => 'static/decorate/profile-order-pay.svg',
                'icon' => 'lucide:home',
                'background_image' => '',
            ],
            'floating' => [
                'selected_icon' => [
                    'name' => '购物车',
                    'url' => 'static/decorate/floating/cart.png',
                ],
            ],
            'tabbar' => [
                'icon' => 'static/images/tabbar/home.png',
            ],
            'external' => [
                'icon_image' => '//cdn.example.com/decorate/entry.svg',
            ],
        ]);

        $this->assertSame([
            'url' => 'static/decorate/profile-order-pay.svg',
            'full_url' => 'https://preview.example.com/static/decorate/profile-order-pay.svg',
        ], $schema['profile']['image']);
        $this->assertSame('lucide:home', $schema['profile']['icon']);
        $this->assertSame('', $schema['profile']['background_image']);
        $this->assertSame([
            'name' => '购物车',
            'url' => 'static/decorate/floating/cart.png',
            'full_url' => 'https://preview.example.com/static/decorate/floating/cart.png',
        ], $schema['floating']['selected_icon']);
        $this->assertSame(
            'static/images/tabbar/home.png',
            $schema['tabbar']['icon']
        );
        $this->assertSame([
            'url' => '//cdn.example.com/decorate/entry.svg',
            'full_url' => '//cdn.example.com/decorate/entry.svg',
        ], $schema['external']['icon_image']);
    }
}
