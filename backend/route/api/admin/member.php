<?php

use app\middleware\admin\CheckPermission;
use app\middleware\admin\JwtAuth;
use app\model\auth\Permission;
use think\facade\Route;

Route::group('member/level', function () {
    Route::get('list', 'list')->name('SystemMemberLevelList')->option(['_alias' => '等级列表', '_desc' => '获取会员等级列表', '_auth' => true, '_type' => Permission::TYPE_MENU]);
    Route::get('info/:id', 'info')->name('SystemMemberLevelInfo')->option(['_alias' => '等级详情', '_desc' => '获取会员等级详情', '_auth' => true, '_type' => Permission::TYPE_MENU]);
    Route::post('create', 'create')->name('SystemMemberLevelCreate')->option(['_alias' => '新增等级', '_desc' => '新增会员等级', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::put('update/:id', 'update')->name('SystemMemberLevelUpdate')->option(['_alias' => '编辑等级', '_desc' => '编辑会员等级', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::delete('delete/:id', 'delete')->name('SystemMemberLevelDelete')->option(['_alias' => '删除等级', '_desc' => '删除会员等级', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::put('updateStatus/:id', 'updateStatus')->name('SystemMemberLevelUpdateStatus')->option(['_alias' => '等级状态', '_desc' => '更新会员等级状态', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
})->prefix('admin.marketing.MemberLevelController/')
    ->middleware([JwtAuth::class, CheckPermission::class])
    ->option([
        '_group_name'      => '会员等级',
        '_group_code'      => 'SystemMemberLevel',
        '_group_name_desc' => '会员等级与等级折扣配置',
        '_parent'          => 'SystemMemberManagement',
        '_icon'            => 'lucide:badge-check',
        '_path'            => '/member/level',
        '_auth'            => true,
        '_component'       => '/member/level/index',
        '_sort'            => 20,
    ]);
