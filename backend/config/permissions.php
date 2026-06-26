<?php

/**
 * 权限配置文件
 *
 * 此文件用于配置权限的基础数据
 *
 * @package config
 */

/**
 * 路由权限字段说明
 *
 * 在路由配置中，可以使用以下字段来配置权限信息：
 *
 * 1. 基础字段
 *    - _alias          : 权限名称（必填），用于显示在权限列表中
 *    - _desc           : 权限描述/备注（别名），映射到数据库的 remark 字段
 *    - _type           : 权限类型（可选），支持：
 *                        * 'menu' 或 1   - 菜单
 *                        * 'button' 或 2 - 按钮
 *                        * 'api'         - 历史兼容写法，按菜单权限处理
 *    - _parent         : 父级权限 code（可选），用于建立父子关系
 *    - _auth           : 是否需要认证（可选），默认 true
 *
 * 2. 路由组专用字段（用于创建路由组菜单）
 *    - _group_name     : 路由组名称（必填），用于创建路由组菜单的名称
 *    - _group_code     : 路由组代码（必填），用于创建路由组菜单的唯一标识
 *    - _group_name_desc: 路由组描述（可选），映射到数据库的 remark 字段
 *                        示例：_group_name_desc => '管理员管理模块的菜单和操作权限'
 *
 * 3. 菜单专用字段（type=1）
 *    - _path           : 菜单路径（可选），前端路由路径
 *    - _icon           : 菜单图标（可选），使用图标库的图标名称
 *    - _component      : 组件路径（可选），前端组件文件路径
 *    - _redirect       : 重定向路径（可选）
 *    - _sort           : 排序（可选），默认 0
 *    - _status         : 状态（可选），1-启用（默认），0-禁用
 *    - _is_show        : 是否显示（可选），1-显示（默认），0-隐藏
 *    - _affix_tab      : 是否固定标签页（可选），1-固定，0-不固定（默认）
 *    - _no_basic_layout: 是否不使用基础布局（可选），1-不使用，0-使用（默认）
 *
 * 4. 其他字段
 *    - _remark         : 备注信息（可选），映射到数据库的 remark 字段
 *
 * 使用示例：
 * ```php
 * Route::group('auth/admin', function () {
 *     Route::get('list', 'list')->name('SystemAdminList')->option([
 *         '_alias' => '列表',
 *         '_desc' => '管理员列表',
 *         '_type' => 'menu',
 *     ]);
 * })->option([
 *     '_group_name' => '管理员管理',
 *     '_group_code' => 'SystemAdmin',
 *     '_group_name_desc' => '管理员管理模块的菜单和操作权限',
 *     '_parent' => 'SystemPermissionManagement',
 *     '_icon' => 'lucide:users',
 *     '_path' => '/admin',
 *     '_component' => 'system/admin/index',
 * ]);
 * ```
 *
 * 注意事项：
 * 1. type=2（按钮）时，_path _icon、_component、_redirect 字段不会被设置，使用数据库默认值（NULL）
 * 2. _group_code 和 _group_name 用于自动创建路由组菜单，路由组的子路由会自动挂在该菜单下
 * 3. 子路由的 option 优先级高于父级，避免父级 option 覆盖子级 option
 */

