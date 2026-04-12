<?php

declare (strict_types=1);

namespace app\service;

use mall_base\base\BaseService;
use mall_base\drivers\DriverManager;
use mall_base\exception\BusinessException;

/**
 * 上传公共服务
 * @extends BaseService<\mall_base\base\BaseModel>
 *
 * 所有模块（admin、api 等）统一使用此服务进行文件上传
 * 上传路径按 module 区分，如：images/admin/2026/04/04/xxx.jpg
 *
 * 功能说明：
 * - 提供统一的上传配置获取入口（getRules / getRuleByType / getDriver / getUploadDomain）
 * - 支持通过 module + related_id 从 mb_setting 动态获取上传规则（dynamic_form 模块）
 * - 单文件/批量文件上传
 * - 上传路径按 module 区分存储
 *
 * 使用示例：
 * ```php
 * use app\service\UploadService;
 *
 * // 获取上传规则
 * $rules = UploadService::getRuleByType('image');
 *
 * // 获取上传域名
 * $domain = UploadService::getUploadDomain();
 *
 * // 上传文件（通过容器获取实例）
 * $uploadService = app()->make(UploadService::class);
 * $result = $uploadService->upload($file, $rules, 'admin');
 * ```
 */
class UploadService extends BaseService
{
    // ==================== 统一配置获取入口（静态方法，供所有模块调用） ====================

    /**
     * 获取完整上传配置
     *
     * @return array
     */
    public static function getConfig(): array
    {
        return config('upload', []);
    }

    /**
     * 获取指定配置项（支持点号分隔的嵌套键名）
     *
     * @param string $key 配置键名（如 'driver'、'rules'、'local.base_url'）
     * @param mixed $default 默认值
     * @return mixed
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $config = self::getConfig();
        $keys = explode('.', $key);
        $value = $config;

        foreach ($keys as $k) {
            if (!is_array($value) || !array_key_exists($k, $value)) {
                return $default;
            }
            $value = $value[$k];
        }

        return $value;
    }

    /**
     * 获取上传规则配置（全部类型）
     *
     * @return array
     */
    public static function getRules(): array
    {
        return self::get('rules', []);
    }

    /**
     * 获取指定类型的上传规则
     * 不存在则返回 null
     *
     * @param string $type 上传类型（image/images/file/files/video/videos）
     * @return array|null
     */
    public static function getRuleByType(string $type): ?array
    {
        return self::get("rules.{$type}");
    }

    /**
     * 获取当前上传驱动名称
     *
     * @return string
     */
    public static function getDriver(): string
    {
        return self::get('driver', 'local');
    }

    /**
     * 获取上传域名
     * 根据当前驱动类型返回对应的上传域名
     *
     * @return string
     */
    public static function getUploadDomain(): string
    {
        $driver = self::getDriver();

        return match ($driver) {
            'local' => self::get('local.base_url', ''),
            'oss' => self::get('oss.urlPrefix', ''),
            'cos' => self::get('cos.urlPrefix', ''),
            default => '',
        };
    }

    /**
     * 标准化规则配置（确保 max_size/max_count/accept_types 字段完整）
     *
     * @param array $rule 原始规则配置
     * @return array{max_size: float, max_count: int, accept_types: string[]}
     */
    public static function normalizeRule(array $rule): array
    {
        return [
            'max_size' => floatval($rule['max_size'] ?? 2),
            'max_count' => intval($rule['max_count'] ?? 1),
            'accept_types' => $rule['accept_types'] ?? ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
        ];
    }

    // ==================== 前端配置获取 ====================

    /**
     * 获取上传配置（前端 Upload 组件使用）
     * 根据 type 参数返回对应的验证规则
     *
     * @param string $type 上传类型：image/images/file/files/video/videos
     * @return array{max_size: float, max_count: int, accept_types: string[]}
     */
    public function getUploadConfig(string $type): array
    {
        $rules = self::getRules();

        if (!isset($rules[$type])) {
            throw new BusinessException("不支持的上传类型: {$type}");
        }

        return self::normalizeRule($rules[$type]);
    }

    // ==================== 规则解析 ====================

