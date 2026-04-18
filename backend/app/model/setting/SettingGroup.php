<?php

declare (strict_types=1);

namespace app\model\setting;

use mall_base\base\BaseModel;

/**
 * 设置分组模型
 */
class SettingGroup extends BaseModel
{
    /**
     * 展示方式：目录（纯导航）
     */
    const DISPLAY_TYPE_CATEGORY = 'category';

    /**
     * 展示方式：独立页面
     */
    const DISPLAY_TYPE_PAGE = 'page';

    /**
     * 展示方式：选项卡聚合
     */
    const DISPLAY_TYPE_TAB = 'tab';

    /**
     * 展示类型映射
     */
    const DISPLAY_TYPES = [
        self::DISPLAY_TYPE_CATEGORY => '目录',
        self::DISPLAY_TYPE_PAGE => '页面',
        self::DISPLAY_TYPE_TAB => '选项卡',
    ];

    /**
     * 表名
     */
    protected $name = 'setting_group';

    /**
     * 自动写入时间戳
     */
    protected $autoWriteTimestamp = true;

    /**
     * 关联设置项
     */
    public function settings()
    {
        return $this->hasMany(Setting::class, 'group_id', 'id')
            ->order('sort', 'asc');
    }

    /**
     * 关联子分组
     */
    public function children()
    {
        return $this->hasMany(self::class, 'parent_id', 'id')
            ->order('sort', 'asc');
    }

    /**
     * 关联父级分组
     */
    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id', 'id');
    }

    /**
     * 将扁平数据转换为树形结构
     *
     * @param array $list 扁平数据列表
     * @param int $parentId 起始父级ID
     * @return array 树形结构数据
     */
    public static function toTree(array $list, int $parentId = 0): array
    {
        $tree = [];
        foreach ($list as $item) {
            if ((int)$item['parent_id'] === $parentId) {
                $children = self::toTree($list, (int)$item['id']);
                if (!empty($children)) {
                    $item['children'] = $children;
                }
                $tree[] = $item;
            }
        }
        return $tree;
    }

    /**
     * 获取所有有效的展示类型
     *
     * @return array 有效类型列表
     */
    public static function getValidDisplayTypes(): array
    {
        return [
            self::DISPLAY_TYPE_CATEGORY,
            self::DISPLAY_TYPE_PAGE,
            self::DISPLAY_TYPE_TAB,
        ];
    }

    /**
     * 获取展示类型的中文名称
     *
     * @return string 类型名称
     */
    public function getDisplayTypeNameAttribute(): string
    {
        return self::DISPLAY_TYPES[$this->display_type] ?? '未知';
    }

    /**
     * 验证父级关系是否合法
     *
     * @param int $parentId 父级ID
     * @param string $displayType 展示类型
     * @return bool 是否合法
     */
    public function isValidParent(int $parentId, string $displayType): bool
    {
        // 目录不能有父级
        if ($displayType === self::DISPLAY_TYPE_CATEGORY) {
            return $parentId === 0;
        }

        // 选项卡的父级必须是页面类型
        if ($displayType === self::DISPLAY_TYPE_TAB && $parentId > 0) {
            $parent = self::find($parentId);
            return $parent && $parent->display_type === self::DISPLAY_TYPE_PAGE;
        }

        return true;
    }

    /**
     * 检查是否可以关联设置项
     *
     * @return bool 是否可以关联设置项
     */
    public function canHaveSettings(): bool
    {
        // 只有页面类型可以关联设置项
        return $this->display_type === self::DISPLAY_TYPE_PAGE;
    }
}
