<?php

declare(strict_types=1);

namespace app\job {
    if (!function_exists(__NAMESPACE__ . '\\getSystemSetting')) {
        function getSystemSetting(string $code, mixed $default = null): mixed
        {
            return $default;
        }
    }
}

namespace Tests\Unit\Upload {
    use app\job\UploadAssetMigrationJob;
    use PHPUnit\Framework\TestCase;
    use ReflectionMethod;

    final class UploadAssetMigrationJobLegacyReferenceTest extends TestCase
    {
        private UploadAssetMigrationJob $job;

        protected function setUp(): void
        {
            $this->job = new UploadAssetMigrationJob(['migration_id' => 1]);
        }

        public function testDetectsLegacySinglePath(): void
        {
            $this->assertTrue($this->hasLegacyReference(
                ['main_image' => '/static/demo/laptop-01-main.jpg'],
                ['single' => ['main_image']],
            ));
        }

        public function testIgnoresAlreadyMigratedAssetId(): void
        {
            $this->assertFalse($this->hasLegacyReference(
                ['main_image' => '12', 'images' => '[12,13]'],
                ['single' => ['main_image'], 'multi' => ['images']],
            ));
        }

        public function testDetectsLegacyPathInJsonList(): void
        {
            $this->assertTrue($this->hasLegacyReference(
                ['images' => '["/static/demo/laptop-01-main.jpg"]'],
                ['multi' => ['images']],
            ));
        }

        public function testDetectsLegacySpecMetaPic(): void
        {
            $this->assertTrue($this->hasLegacyReference(
                [
                    'spec_meta' => '[{"name":"颜色","values":[{"value":"白色","pic":"/static/demo/watch-01-main.png"}]}]',
                ],
                ['spec_meta' => ['spec_meta']],
            ));
        }

        public function testRichTextWithDataAssetIdIsNotPendingMigration(): void
        {
            $this->assertFalse($this->hasLegacyReference(
                ['description' => '<p><img src="/static/demo/laptop-01-main.jpg" data-asset-id="12"></p>'],
                ['rich_text' => ['description']],
            ));
        }

        /**
         * @param array<string,mixed> $row
         * @param array<string,mixed> $spec
         */
        private function hasLegacyReference(array $row, array $spec): bool
        {
            $method = new ReflectionMethod(UploadAssetMigrationJob::class, 'rowHasLegacyReference');
            $method->setAccessible(true);

            return (bool) $method->invoke($this->job, $row, $spec);
        }
    }
}
