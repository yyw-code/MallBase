<?php

/**
 * 路由权限同步命令
 *
 * 功能说明：
 * - 读取所有路由配置
 * - 自动同步路由信息到权限表
 * - 以路由为主进行增量同步
 * - 通过 code 字段确定 parent_id
 * - 保留前端菜单权限
 *
 * 使用示例：
 * ```bash
 * # 同步路由到权限表（增量更新）
 * php think sync:permissions
 *
 * # 预览即将同步的权限（不实际执行）
 * php think sync:permissions --preview
 *
 * # 显示路由树结构
 * php think sync:permissions --tree
 * ```
 *
 * @package app\command
 */

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use think\facade\Db;

class SyncPermissions extends Command
{
    /**
     * 权限类型映射
     */
    const TYPE_MENU = 1;      // 菜单
    const TYPE_BUTTON = 2;    // 按钮
    const TYPE_API = 3;       // 历史接口类型，仅兼容旧数据，不再生成

    /**
     * 权限来源
     */
    const SOURCE_MANUAL = 1;  // 手动添加
    const SOURCE_ROUTE = 2;   // 路由同步
    const SOURCE_SETTING = 3; // 设置模块同步

    /**
     * 数据库表默认值
     */
    const DB_DEFAULTS = [
        'type' => 1,
        'path' => null,
        'icon' => null,
        'component' => null,
        'redirect' => null,
        'sort' => 0,
        'status' => 1,
        'is_show' => 1,
        'affix_tab' => 0,
        'no_basic_layout' => 0,
        'remark' => null,
    ];

    /**
     * 路由数据
     * @var array
     */
    protected $routeData = [];

    /**
     * 已创建的路由组（用于去重）
     * @var array
     */
    protected $createdGroups = [];

    /**
     * 基础菜单数据
     * @var array
     */
    protected $baseMenus = [];

    /**
     * 数据库权限数据（全量，按 code 索引，用于 parent_id 查找）
     * @var array
     */
    protected $dbPermissions = [];

    /**
     * 数据库权限数据（仅 source=2 路由同步的，用于比对增删改）
     * @var array
     */
    protected $syncDbPermissions = [];

    /**
     * 数据库权限数据（按 name 索引，用于查找 parent_id）
     * @var array
     */
    protected $dbPermissionsByName = [];

    /**
     * 待创建的权限
     * @var array
     */
    protected $toCreate = [];

    /**
     * 待更新的权限
     * @var array
     */
    protected $toUpdate = [];

    /**
     * 待删除的权限 code 列表
     * @var array
     */
    protected $toDelete = [];

    /**
     * 菜单 code 到父级 code 的映射
     * @var array
     */
    protected $menuParentMap = [];

    /**
     * 配置命令
     */
    protected function configure()
    {
        $this->setName('sync:permissions')
            ->setDescription('同步路由配置到权限表')
            ->addOption('preview', 'p', Option::VALUE_NONE, '预览模式（不实际执行）')
            ->addOption('tree', 't', Option::VALUE_NONE, '显示路由树结构');
    }

    /**
     * 执行命令
     */
    protected function execute(Input $input, Output $output)
    {
        $preview = $input->getOption('preview');
        $showTree = $input->getOption('tree');

        $output->writeln('<info>开始同步路由到权限表...</info>');

        if ($preview) {
            $output->writeln('<comment>预览模式：不会实际修改数据库</comment>');
        }

        // 1. 读取路由配置
        $output->writeln('<comment>1. 读取路由配置...</comment>');
        $this->loadRoutes($output);

        // 2. 显示路由树（如果指定了 --tree 选项）
        if ($showTree) {
            $this->showMenuTree($output);
            return;
        }

        // 3. 读取数据库权限
        $output->writeln('<comment>2. 读取数据库权限...</comment>');
        $this->loadDbPermissions();

        // 4. 比对数据，确定操作
        $output->writeln('<comment>3. 比对数据...</comment>');
        $this->compareData();

        // 5. 预览或执行同步
        if ($preview) {
            $this->previewSync($output);
        } else {
            $this->executeSync($output);
        }

        $output->writeln('<info>同步完成！</info>');
    }

