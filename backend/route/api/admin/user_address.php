<?php

use app\middleware\admin\CheckPermission;
use app\middleware\admin\JwtAuth;
use app\model\auth\Permission;
use think\facade\Route;

Route::group('user/address', function () {
    Route::get('list', 'list')->name('SystemUserAddressList')->option(['_alias' => '地址列表', '_desc' => '获取用户收货地址列表', '_auth' => true, '_type' => Permission::TYPE_MENU]);
    Route::get('info/:id', 'info')->name('SystemUserAddressInfo')->option(['_alias' => '地址详情', '_desc' => '获取用户收货地址详情', '_auth' => true, '_type' => Permission::TYPE_MENU]);
    Route::post('create', 'create')->name('SystemUserAddressCreate')->option(['_alias' => '新增地址', '_desc' => '新增用户收货地址', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::put('update/:id', 'update')->name('SystemUserAddressUpdate')->option(['_alias' => '编辑地址', '_desc' => '编辑用户收货地址', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::delete('delete/:id', 'delete')->name('SystemUserAddressDelete')->option(['_alias' => '删除地址', '_desc' => '删除用户收货地址', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::put('setDefault/:id', 'setDefault')->name('SystemUserAddressSetDefault')->option(['_alias' => '设为默认', '_desc' => '设置默认地址', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::put('refreshInvalid', 'refreshInvalid')->name('SystemUserAddressRefreshInvalid')->option(['_alias' => '更新失效数据', '_desc' => '按编码重匹配失效收货地址', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
})->prefix('admin.user.UserAddressController/')
    ->middleware([JwtAuth::class, CheckPermission::class])
    ->option([
        '_group_name'      => '用户地址',
        '_group_code'      => 'SystemUserAddress',
        '_group_name_desc' => '用户收货地址管理',
        '_parent'          => 'SystemClientUserManagement',
        '_icon'            => 'lucide:map-pin-house',
        '_path'            => '/user/address',
        '_auth'            => true,
        '_component'       => '/user/address/index',
    ]);
