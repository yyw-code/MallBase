<?php

declare(strict_types=1);

namespace Tests\Unit\Install;

use app\service\install\InstallService;
use PHPUnit\Framework\TestCase;

final class InstallServiceEnvironmentTest extends TestCase
{
    /** @var array<int, string> */
    private const CURL_FUNCTIONS = [
        'curl_init',
        'curl_setopt_array',
        'curl_exec',
        'curl_error',
        'curl_getinfo',
        'curl_close',
    ];

    /** @var array<int, string> */
    private const PROCESS_FUNCTIONS = [
        'proc_open',
        'proc_get_status',
        'proc_terminate',
        'proc_close',
    ];

    private string $root;

    private string $phpCli;

    /** @var array{runtime:string,backend_env_directory:string,public:string,uploads:string,demo:string} */
    private array $paths;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/mallbase-install-environment-' . bin2hex(random_bytes(8));
        $this->paths = [
            'runtime' => $this->root . '/backend/runtime',
            'backend_env_directory' => $this->root . '/env',
            'public' => $this->root . '/backend/public',
            'uploads' => $this->root . '/backend/public/uploads',
            'demo' => $this->root . '/backend/public/static/demo',
        ];
        foreach ([$this->paths['runtime'], $this->paths['backend_env_directory'], $this->paths['uploads'], $this->paths['demo']] as $path) {
            mkdir($path, 0770, true);
        }
        $this->phpCli = $this->root . '/php-bin/php';
        mkdir(dirname($this->phpCli), 0770, true);
        file_put_contents($this->phpCli, "#!/bin/sh\nexit 0\n");
        chmod($this->phpCli, 0755);
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

    public function testShowsCurlProcessCliAndUnrestrictedOpenBasedirCapabilities(): void
    {
        $result = (new InstallServiceEnvironmentProbe(
            $this->paths,
            array_merge(self::CURL_FUNCTIONS, self::PROCESS_FUNCTIONS),
            [$this->phpCli],
            '',
        ))->checkEnvironment();
        $items = $this->itemsByName($result['items']);

        self::assertTrue($items['cURL 能力']['pass']);
        self::assertTrue($items['PHP 子进程函数组']['pass']);
        self::assertTrue($items['PHP CLI 可执行']['pass']);
        self::assertTrue($items['open_basedir 路径覆盖']['pass']);
        self::assertSame('未限制', $items['open_basedir 路径覆盖']['current']);
    }

    public function testCurlCapabilityRequiresEveryFunctionUsedByTheAgreementRequest(): void
    {
        $availableFunctions = array_values(array_diff(
            array_merge(self::CURL_FUNCTIONS, self::PROCESS_FUNCTIONS),
            ['curl_exec'],
        ));
        $result = (new InstallServiceEnvironmentProbe(
            $this->paths,
            $availableFunctions,
            [$this->phpCli],
        ))->checkEnvironment();
        $items = $this->itemsByName($result['items']);

        self::assertFalse($items['cURL 能力']['pass']);
        self::assertStringContainsString('curl_exec', $items['cURL 能力']['current']);
    }

    public function testAgreementRequestReturnsUnavailableBeforeCallingMissingCurlFunction(): void
    {
        $availableFunctions = array_values(array_diff(
            array_merge(self::CURL_FUNCTIONS, self::PROCESS_FUNCTIONS),
            ['curl_exec'],
        ));
        $agreement = (new InstallServiceEnvironmentProbe(
            $this->paths,
            $availableFunctions,
            [$this->phpCli],
        ))->getInstallAgreement();

        self::assertFalse($agreement['available']);
        self::assertSame('platform', $agreement['source']);
        self::assertStringContainsString('curl_exec', $agreement['error']);
    }

