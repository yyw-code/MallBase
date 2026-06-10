<?php

declare(strict_types=1);

namespace Tests\Feature\Install;

use PHPUnit\Framework\TestCase;

final class DemoAssetReferenceTest extends TestCase
{
    public function testDemoStaticAssetSeedReferencesExistingFiles(): void
    {
        $root = dirname(__DIR__, 4);
        $sql = file_get_contents($root . '/backend/install/data/demo/00_demo_upload_assets.sql');

        $this->assertIsString($sql);
        preg_match_all("#'static/demo/([^']+)'#", $sql, $matches);

        $missing = [];
        foreach (array_unique($matches[1] ?? []) as $relativePath) {
            if (!is_file($root . '/backend/install/static/demo/' . $relativePath)) {
                $missing[] = 'static/demo/' . $relativePath;
            }
        }

        $this->assertNotEmpty($matches[1] ?? [], '演示素材 seed 未声明 static/demo 文件');
        $this->assertSame([], $missing, '演示素材 seed 引用了不存在的静态图片');
    }

    public function testDemoSqlReferencesExistingStaticDemoImages(): void
    {
        $root = dirname(__DIR__, 4);
        $missing = [];

        foreach (['schema', 'demo'] as $dirName) {
            $files = glob($root . '/backend/install/data/' . $dirName . '/*.sql') ?: [];
            foreach ($files as $file) {
                $sql = file_get_contents($file);
                $this->assertIsString($sql);

                preg_match_all("#/static/demo/[^'\"\\\\\]]+#", $sql, $matches);
                foreach (array_unique($matches[0] ?? []) as $path) {
                    $relativePath = str_replace('/static/demo/', '', $path);
                    if (!is_file($root . '/backend/install/static/demo/' . $relativePath)) {
                        $missing[] = str_replace($root . '/', '', $file) . ' -> ' . $path;
                    }
                }
            }
        }

        $this->assertSame([], $missing, '演示 SQL 引用了不存在的静态图片');
    }

    public function testDemoSqlDoesNotWriteStaticPathsIntoAssetIdFields(): void
    {
        $root = dirname(__DIR__, 4);
        $targets = [
            '02_demo_goods.sql' => [
                'mb_goods_category',
                'mb_goods',
                'mb_goods_sku',
            ],
            '04_demo_reviews.sql' => [
                'mb_user',
                'mb_goods_comment',
            ],
        ];

        $violations = [];
        foreach ($targets as $fileName => $tables) {
            $sql = file_get_contents($root . '/backend/install/data/demo/' . $fileName);
            $this->assertIsString($sql);

            foreach ($tables as $table) {
                preg_match_all(
                    '/INSERT INTO `' . preg_quote($table, '/') . '` .*? VALUES\s*(.*?);/s',
                    $sql,
                    $matches,
                    PREG_SET_ORDER,
                );
                foreach ($matches as $match) {
                    if (str_contains($match[1], '/static/demo/')) {
                        $violations[] = $fileName . ' -> INSERT ' . $table;
                    }
                }

                preg_match_all(
                    '/UPDATE `' . preg_quote($table, '/') . '` .*?;/s',
                    $sql,
                    $matches,
                    PREG_SET_ORDER,
                );
                foreach ($matches as $match) {
                    if (str_contains($match[0], '/static/demo/')) {
                        $violations[] = $fileName . ' -> UPDATE ' . $table;
                    }
                }
            }
        }

        $this->assertSame([], $violations, '素材 ID 字段不能写入 /static/demo 路径字符串');
    }

    public function testDemoHomeBannersAreValidJsonArray(): void
    {
        $root = dirname(__DIR__, 4);
        $sql = file_get_contents($root . '/backend/install/data/demo/02_demo_goods.sql');

        $this->assertIsString($sql);
        $matched = preg_match(
            "/client_home_banners';\\s*\\nUPDATE `mb_setting` SET `value` = '([^']+)'/m",
            $sql,
            $matches,
        );
        if ($matched !== 1) {
            $matched = preg_match("/SET `value` = '([^']+)'\\s*\\nWHERE `code` = 'client_home_banners'/m", $sql, $matches);
        }

        $this->assertSame(1, $matched);
        $banners = json_decode($matches[1], true);
        $this->assertIsArray($banners);
        $this->assertNotEmpty($banners);
    }
}