    /**
     * 加载路由配置
     */
    protected function loadRoutes($output)
    {
        // 1. 加载基础菜单
        $this->loadBaseMenus();

        // 2. 加载各模块路由
        $this->loadAppRoutes();

        $output->writeln('<info>   加载菜单数: ' . count($this->baseMenus) . '</info>');
        $output->writeln('<info>   加载路由数: ' . count($this->routeData) . '</info>');
    }

    /**
     * 加载基础菜单
     */
    protected function loadBaseMenus()
    {
        $config = config('permissions');
        $this->baseMenus = $config['base_menus'] ?? [];
    }

    /**
     * 加载各模块路由
     */
    protected function loadAppRoutes()
    {
        $config = config('permissions');
        $apps = $config['app'] ?? [];

        foreach ($apps as $appName => $appConfig) {
            // 清除路由缓存
            $this->app->route->clear();
            $this->app->route->lazy(false);

            $path = $this->app->getRootPath() . 'route' . DIRECTORY_SEPARATOR . $appName . '.php';

            if (!is_file($path)) {
                continue;
            }

            include $path;

            // 获取路由列表
            $routeList = $this->app->route->getRuleList();

            // 构建路由父子关系映射
            $routeMap = [];
            foreach ($routeList as $route) {
                $routeName = $route['name'] ?? '';
                if (!empty($routeName)) {
                    $routeMap[$routeName] = $route;
                }
            }

            // 处理所有路由
            foreach ($routeList as $route) {
                $routeName = $route['name'] ?? '';

                // 合并父级路由的 option（向上递归查找）
                $option = $this->mergeParentOptions($route, $routeMap);

                // 检查是否有 _group_code（路由组标识）
                $hasGroupCode = isset($option['_group_code']);
                $isRouteGroup = empty($route['rule']) || $route['rule'] === '/';

                // 跳过不参与角色授权的路由和路由组，避免基础接口集合生成可选权限节点。
                if (isset($option['_auth']) && $option['_auth'] === false) {
                    continue;
                }

                // 如果有 _group_code，创建路由组菜单（路由组本身不在 getRuleList 中，需要从子路由创建）
                if ($hasGroupCode) {
                    $groupCode = $option['_group_code'];
                    $groupName = $option['_group_name'] ?? '';

                    // 如果还没有创建过这个路由组
                    if (!isset($this->createdGroups[$groupCode]) && !empty($groupName)) {
                        // 创建路由组菜单
                        $groupOption = array_merge($option, [
                            '_alias' => $groupName,
                            '_type' => 'menu', // 强制为菜单类型
                        ]);
                        $this->routeData[$groupCode] = $this->parseRouteOption($groupCode, $route, $groupOption);
                        $this->createdGroups[$groupCode] = true;
                    }

                    // 修改子路由的 _parent 为路由组 code，这样子路由会挂在路由组菜单下
                    $option['_parent'] = $groupCode;
                }

                // 处理有 _alias 或 _group_name 的路由
                $hasAlias = isset($option['_alias']);
                $hasGroupName = isset($option['_group_name']);

                if (!$hasAlias && !$hasGroupName) {
                    continue;
                }

                // 有 name 的路由才处理（子路由）
                if (empty($routeName)) {
                    continue;
                }

                // 对于路由组（没有实际 rule），使用 _group_name 作为 _alias
                if ($isRouteGroup && $hasGroupName && !$hasAlias) {
                    $option['_alias'] = $option['_group_name'];
                }

                // 构建路由数据
                $this->routeData[$routeName] = $this->parseRouteOption($routeName, $route, $option);
            }
        }
    }

    /**
     * 合并父级路由的 option（向上递归查找）
     *
     * 注意：子路由的 option 优先级高于父级，避免父级覆盖子级
     *
     * @param array $route 当前路由
     * @param array $routeMap 路由映射
     * @return array 合并后的 option
     */
    protected function mergeParentOptions($route, $routeMap)
    {
        $option = $route['option'] ?? [];
        $parentName = $route['parent'] ?? '';

        if (empty($parentName)) {
            return $option;
        }

        // 递归合并父级的 option
        $parentRoute = $routeMap[$parentName] ?? null;
        if ($parentRoute) {
            $parentOption = $this->mergeParentOptions($parentRoute, $routeMap);
            // 父级 option 被子级 option 覆盖（子级优先级更高）
            $option = array_merge($parentOption, $option);
        }

        return $option;
    }