    /** @dataProvider missingProcessFunctionProvider */
    public function testRequiresEveryProcessFunctionUsedByTheCommandRunner(string $missingFunction): void
    {
        $availableFunctions = array_values(array_diff(
            array_merge(self::CURL_FUNCTIONS, self::PROCESS_FUNCTIONS),
            [$missingFunction],
        ));
        $result = (new InstallServiceEnvironmentProbe(
            $this->paths,
            $availableFunctions,
            [$this->phpCli],
        ))->checkEnvironment();
        $items = $this->itemsByName($result['items']);

        self::assertFalse($items['PHP 子进程函数组']['pass']);
        self::assertStringContainsString($missingFunction, $items['PHP 子进程函数组']['current']);
    }

    /** @return iterable<string, array{string}> */
    public static function missingProcessFunctionProvider(): iterable
    {
        foreach (self::PROCESS_FUNCTIONS as $function) {
            yield $function => [$function];
        }
    }

    public function testRejectsPhpCliCandidatesThatDoNotExist(): void
    {
        $result = (new InstallServiceEnvironmentProbe(
            $this->paths,
            array_merge(self::CURL_FUNCTIONS, self::PROCESS_FUNCTIONS),
            [$this->root . '/bin/missing-php'],
        ))->checkEnvironment();
        $items = $this->itemsByName($result['items']);

        self::assertFalse($items['PHP CLI 可执行']['pass']);
        self::assertSame('不可用', $items['PHP CLI 可执行']['current']);
    }

    public function testPhpCliExecutionProbeDoesNotRequireBinaryPathInsideOpenBasedir(): void
    {
        $phpCliOutsideOpenBasedir = '/opt/1panel/php/82/bin/php';
        $allowed = implode(PATH_SEPARATOR, [
            dirname($this->paths['public']),
            $this->paths['backend_env_directory'],
        ]);
        $result = (new InstallServiceEnvironmentProbe(
            $this->paths,
            array_merge(self::CURL_FUNCTIONS, self::PROCESS_FUNCTIONS),
            [$phpCliOutsideOpenBasedir],
            $allowed,
            [],
            [$phpCliOutsideOpenBasedir => true],
        ))->checkEnvironment();
        $items = $this->itemsByName($result['items']);

        self::assertTrue($items['PHP CLI 可执行']['pass']);
        self::assertTrue($items['open_basedir 路径覆盖']['pass']);
        self::assertSame('已覆盖', $items['open_basedir 路径覆盖']['current']);
        self::assertSame('覆盖后端目录及环境配置目录', $items['open_basedir 路径覆盖']['required']);
    }

    public function testPhpCliCandidateFailsWhenItsExecutionProbeFails(): void
    {
        $result = (new InstallServiceEnvironmentProbe(
            $this->paths,
            array_merge(self::CURL_FUNCTIONS, self::PROCESS_FUNCTIONS),
            [$this->phpCli],
            '',
            [],
            [$this->phpCli => false],
        ))->checkEnvironment();
        $items = $this->itemsByName($result['items']);

        self::assertFalse($items['PHP CLI 可执行']['pass']);
        self::assertSame('不可用', $items['PHP CLI 可执行']['current']);
    }