    /**
     * 解析上传验证规则
     *
     * 规则来源优先级：
     * 1. module=dynamic_form 时：通过 related_id 查询 mb_setting 的 rules 字段
     *    - 如果 rules 中包含 max_size/max_count/accept_types 则使用规则中的值
     *    - 如果规则中没有，则回退到 upload 配置文件中对应 type 的默认值
     *    - 如果配置文件中也没有对应 type，则报错
     * 2. module 为空或其他值时：直接从 upload 配置文件按 type 获取
     *
     * @param string $type 上传类型（image/file/images/files/video/videos）
     * @param string $module 模块标识（如 dynamic_form）
     * @param int $relatedId 关联ID（module=dynamic_form 时为 mb_setting 的 ID）
     * @return array{max_size: float, max_count: int, accept_types: string[]}
     */
    public function resolveUploadRules(string $type, string $module = '', int $relatedId = 0): array
    {
        // 从 upload 配置获取默认规则（作为回退），type 不存在则报错
        $configRules = $this->getConfigByType($type);

        // 非 dynamic_form 模块，直接返回配置文件规则
        if ($module !== 'dynamic_form') {
            return $configRules;
        }

        // dynamic_form 模块：必须提供 related_id
        if ($relatedId <= 0) {
            throw new BusinessException('dynamic_form 模块必须提供 related_id');
        }

        // 查询 mb_setting 记录
        $settingModel = $this->getSettingModel();
        $setting = $settingModel->findOrEmpty($relatedId);
        if ($setting->isEmpty()) {
            throw new BusinessException("设置项不存在（ID: {$relatedId}）");
        }

        // 获取 rules JSON 字段（已通过模型自动解码为数组）
        $settingRules = $setting->rules ?? [];
        if (!is_array($settingRules)) {
            $settingRules = [];
        }

        // 从 rules 中提取上传相关规则
        // rules 格式：[{"type": "max_size", "value": 5}, {"type": "max_count", "value": 3}, ...]
        $extracted = $this->extractUploadRules($settingRules);

        // 合并规则：规则中有的用规则中的，没有的用配置文件的
        $result = $configRules;
        if (isset($extracted['max_size'])) {
            $result['max_size'] = floatval($extracted['max_size']);
        }
        if (isset($extracted['max_count'])) {
            $result['max_count'] = intval($extracted['max_count']);
        }
        if (isset($extracted['accept_types'])) {
            $result['accept_types'] = (array)$extracted['accept_types'];
        }

        return $result;
    }

    // ==================== 上传功能 ====================

    /**
     * 上传文件（图片和文件统一入口）
     *
     * @param mixed $file 上传的文件对象
     * @param array $rules 验证规则 max_size(MB)/max_count/accept_types
     * @param string $module 模块标识（用于区分上传路径，如 admin、api）
     * @return array 返回文件路径信息
     */
    public function upload($file, array $rules = [], string $module = ''): array
    {
        if (!$file) {
            throw new BusinessException('文件不存在');
        }

        // 没有传入规则则使用默认图片规则
        if (empty($rules)) {
            $rules = $this->getUploadConfig('image');
        }

        $this->validateUploadFile($file, $rules);

        // 获取上传驱动
        $uploadDriver = $this->getUploadDriver();

        // 生成文件名和路径（按 module 区分）
        $extension = strtolower(pathinfo($file->getOriginalName(), PATHINFO_EXTENSION));
        $fileName = $this->generateFileName($extension);
        $subDir = $this->getSubDir($rules['accept_types'], $module);
        $objectName = $subDir . '/' . $this->generateDatePath() . '/' . $fileName;

        $tempPath = $file->getPathname();

        try {
            $uploadDriver->upload($tempPath, $objectName);
            return $uploadDriver->getFileInfo($objectName);
        } finally {
            if (file_exists($tempPath)) {
                unlink($tempPath);
            }
        }
    }

    /**
     * 批量上传文件
     *
     * @param array $files 文件对象数组
     * @param array $rules 验证规则
     * @param string $module 模块标识（用于区分上传路径）
     * @return array{results: array, errors: array}
     */
    public function batchUpload(array $files, array $rules = [], string $module = ''): array
    {
        if (empty($rules)) {
            $rules = $this->getUploadConfig('images');
        }

        $maxCount = $rules['max_count'] ?? 9;
        if (count($files) > $maxCount) {
            throw new BusinessException("最多上传{$maxCount}个文件");
        }

        $results = [];
        $errors = [];

        foreach ($files as $key => $file) {
            try {
                $results[] = $this->upload($file, $rules, $module);
            } catch (\Exception $e) {
                $errors[] = "文件 {$key}: " . $e->getMessage();
            }
        }

        if (empty($results)) {
            throw new BusinessException(implode('; ', $errors));
        }

        return ['results' => $results, 'errors' => $errors];
    }