    /**
     * 解析路由 option
     */
    protected function parseRouteOption($routeName, $route, $option)
    {
        $isActualRoute = !empty($route['rule']) && $route['rule'] !== '/';
        $isGeneratedGroup = isset($option['_group_code']) && $routeName === $option['_group_code'];

        // 确定权限类型
        $type = $option['_type'] ?? null;

        if ($type === null) {
            // 未指定类型时默认按读权限处理；写操作应在路由中显式标记为按钮权限。
            $type = self::TYPE_MENU;
        } else {
            // 支持数字和字符串类型
            if (is_numeric($type)) {
                // 数字类型，直接使用
                $type = (int)$type;
            } else {
                // 字符串类型，映射到常量
                $typeMap = [
                    'menu' => self::TYPE_MENU,
                    'button' => self::TYPE_BUTTON,
                    'api' => self::TYPE_MENU,
                ];
                $type = $typeMap[strtolower($type)] ?? self::TYPE_MENU;
            }
        }

        if ($type === self::TYPE_API) {
            $type = self::TYPE_MENU;
        }

        // 确定父级权限的 code
        $parentCode = '';
        if ($type === self::TYPE_MENU) {
            // 菜单类型：使用 _parent 字段（是 code）
            $parentCode = $option['_parent'] ?? '';
        } else {
            // 接口类型：也使用 _parent 字段（路由组的 _parent）
            // 这样接口可以直接挂在路由组的父级菜单下
            $parentCode = $option['_parent'] ?? '';

            // 如果没有 _parent，尝试使用 _group_name 查找
            if (empty($parentCode)) {
                $groupName = $option['_group_name'] ?? '';
                if (!empty($groupName)) {
                    // 在 base_menus 或 routeData 中查找对应的菜单
                    $parentCode = $this->findMenuCodeByName($groupName);
                }
            }
        }

        // 构建基础数据
        $data = [
            'code' => $routeName,
            'name' => $option['_alias'] ?? '',
            'type' => $type,
            'parent_code' => $parentCode,
        ];

        // 添加其他可选字段（路由中定义的）
        $fieldMap = [
            '_path' => 'path',
            '_icon' => 'icon',
            '_component' => 'component',
            '_redirect' => 'redirect',
            '_sort' => 'sort',
            '_status' => 'status',
            '_is_show' => 'is_show',
            '_affix_tab' => 'affix_tab',
            '_no_basic_layout' => 'no_basic_layout',
            '_remark' => 'remark',
            '_desc' => 'remark', // _desc 别名
            '_group_name_desc' => 'remark', // _group_name_desc 用于路由组备注
        ];

        foreach ($fieldMap as $routeKey => $dbField) {
            if (isset($option[$routeKey])) {
                $data[$dbField] = $option[$routeKey];
            }
        }

        // 路由子权限默认不显示在菜单，只作为页面读权限或按钮权限参与授权。
        if ($isActualRoute && !$isGeneratedGroup) {
            unset($data['path'], $data['icon'], $data['component'], $data['redirect']);
            $data['is_show'] = 0;
        }

        return $data;
    }

    /**
     * 根据名称查找菜单的 code（递归）
     * 先在 base_menus 中查找，再在 routeData 中查找
     *
     * @param string $name 菜单名称
     * @param array $menus 菜单数组
     * @return string
     */
    protected function findMenuCodeByName($name, $menus = null)
    {
        if ($menus === null) {
            $menus = $this->baseMenus;
        }

        // 先在 base_menus 中查找
        foreach ($menus as $menu) {
            if ($menu['name'] === $name) {
                return $menu['code'];
            }

            // 递归查找子菜单
            if (isset($menu['children']) && !empty($menu['children'])) {
                $code = $this->findMenuCodeByName($name, $menu['children']);
                if ($code) {
                    return $code;
                }
            }
        }

        // 如果在 base_menus 中没找到，在 routeData 中查找（路由组创建的菜单）
        foreach ($this->routeData as $code => $route) {
            if ($route['type'] === self::TYPE_MENU && $route['name'] === $name) {
                return $code;
            }
        }

        return '';
    }