    public function testOpenBasedirRequiresCanonicalCoverageWithoutPrefixMatches(): void
    {
        $backendRoot = dirname($this->paths['public']);
        $allowed = implode(PATH_SEPARATOR, [
            $backendRoot,
            $this->paths['backend_env_directory'],
        ]);
        $allowedResult = (new InstallServiceEnvironmentProbe(
            $this->paths,
            array_merge(self::CURL_FUNCTIONS, self::PROCESS_FUNCTIONS),
            [$this->phpCli],
            $allowed,
        ))->checkEnvironment();
        $allowedItems = $this->itemsByName($allowedResult['items']);

        self::assertTrue($allowedItems['open_basedir 路径覆盖']['pass']);
        self::assertSame('已覆盖', $allowedItems['open_basedir 路径覆盖']['current']);

        $runtimeOnly = implode(PATH_SEPARATOR, [
            $this->paths['runtime'],
            $this->paths['backend_env_directory'],
        ]);
        $blockedResult = (new InstallServiceEnvironmentProbe(
            $this->paths,
            array_merge(self::CURL_FUNCTIONS, self::PROCESS_FUNCTIONS),
            [$this->phpCli],
            $runtimeOnly,
        ))->checkEnvironment();
        $blockedItems = $this->itemsByName($blockedResult['items']);

        self::assertFalse($blockedItems['open_basedir 路径覆盖']['pass']);
        self::assertSame('未覆盖', $blockedItems['open_basedir 路径覆盖']['current']);

        mkdir($this->root . '/back');
        $prefixOnly = implode(PATH_SEPARATOR, [
            $this->root . '/back',
            $this->paths['backend_env_directory'],
        ]);
        $prefixResult = (new InstallServiceEnvironmentProbe(
            $this->paths,
            array_merge(self::CURL_FUNCTIONS, self::PROCESS_FUNCTIONS),
            [$this->phpCli],
            $prefixOnly,
        ))->checkEnvironment();
        $prefixItems = $this->itemsByName($prefixResult['items']);

        self::assertFalse($prefixItems['open_basedir 路径覆盖']['pass']);
    }

    public function testCreatesMissingInstallDirectoryWithoutCreatingInstallLock(): void
    {
        $installDirectory = $this->paths['runtime'] . '/install';
        $lockFile = $installDirectory . '/install.lock';
        self::assertDirectoryDoesNotExist($installDirectory);

        $service = new InstallServiceEnvironmentProbe(
            $this->paths,
            array_merge(self::CURL_FUNCTIONS, self::PROCESS_FUNCTIONS),
            [$this->phpCli],
        );
        $result = $service->checkEnvironment();
        $items = $this->itemsByName($result['items']);

        self::assertDirectoryExists($installDirectory);
        self::assertFileDoesNotExist($lockFile);
        self::assertTrue($items['runtime 目录可写']['pass']);
        self::assertTrue($items['runtime/install 目录可写']['pass']);
        clearstatcache(true, $this->paths['runtime']);
        clearstatcache(true, $installDirectory);
        self::assertSame(0755, fileperms($this->paths['runtime']) & 0777);
        self::assertSame(0755, fileperms($installDirectory) & 0777);

        $service->checkEnvironment();
        self::assertFileDoesNotExist($lockFile);
    }

    public function testFailsWhenRuntimeOrInstallDirectoryCannotBeChmoddedDespiteBeingWritable(): void
    {
        $runtimeDirectory = $this->paths['runtime'];
        $installDirectory = $runtimeDirectory . '/install';
        mkdir($installDirectory, 0777);
        chmod($runtimeDirectory, 0777);
        chmod($installDirectory, 0777);

        self::assertTrue(is_writable($runtimeDirectory));
        self::assertTrue(is_writable($installDirectory));

        $runtimeBlocked = new InstallServiceEnvironmentProbe(
            $this->paths,
            array_merge(self::CURL_FUNCTIONS, self::PROCESS_FUNCTIONS),
            [$this->phpCli],
            '',
            [$runtimeDirectory => false],
        );
        $runtimeItems = $this->itemsByName($runtimeBlocked->checkEnvironment()['items']);
        self::assertFalse($runtimeItems['runtime 目录可写']['pass']);
        clearstatcache(true, $runtimeDirectory);
        clearstatcache(true, $installDirectory);
        self::assertSame(0777, fileperms($runtimeDirectory) & 0777);
        self::assertSame(0755, fileperms($installDirectory) & 0777);

        chmod($runtimeDirectory, 0777);
        chmod($installDirectory, 0777);

        $installBlocked = new InstallServiceEnvironmentProbe(
            $this->paths,
            array_merge(self::CURL_FUNCTIONS, self::PROCESS_FUNCTIONS),
            [$this->phpCli],
            '',
            [$installDirectory => false],
        );
        $installItems = $this->itemsByName($installBlocked->checkEnvironment()['items']);
        self::assertFalse($installItems['runtime/install 目录可写']['pass']);

        clearstatcache(true, $runtimeDirectory);
        clearstatcache(true, $installDirectory);
        self::assertSame(0755, fileperms($runtimeDirectory) & 0777);
        self::assertSame(0777, fileperms($installDirectory) & 0777);
        self::assertFileDoesNotExist($installDirectory . '/install.lock');
    }

