<?php

/**
 * 接口文档配置文件
 * 
 * 此文件用于配置接口文档生成命令的相关参数
 * 
 * @package config
 */

return [
    /**
     * 文档标题
     * 显示在页面左上角和主内容区顶部
     */
    'title' => 'Open-Core API 接口文档',

    /**
     * 文档版本
     * 显示在左侧导航栏头部
     * 建议格式：主版本号.次版本号.修订号（如 1.0.0）
     */
    'version' => '1.0.0',

    /**
     * 基础 URL
     * API 请求的基础地址
     * 格式：协议://域名:端口（如 http://localhost:9501）
     * 注意：生产环境需要修改为实际的服务器地址
     */
    'base_url' => 'http://localhost:9501',

    /**
     * 文档描述
     * 显示在主内容区顶部，用于说明文档的整体介绍
     * 可以包含项目简介、技术栈说明等
     */
    'description' => '基于 Swoole + ThinkPHP 的高性能框架 API 文档',

    /**
     * 分组配置
     * 
     * 此参数用于控制接口文档的分组展示方式
     * 
     * groups 参数说明：
     * - groups 不是在此配置文件中手动定义的
     * - groups 是由 Docs 命令自动从路由文件中读取并生成的
     * - 命令会扫描所有路由，提取 _alias 等选项，按控制器自动分组
     * 
     * 分组生成规则：
     * 1. 如果路由分组使用了 ->option(['_alias' => '分组名称'])，则使用该名称作为分组名
     * 2. 如果没有配置分组别名，则使用控制器名称（去掉命名空间）作为分组名
     * 3. 每个分组下包含该控制器的所有路由
     * 
     * 使用示例：
     * 
     * // 方式1：使用分组别名（推荐）
     * Route::group('user', function () {
     *     Route::get('list', 'list')->option(['_alias' => '获取用户列表']);
     *     Route::get('detail', 'detail')->option(['_alias' => '获取用户详情']);
     * })->prefix('User')->option(['_alias' => '用户管理']);
     * 
     * // 方式2：不使用分组别名（使用控制器名）
     * Route::group('user', function () {
     *     Route::get('list', 'list')->option(['_alias' => '获取用户列表']);
     *     Route::get('detail', 'detail')->option(['_alias' => '获取用户详情']);
     * })->prefix('User');
     * // 会生成名为 "User" 的分组
     * 
     * groups 数据结构（自动生成）：
     * [
     *     '分组名称' => [
     *         'name' => '分组名称',
     *         'routes' => [
     *             [
     *                 'method' => 'GET',
     *                 'path' => '/user/list',
     *                 'alias' => '获取用户列表',
     *                 'description' => '获取所有用户列表',
     *                 'controller' => 'User',
     *                 'action' => 'list',
     *                 'params' => [...],
     *                 'response' => [...],
     *             ],
     *             ...
     *         ],
     *     ],
     *     ...
     * ]
     * 
     * 注意事项：
     * - groups 参数不需要在此配置文件中手动设置
     * - 只需在路由文件中正确配置 _alias 选项即可
     * - 只有配置了 _alias 的路由才会被包含在文档中
     */
];