    /**
     * 根据 code 查找菜单（递归）
     *
     * @param string $code 菜单 code
     * @param array $menus 菜单数组
     * @return array|null
     */
    protected function findMenuByCode($code, $menus = null)
    {
        if ($menus === null) {
            $menus = $this->baseMenus;
        }

        foreach ($menus as $menu) {
            if ($menu['code'] === $code) {
                return $menu;
            }

            // 递归查找子菜单
            if (isset($menu['children']) && !empty($menu['children'])) {
                $found = $this->findMenuByCode($code, $menu['children']);
                if ($found) {
                    return $found;
                }
            }
        }

        return null;
    }

    /**
     * 加载数据库权限
     */
    protected function loadDbPermissions()
    {
        // 加载所有权限数据
        $permissions = Db::name('permission')
            ->select()
            ->toArray();

        foreach ($permissions as $permission) {
            $code = $permission['code'];

            // 按 code 索引（全量，用于 parent_id 查找）
            $this->dbPermissions[$code] = $permission;

            // 按 name 索引（用于查找 parent_id）
            if (!empty($permission['name'])) {
                $this->dbPermissionsByName[$permission['name']] = $permission;
            }

            // source=2 的单独索引（用于比对增删改）
            if (!isset($permission['source']) || $permission['source'] == self::SOURCE_ROUTE) {
                $this->syncDbPermissions[$code] = $permission;
            }
        }
    }

    /**
     * 比对数据
     */
    protected function compareData()
    {
        // 清空菜单父子映射
        $this->menuParentMap = [];

        // 先遍历 base_menus，标记哪些需要创建或更新
        $this->compareMenuData($this->baseMenus, '', []);

        // 再遍历路由数据，只与 source=2 的数据比对
        foreach ($this->routeData as $code => $route) {
            $syncPermission = $this->syncDbPermissions[$code] ?? null;

            if ($syncPermission) {
                // source=2 的记录已存在，需要更新
                $this->toUpdate[] = [
                    'code' => $code,
                    'db_id' => $syncPermission['id'],
                    'data' => $this->buildRoutePermissionData($route, false),
                ];
            } elseif (!isset($this->dbPermissions[$code])) {
                // 数据库中完全不存在，需要创建
                $this->toCreate[] = [
                    'code' => $code,
                    'data' => $this->buildRoutePermissionData($route, false),
                ];
            }
            // source=1 的记录已存在，跳过不动
        }

        // 找出需要删除的权限（在 source=2 的数据中，但路由中没有的）
        foreach ($this->syncDbPermissions as $code => $permission) {
            if (!isset($this->routeData[$code]) && !$this->isBaseMenu($code)) {
                $this->toDelete[] = $code;
            }
        }
    }

    /**
     * 比对菜单数据（递归）
     *
     * @param array $menus 菜单数组
     * @param string $parentCode 父级 code
     * @param array $parentMenus 父级菜单路径
     */
    protected function compareMenuData($menus, $parentCode, $parentMenus = [])
    {
        foreach ($menus as $menu) {
            $code = $menu['code'];
            $effectiveParentCode = $menu['parent'] ?? $parentCode;
            $syncPermission = $this->syncDbPermissions[$code] ?? null;

            // 构建菜单父子映射
            $this->menuParentMap[$code] = $effectiveParentCode;

            if ($syncPermission) {
                // source=2 的记录已存在，需要更新
                $this->toUpdate[] = [
                    'code' => $code,
                    'db_id' => $syncPermission['id'],
                    'data' => $this->buildMenuPermissionData($menu, $effectiveParentCode, false),
                ];
            } elseif (!isset($this->dbPermissions[$code])) {
                // 数据库中完全不存在，需要创建
                $this->toCreate[] = [
                    'code' => $code,
                    'data' => $this->buildMenuPermissionData($menu, $effectiveParentCode, false),
                ];
            }
            // source=1 的记录已存在，跳过不动

            // 递归处理子菜单
            if (isset($menu['children']) && !empty($menu['children'])) {
                $newParentMenus = array_merge($parentMenus, [$menu]);
                $this->compareMenuData($menu['children'], $code, $newParentMenus);
            }
        }
    }

