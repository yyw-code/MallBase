<?php
// 应用公共文件

use app\service\SystemSettingService;
use app\service\UploadService;

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
     * 统一调用公共 UploadService 获取，方便后续维护
     *
     * @return string 返回上传域名
     */
    function getUploadDomain(): string
    {
        return UploadService::getUploadDomain();
    }
}

if (!function_exists('buildUploadUrl')) {
    /**
     * 构建上传文件完整 URL
     *
     * 规则：
     * - 为空返回空字符串
     * - 已经是 http/https 绝对地址则原样返回
     * - 其他情况拼接上传域名
     */
    function buildUploadUrl(?string $path): string
    {
        if ($path === null) {
            return '';
        }

        $path = trim($path);
        if ($path === '') {
            return '';
        }

        if (ctype_digit($path)) {
            return '';
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        $normalizedPath = ltrim($path, '/');
        if (str_starts_with($normalizedPath, 'static/')) {
            $siteUrl = rtrim(trim((string) getSystemSetting('site_url', '')), '/');
            $staticPath = '/' . $normalizedPath;
            return $siteUrl === '' ? $staticPath : $siteUrl . $staticPath;
        }

        $localUrlPrefix = trim((string) getSystemSetting('local_url_prefix', '/uploads'), '/');
        if ($localUrlPrefix !== '' && str_starts_with($normalizedPath, $localUrlPrefix . '/')) {
            $localBaseUrl = rtrim(trim((string) getSystemSetting('local_base_url', '')), '/');
            if ($localBaseUrl === '') {
                $localBaseUrl = rtrim(trim((string) getSystemSetting('site_url', '')), '/');
            }
            $localPath = '/' . $normalizedPath;
            return $localBaseUrl === '' ? $localPath : $localBaseUrl . $localPath;
        }

        $domain = rtrim(trim(getUploadDomain()), '/');
        if ($domain === '') {
            return $path;
        }

        return $domain . '/' . ltrim($path, '/');
    }
}

if (!function_exists('buildUploadUrls')) {
    /**
     * 批量构建上传文件完整 URL
     *
     * @param array<int, string> $paths
     * @return array<int, string>
     */
    function buildUploadUrls(array $paths): array
    {
        return array_map(
            static fn($path) => buildUploadUrl(is_string($path) ? $path : ''),
            $paths
        );
    }
}

if (!function_exists('getSystemSetting')) {
    /**
     * 获取系统设置项的值（带缓存）
     *
     * 使用示例：
     *   getSystemSetting('wechat_appid')                    // 不存在返回 null
     *   getSystemSetting('wechat_appid', 'default_value')   // 不存在时返回 'default_value'
     *
     * @param string $code 设置项编码
     * @param mixed $default 默认值（设置项不存在或值为空时返回）
     * @return mixed
     */
    function getSystemSetting(string $code, mixed $default = null): mixed
    {
        try {
            return app()->make(SystemSettingService::class)
                ->getSystemSetting($code, $default);
        } catch (\Throwable) {
            return $default;
        }
    }
}

if (!function_exists('getSystemSettingGroup')) {
    /**
     * 按分组编码批量获取该组下全部键值（图片字段自动返回完整 URL）
     *
     * 使用示例：
     *   getSystemSettingGroup('UploadLocal')
     *   // => ['local_url_prefix' => '/uploads', 'local_root_path' => 'uploads', 'local_base_url' => '...']
     *
     * @param string $groupCode 分组编码（如 UploadLocal、SmsAliyun）
     * @return array<string, mixed>
     */
    function getSystemSettingGroup(string $groupCode): array
    {
        try {
            return app()->make(SystemSettingService::class)
                ->getSystemSettingGroups([$groupCode]);
        } catch (\Throwable) {
            return [];
        }
    }
}
