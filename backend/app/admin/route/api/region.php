<?php

use app\middleware\admin\CheckPermission;
use app\middleware\admin\JwtAuth;
use app\model\auth\Permission;
use think\facade\Route;

Route::group('region', function () {
    Route::get('list', 'list')->name('SystemRegionList')->option(['_alias' => '地区列表', '_desc' => '获取地区列表', '_auth' => true]);
    Route::get('children', 'children')->name('SystemRegionChildren')->option(['_alias' => '子级地区', '_desc' => '获取子级地区', '_auth' => true]);
    Route::get('path/:id', 'path')->name('SystemRegionPath')->option(['_alias' => '地区路径', '_desc' => '获取地区路径', '_auth' => true]);
    Route::get('info/:id', 'info')->name('SystemRegionInfo')->option(['_alias' => '地区详情', '_desc' => '获取地区详情', '_auth' => true]);
    Route::post('create', 'create')->name('SystemRegionCreate')->option(['_alias' => '新增地区', '_desc' => '新增地区', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::put('update/:id', 'update')->name('SystemRegionUpdate')->option(['_alias' => '编辑地区', '_desc' => '编辑地区', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::put('updateStatus/:id', 'updateStatus')->name('SystemRegionUpdateStatus')->option(['_alias' => '地区状态', '_desc' => '更新地区状态', '_auth' => true]);
    Route::delete('delete/:id', 'delete')->name('SystemRegionDelete')->option(['_alias' => '删除地区', '_desc' => '删除地区', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
})->prefix('region.RegionController/')
    ->middleware([JwtAuth::class, CheckPermission::class])
    ->option([
        '_group_name' => '地区管理',
        '_group_code' => 'SystemRegion',
        '_group_name_desc' => '中国省市区街道地区库管理',
        '_parent' => 'SystemManagement',
        '_icon' => 'lucide:map-pinned',
        '_path' => '/settings/region',
        '_auth' => true,
        '_component' => '/settings/region/index',
    ]);