    /**
     * 构建 base_menus 中菜单的权限数据
     *
     * @param array $menu 菜单数据
     * @param string $parentCode 父级 code
     * @param bool $setParentId 是否设置 parent_id
     * @return array
     */
    protected function buildMenuPermissionData($menu, $parentCode = '', $setParentId = true)
    {
        // 构建数据
        $data = [
            'code' => $menu['code'],
            'name' => $menu['name'],
            'type' => $menu['type'],
            'path' => $menu['path'] ?? null,
            'icon' => $menu['icon'] ?? null,
            'component' => $menu['component'] ?? null,
            'redirect' => $menu['redirect'] ?? null,
            'sort' => $menu['sort'] ?? 0,
            'status' => $menu['status'] ?? 1,
            'is_show' => $menu['is_show'] ?? 1,
            'affix_tab' => $menu['affix_tab'] ?? 0,
            'no_basic_layout' => $menu['no_basic_layout'] ?? 0,
            'remark' => $menu['remark'] ?? null,
            'parent_id' => 0,
            'parent_code' => $parentCode, // 保存 parent_code，用于预览显示
        ];

        if ($setParentId && !empty($parentCode)) {
            // 查找父级权限
            $parentPermission = $this->dbPermissions[$parentCode] ?? null;
            $data['parent_id'] = $parentPermission ? $parentPermission['id'] : 0;
        }

        // 添加更新时间
        $data['update_time'] = date('Y-m-d H:i:s');

        // 实际同步时移除 parent_code（数据库表中没有这个字段）
        if ($setParentId) {
            unset($data['parent_code']);
        }

        return $data;
    }

    /**
     * 构建路由权限数据
     *
     * @param array $route 路由数据
     * @param bool $setParentId 是否设置 parent_id
     * @return array
     */
    protected function buildRoutePermissionData($route, $setParentId = true)
    {
        // 保留关键字段（name、code、type、parent_code）
        $data = [
            'code' => $route['code'] ?? '',
            'name' => $route['name'] ?? '',
            'type' => $route['type'] ?? self::TYPE_MENU,
            'parent_code' => $route['parent_code'] ?? '',
        ];

        // 根据 type 确定允许的字段
        $dbFields = array_keys(self::DB_DEFAULTS);

        // 按钮权限不包含 path、icon、component、redirect
        if ($route['type'] === self::TYPE_BUTTON) {
            // 排除这些字段
            $excludeFields = ['path', 'icon', 'component', 'redirect'];
            $dbFields = array_diff($dbFields, $excludeFields);
        }

        // 添加其他允许的字段
        foreach ($dbFields as $field) {
            if (isset($route[$field])) {
                $data[$field] = $route[$field];
            }
        }

        // 未定义的字段用数据库默认值
        foreach (self::DB_DEFAULTS as $field => $defaultValue) {
            if (!isset($data[$field])) {
                $data[$field] = $defaultValue;
            }
        }

        if ($setParentId) {
            // 查找父级权限
            $parentPermission = null;
            $parentCode = $data['parent_code'];

            if (!empty($parentCode)) {
                $parentPermission = $this->dbPermissions[$parentCode] ?? null;
            }

            $data['parent_id'] = $parentPermission ? $parentPermission['id'] : 0;
        } else {
            // 不设置 parent_id，暂时为 0
            $data['parent_id'] = 0;
        }

        // 添加更新时间
        $data['update_time'] = date('Y-m-d H:i:s');

        // 实际同步时移除 parent_code（数据库表中没有这个字段）
        if ($setParentId) {
            unset($data['parent_code']);
        }

        return $data;
    }

