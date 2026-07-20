<?php

declare(strict_types=1);

namespace Tests\Unit\Install;

use app\service\install\InstallService;
use PHPUnit\Framework\TestCase;

final class InstallServiceEnvironmentTest extends TestCase
{
    private string $root;

    /** @var array{runtime:string,backend_env_directory:string,public:string,uploads:string,demo:string} */
    private array $paths;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/mallbase-install-environment-' . bin2hex(random_bytes(8));
        $this->paths = [
            'runtime' => $this->root . '/runtime',
            'backend_env_directory' => $this->root . '/env',
            'public' => $this->root . '/public',
            'uploads' => $this->root . '/public/uploads',
            'demo' => $this->root . '/public/static/demo',
        ];
        foreach ([$this->paths['runtime'], $this->paths['backend_env_directory'], $this->paths['uploads'], $this->paths['demo']] as $path) {
            mkdir($path, 0770, true);
        }
        chmod($this->paths['public'], 0555);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
        parent::tearDown();
    }

    public function testChecksOnlyThePublicSubdirectoriesThatActuallyNeedWrites(): void
    {
        $result = (new InstallServiceEnvironmentProbe($this->paths))->checkEnvironment();
        $items = $this->itemsByName($result['items']);

        self::assertArrayNotHasKey('public 目录可写', $items);
        self::assertTrue($items['runtime 目录可写']['pass']);
        self::assertTrue($items['backend 环境配置目录可写']['pass']);
        self::assertTrue($items['public 目录可读']['pass']);
        self::assertTrue($items['public/uploads 目录可写']['pass']);
        self::assertTrue($items['public/static/demo 目录可写']['pass']);
    }

    public function testMissingDemoDirectoryFailsTheExactEnvironmentItem(): void
    {
        rmdir($this->paths['demo']);

        $result = (new InstallServiceEnvironmentProbe($this->paths))->checkEnvironment();
        $items = $this->itemsByName($result['items']);

        self::assertFalse($items['public/static/demo 目录可写']['pass']);
        self::assertSame('不可写', $items['public/static/demo 目录可写']['current']);
        self::assertTrue($items['public/uploads 目录可写']['pass']);
    }

    public function testConfiguredBackendEnvPathIsTheSingleReadWriteTruthSource(): void
    {
        $previous = getenv('MALLBASE_BACKEND_ENV_PATH');
        $configured = $this->paths['backend_env_directory'] . '/backend.env';
        putenv('MALLBASE_BACKEND_ENV_PATH=' . $configured);

        try {
            self::assertSame($configured, (new InstallServicePathProbe())->resolvedBackendEnvPath());

            $source = (string) file_get_contents(dirname(__DIR__, 3) . '/app/service/install/InstallService.php');
            self::assertGreaterThanOrEqual(3, substr_count($source, '$this->resolveBackendEnvPath()'));
        } finally {
            $previous === false
                ? putenv('MALLBASE_BACKEND_ENV_PATH')
                : putenv('MALLBASE_BACKEND_ENV_PATH=' . $previous);
        }
    }

    public function testWebInstallUsesIsolatedCommandRunnerInsteadOfMutatingConsoleFacade(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/app/service/install/InstallService.php');

        self::assertStringNotContainsString("Console::call('sync:permissions')", $source);
        self::assertStringNotContainsString('正在写入 backend/.env', $source);
        self::assertStringContainsString('正在写入后端运行配置', $source);
        self::assertStringContainsString('InstallCommandRunner::class', $source);
        self::assertStringContainsString("['sync:permissions']", $source);
    }

    public function testWebInstallPreservesTheCurrentJwtSecret(): void
    {
        self::assertSame(
            'existing-secret',
            $this->resolveJwtSecret([], ['JWT_SECRET' => 'existing-secret']),
        );
        self::assertSame(
            'explicit-secret',
            $this->resolveJwtSecret(
                ['jwt_secret' => 'explicit-secret'],
                ['JWT_SECRET' => 'existing-secret'],
            ),
        );
    }

    public function testWebInstallReplacesMissingOrPlaceholderJwtSecret(): void
    {
        foreach (['', 'please-change-or-leave-for-random'] as $currentSecret) {
            $generated = $this->resolveJwtSecret([], ['JWT_SECRET' => $currentSecret]);

            self::assertSame(64, strlen($generated));
            self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{64}$/D', $generated);
            self::assertNotSame($currentSecret, $generated);
        }
    }

    /**
     * @param array<int, array{name:string,required:string,current:string,pass:bool}> $items
     * @return array<string, array{name:string,required:string,current:string,pass:bool}>
     */
    private function itemsByName(array $items): array
    {
        $indexed = [];
        foreach ($items as $item) {
            $indexed[$item['name']] = $item;
        }

        return $indexed;
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, string> $envValues
     */
    private function resolveJwtSecret(array $params, array $envValues): string
    {
        $method = new \ReflectionMethod(InstallService::class, 'resolveJwtSecret');

        return $method->invoke(new InstallService(), $params, $envValues);
    }

    private function removeTree(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            @unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        @chmod($path, 0770);
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeTree($path . DIRECTORY_SEPARATOR . $entry);
            }
        }
        @rmdir($path);
    }
}

final class InstallServiceEnvironmentProbe extends InstallService
{
    /** @param array{runtime:string,backend_env_directory:string,public:string,uploads:string,demo:string} $paths */
    public function __construct(private readonly array $paths)
    {
    }

    protected function installationEnvironmentPaths(): array
    {
        return $this->paths;
    }
}

final class InstallServicePathProbe extends InstallService
{
    public function resolvedBackendEnvPath(): string
    {
        return $this->resolveBackendEnvPath();
    }
}
