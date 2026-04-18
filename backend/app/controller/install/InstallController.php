<?php

declare(strict_types=1);

namespace app\controller\install;

use app\install\service\InstallService;
use think\Request;
use think\Response;

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
            'password' => $request->post('redis_password', ''),
        ];

        $result = $this->service->testRedis($config);
        $code = $result['success'] ? 200 : 400;

        return json(['code' => $code, 'data' => $result, 'message' => $result['message']]);
    }

    public function execute(Request $request): Response
    {
        $params = $request->post();

        $required = ['db_host', 'db_user', 'db_name', 'admin_user', 'admin_pass', 'redis_host'];
        foreach ($required as $field) {
            if (empty($params[$field])) {
                return json(['code' => 400, 'message' => "缺少必填字段: {$field}", 'data' => null]);
            }
        }

        if (strlen($params['admin_pass']) < 6) {
            return json(['code' => 400, 'message' => '管理员密码至少 6 位', 'data' => null]);
        }

        $result = $this->service->execute($params);
        $code = $result['success'] ? 200 : 500;

        return json(['code' => $code, 'data' => $result, 'message' => $result['message']]);
    }
}