    /**
     * 预览同步
     */
    protected function previewSync($output)
    {
        $output->writeln('');
        $output->writeln('<info>=== 预览结果 ===</info>');

        // 构建 code 到名称的映射
        $codeToName = [];
        foreach ($this->dbPermissions as $perm) {
            $codeToName[$perm['code']] = $perm['name'];
        }
        foreach ($this->toCreate as $item) {
            $codeToName[$item['code']] = $item['data']['name'];
        }

        // 辅助函数：获取父级名称
        $getParentName = function ($item) use ($codeToName) {
            $code = $item['code'];
            $data = $item['data'];

            // 直接使用 data 中的 parent_code
            $parentCode = $data['parent_code'] ?? '';
            if (empty($parentCode)) {
                return '无';
            }

            return $codeToName[$parentCode] ?? '无';
        };

        $output->writeln('');
        $output->writeln('<comment>1. 将新增的权限 (' . count($this->toCreate) . '):</comment>');
        foreach ($this->toCreate as $item) {
            $data = $item['data'];
            $typeName = $this->getTypeName($data['type']);
            $parentName = $getParentName($item);

            $output->writeln("   - {$item['code']} ({$data['name']}) type:{$typeName} parent:{$parentName}");
        }

        $output->writeln('');
        $output->writeln('<comment>2. 将更新的权限 (' . count($this->toUpdate) . '):</comment>');
        foreach ($this->toUpdate as $item) {
            $data = $item['data'];
            $typeName = $this->getTypeName($data['type']);
            $parentName = $getParentName($item);

            $output->writeln("   - {$item['code']} ({$data['name']}) type:{$typeName} parent:{$parentName}");
        }

        $output->writeln('');
        $output->writeln('<comment>3. 将删除的权限 (' . count($this->toDelete) . '):</comment>');
        foreach ($this->toDelete as $code) {
            $permission = $this->dbPermissions[$code];
            $output->writeln("   - {$code} ({$permission['name']})");
        }

        $output->writeln('');
        $output->writeln('<info>=== 统计 ===</info>');
        $output->writeln("新增: " . count($this->toCreate));
        $output->writeln("更新: " . count($this->toUpdate));
        $output->writeln("删除: " . count($this->toDelete));
    }

