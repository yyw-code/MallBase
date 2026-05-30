<?php

declare(strict_types=1);

namespace app\controller\install;

use app\service\install\InstallService;
use mall_base\base\BaseController;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use think\Response;
use think\swoole\response\Iterator as IteratorResponse;

/**
 * 安装控制器
 * @extends BaseController<InstallService>
 */
class InstallController extends BaseController
{
    protected string $serviceClass = InstallService::class;

    public function check(): Response
    {
        return $this->success($this->service()->checkEnvironment(), '获取成功');
    }

    public function formDefaults(): Response
    {
        return $this->success($this->service()->getFormDefaults(), '获取成功');
    }

    public function status(): Response
    {
        return $this->success($this->service()->getInstallStatus($this->buildEntryUrls()), '获取成功');
    }

    public function adminReady(): Response
    {
        $target = (string) $this->request->get('target', 'admin');
        $result = $this->service()->checkEntryReady($target);
        if ($result['ready']) {
            return $this->success($result, $result['message']);
        }

        return $this->error($result['message'], 400, $result);
    }

    public function testDb(): Response
    {
        $config = [
            'host' => $this->request->post('db_host', '127.0.0.1'),
            'port' => $this->request->post('db_port', 3306),
            'user' => $this->request->post('db_user', 'root'),
            'pass' => $this->request->post('db_pass', ''),
            'name' => $this->request->post('db_name', 'mallbase'),
        ];

        $result = $this->service()->testDatabase($config);
        if ($result['success']) {
            return $this->success($result, $result['message']);
        }

        return $this->error($result['message'], 400, $result);
    }

    public function testRedis(): Response
    {
        $config = [
            'host'     => $this->request->post('redis_host', '127.0.0.1'),
            'port'     => $this->request->post('redis_port', 6379),
            'db'       => $this->request->post('redis_db', 0),
            'password' => $this->request->post('redis_password', ''),
        ];

        $result = $this->service()->testRedis($config);
        if ($result['success']) {
            return $this->success($result, $result['message']);
        }

        return $this->error($result['message'], 400, $result);
    }

    public function execute(): Response
    {
        $params = $this->request->post();
        $validation = $this->validateInstallParams($params);
        if ($validation !== null) {
            return $validation;
        }

        $result = $this->service()->execute($params);
        if ($result['success']) {
            return $this->success($result, $result['message']);
        }

        return $this->error($result['message'], 400, $result);
    }

    public function executeStream()
    {
        $params = $this->request->post();
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
                $result = $this->service()->execute($params, function (array $event) use ($emit): void {
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

        return $this->error($validation['message']);
    }

    private function validateInstallInput(array $params): array
    {
        $required = ['db_host', 'db_user', 'db_name', 'admin_user', 'admin_pass', 'redis_host'];
        foreach ($required as $field) {
            if (empty($params[$field])) {
                return ['success' => false, 'message' => "缺少必填字段: {$field}"];
            }
        }

        if (!preg_match('/^[A-Za-z0-9_]+$/', (string) $params['db_name'])) {
            return ['success' => false, 'message' => '数据库名只能包含字母、数字和下划线'];
        }

        if (strlen((string) $params['admin_pass']) < 6) {
            return ['success' => false, 'message' => '管理员密码至少 6 位'];
        }

        if (isset($params['redis_db']) && (int) $params['redis_db'] < 0) {
            return ['success' => false, 'message' => 'Redis DB 编号不能小于 0'];
        }

        foreach (['cron_enable', 'swoole_queue_enable'] as $field) {
            if (!isset($params[$field])) {
                continue;
            }

            $value = strtolower(trim((string) $params[$field]));
            if (!in_array($value, ['0', '1', 'true', 'false', 'on', 'off', 'yes', 'no', ''], true)) {
                return ['success' => false, 'message' => "{$field} 参数值无效"];
            }
        }

        return ['success' => true, 'message' => 'ok'];
    }

    private function buildEntryUrls(): array
    {
        $baseUrl = rtrim((string) $this->request->domain(), '/');
        if ($baseUrl === '' && method_exists($this->request, 'host')) {
            $host = (string) $this->request->host();
            if ($host !== '') {
                $baseUrl = $this->request->scheme() . '://' . $host;
            }
        }

        return [
            'admin_url'  => $baseUrl !== '' ? $baseUrl . '/admin' : '/admin',
            'client_url' => $baseUrl !== '' ? $baseUrl : '/',
        ];
    }

    private function buildStreamResponse(callable $producer): Response
    {
        if (!class_exists(IteratorResponse::class) || !class_exists(Channel::class) || !class_exists(Coroutine::class)) {
            return json([
                'code'    => 500,
                'message' => '当前环境不支持流式安装响应',
                'data'    => null,
                'timestamp' => time(),
            ], 500);
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
