<?php
// 应用公共文件

if (!function_exists('load_routes')) {
    function load_routes(string $name): void
    {
        $path = app()->getRootPath() . 'route' . DIRECTORY_SEPARATOR . $name;

        foreach (glob($path . DIRECTORY_SEPARATOR . '*.php') as $file) {
            require_once $file;
        }
    }
}


if (!function_exists('convertToRouteName')) {
    /**
     * 转换为路由名称格式
     */
    function convertToRouteName($code)
    {
        // 将下划线或短横线转换为驼峰
        return str_replace(['-', '_'], '', ucwords($code, '-_'));
    }
}

if (!function_exists('getUploadDomain')) {
    /**
     * 获取上传域名
     * 根据配置文件 backend/config/upload.php 中的驱动类型返回对应的上传域名
     *
     * @return string 返回上传域名
     */
    function getUploadDomain(): string
    {
        $config = config('upload');
        $driver = $config['driver'] ?? 'local';

        return match ($driver) {
            'local' => $config['local']['base_url'] ?? '',
            'oss' => $config['oss']['urlPrefix'] ?? '',
            'cos' => $config['cos']['urlPrefix'] ?? '',
            default => '',
        };
    }
}