    /**
     * 执行同步
     */
    protected function executeSync($output)
    {
        $startTime = microtime(true);

        // 启动事务
        Db::startTrans();
        try {
            $createdCount = 0;
            $updatedCount = 0;
            $deletedCount = 0;

            // 1. 创建新权限（按顺序创建，先父后子）
            if (!empty($this->toCreate)) {
                $output->writeln('<comment>1. 创建新权限...</comment>');

                // 按 type 排序：菜单(1) -> 按钮(2) -> 接口(3)，确保父级先创建
                usort($this->toCreate, function ($a, $b) {
                    return $a['data']['type'] - $b['data']['type'];
                });

                foreach ($this->toCreate as $item) {
                    // 重新构建数据，这次设置 parent_id
                    $code = $item['code'];
                    $routeOrMenu = $this->routeData[$code] ?? null;

                    if ($routeOrMenu) {
                        // 路由数据
                        $data = $this->buildRoutePermissionData($routeOrMenu, true);
                    } else {
                        // 菜单数据，需要从 base_menus 中查找
                        $menu = $this->findMenuByCode($code, $this->baseMenus);
                        if ($menu) {
                            // 使用 menuParentMap 中的 parentCode
                            $parentCode = $this->menuParentMap[$code] ?? '';
                            $data = $this->buildMenuPermissionData($menu, $parentCode, true);
                        } else {
                            $data = $item['data'];
                        }
                    }

                    // 添加创建时间
                    $data['create_time'] = date('Y-m-d H:i:s');
                    // 新增时标记来源为路由同步
                    $data['source'] = self::SOURCE_ROUTE;

                    $insertId = Db::name('permission')->insertGetId($data);

                    // 更新内存中的数据，让后续的子权限能找到父级
                    $data['id'] = $insertId;
                    $this->dbPermissions[$code] = $data;

                    $output->writeln("   - 创建: {$code} (ID: {$insertId}, parent_id: {$data['parent_id']})");
                    $createdCount++;
                }
            }

            // 2. 更新现有权限（先菜单，后接口）
            if (!empty($this->toUpdate)) {
                $output->writeln('<comment>2. 更新现有权限...</comment>');

                // 按 type 分组：先处理菜单，再处理接口
                $menusToUpdate = [];
                $routesToUpdate = [];
                foreach ($this->toUpdate as $item) {
                    if (isset($this->routeData[$item['code']])) {
                        $routesToUpdate[] = $item;
                    } else {
                        $menusToUpdate[] = $item;
                    }
                }

                // 先更新菜单（按层级排序）
                usort($menusToUpdate, function ($a, $b) {
                    $levelA = $this->getMenuLevel($a['code']);
                    $levelB = $this->getMenuLevel($b['code']);
                    return $levelA - $levelB;
                });

                foreach ($menusToUpdate as $item) {
                    $code = $item['code'];
                    $menu = $this->findMenuByCode($code, $this->baseMenus);
                    if ($menu) {
                        // 使用 menuParentMap 中的 parentCode
                        $parentCode = $this->menuParentMap[$code] ?? '';
                        $data = $this->buildMenuPermissionData($menu, $parentCode, true);
                    } else {
                        $data = $item['data'];
                    }

                    // 标记来源为路由同步
                    $data['source'] = self::SOURCE_ROUTE;

                    Db::name('permission')
                        ->where('id', $item['db_id'])
                        ->update($data);

                    // 更新内存中的数据
                    $data['id'] = $item['db_id'];
                    $this->dbPermissions[$code] = $data;

                    $output->writeln("   - 更新: {$code} (parent_id: {$data['parent_id']})");
                    $updatedCount++;
                }

                // 再更新接口
                foreach ($routesToUpdate as $item) {
                    $code = $item['code'];
                    $routeOrMenu = $this->routeData[$code] ?? null;

                    if ($routeOrMenu) {
                        $data = $this->buildRoutePermissionData($routeOrMenu, true);
                    } else {
                        $data = $item['data'];
                    }

                    // 标记来源为路由同步
                    $data['source'] = self::SOURCE_ROUTE;

                    Db::name('permission')
                        ->where('id', $item['db_id'])
                        ->update($data);

                    // 更新内存中的数据
                    $data['id'] = $item['db_id'];
                    $this->dbPermissions[$code] = $data;

                    $output->writeln("   - 更新: {$code} (parent_id: {$data['parent_id']})");
                    $updatedCount++;
                }
            }

            // 3. 删除无效权限
            if (!empty($this->toDelete)) {
                $output->writeln('<comment>3. 删除无效权限...</comment>');
                foreach ($this->toDelete as $code) {
                    $permissionId = Db::name('permission')->where('code', $code)->value('id');
                    if (!empty($permissionId)) {
                        Db::name('role_permission')->where('permission_id', $permissionId)->delete();
                    }
                    Db::name('permission')->where('code', $code)->delete();
                    $output->writeln("   - 删除: {$code}");
                    $deletedCount++;
                }
            }

            // 提交事务
            Db::commit();

            $endTime = microtime(true);
            $duration = round(($endTime - $startTime) * 1000, 2);

            $output->writeln('');
            $output->writeln('<info>=== 同步完成 ===</info>');
            $output->writeln("新增: {$createdCount}");
            $output->writeln("更新: {$updatedCount}");
            $output->writeln("删除: {$deletedCount}");
            $output->writeln("耗时: {$duration}ms");

        } catch (\Exception $e) {
            // 回滚事务
            Db::rollback();
            $output->writeln('<error>同步失败: ' . $e->getMessage() . '</error>');
            $output->writeln('<error>错误位置: ' . $e->getFile() . ':' . $e->getLine() . '</error>');
            throw $e;
        }
    }