return [
    /**
     * 基础菜单
     * 这些菜单会作为权限表的初始数据
     */
    'base_menus' => [
        [
            'name' => '概览',
            'code' => 'Dashboard',
            'type' => 1,
            'path' => '/dashboard',
            'icon' => 'lucide:layout-dashboard',
            'component' => null,
            'redirect' => '/analytics',
            'sort' => 0,
            'status' => 1,
            'is_show' => 1,
            'affix_tab' => 0,
            'no_basic_layout' => 0,
            'remark' => null,
            'children' => [
                [
                    'name' => '分析页',
                    'code' => 'Analytics',
                    'type' => 1,
                    'path' => '/analytics',
                    'icon' => 'lucide:area-chart',
                    'component' => '/dashboard/analytics/index',
                    'redirect' => null,
                    'sort' => 0,
                    'status' => 1,
                    'is_show' => 1,
                    'affix_tab' => 0,
                    'no_basic_layout' => 0,
                    'remark' => null,
                ],
                [
                    'name' => '工作台',
                    'code' => 'Workspace',
                    'type' => 1,
                    'path' => '/workspace',
                    'icon' => 'carbon:workspace',
                    'component' => '/dashboard/workspace/index',
                    'redirect' => null,
                    'sort' => 0,
                    'status' => 1,
                    'is_show' => 1,
                    'affix_tab' => 0,
                    'no_basic_layout' => 0,
                    'remark' => null,
                ]
            ]
        ],
        [
            'name' => '用户管理',
            'code' => 'SystemClientUserManagement',
            'type' => 1,
            'path' => '/client-user-management',
            'icon' => 'lucide:users',
            'component' => null,
            'redirect' => '/user',
            'sort' => 0,
            'status' => 1,
            'is_show' => 1,
            'affix_tab' => 0,
            'no_basic_layout' => 0,
            'remark' => null,
        ],
        [
            'name' => '商品管理',
            'code' => 'SystemGoodsManagement',
            'type' => 1,
            'path' => '/goods-management',
            'icon' => 'lucide:package',
            'component' => null,
            'redirect' => '/goods',
            'sort' => 0,
            'status' => 1,
            'is_show' => 1,
            'affix_tab' => 0,
            'no_basic_layout' => 0,
            'remark' => null,
        ],
        [
            // 隐藏页面路由：供商品列表新增/编辑跳转使用，不在侧边栏显示。
            'name' => '商品编辑',
            'code' => 'SystemGoodsEdit',
            'type' => 1,
            'path' => '/goods/edit',
            'icon' => null,
            'component' => '/goods/goods/goods-edit',
            'redirect' => null,
            'sort' => 0,
            'status' => 1,
            'is_show' => 0,
            'affix_tab' => 0,
            'no_basic_layout' => 0,
            'remark' => '商品新增/编辑页面',
            'parent' => 'SystemGoodsList',
        ],
        [
            'name' => '订单管理',
            'code' => 'SystemOrderManagement',
            'type' => 1,
            'path' => '/order-management',
            'icon' => 'lucide:shopping-cart',
            'component' => null,
            'redirect' => '/order',
            'sort' => 0,
            'status' => 1,
            'is_show' => 1,
            'affix_tab' => 0,
            'no_basic_layout' => 0,
            'remark' => null,
        ],
        [
            'name' => '营销管理',
            'code' => 'SystemMarketingManagement',
            'type' => 1,
            'path' => '/marketing-management',
            'icon' => 'lucide:megaphone',
            'component' => null,
            'redirect' => '/marketing/recharge-package',
            'sort' => 0,
            'status' => 1,
            'is_show' => 1,
            'affix_tab' => 0,
            'no_basic_layout' => 0,
            'remark' => null,
        ],
        [
            'name' => '客户端装修',
            'code' => 'SystemClientDiy',
            'type' => 1,
            'path' => '/client',
            'icon' => 'lucide:paintbrush',
            'component' => null,
            'redirect' => '/client/decorate/home',
            'sort' => 0,
            'status' => 1,
            'is_show' => 1,
            'affix_tab' => 0,
            'no_basic_layout' => 0,
            'remark' => null,
            'children' => [
                [
                    'name' => '客户端配置',
                    'code' => 'SystemClientConfig',
                    'type' => 1,
                    'path' => '/client/config',
                    'icon' => 'lucide:sliders-horizontal',
                    'component' => '/client/config/index',
                    'redirect' => null,
                    'sort' => 5,
                    'status' => 1,
                    'is_show' => 1,
                    'affix_tab' => 0,
                    'no_basic_layout' => 0,
                    'remark' => '客户端基础、商品、搜索与内容配置',
                ],
                [
                    'name' => '装修',
                    'code' => 'SystemClientDecorationManagement',
                    'type' => 1,
                    'path' => '/client/decorate',
                    'icon' => 'lucide:layout-template',
                    'component' => null,
                    'redirect' => '/client/decorate/home',
                    'sort' => 20,
                    'status' => 1,
                    'is_show' => 1,
                    'affix_tab' => 0,
                    'no_basic_layout' => 0,
                    'remark' => null,
                    'children' => [
                        [
                            'name' => '首页装修',
                            'code' => 'SystemClientDecorationHome',
                            'type' => 1,
                            'path' => '/client/decorate/home',
                            'icon' => 'lucide:home',
                            'component' => '/client/decorate/index',
                            'redirect' => null,
                            'sort' => 10,
                            'status' => 1,
                            'is_show' => 1,
                            'affix_tab' => 0,
                            'no_basic_layout' => 0,
                            'remark' => '首页模块、轮播、导航和商品分组装修',
                        ],
                        [
                            'name' => '底部导航',
                            'code' => 'SystemClientDecorationTabbar',
                            'type' => 1,
                            'path' => '/client/decorate/tabbar',
                            'icon' => 'lucide:panel-bottom',
                            'component' => '/client/decorate/index',
                            'redirect' => null,
                            'sort' => 20,
                            'status' => 1,
                            'is_show' => 1,
                            'affix_tab' => 0,
                            'no_basic_layout' => 0,
                            'remark' => '客户端底部导航装修',
                        ],
                        [
                            'name' => '个人中心',
                            'code' => 'SystemClientDecorationProfile',
                            'type' => 1,
                            'path' => '/client/decorate/profile',
                            'icon' => 'lucide:user-round',
                            'component' => '/client/decorate/index',
                            'redirect' => null,
                            'sort' => 30,
                            'status' => 1,
                            'is_show' => 1,
                            'affix_tab' => 0,
                            'no_basic_layout' => 0,
                            'remark' => '个人中心模块和服务入口装修',
                        ],
                    ],
                ],
            ],
        ],
        [
            'name' => '系统维护',
            'code' => 'System',
            'type' => 1,
            'path' => '/system',
            'icon' => 'lucide:wrench',
            'component' => null,
            'redirect' => '/system/permission-management',
            'sort' => 0,
            'status' => 1,
            'is_show' => 1,
            'affix_tab' => 0,
            'no_basic_layout' => 0,
            'remark' => null,
            'children' => [
                [
                    'name' => '权限管理',
                    'code' => 'SystemPermissionManagement',
                    'type' => 1,
                    'path' => '/system/permission-management',
                    'icon' => 'ant-design:lock-filled',
                    'component' => null,
                    'redirect' => '/admin',
                    'sort' => 0,
                    'status' => 1,
                    'is_show' => 1,
                    'affix_tab' => 0,
                    'no_basic_layout' => 0,
                    'remark' => null,
                ],
                [
                    'name' => '设置维护',
                    'code' => 'SystemManagement',
                    'type' => 1,
                    'path' => '/system/setting-management',
                    'icon' => 'lucide:settings',
                    'component' => null,
                    'redirect' => '/setting/group',
                    'sort' => 0,
                    'status' => 1,
                    'is_show' => 1,
                    'affix_tab' => 0,
                    'no_basic_layout' => 0,
                    'remark' => null,
                ],
                [
                    'name' => '素材管理',
                    'code' => 'SystemUploadAssetManagement',
                    'type' => 1,
                    'path' => '/system/upload-asset-management',
                    'icon' => 'lucide:images',
                    'component' => null,
                    'redirect' => '/upload/asset',
                    'sort' => 5,
                    'status' => 1,
                    'is_show' => 1,
                    'affix_tab' => 0,
                    'no_basic_layout' => 0,
                    'remark' => null,
                ],
                [
                    'name' => '短信配置',
                    'code' => 'SmsConfig',
                    'type' => 1,
                    'path' => '/sms',
                    'icon' => 'lucide:message-square',
                    'component' => null,
                    'redirect' => '/sms/config',
                    'sort' => 10,
                    'status' => 1,
                    'is_show' => 1,
                    'affix_tab' => 0,
                    'no_basic_layout' => 0,
                    'remark' => '服务商 / 签名 / 模板 / 场景绑定 / 频控设置',
                ],
                [
                    'name' => '物流管理',
                    'code' => 'SystemLogistics',
                    'type' => 1,
                    'path' => '/logistics',
                    'icon' => 'lucide:truck',
                    'component' => null,
                    'redirect' => '/logistics/platform',
                    'sort' => 20,
                    'status' => 1,
                    'is_show' => 1,
                    'affix_tab' => 0,
                    'no_basic_layout' => 0,
                    'remark' => '物流平台与公司目录',
                ]
            ]
        ],
        [
            'name' => '关于',
            'code' => 'VbenAbout',
            'type' => 1,
            'path' => '/vben-admin/about',
            'icon' => 'lucide:copyright',
            'component' => '/_core/about/index',
            'redirect' => null,
            'sort' => 9999,
            'status' => 1,
            'is_show' => 1,
            'affix_tab' => 0,
            'no_basic_layout' => 0,
            'remark' => null,
        ],
        [
            'name' => '个人中心',
            'code' => 'Profile',
            'type' => 1,
            'path' => '/profile',
            'icon' => null,
            'component' => '/_core/profile/index',
            'redirect' => null,
            'sort' => 0,
            'status' => 1,
            'is_show' => 0,
            'affix_tab' => 0,
            'no_basic_layout' => 0,
            'remark' => null,
        ],
    ],

    /**
     * 应用配置
     */
    'app' => [
        'admin' => [
            'is_folder' => true,
            'path' => 'admin',
            'alias' => '后台管理',
        ],
    ],
];
