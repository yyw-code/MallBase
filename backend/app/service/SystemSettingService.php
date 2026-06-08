<?php

declare(strict_types=1);

namespace app\service;

use app\model\setting\Setting;
use app\model\setting\SettingGroup;
use app\service\cache\SettingCacheService;
use app\service\upload\AssetHydrator;
use mall_base\base\BaseService;

/**
 * 系统设置统一读取门面（只读）
 *
 * 职责边界：
 * - 本类只负责"读取"与"缓存"，Admin 展示 logo/版权、Client 公开 API 都调本类。
 * - 写入 / 验证 / 表单结构仍由 {@see \app\service\admin\setting\SettingService} 负责。
 *
 * 设计要点（遵守 service-stateless-swoole）：
 * - 无状态：Model 每次通过 $this->model() 动态获取，不缓存实例。
 * - 缓存：直接复用 {@see SettingCacheService}（Redis 层），与 SettingService 写入路径同 key，读写一致。
 * - 图片字段：通过 AssetHydrator 批量补全 full_url，兼容素材 ID 与旧路径。
 *
 * @extends BaseService<SettingGroup>
 */
class SystemSettingService extends BaseService
{
    protected string $modelClass = SettingGroup::class;

    protected SettingCacheService $cacheService;

    public function __construct()
    {
        $this->cacheService = app()->make(SettingCacheService::class);
    }

    /**
     * 统一读取入口
     * - 传 string $code：返回单个值 mixed
     * - 传 array  $codes：返回 array<code => value>
     *
     * 三级 fallback：Redis 缓存 → DB 非空值 → $default（null 时再回退 config()）
     *
     * @param string|array<int, string> $codeOrCodes
     * @param mixed $default
     * @return mixed
     */
    public function getSystemSetting(string|array $codeOrCodes, mixed $default = null): mixed
    {
        if (is_string($codeOrCodes)) {
            return $this->readSingle($codeOrCodes, $default);
        }

        $result = [];
        foreach ($codeOrCodes as $code) {
            $result[$code] = $this->readSingle($code, null);
        }
        return $result;
    }

    /**
     * 按 group_code 批量取该组下全部键值（不含 full_url）
     *
     * @param string $groupCode
     * @return array<string, mixed>
     */
    public function getSystemSettingGroup(string $groupCode): array
    {
        return $this->loadGroupSettings($groupCode)['values'];
    }

    /**
     * 按 group_code 取该组的完整元数据（含 full_url 图片补全）
     * 用于前端 appMeta / client basic 等需要图片完整 URL 的场景。
     *
     * @param string $groupCode
     * @return array<string, array{value: mixed, full_url: mixed, type: string}>
     */
    public function getSystemSettingGroupWithMeta(string $groupCode): array
    {
        return $this->loadGroupSettings($groupCode)['meta'];
    }

    /**
     * 多 group 合并（扁平 key->value，含 full_url 的图片字段值自动替换为 full_url）
     * 典型用途：/admin/api/config/appMeta、/client/api/setting/basic
     *
     * @param array<int, string> $groupCodes
     * @return array<string, mixed>
     */
    public function getSystemSettingGroups(array $groupCodes): array
    {
        $merged = [];
        foreach ($groupCodes as $groupCode) {
            $meta = $this->getSystemSettingGroupWithMeta($groupCode);
            foreach ($meta as $code => $item) {
                $merged[$code] = $this->resolveDisplayValue($item);
            }
        }
        return $merged;
    }

    /** 强类型便捷包装 */
    public function bool(string $code, bool $default = false): bool
    {
        $value = $this->getSystemSetting($code, null);
        if ($value === null) {
            return $default;
        }
        return in_array((string) $value, ['1', 'true', 'on', 'yes'], true);
    }

    public function int(string $code, int $default = 0): int
    {
        $value = $this->getSystemSetting($code, null);
        if ($value === null || $value === '') {
            return $default;
        }
        return (int) $value;
    }

    public function string(string $code, string $default = ''): string
    {
        $value = $this->getSystemSetting($code, null);
        if ($value === null || $value === '') {
            return $default;
        }
        return (string) $value;
    }

    /**
     * 清缓存（SettingService 保存后内部已调，此处提供外部主动触发入口）
     */
    public function flush(?string $groupCode = null): void
    {
        if ($groupCode === null) {
            $this->cacheService->clearAll();
            return;
        }
        $this->cacheService->clearGroup($groupCode);
    }

    /**
     * 单值读取（内部方法）
     */
    private function readSingle(string $code, mixed $default): mixed
    {
        $value = $this->cacheService->getSettingValue($code, function () use ($code) {
            $setting = $this->model(Setting::class)
                ->where('code', $code)
                ->find();

            if (!$setting || $setting->value === null || $setting->value === '') {
                return null;
            }
            return $setting->value;
        });

        if ($value !== null) {
            return $value;
        }

        if ($default !== null) {
            return $default;
        }

        return null;
    }

    /**
     * 加载分组下所有设置项（直接查 DB，不加外层缓存）
     *
     * 为何不缓存：SettingCacheService 的 `setting:group:{code}` 已被 SettingService::getGroupConfig 占用
     * 且返回的数据结构不同；为避免双结构冲突与失效不同步，此处每请求直查 DB（单 SQL，性能可接受）。
     * 若后续需缓存，可走 `setting:value:{code}` 的粒度级缓存（SettingService 已同步失效）。
     *
     * @return array{values: array<string, mixed>, meta: array<string, array{value: mixed, full_url: mixed, type: string}>}
     */
    private function loadGroupSettings(string $groupCode): array
    {
        $group = $this->model()->where('code', $groupCode)->find();
        if (!$group) {
            return ['values' => [], 'meta' => []];
        }

        $settings = $this->model(Setting::class)
            ->where('group_id', $group->id)
            ->order('sort', 'asc')
            ->select()
            ->toArray();
        $settings = app()->make(AssetHydrator::class)->hydrateSettings($settings);

        $values = [];
        $meta = [];
        foreach ($settings as $setting) {
            $arr = $setting;
            $code = (string) $arr['code'];
            $values[$code] = $arr['value'] ?? null;
            $meta[$code] = [
                'value' => $arr['value'] ?? null,
                'full_url' => $arr['full_url'] ?? null,
                'type' => (string) ($arr['type'] ?? ''),
            ];
        }

        return ['values' => $values, 'meta' => $meta];
    }

    /**
     * 将 meta 条目解析为对外展示值：
     * - 图片/文件类型：优先返回 full_url（若为空则返回原始 value）
     * - 其他类型：直接返回 value
     *
     * @param array{value: mixed, full_url: mixed, type: string} $item
     * @return mixed
     */
    private function resolveDisplayValue(array $item): mixed
    {
        if (in_array($item['type'], Setting::FILE_TYPES, true)) {
            if (!empty($item['full_url'])) {
                return $item['full_url'];
            }
        }
        return $item['value'];
    }
}
