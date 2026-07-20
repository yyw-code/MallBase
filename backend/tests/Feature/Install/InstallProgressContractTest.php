<?php

declare(strict_types=1);

namespace Tests\Feature\Install;

use PHPUnit\Framework\TestCase;

final class InstallProgressContractTest extends TestCase
{
    public function testRegionImportReportsStreamingProgressDuringInstall(): void
    {
        $root = dirname(__DIR__, 3);
        $installService = (string) file_get_contents($root . '/app/service/install/InstallService.php');
        $regionService = (string) file_get_contents($root . '/app/service/RegionImportService.php');

        $this->assertStringContainsString('?callable $progress = null', $regionService);
        $this->assertStringContainsString('private function countNodes(array $nodes): int', $regionService);
        $this->assertStringContainsString("'processed' => \$processed", $regionService);
        $this->assertStringContainsString("'percent'   => \$total > 0 ? (int) floor(\$processed * 100 / \$total) : 100", $regionService);
        $this->assertStringContainsString("function (array \$progress) use (&\$regionProgress, \$emit): void", $installService);
        $this->assertStringContainsString("'progress' => \$progress", $installService);
        $this->assertStringContainsString('正在导入地区数据：%d/%d（%d%%），新增 %d，更新 %d', $installService);
    }

    public function testInstallPageShowsProgressMeterAndProtectsRunningInstallFromRefresh(): void
    {
        $installPage = (string) file_get_contents(dirname(__DIR__, 3) . '/public/install/index.html');

        $this->assertStringContainsString('progress-meter', $installPage);
        $this->assertStringContainsString('event.progress || (event.percent !== undefined ? event : null)', $installPage);
        $this->assertStringContainsString('meterBar.style.width', $installPage);
        $this->assertStringContainsString("window.addEventListener('beforeunload'", $installPage);
        $this->assertStringContainsString('installRunning', $installPage);
    }

    public function testInstallProgressListIncludesEnvironmentPreflightBeforeDatabaseSteps(): void
    {
        $installPage = (string) file_get_contents(dirname(__DIR__, 3) . '/public/install/index.html');
        $stepOrder = $this->sectionBetween(
            $installPage,
            'const installStepOrder = [',
            'const validationState =',
        );

        $this->assertMatchesRegularExpression(
            "/const installStepOrder = \\[\\s*\\{ step: 'environment', title: '检查安装环境' \\},\\s*\\{ step: 'db_test'/",
            $stepOrder,
        );
    }

