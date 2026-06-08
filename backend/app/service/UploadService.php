<?php

declare (strict_types=1);

namespace app\service;

use app\service\client\WechatService;
use app\service\upload\AssetService;
use mall_base\base\BaseService;
use mall_base\drivers\DriverManager;
use mall_base\drivers\upload\LocalUploadDriver;
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
    public const NGINX_413_HINT = '若仍报 413 Payload Too Large，请检查 Nginx client_max_body_size 并与 PHP 上传限制保持一致。';

    /** 证书私有上传模块标记。文件落到 backend/storage/cert/，不进 public 目录，不返回外网可访问 URL。 */
    public const MODULE_CERT = 'cert';

    /** 证书存储相对路径（相对项目根 root_path()） */
    public const CERT_STORAGE_SUBDIR = 'storage/cert';

    /** 证书允许的文件扩展名 */
    public const CERT_ALLOWED_EXTENSIONS = ['pem', 'key', 'crt', 'cer'];

    /** 证书默认大小上限（MB）。PEM/CRT 通常 < 5KB，1MB 足够 */
    public const CERT_DEFAULT_MAX_SIZE_MB = 1.0;

    // ==================== 统一配置获取入口（静态方法，供所有模块调用） ====================

    private static array $defaultMime = [
        'image' => ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'],
        'document' => [
            'application/pdf',
            'application/zip',
            'application/x-zip-compressed',
            'application/x-rar-compressed',
            'application/vnd.rar',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ],
        'video' => ['video/mp4', 'video/webm', 'video/quicktime'],
        // 证书/密钥按扩展名匹配（PEM/CRT 的 MIME 检测不稳定），存以 . 开头的扩展名
        'cert' => ['.pem', '.key', '.crt', '.cer'],
    ];

    private static array $defaultExtensions = [
        'image' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
        'document' => ['pdf', 'zip', 'rar', 'doc', 'docx', 'xls', 'xlsx'],
        'video' => ['mp4', 'webm', 'mov'],
        'cert' => ['pem', 'key', 'crt', 'cer'],
    ];

    private static array $mimeExtensionMap = [
        'image/jpeg' => 'jpg',
        'image/jpg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
        'application/zip' => 'zip',
        'application/x-zip-compressed' => 'zip',
        'application/x-rar-compressed' => 'rar',
        'application/vnd.rar' => 'rar',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'video/mp4' => 'mp4',
        'video/webm' => 'webm',
        'video/quicktime' => 'mov',
    ];

    private static array $defaultRules = [
        'image'  => ['max_size' => 2,   'max_count' => 1,  'mime_key' => 'image'],
        'images' => ['max_size' => 5,   'max_count' => 9,  'mime_key' => 'image'],
        'file'   => ['max_size' => 10,  'max_count' => 1,  'mime_key' => 'document'],
        'files'  => ['max_size' => 10,  'max_count' => 5,  'mime_key' => 'document'],
        'video'  => ['max_size' => 200, 'max_count' => 1,  'mime_key' => 'video'],
        'videos' => ['max_size' => 200, 'max_count' => 5,  'mime_key' => 'video'],
        // cert 是一种"虚拟"上传类型，专供 form_type=file/files 字段在 secure_upload 模式下使用；
        // 它本身不作为前端的 form_type，但 accept_types 选项库会被合并进 file/files 字段
        'cert'   => ['max_size' => 1,   'max_count' => 1,  'mime_key' => 'cert'],
    ];

    private static array $uploadTypeLabels = [
        'image' => '单图',
        'images' => '多图',
        'video' => '单视频',
        'videos' => '多视频',
        'file' => '单文件',
        'files' => '多文件',
    ];

    private static array $assetTypeLabels = [
        'image' => '图片',
        'video' => '视频',
        'file' => '文件',
    ];

    private static array $uploadDriverLabels = [
        'local' => '本地',
        'oss' => 'OSS',
        'cos' => 'COS',
    ];

    /**
     * 解析 MIME 白名单（数据库逗号分隔字符串 → 数组）
     */
    private static function parseMimeTypes(string $mimeKey): array
    {
        $dbKeys = [
            'image'    => 'mime_image',
            'document' => 'mime_document',
            'video'    => 'mime_video',
            'cert'     => 'mime_cert',
        ];
        $code = $dbKeys[$mimeKey] ?? null;
        if ($code === null) {
            return self::$defaultMime[$mimeKey] ?? [];
        }

        $raw = (string) getSystemSetting($code, '');
        if ($raw === '') {
            return self::$defaultMime[$mimeKey] ?? [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    /**
     * 获取上传规则配置（全部类型）
     *
     * @return array<string, array{max_size: float, max_count: int, accept_types: string[]}>
     */
    public static function getRules(): array
    {
        $rules = [];
        foreach (self::$defaultRules as $type => $defaults) {
            $rules[$type] = self::buildRule($type, $defaults);
        }
        return $rules;
    }

    /**
     * 获取指定类型的上传规则
     *
     * @param string $type 上传类型（image/images/file/files/video/videos）
     * @return array|null
     */
    public static function getRuleByType(string $type): ?array
    {
        if (!isset(self::$defaultRules[$type])) {
            return null;
        }
        return self::buildRule($type, self::$defaultRules[$type]);
    }

    private static function buildRule(string $type, array $defaults): array
    {
        return [
            'max_size'     => (float) getSystemSetting("upload_{$type}_max_size", $defaults['max_size']),
            'max_count'    => (int) getSystemSetting("upload_{$type}_max_count", $defaults['max_count']),
            'accept_types' => self::parseMimeTypes($defaults['mime_key']),
        ];
    }

    /**
     * 获取 PHP 运行时上传限制（MB / 个数）
     *
     * @return array{
     *   php_upload_max_filesize_mb: float|null,
     *   php_post_max_size_mb: float|null,
     *   php_max_file_uploads: int|null,
     *   effective_max_size_mb: float|null,
     *   effective_max_count: int|null
     * }
     */
    public static function getSystemUploadLimits(): array
    {
        $uploadMaxSizeMb = self::parseIniSizeToMb(ini_get('upload_max_filesize'));
        $postMaxSizeMb = self::parseIniSizeToMb(ini_get('post_max_size'));
        $maxFileUploads = self::parseIniInt(ini_get('max_file_uploads'));

        $sizeCandidates = array_values(array_filter([$uploadMaxSizeMb, $postMaxSizeMb], static fn($v) => is_numeric($v) && $v > 0));
        $effectiveMaxSizeMb = empty($sizeCandidates) ? null : floatval(min($sizeCandidates));
        $effectiveMaxCount = ($maxFileUploads !== null && $maxFileUploads > 0) ? $maxFileUploads : null;

        return [
            'php_upload_max_filesize_mb' => $uploadMaxSizeMb,
            'php_post_max_size_mb' => $postMaxSizeMb,
            'php_max_file_uploads' => $maxFileUploads,
            'effective_max_size_mb' => $effectiveMaxSizeMb,
            'effective_max_count' => $effectiveMaxCount,
        ];
    }

    /**
     * 获取当前上传驱动名称
     *
     * @return string
     */
    public static function getDriver(): string
    {
        return (string) getSystemSetting('upload_driver', 'local');
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

        $domain = match ($driver) {
            'local' => (string) getSystemSetting('local_base_url', ''),
            'oss' => (string) getSystemSetting('oss_url_prefix', ''),
            'cos' => (string) getSystemSetting('cos_url_prefix', ''),
            default => '',
        };

        if ($driver === 'local' && $domain === '') {
            $domain = (string) getSystemSetting('site_url', '');
        }

        return $domain;
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

    /**
     * 按系统上限处理上传规则
     *
     * @param array $rule 原始规则
     * @param string $type 上传类型（用于 warning 文案）
     * @return array{
     *   rule: array{max_size: float, max_count: int, accept_types: string[]},
     *   warnings: string[],
     *   system_limits: array{
     *      php_upload_max_filesize_mb: float|null,
     *      php_post_max_size_mb: float|null,
     *      php_max_file_uploads: int|null,
     *      effective_max_size_mb: float|null,
     *      effective_max_count: int|null
     *   },
     *   clamped: bool
     * }
     */
    public static function applySystemLimits(array $rule, string $type = ''): array
    {
        $normalizedRule = self::normalizeRule($rule);
        $limits = self::getSystemUploadLimits();
        $warnings = [];
        $clamped = false;
        $sizeLimitedByPhp = false;

        $effectiveMaxSizeMb = $limits['effective_max_size_mb'];
        if (is_numeric($effectiveMaxSizeMb) && $effectiveMaxSizeMb > 0) {
            $effectiveMaxSize = floatval($effectiveMaxSizeMb);
            if ($normalizedRule['max_size'] > $effectiveMaxSize) {
                $normalizedRule['max_size'] = $effectiveMaxSize;
                $warnings[] = sprintf(
                    '上传类型%s的 max_size 已按 PHP 上限截断为 %sMB。',
                    $type !== '' ? " {$type}" : '',
                    self::formatNumber($effectiveMaxSize)
                );
                $clamped = true;
                $sizeLimitedByPhp = true;
            } elseif (abs($normalizedRule['max_size'] - $effectiveMaxSize) < 0.0001) {
                $warnings[] = sprintf(
                    '上传类型%s的 max_size 已受 PHP 上限限制为 %sMB。',
                    $type !== '' ? " {$type}" : '',
                    self::formatNumber($effectiveMaxSize)
                );
                $sizeLimitedByPhp = true;
            }
        }

        $effectiveMaxCount = $limits['effective_max_count'];
        if (is_numeric($effectiveMaxCount) && $effectiveMaxCount > 0 && $normalizedRule['max_count'] > $effectiveMaxCount) {
            $normalizedRule['max_count'] = intval($effectiveMaxCount);
            $warnings[] = sprintf(
                '上传类型%s的 max_count 已按 PHP 上限截断为 %d。',
                $type !== '' ? " {$type}" : '',
                intval($effectiveMaxCount)
            );
            $clamped = true;
        }

        if ($clamped || $sizeLimitedByPhp) {
            $warnings[] = self::NGINX_413_HINT;
        }

        return [
            'rule' => $normalizedRule,
            'warnings' => array_values(array_unique($warnings)),
            'system_limits' => $limits,
            'clamped' => $clamped,
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

        $result = self::applySystemLimits($rules[$type], $type);

        return array_merge($result['rule'], [
            'system_limits' => $result['system_limits'],
            'warnings' => $result['warnings'],
        ]);
    }

    /**
     * 获取后台上传公共选项。
     *
     * @return array{
     *   upload_types: array<int,array{label:string,value:string,asset_type:string,multiple:bool}>,
     *   asset_types: array<int,array{label:string,value:string}>,
     *   upload_drivers: array<int,array{label:string,value:string,enabled:bool}>
     * }
     */
    public function getUploadOptions(): array
    {
        $uploadTypes = [];
        foreach (['image', 'images', 'video', 'videos', 'file', 'files'] as $type) {
            $uploadTypes[] = [
                'label' => self::$uploadTypeLabels[$type],
                'value' => $type,
                'asset_type' => $this->assetTypeFromUploadType($type),
                'multiple' => in_array($type, ['images', 'videos', 'files'], true),
            ];
        }

        $assetTypes = [];
        foreach (self::$assetTypeLabels as $value => $label) {
            $assetTypes[] = compact('label', 'value');
        }

        $currentDriver = self::getDriver();
        $uploadDrivers = [];
        foreach (DriverManager::getRegisteredDrivers('upload') as $driver => $driverClass) {
            if (!class_exists($driverClass)) {
                continue;
            }
            $uploadDrivers[] = [
                'label' => self::$uploadDriverLabels[$driver] ?? strtoupper((string) $driver),
                'value' => (string) $driver,
                'enabled' => (string) $driver === $currentDriver,
            ];
        }

        return [
            'upload_types' => $uploadTypes,
            'asset_types' => $assetTypes,
            'upload_drivers' => $uploadDrivers,
        ];
    }

    /**
     * 获取客户端安全上传配置。
     *
     * @return array{max_size: float, max_count: int, accept_types: string[], tips: string[]}
     */
    public function getClientUploadConfig(string $type): array
    {
        $config = $this->getUploadConfig($type);
        return [
            'max_size' => (float) ($config['max_size'] ?? 0),
            'max_count' => (int) ($config['max_count'] ?? 1),
            'accept_types' => (array) ($config['accept_types'] ?? []),
            'tips' => $this->buildClientUploadTips($type, (float) ($config['max_size'] ?? 0)),
        ];
    }

    private function assetTypeFromUploadType(string $type): string
    {
        if (in_array($type, ['image', 'images'], true)) {
            return 'image';
        }
        if (in_array($type, ['video', 'videos'], true)) {
            return 'video';
        }
        return 'file';
    }

    /**
     * @return string[]
     */
    private function buildClientUploadTips(string $type, float $maxSize): array
    {
        if ($maxSize <= 0) {
            return [];
        }

        $label = match ($this->assetTypeFromUploadType($type)) {
            'image' => '图片',
            'video' => '视频',
            default => '文件',
        };
        $size = self::formatNumber($maxSize);
        $tip = "{$label}最大支持 {$size}MB";
        if ($label === '视频') {
            $tip .= '，请压缩后上传';
        }

        return [$tip];
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
        // cert 模块单独处理：从 mb_setting.rules 读取 accept_types/max_size，但 accept_types 按扩展名解析
        if ($module === self::MODULE_CERT) {
            return $this->resolveCertRules($relatedId);
        }

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

        // 所有来源统一做系统上限截断，保证行为一致
        $applied = self::applySystemLimits($result, $type);

        return $applied['rule'];
    }

    // ==================== 上传功能 ====================

    /**
     * 上传文件（图片和文件统一入口）
     *
     * @param mixed $file 上传的文件对象
     * @param array $rules 验证规则 max_size(MB)/max_count/accept_types
     * @param string $module 存储路径模块（用于区分上传路径，如 admin、api）
     * @param string $assetModule 业务素材模块（用于分类，如 goods/review/avatar）
     * @param string $uploaderType 上传者类型：admin/user/system
     * @param int $uploaderId 上传者 ID
     * @param int $categoryId 指定素材分类 ID，0 表示按模块自动归类
     * @return array 返回文件路径信息
     */
    public function upload(
        $file,
        array $rules = [],
        string $module = '',
        string $assetModule = '',
        string $uploaderType = 'admin',
        int $uploaderId = 0,
        int $categoryId = 0
    ): array
    {
        if (!$file) {
            throw new BusinessException('文件不存在');
        }

        // cert 模块走专用私有上传分支（不进 public/uploads，不暴露外网 URL）
        if ($module === self::MODULE_CERT) {
            return $this->uploadCert($file, $rules);
        }

        // 没有传入规则则使用默认图片规则
        if (empty($rules)) {
            $rules = $this->getUploadConfig('image');
        }

        $this->validateUploadFile($file, $rules);

        // 获取上传驱动
        $driverName = self::getDriver();
        $uploadDriver = $this->getUploadDriver();

        // 生成文件名和路径（按 module 区分）
        $extension = $this->resolveStorageExtension($file);
        $fileName = $this->generateFileName($extension);
        $subDir = $this->getSubDir($rules['accept_types'], $module);
        $objectName = $subDir . '/' . $this->generateDatePath() . '/' . $fileName;

        $tempPath = $file->getPathname();

        try {
            $uploadDriver->upload($tempPath, $objectName);
            $fileInfo = $uploadDriver->getFileInfo($objectName);
            if (!is_array($fileInfo)) {
                throw new BusinessException('上传失败：无法读取文件信息');
            }

            return app()->make(AssetService::class)->createFromUploadedFile(
                $fileInfo,
                $driverName,
                $tempPath,
                $file,
                $assetModule !== '' ? $assetModule : ($module !== '' ? $module : 'other'),
                $uploaderType,
                $uploaderId,
                $categoryId
            );
        } finally {
            if (file_exists($tempPath)) {
                unlink($tempPath);
            }
        }
    }

    // ==================== 证书/密钥私有上传（cert module） ====================

    /**
     * 证书/密钥私有上传：写入 backend/storage/cert/，文件权限 0600，目录权限 0700。
     * - 验证：扩展名白名单 + 大小上限 + 内容嗅探（必须含 "-----BEGIN" 标识）。
     * - 不走 MIME 校验（PEM/CRT 等证书文件的 MIME 检测不稳定）。
     * - 返回 FileInfo 的 url 形如 "storage/cert/abc.pem"（相对项目根），full_url 强制为空。
     *
     * @param mixed $file 上传文件对象（think\File）
     * @param array $rules 验证规则，包含 max_size (MB) / accept_types (扩展名列表，形如 [".pem", ".key"])
     */
    private function uploadCert($file, array $rules = []): array
    {
        if (empty($rules)) {
            $rules = $this->resolveCertRules(0);
        }

        $this->validateCertFile($file, $rules);

        $driver = $this->buildCertDriver();

        // 保留原始扩展名（已通过白名单校验），文件名走随机 md5
        $extension = strtolower(pathinfo((string)$file->getOriginalName(), PATHINFO_EXTENSION));
        $fileName = $this->generateFileName($extension);

        $tempPath = $file->getPathname();
        $targetAbsolute = root_path() . self::CERT_STORAGE_SUBDIR . '/' . $fileName;

        try {
            $driver->upload($tempPath, $fileName);

            // 收紧文件权限至 0600（驱动默认 0644 对私钥不够安全）
            if (file_exists($targetAbsolute)) {
                @chmod($targetAbsolute, 0600);
            }

            $info = $driver->getFileInfo($fileName);
            if (!is_array($info)) {
                throw new BusinessException('证书上传失败：无法读取文件信息');
            }

            // 抹掉 full_url，避免前端误以为有公开访问入口
            $info['full_url'] = '';
            return $info;
        } finally {
            if (file_exists($tempPath)) {
                @unlink($tempPath);
            }
        }
    }

    /**
     * 解析 cert 模块的上传规则：
     * - 默认值走 UploadConfig 联动（cert 类型规则 + mime_cert 白名单）
     * - 单个字段在 mb_setting.rules 显式声明 accept_types/max_size 时按字段优先
     *
     * accept_types 按"扩展名"语义解析（与 file/image 模块的 MIME 语义不同）。
     *
     * @return array{max_size: float, max_count: int, accept_types: string[]}
     */
    private function resolveCertRules(int $relatedId): array
    {
        // 联动：cert 类型的 max_size 与 mime_cert 来自 UploadConfig
        $certDefaults = self::getRuleByType('cert') ?? [
            'max_size'     => self::CERT_DEFAULT_MAX_SIZE_MB,
            'max_count'    => 1,
            'accept_types' => array_map(static fn($ext) => '.' . $ext, self::CERT_ALLOWED_EXTENSIONS),
        ];

        $maxSize     = (float)($certDefaults['max_size'] ?? self::CERT_DEFAULT_MAX_SIZE_MB);
        $acceptTypes = (array)($certDefaults['accept_types'] ?? []);
        if (empty($acceptTypes)) {
            $acceptTypes = array_map(static fn($ext) => '.' . $ext, self::CERT_ALLOWED_EXTENSIONS);
        }

        if ($relatedId > 0) {
            $settingModel = $this->getSettingModel();
            $setting = $settingModel->findOrEmpty($relatedId);
            if (!$setting->isEmpty()) {
                $settingRules = $setting->rules ?? [];
                if (is_array($settingRules)) {
                    $extracted = $this->extractUploadRules($settingRules);
                    if (isset($extracted['max_size'])) {
                        $maxSize = (float)$extracted['max_size'];
                    }
                    if (isset($extracted['accept_types']) && is_array($extracted['accept_types']) && !empty($extracted['accept_types'])) {
                        $acceptTypes = $extracted['accept_types'];
                    }
                }
            }
        }

        return [
            'max_size'     => $maxSize,
            'max_count'    => 1,
            'accept_types' => $acceptTypes,
        ];
    }

    /**
     * 校验 cert 文件：扩展名白名单 + 大小 + BEGIN 标识。
     */
    private function validateCertFile($file, array $rules): void
    {
        $maxSize = isset($rules['max_size']) ? (float)$rules['max_size'] : self::CERT_DEFAULT_MAX_SIZE_MB;
        if ($maxSize <= 0) {
            $maxSize = self::CERT_DEFAULT_MAX_SIZE_MB;
        }
        $maxSizeBytes = $maxSize * 1024 * 1024;
        if ($file->getSize() > $maxSizeBytes) {
            throw new BusinessException("证书文件大小不能超过 {$maxSize}MB");
        }

        $accept = $rules['accept_types'] ?? [];
        $allowedExt = [];
        foreach ($accept as $entry) {
            $normalized = strtolower(ltrim((string)$entry, '.'));
            if ($normalized !== '') {
                $allowedExt[] = $normalized;
            }
        }
        if (empty($allowedExt)) {
            $allowedExt = self::CERT_ALLOWED_EXTENSIONS;
        }

        $extension = strtolower(pathinfo((string)$file->getOriginalName(), PATHINFO_EXTENSION));
        if ($extension === '' || !in_array($extension, $allowedExt, true)) {
            throw new BusinessException(
                '证书文件扩展名不允许，允许的扩展名：' . implode(', ', array_map(static fn($e) => '.' . $e, $allowedExt))
            );
        }

        // 内容嗅探：前 256 字节内应出现 "-----BEGIN" 标识
        $head = @file_get_contents($file->getPathname(), false, null, 0, 256);
        if ($head === false || !str_contains($head, '-----BEGIN')) {
            throw new BusinessException('文件内容不是有效的证书/密钥格式（缺少 -----BEGIN 标识）');
        }
    }

    /**
     * 构造 cert 专用 LocalUploadDriver。
     * - root_path 指向项目根下 storage/cert（非 public，外网不可访问）
     * - url_prefix 设为 storage/cert，使 getUrl 返回 "storage/cert/xxx.pem" 作为 DB value
     * - base_url 留空，full_url 由 uploadCert() 单独抹空
     * - 目录提前以 0700 创建
     */
    private function buildCertDriver(): LocalUploadDriver
    {
        $absRoot = root_path() . self::CERT_STORAGE_SUBDIR;
        if (!is_dir($absRoot)) {
            @mkdir($absRoot, 0700, true);
        }
        // 确保目录权限收紧（防止 .gitkeep 创建时留下的 0755）
        @chmod($absRoot, 0700);

        return new LocalUploadDriver([
            'root_path'  => $absRoot,
            'url_prefix' => self::CERT_STORAGE_SUBDIR,
            'base_url'   => '',
        ]);
    }

    /**
     * 批量上传文件
     *
     * @param array $files 文件对象数组
     * @param array $rules 验证规则
     * @param string $module 存储路径模块（用于区分上传路径）
     * @param string $assetModule 业务素材模块
     * @param string $uploaderType 上传者类型
     * @param int $uploaderId 上传者 ID
     * @param int $categoryId 指定素材分类 ID，0 表示按模块自动归类
     * @return array{results: array, errors: array}
     */
    public function batchUpload(
        array $files,
        array $rules = [],
        string $module = '',
        string $assetModule = '',
        string $uploaderType = 'admin',
        int $uploaderId = 0,
        int $categoryId = 0
    ): array
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
                $results[] = $this->upload($file, $rules, $module, $assetModule, $uploaderType, $uploaderId, $categoryId);
            } catch (\Exception $e) {
                $errors[] = "文件 {$key}: " . $e->getMessage();
            }
        }

        if (empty($results)) {
            throw new BusinessException(implode('; ', $errors));
        }

        return ['results' => $results, 'errors' => $errors];
    }

    /**
     * 客户端单图上传。
     */
    public function uploadClientImage($file, string $module = 'client', int $userId = 0): array
    {
        $rules = $this->resolveUploadRules('image', '', 0);
        return $this->upload($file, $rules, 'client', $module, 'user', $userId);
    }

    /**
     * 微信绑定阶段临时头像上传，仅校验 bind_token，不签发登录态。
     */
    public function uploadWechatAvatar($file, string $bindToken): array
    {
        app()->make(WechatService::class)->assertMiniappBindToken($bindToken);
        return $this->uploadClientImage($file, 'wechat_avatar', 0);
    }

    /**
     * 头像等图片入库前的路径校验。
     *
     * 头像文件应先通过上传接口进入平台上传系统，再保存返回的 url/path。
     */
    public function normalizeStoredImagePath(string $image): string
    {
        $image = trim($image);
        if ($image === '') {
            return '';
        }

        if (ctype_digit($image)) {
            app()->make(AssetService::class)->assertUsableImageAssets([(int) $image]);
            return $image;
        }

        if (str_starts_with($image, '//') || preg_match('#^https?://#i', $image)) {
            throw new BusinessException('请先上传头像后再保存平台上传路径');
        }

        $scheme = (string) (parse_url($image, PHP_URL_SCHEME) ?: '');
        if ($scheme !== '') {
            throw new BusinessException('头像地址格式不正确');
        }

        return $image;
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

            if (!isset(self::$mimeExtensionMap[$mimeType])) {
                throw new BusinessException('文件类型未配置安全扩展名映射');
            }
        }

        $extension = strtolower(pathinfo((string)$file->getOriginalName(), PATHINFO_EXTENSION));
        $allowedExtensions = $this->getAllowedExtensions($acceptTypes);
        if ($extension === '' || !in_array($extension, $allowedExtensions, true)) {
            throw new BusinessException('文件扩展名不允许，允许的扩展名: ' . implode(', ', $allowedExtensions));
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

        $applied = self::applySystemLimits($rule, $type);

        return $applied['rule'];
    }

    /**
     * 解析 php.ini 大小配置并转为 MB
     *
     * @param mixed $value
     */
    private static function parseIniSizeToMb($value): ?float
    {
        if (!is_string($value) && !is_numeric($value)) {
            return null;
        }

        $raw = trim((string)$value);
        if ($raw === '' || $raw === '-1') {
            return null;
        }

        if (!preg_match('/^([0-9]+(?:\.[0-9]+)?)\s*([kmgtp]?)/i', $raw, $matches)) {
            return null;
        }

        $number = floatval($matches[1] ?? 0);
        $unit = strtolower($matches[2] ?? '');
        if ($number <= 0) {
            return null;
        }

        $bytes = match ($unit) {
            'k' => $number * 1024,
            'm' => $number * 1024 * 1024,
            'g' => $number * 1024 * 1024 * 1024,
            't' => $number * 1024 * 1024 * 1024 * 1024,
            'p' => $number * 1024 * 1024 * 1024 * 1024 * 1024,
            default => $number,
        };

        return $bytes / 1024 / 1024;
    }

    /**
     * 解析 php.ini 整数配置
     *
     * @param mixed $value
     */
    private static function parseIniInt($value): ?int
    {
        if (!is_string($value) && !is_numeric($value)) {
            return null;
        }
        $num = intval(trim((string)$value));
        return $num > 0 ? $num : null;
    }

    private static function formatNumber(float $num): string
    {
        if (floor($num) === $num) {
            return (string)intval($num);
        }
        return rtrim(rtrim(sprintf('%.2f', $num), '0'), '.');
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
     * 根据 MIME 白名单计算扩展名白名单。
     *
     * @param string[] $acceptTypes
     * @return string[]
     */
    private function getAllowedExtensions(array $acceptTypes): array
    {
        $extensions = [];
        foreach ($acceptTypes as $mime) {
            if (isset(self::$mimeExtensionMap[$mime])) {
                $extensions[] = self::$mimeExtensionMap[$mime];
            }

            if ($mime === 'image/jpeg' || $mime === 'image/jpg') {
                $extensions[] = 'jpeg';
            }
        }

        if ($extensions === []) {
            return array_values(array_unique(array_merge(
                self::$defaultExtensions['image'],
                self::$defaultExtensions['document'],
                self::$defaultExtensions['video'],
            )));
        }

        return array_values(array_unique($extensions));
    }

    private function resolveStorageExtension($file): string
    {
        $mimeType = (string)$file->getMime();
        if (isset(self::$mimeExtensionMap[$mimeType])) {
            return self::$mimeExtensionMap[$mimeType];
        }

        throw new BusinessException('文件类型未配置安全扩展名映射');
    }

    /**
     * 获取上传驱动
     */
    private function getUploadDriver()
    {
        $driverName = self::getDriver();

        if (!in_array($driverName, ['local', 'oss', 'cos'], true)) {
            throw new BusinessException('当前上传驱动暂不可用，请切换为本地存储、阿里云 OSS 或腾讯云 COS');
        }

        $groupMap = [
            'local' => 'UploadLocal',
            'oss'   => 'UploadOss',
            'cos'   => 'UploadCos',
        ];

        $groupCode = $groupMap[$driverName] ?? null;
        $rawConfig = $groupCode !== null ? getSystemSettingGroup($groupCode) : [];

        $prefix = $driverName . '_';
        $prefixLen = strlen($prefix);
        $driverConfig = [];
        foreach ($rawConfig as $key => $value) {
            if (str_starts_with($key, $prefix)) {
                $driverConfig[substr($key, $prefixLen)] = $value;
            } else {
                $driverConfig[$key] = $value;
            }
        }

        if (empty($driverConfig['base_url'])) {
            $driverConfig['base_url'] = (string) getSystemSetting('site_url', '');
        }

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
        return app()->make(\app\model\setting\Setting::class);
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