    // ==================== 验证 ====================

    /**
     * 验证上传文件
     *
     * @param mixed $file 上传的文件对象
     * @param array $rules 验证规则 [max_size(MB), accept_types(MIME数组)]
     */
    private function validateUploadFile($file, array $rules): void
    {
        // 检查文件大小（max_size 单位 MB）
        $maxSizeBytes = $rules['max_size'] * 1024 * 1024;
        if ($file->getSize() > $maxSizeBytes) {
            throw new BusinessException("文件大小不能超过{$rules['max_size']}MB");
        }

        // 检查文件 MIME 类型
        $acceptTypes = $rules['accept_types'] ?? [];
        if (!empty($acceptTypes)) {
            $mimeType = $file->getMime();
            if (!in_array($mimeType, $acceptTypes, true)) {
                throw new BusinessException('文件类型不允许，允许的类型: ' . implode(', ', $acceptTypes));
            }
        }
    }

    // ==================== 私有工具方法 ====================

    /**
     * 根据 type 获取上传配置（严格模式，type 不存在则报错）
     *
     * @param string $type 上传类型
     * @return array{max_size: float, max_count: int, accept_types: string[]}
     * @throws BusinessException
     */
    private function getConfigByType(string $type): array
    {
        $rule = self::getRuleByType($type);

        if ($rule === null) {
            throw new BusinessException("不支持的上传类型: {$type}");
        }

        return self::normalizeRule($rule);
    }

    /**
     * 从设置项的 rules 中提取上传相关规则
     * rules 格式：[{"type": "max_size", "value": 5}, {"type": "max_count", "value": 3}, {"type": "accept_types", "value": ["image/jpeg"]}]
     *
     * @param array $rules 设置项的 rules 字段
     * @return array 提取出的上传规则 [max_size, max_count, accept_types]
     */
    private function extractUploadRules(array $rules): array
    {
        $extracted = [];

        foreach ($rules as $rule) {
            $type = $rule['type'] ?? '';
            $value = $rule['value'] ?? null;

            // 只提取上传相关的规则类型
            if (in_array($type, ['max_size', 'max_count', 'accept_types'], true) && $value !== null) {
                $extracted[$type] = $value;
            }
        }

        return $extracted;
    }

    /**
     * 获取存储子目录（按 accept_types 和 module 区分）
     * 路径格式：{fileType}/{module}  如 images/admin、videos/admin、files/api
     * module 为空时不加模块层级：images、files
     *
     * @param string[] $acceptTypes 允许的 MIME 类型
     * @param string $module 模块标识
     * @return string
     */
    private function getSubDir(array $acceptTypes, string $module = ''): string
    {
        $firstType = $acceptTypes[0] ?? '';
        if (str_starts_with($firstType, 'image/')) {
            $fileType = 'images';
        } elseif (str_starts_with($firstType, 'video/')) {
            $fileType = 'videos';
        } else {
            $fileType = 'files';
        }

        if (!empty($module)) {
            return $fileType . '/' . $module;
        }

        return $fileType;
    }

    /**
     * 获取上传驱动
     */
    private function getUploadDriver()
    {
        $driverName = self::getDriver();
        $driverConfig = self::get($driverName, []);

        return DriverManager::driver('upload', $driverName, $driverConfig);
    }

    /**
     * 获取 Setting 模型实例
     * 通过 app() 动态获取，避免直接 use admin 模块的模型类
     * 这样当其他模块（如 api）使用此服务时，只要对应模型存在即可
     *
     * @return mixed
     */
    private function getSettingModel()
    {
        return app()->make(\app\admin\model\setting\Setting::class);
    }

    /**
     * 生成随机文件名
     */
    private function generateFileName(string $extension = ''): string
    {
        $name = md5(uniqid((string)mt_rand(), true));

        if ($extension) {
            $name .= '.' . $extension;
        }

        return $name;
    }

    /**
     * 生成按日期分组的文件路径
     */
    private function generateDatePath(): string
    {
        return date('Y/m/d');
    }
}