    public function testExecuteRechecksEnvironmentBeforeDatabaseOrEnvWrites(): void
    {
        $envPath = $this->root . '/blocked/backend.env';
        $previous = getenv('MALLBASE_BACKEND_ENV_PATH');
        putenv('MALLBASE_BACKEND_ENV_PATH=' . $envPath);
        $service = new InstallServiceExecutePreflightProbe();

        try {
            $result = $service->execute([
                'db_host' => '127.0.0.1',
                'db_port' => 3306,
                'db_user' => 'root',
                'db_pass' => '',
                'db_name' => 'mallbase_test',
                'site_url' => 'https://example.test',
            ]);

            self::assertFalse($result['success']);
            self::assertSame('environment', $result['step']);
            self::assertSame(1, $service->environmentChecks);
            self::assertSame(0, $service->databaseTests);
            self::assertFileDoesNotExist($envPath);
        } finally {
            $previous === false
                ? putenv('MALLBASE_BACKEND_ENV_PATH')
                : putenv('MALLBASE_BACKEND_ENV_PATH=' . $previous);
        }
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
    /**
     * @param array{runtime:string,backend_env_directory:string,public:string,uploads:string,demo:string} $paths
     * @param array<int, string>|null $availableFunctions
     * @param array<int, string>|null $phpCliCandidates
     * @param array<string, bool> $chmodResults
     * @param array<string, bool> $phpCliProbeResults
     */
    public function __construct(
        private readonly array $paths,
        private readonly ?array $availableFunctions = null,
        private readonly ?array $phpCliCandidates = null,
        private readonly ?string $openBasedir = null,
        private readonly array $chmodResults = [],
        private readonly array $phpCliProbeResults = [],
    ) {
    }

    protected function installationEnvironmentPaths(): array
    {
        return $this->paths;
    }

    protected function environmentFunctionAvailable(string $function): bool
    {
        return $this->availableFunctions === null
            ? parent::environmentFunctionAvailable($function)
            : in_array($function, $this->availableFunctions, true);
    }

    protected function phpCliCandidates(): array
    {
        return $this->phpCliCandidates ?? parent::phpCliCandidates();
    }

    protected function configuredOpenBasedir(): string
    {
        return $this->openBasedir ?? parent::configuredOpenBasedir();
    }

    protected function probePhpCliExecution(string $candidate): bool
    {
        if (array_key_exists($candidate, $this->phpCliProbeResults)) {
            return $this->phpCliProbeResults[$candidate];
        }

        return parent::probePhpCliExecution($candidate);
    }

    protected function probeDirectoryChmodCapability(string $path): bool
    {
        if (array_key_exists($path, $this->chmodResults)) {
            return $this->chmodResults[$path];
        }

        return parent::probeDirectoryChmodCapability($path);
    }
}

final class InstallServicePathProbe extends InstallService
{
    public function resolvedBackendEnvPath(): string
    {
        return $this->resolveBackendEnvPath();
    }
}

final class InstallServiceExecutePreflightProbe extends InstallService
{
    public int $environmentChecks = 0;

    public int $databaseTests = 0;

    public function checkEnvironment(): array
    {
        ++$this->environmentChecks;

        return [
            'items' => [[
                'name' => 'runtime/install 目录可写',
                'required' => '可写且可收紧权限',
                'current' => '无法收紧权限',
                'pass' => false,
            ]],
            'pass' => false,
        ];
    }

    public function testDatabase(array $config): array
    {
        ++$this->databaseTests;

        return [
            'success' => false,
            'message' => '环境预检失败时不应测试数据库',
            'detail' => null,
        ];
    }
}