    public function testExecuteReportsEnvironmentPreflightRunningSuccessAndFailureStates(): void
    {
        $installService = (string) file_get_contents(dirname(__DIR__, 3) . '/app/service/install/InstallService.php');
        $execute = $this->sectionBetween(
            $installService,
            'public function execute(',
            '$dbConfig = [',
        );

        $this->assertMatchesRegularExpression(<<<'REGEX'
/\$emit\('environment',\s*'running',/
REGEX, $execute);
        $this->assertMatchesRegularExpression(<<<'REGEX'
/\$emit\('environment',\s*'success',/
REGEX, $execute);
        $this->assertMatchesRegularExpression(<<<'REGEX'
/\$emit\('environment',\s*'error',/
REGEX, $execute);
    }

    public function testFailedCompleteEventKeepsServerMessageAndRestoresRetryButton(): void
    {
        $installPage = (string) file_get_contents(dirname(__DIR__, 3) . '/public/install/index.html');
        $streamHandler = $this->sectionBetween(
            $installPage,
            'function handleStreamEvent(',
            'async function consumeInstallStream(',
        );
        $installRequest = $this->sectionBetween(
            $installPage,
            'async function doInstall(',
            'function renderInstallMeta(',
        );
        $failureHandler = $this->sectionBetween(
            $installPage,
            'function finishInstallFailure(',
            'function handleStreamEvent(',
        );
        $streamState = $this->sectionBetween(
            $installPage,
            'const API =',
            'const agreementState =',
        );

        $this->assertStringContainsString('let installStreamCompleted = false;', $streamState);
        $this->assertStringContainsString('installStreamCompleted = true;', $streamHandler);
        $this->assertStringContainsString("finishInstallFailure(payload.message || '安装失败');", $streamHandler);
        $this->assertStringContainsString('if (!installStreamCompleted) {', $installRequest);
        $this->assertStringNotContainsString('if (!installCompleted) {', $installRequest);
        $this->assertStringContainsString("const btn = document.getElementById('installBtn');", $failureHandler);
        $this->assertStringContainsString('btn.disabled = false;', $failureHandler);
        $this->assertStringContainsString("btn.textContent = '开始安装';", $failureHandler);
    }

    public function testInstallPageProvidesHostQuickFillButtons(): void
    {
        $installPage = (string) file_get_contents(dirname(__DIR__, 3) . '/public/install/index.html');

        $this->assertStringContainsString("fillHostValue('db_host', 'db', 'mysql')", $installPage);
        $this->assertStringContainsString("fillHostValue('db_host', 'db', 'host.docker.internal')", $installPage);
        $this->assertStringContainsString("fillHostValue('db_host', 'db', '127.0.0.1')", $installPage);
        $this->assertStringContainsString("fillHostValue('redis_host', 'redis', 'redis')", $installPage);
        $this->assertStringContainsString("fillHostValue('redis_host', 'redis', 'host.docker.internal')", $installPage);
        $this->assertStringContainsString("fillHostValue('redis_host', 'redis', '127.0.0.1')", $installPage);
        $this->assertStringContainsString('function fillHostValue(inputId, validationType, value)', $installPage);
    }

    public function testInstallPageRequiresPlatformAgreementBeforeContinuing(): void
    {
        $root = dirname(__DIR__, 3);
        $installPage = (string) file_get_contents($root . '/public/install/index.html');
        $installService = (string) file_get_contents($root . '/app/service/install/InstallService.php');
        $installController = (string) file_get_contents($root . '/app/controller/install/InstallController.php');
        $installRoute = (string) file_get_contents($root . '/route/install.php');

        $this->assertStringContainsString("Route::get('agreement', 'agreement');", $installRoute);
        $this->assertStringContainsString('public function agreement(): Response', $installController);
        $this->assertStringContainsString('getInstallAgreement()', $installController);

        $this->assertStringContainsString(
            "private const PLATFORM_BASE_URL = 'https://platform.gosowong.cn';",
            $installService,
        );
        $this->assertStringContainsString("private const PLATFORM_APP_CODE = 'mallbase';", $installService);
        $this->assertStringContainsString("'/api/v1/install/agreement?'", $installService);
        $this->assertStringContainsString(
            "http_build_query(['app_code' => self::PLATFORM_APP_CODE])",
            $installService,
        );
        $this->assertStringContainsString('CURLOPT_CONNECTTIMEOUT_MS', $installService);
        $this->assertStringContainsString('CURLOPT_TIMEOUT_MS', $installService);
        $this->assertStringContainsString('if (!is_string($raw))', $installService);
        $this->assertStringContainsString('if ($status < 200 || $status >= 300)', $installService);
        $this->assertStringContainsString('$decoded = json_decode($raw, true)', $installService);
        $this->assertStringContainsString("if (!is_array(\$decoded['data'] ?? null))", $installService);
        $this->assertStringContainsString(
            "\$enabled = \$this->platformBoolean(\$data['enabled'] ?? true, true)",
            $installService,
        );
        $this->assertStringContainsString('if (!$enabled)', $installService);
        $this->assertMatchesRegularExpression("/'source'\\s*=>\\s*'platform'/", $installService);
        $this->assertStringContainsString('unavailableInstallAgreement', $installService);
        $this->assertStringContainsString("if (\$content === '')", $installService);
        $this->assertStringContainsString(
            "'available' => false",
            $installService,
        );
        $this->assertStringNotContainsString("DIRECTORY_SEPARATOR . 'LICENSE'", $installService);
        $this->assertStringNotContainsString("'source'   => 'local'", $installService);
        $this->assertStringNotContainsString('license_unavailable', $installService);

        $this->assertStringContainsString('id="agreementPanel"', $installPage);
        $this->assertStringContainsString('id="install_agreement_accept"', $installPage);
        $this->assertStringContainsString('<h2>1. 安装协议</h2>', $installPage);
        $this->assertStringContainsString('<h2>2. 环境检测</h2>', $installPage);
        $this->assertStringContainsString('id="agreementNext"', $installPage);
        $this->assertStringContainsString('id="envNext"', $installPage);
        $this->assertStringContainsString('sandbox=""', $installPage);
        $this->assertStringContainsString("const r = await api('/agreement');", $installPage);
        $this->assertStringContainsString('请阅读平台提供的安装协议，确认后再继续安装。', $installPage);
        $this->assertStringContainsString('请检查服务器网络或平台服务后点击刷新协议。', $installPage);
        $this->assertStringNotContainsString('MallBase 开源许可文件缺失或不可读', $installPage);
        $this->assertStringContainsString('function agreementBlockMessage()', $installPage);
        $this->assertStringContainsString('function handleAgreementNext()', $installPage);
        $this->assertStringContainsString('frame.srcdoc = buildAgreementDocument(data.content);', $installPage);
        $this->assertMatchesRegularExpression(
            "/if \\(!agreementState\\.available\\) \\{\\s*return '安装协议未加载成功，请刷新协议后再继续。';\\s*\\}/",
            $installPage,
        );
        $this->assertStringContainsString("btn.disabled = agreementBlockMessage() !== '';", $installPage);
        $this->assertStringContainsString('btn.disabled = !envCheckPassed;', $installPage);
        $this->assertStringContainsString('<h2 id="installStateTitle">6. 正在安装</h2>', $installPage);
    }

    public function testAgreementChecksEveryCurlFunctionBeforeCallingCurl(): void
    {
        $installService = (string) file_get_contents(dirname(__DIR__, 3) . '/app/service/install/InstallService.php');
        $agreement = $this->sectionBetween(
            $installService,
            'public function getInstallAgreement()',
            'public function getInstallPageMeta()',
        );
        $fetch = $this->sectionBetween(
            $installService,
            'private function fetchPlatformInstallAgreement()',
            'private function platformBoolean(',
        );

        $this->assertStringContainsString(
            '$missingCurlFunctions = $this->missingEnvironmentFunctions(self::INSTALL_CURL_FUNCTIONS);',
            $fetch,
        );
        $this->assertMatchesRegularExpression(
            <<<'REGEX'
/if \(\$missingCurlFunctions !== \[\]\) \{\s*return\s*\[\s*'success'\s*=>\s*false,/
REGEX,
            $fetch,
        );

        $guardPosition = strpos($fetch, 'missingEnvironmentFunctions(self::INSTALL_CURL_FUNCTIONS)');
        $firstCurlCallPosition = strpos($fetch, 'curl_init(');
        $this->assertIsInt($guardPosition);
        $this->assertIsInt($firstCurlCallPosition);
        $this->assertLessThan($firstCurlCallPosition, $guardPosition);

        $this->assertMatchesRegularExpression(
            <<<'REGEX'
/if \(\(\$response\['success'] \?\? false\) !== true\) \{\s*return \$this->unavailableInstallAgreement/
REGEX,
            $agreement,
        );
    }

    public function testDemoStaticCopyMessageUsesExistingInsteadOfSkippedForInstalledFiles(): void
    {
        $installService = (string) file_get_contents(dirname(__DIR__, 3) . '/app/service/install/InstallService.php');

        $this->assertStringContainsString("'existing'       => 0", $installService);
        $this->assertStringContainsString("\$result['existing']++", $installService);
        $this->assertStringContainsString('演示静态资源就绪（新增 %d，已存在 %d）', $installService);
        $this->assertStringNotContainsString('演示静态资源就绪（新增 %d，跳过 %d）', $installService);
    }

    private function sectionBetween(string $source, string $startMarker, string $endMarker): string
    {
        $start = strpos($source, $startMarker);
        self::assertIsInt($start, '未找到契约起始标记：' . $startMarker);
        $end = strpos($source, $endMarker, $start + strlen($startMarker));
        self::assertIsInt($end, '未找到契约结束标记：' . $endMarker);

        return substr($source, $start, $end - $start);
    }
}
