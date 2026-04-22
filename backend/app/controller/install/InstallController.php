<?php

declare(strict_types=1);

namespace app\controller\install;

use app\service\install\InstallService;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use think\Request;
use think\Response;
use think\swoole\response\Iterator as IteratorResponse;

class InstallController
{
    protected InstallService $service;

    public function __construct()
    {
        $this->service = new InstallService();
    }

    public function check(): Response
    {
        $result = $this->service->checkEnvironment();
        return json(['code' => 200, 'data' => $result, 'message' => 'ok']);
    }

    public function formDefaults(): Response
    {
        $result = $this->service->getFormDefaults();
        return json(['code' => 200, 'data' => $result, 'message' => 'ok']);
    }

    public function status(): Response
    {
        $installed = $this->service->isInstalled();
        $data = [
            'installed'    => $installed,
            'installed_at' => null,
            'version'      => null,
        ];
        if ($installed) {
            $info = $this->service->getLockInfo() ?? [];
            $data['installed_at'] = $info['installed_at'] ?? null;
            $data['version']      = $info['version'] ?? null;
        }
        return json(['code' => 200, 'data' => $data, 'message' => 'ok']);
    }

    public function testDb(Request $request): Response
    {
        $config = [
            'host' => $request->post('db_host', '127.0.0.1'),
            'port' => $request->post('db_port', 3306),
            'user' => $request->post('db_user', 'root'),
            'pass' => $request->post('db_pass', ''),
            'name' => $request->post('db_name', 'mallbase'),
        ];

        $result = $this->service->testDatabase($config);
        $code = $result['success'] ? 200 : 400;

        return json(['code' => $code, 'data' => $result, 'message' => $result['message']]);
    }

    public function testRedis(Request $request): Response
    {
        $config = [
            'host'     => $request->post('redis_host', '127.0.0.1'),
            'port'     => $request->post('redis_port', 6379),
            'db'       => $request->post('redis_db', 0),
            'password' => $request->post('redis_password', ''),
        ];

        $result = $this->service->testRedis($config);
        $code = $result['success'] ? 200 : 400;

        return json(['code' => $code, 'data' => $result, 'message' => $result['message']]);
    }

    public function execute(Request $request): Response
    {
        $params = $request->post();
        $validation = $this->validateInstallParams($params);
        if ($validation !== null) {
            return $validation;
        }

        $result = $this->service->execute($params);
        $code = $result['success'] ? 200 : 400;

        return json(['code' => $code, 'data' => $result, 'message' => $result['message']]);
    }

    public function executeStream(Request $request)
    {
        $params = $request->post();
        $validation = $this->validateInstallInput($params);
        if (!$validation['success']) {
            return $this->buildStreamResponse(function (callable $emit) use ($validation): void {
                $emit('complete', [
                    'success' => false,
                    'message' => $validation['message'],
                    'result'  => [
                        'success'  => false,
                        'step'     => 'validate',
                        'message'  => $validation['message'],
                        'steps'    => [],
                        'redirect' => false,
                    ],
                ]);
            });
        }

        return $this->buildStreamResponse(function (callable $emit) use ($params): void {
            try {
                $result = $this->service->execute($params, function (array $event) use ($emit): void {
                    $name = $event['event'] ?? 'progress';
                    unset($event['event']);
                    $emit($name, $event);
                });

                if (!$result['success']) {
                    $emit('complete', [
                        'success' => false,
                        'message' => $result['message'],
                        'result'  => $result,
                    ]);
                }
            } catch (\Throwable $e) {
                $emit('complete', [
                    'success' => false,
                    'message' => '安装执行异常：' . $e->getMessage(),
                    'result'  => [
                        'success'  => false,
                        'step'     => 'exception',
                        'message'  => '安装执行异常：' . $e->getMessage(),
                        'steps'    => [],
                        'redirect' => false,
                    ],
                ]);
            }
        });
    }

    private function validateInstallParams(array $params): ?Response
    {
        $validation = $this->validateInstallInput($params);
        if ($validation['success']) {
            return null;
        }

        return json(['code' => 400, 'message' => $validation['message'], 'data' => null]);
    }

    private function validateInstallInput(array $params): array
    {
        $required = ['db_host', 'db_user', 'db_name', 'admin_user', 'admin_pass', 'redis_host'];
        foreach ($required as $field) {
            if (empty($params[$field])) {
                return ['success' => false, 'message' => "缺少必填字段: {$field}"];
            }
        }

        if (strlen((string) $params['admin_pass']) < 6) {
            return ['success' => false, 'message' => '管理员密码至少 6 位'];
        }

        if (isset($params['redis_db']) && (int) $params['redis_db'] < 0) {
            return ['success' => false, 'message' => 'Redis DB 编号不能小于 0'];
        }

        return ['success' => true, 'message' => 'ok'];
    }

    private function buildStreamResponse(callable $producer): Response
    {
        if (!class_exists(IteratorResponse::class) || !class_exists(Channel::class) || !class_exists(Coroutine::class)) {
            return json([
                'code'    => 500,
                'message' => '当前环境不支持流式安装响应',
                'data'    => null,
            ]);
        }

        $channel = new Channel(32);

        Coroutine::create(function () use ($channel, $producer): void {
            try {
                $emit = function (string $event, array $payload) use ($channel): void {
                    $channel->push($this->formatStreamChunk($event, $payload));
                };

                $producer($emit);
            } finally {
                $channel->close();
            }
        });

        $response = new IteratorResponse((function () use ($channel) {
            while (true) {
                $chunk = $channel->pop();
                if ($chunk === false) {
                    break;
                }
                yield $chunk;
            }
        })());

        $response->header([
            'Content-Type'      => 'text/event-stream; charset=utf-8',
            'Cache-Control'     => 'no-cache, no-transform',
            'X-Accel-Buffering' => 'no',
        ]);

        return $response;
    }

    private function formatStreamChunk(string $event, array $payload): string
    {
        return 'event: ' . $event . "\n"
            . 'data: ' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
    }
}