    /**
     * 检查 code 是否属于 base_menus
     */
    protected function isBaseMenu(string $code, ?array $menus = null): bool
    {
        if ($menus === null) {
            $menus = $this->baseMenus;
        }

        foreach ($menus as $menu) {
            if ($menu['code'] === $code) {
                return true;
            }
            if (isset($menu['children']) && !empty($menu['children'])) {
                if ($this->isBaseMenu($code, $menu['children'])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * 获取类型名称
     */
    protected function getTypeName($type)
    {
        $typeMap = [
            self::TYPE_MENU => '菜单',
            self::TYPE_BUTTON => '按钮',
            self::TYPE_API => '接口',
        ];
        return $typeMap[$type] ?? '未知';
    }

    /**
     * 获取菜单层级
     *
     * @param string $code 菜单 code
     * @return int
     */
    protected function getMenuLevel($code)
    {
        if (!isset($this->menuParentMap[$code])) {
            return 0;
        }

        $parentCode = $this->menuParentMap[$code];
        if (empty($parentCode)) {
            return 0;
        }

        return 1 + $this->getMenuLevel($parentCode);
    }

    /**
     * 构建菜单树
     *
     * @return array
     */
    protected function buildMenuTree()
    {
        $tree = [];

        // 添加基础菜单
        foreach ($this->baseMenus as $menu) {
            $node = $this->buildMenuNode($menu);
            $tree[] = $node;
        }

        // 添加路由菜单（没有父级的）
        foreach ($this->routeData as $code => $route) {
            if ($route['type'] === self::TYPE_MENU) {
                $parentCode = $route['parent_code'] ?? '';
                if (empty($parentCode)) {
                    $node = [
                        'name' => $route['name'],
                        'code' => $route['code'],
                        'type' => $route['type'],
                        'children' => $this->findRouteChildren($code),
                    ];
                    $tree[] = $node;
                }
            }
        }

        return $tree;
    }

    /**
     * 构建菜单节点
     *
     * @param array $menu 菜单数据
     * @return array
     */
    protected function buildMenuNode($menu)
    {
        $node = [
            'name' => $menu['name'],
            'code' => $menu['code'],
            'type' => $menu['type'],
            'path' => $menu['path'] ?? null,
            'icon' => $menu['icon'] ?? null,
            'component' => $menu['component'] ?? null,
            'redirect' => $menu['redirect'] ?? null,
            'sort' => $menu['sort'] ?? 0,
            'status' => $menu['status'] ?? 1,
            'is_show' => $menu['is_show'] ?? 1,
            'affix_tab' => $menu['affix_tab'] ?? 0,
            'no_basic_layout' => $menu['no_basic_layout'] ?? 0,
            'remark' => $menu['remark'] ?? null,
        ];

        // 递归处理子菜单
        if (isset($menu['children']) && !empty($menu['children'])) {
            $node['children'] = [];
            foreach ($menu['children'] as $child) {
                $node['children'][] = $this->buildMenuNode($child);
            }
        }

        // 查找菜单下的路由
        $routeChildren = $this->findRouteChildren($menu['code']);
        if (!empty($routeChildren)) {
            if (!isset($node['children'])) {
                $node['children'] = [];
            }
            $node['children'] = array_merge($node['children'], $routeChildren);
        }

        return $node;
    }

    /**
     * 查找菜单下的路由
     *
     * @param string $menuCode 菜单 code
     * @return array
     */
    protected function findRouteChildren($menuCode)
    {
        $children = [];

        foreach ($this->routeData as $code => $route) {
            $parentCode = $route['parent_code'] ?? '';
            if ($parentCode === $menuCode) {
                $children[] = [
                    'name' => $route['name'],
                    'code' => $route['code'],
                    'type' => $route['type'],
                    'path' => $route['path'] ?? null,
                    'icon' => $route['icon'] ?? null,
                    'component' => $route['component'] ?? null,
                    'redirect' => $route['redirect'] ?? null,
                    'sort' => $route['sort'] ?? 0,
                    'status' => $route['status'] ?? 1,
                    'is_show' => $route['is_show'] ?? 1,
                    'affix_tab' => $route['affix_tab'] ?? 0,
                    'no_basic_layout' => $route['no_basic_layout'] ?? 0,
                    'remark' => $route['remark'] ?? null,
                ];
            }
        }

        return $children;
    }

    /**
     * 显示菜单树
     *
     * @param Output $output
     */
    protected function showMenuTree($output)
    {
        $tree = $this->buildMenuTree();

        $output->writeln('');
        $output->writeln('<info>=== 路由树结构 ===</info>');
        $output->writeln('');

        $this->printTree($tree, 0, $output);
    }

    /**
     * 打印树结构
     *
     * @param array $tree 树数据
     * @param int $level 层级
     * @param Output $output
     */
    protected function printTree($tree, $level, $output)
    {
        foreach ($tree as $node) {
            $indent = str_repeat('  ', $level);
            $typeName = $this->getTypeName($node['type']);
            $prefix = $node['type'] === self::TYPE_MENU ? '📁 ' : '🔗 ';

            $output->writeln("{$indent}{$prefix}[{$typeName}] {$node['name']} ({$node['code']})");

            if (isset($node['children']) && !empty($node['children'])) {
                $this->printTree($node['children'], $level + 1, $output);
            }
        }
    }
}
