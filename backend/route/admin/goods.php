<?php

use app\admin\middleware\CheckPermission;
use app\admin\middleware\JwtAuth;
use app\admin\model\auth\Permission;
use think\facade\Route;

// 商品分类管理
Route::group('goods/category', function () {
    Route::get('list', 'list')->name('GoodsCategoryList')->option(['_alias' => '分类列表', '_desc' => '获取商品分类列表', '_auth' => true]);
    Route::get('info/:id', 'info')->name('GoodsCategoryInfo')->option(['_alias' => '分类详情', '_desc' => '获取分类详情', '_auth' => true]);
    Route::get('all', 'getAllCategories')->name('GoodsCategoryAll')->option(['_alias' => '全部分类', '_desc' => '获取所有启用分类（树形）', '_auth' => true]);
    Route::post('create', 'create')->name('GoodsCategoryCreate')->option(['_alias' => '创建分类', '_desc' => '创建商品分类', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::put('update/:id', 'update')->name('GoodsCategoryUpdate')->option(['_alias' => '更新分类', '_desc' => '更新商品分类', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::delete('delete/:id', 'delete')->name('GoodsCategoryDelete')->option(['_alias' => '删除分类', '_desc' => '删除商品分类', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::put('updateStatus/:id', 'updateStatus')->name('GoodsCategoryUpdateStatus')->option(['_alias' => '分类状态', '_desc' => '更新分类状态', '_auth' => true]);
})->prefix('goods.GoodsCategoryController/')
    ->middleware([JwtAuth::class, CheckPermission::class])
    ->option([
        '_group_name' => '商品分类',
        '_group_code' => 'GoodsCategory',
        '_group_name_desc' => '商品分类管理模块',
        '_parent' => 'SystemGoodsManagement',
        '_icon' => 'lucide:folder-tree',
        '_path' => '/goods/category',
        '_auth' => true,
        '_component' => '/goods/category/index',
    ]);

// 商品品牌管理
Route::group('goods/brand', function () {
    Route::get('list', 'list')->name('GoodsBrandList')->option(['_alias' => '品牌列表', '_desc' => '获取品牌列表', '_auth' => true]);
    Route::get('info/:id', 'info')->name('GoodsBrandInfo')->option(['_alias' => '品牌详情', '_desc' => '获取品牌详情', '_auth' => true]);
    Route::get('all', 'getAllBrands')->name('GoodsBrandAll')->option(['_alias' => '全部品牌', '_desc' => '获取所有启用品牌', '_auth' => true]);
    Route::post('create', 'create')->name('GoodsBrandCreate')->option(['_alias' => '创建品牌', '_desc' => '创建品牌', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::put('update/:id', 'update')->name('GoodsBrandUpdate')->option(['_alias' => '更新品牌', '_desc' => '更新品牌', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::delete('delete/:id', 'delete')->name('GoodsBrandDelete')->option(['_alias' => '删除品牌', '_desc' => '删除品牌', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::put('updateStatus/:id', 'updateStatus')->name('GoodsBrandUpdateStatus')->option(['_alias' => '品牌状态', '_desc' => '更新品牌状态', '_auth' => true]);
})->prefix('goods.GoodsBrandController/')
    ->middleware([JwtAuth::class, CheckPermission::class])
    ->option([
        '_group_name' => '商品品牌',
        '_group_code' => 'GoodsBrand',
        '_group_name_desc' => '商品品牌管理模块',
        '_parent' => 'SystemGoodsManagement',
        '_icon' => 'lucide:award',
        '_path' => '/goods/brand',
        '_auth' => true,
        '_component' => '/goods/brand/index',
    ]);

// 商品规格管理
Route::group('goods/spec', function () {
    Route::get('list', 'list')->name('GoodsSpecList')->option(['_alias' => '规格列表', '_desc' => '获取规格列表', '_auth' => true]);
    Route::get('info/:id', 'info')->name('GoodsSpecInfo')->option(['_alias' => '规格详情', '_desc' => '获取规格详情', '_auth' => true]);
    Route::get('all', 'getAllSpecs')->name('GoodsSpecAll')->option(['_alias' => '全部规格', '_desc' => '获取所有启用规格（含规格值）', '_auth' => true]);
    Route::post('create', 'create')->name('GoodsSpecCreate')->option(['_alias' => '创建规格', '_desc' => '创建规格', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::put('update/:id', 'update')->name('GoodsSpecUpdate')->option(['_alias' => '更新规格', '_desc' => '更新规格', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::delete('delete/:id', 'delete')->name('GoodsSpecDelete')->option(['_alias' => '删除规格', '_desc' => '删除规格', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::put('updateStatus/:id', 'updateStatus')->name('GoodsSpecUpdateStatus')->option(['_alias' => '规格状态', '_desc' => '更新规格状态', '_auth' => true]);
    Route::post('createSpecValue', 'createSpecValue')->name('GoodsSpecValueCreate')->option(['_alias' => '添加规格值', '_desc' => '添加规格值', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::post('batchCreateSpecValues', 'batchCreateSpecValues')->name('GoodsSpecValueBatchCreate')->option(['_alias' => '批量添加规格值', '_desc' => '批量添加规格值', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::delete('deleteSpecValue/:id', 'deleteSpecValue')->name('GoodsSpecValueDelete')->option(['_alias' => '删除规格值', '_desc' => '删除规格值', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
})->prefix('goods.GoodsSpecController/')
    ->middleware([JwtAuth::class, CheckPermission::class])
    ->option([
        '_group_name' => '商品规格',
        '_group_code' => 'GoodsSpec',
        '_group_name_desc' => '商品规格管理模块',
        '_parent' => 'SystemGoodsManagement',
        '_icon' => 'lucide:sliders-horizontal',
        '_path' => '/goods/spec',
        '_auth' => true,
        '_component' => '/goods/spec/index',
    ]);

// 商品管理
Route::group('goods/list', function () {
    Route::get('list', 'list')->name('GoodsList')->option(['_alias' => '商品列表', '_desc' => '获取商品列表', '_auth' => true]);
    Route::get('info/:id', 'info')->name('GoodsInfo')->option(['_alias' => '商品详情', '_desc' => '获取商品详情', '_auth' => true]);
    Route::post('create', 'create')->name('GoodsCreate')->option(['_alias' => '创建商品', '_desc' => '创建商品', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::put('update/:id', 'update')->name('GoodsUpdate')->option(['_alias' => '更新商品', '_desc' => '更新商品', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::delete('delete/:id', 'delete')->name('GoodsDelete')->option(['_alias' => '删除商品', '_desc' => '删除商品', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::put('updateStatus/:id', 'updateStatus')->name('GoodsUpdateStatus')->option(['_alias' => '商品状态', '_desc' => '更新商品状态', '_auth' => true]);
    Route::put('updateOnSale/:id', 'updateOnSale')->name('GoodsUpdateOnSale')->option(['_alias' => '上下架', '_desc' => '商品上下架', '_auth' => true]);
})->prefix('goods.GoodsController/')
    ->middleware([JwtAuth::class, CheckPermission::class])
    ->option([
        '_group_name' => '商品管理',
        '_group_code' => 'GoodsList',
        '_group_name_desc' => '商品管理模块',
        '_parent' => 'SystemGoodsManagement',
        '_icon' => 'lucide:package',
        '_path' => '/goods',
        '_auth' => true,
        '_component' => '/goods/goods/index',
    ]);

// 商品标签管理
Route::group('goods/tag', function () {
    Route::get('list', 'list')->name('GoodsTagList')->option(['_alias' => '标签列表', '_desc' => '获取商品标签列表', '_auth' => true]);
    Route::get('info/:id', 'info')->name('GoodsTagInfo')->option(['_alias' => '标签详情', '_desc' => '获取标签详情', '_auth' => true]);
    Route::get('all', 'getAllTags')->name('GoodsTagAll')->option(['_alias' => '全部标签', '_desc' => '获取所有启用标签', '_auth' => true]);
    Route::post('create', 'create')->name('GoodsTagCreate')->option(['_alias' => '创建标签', '_desc' => '创建商品标签', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::put('update/:id', 'update')->name('GoodsTagUpdate')->option(['_alias' => '更新标签', '_desc' => '更新商品标签', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::delete('delete/:id', 'delete')->name('GoodsTagDelete')->option(['_alias' => '删除标签', '_desc' => '删除商品标签', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::put('updateStatus/:id', 'updateStatus')->name('GoodsTagUpdateStatus')->option(['_alias' => '标签状态', '_desc' => '更新标签状态', '_auth' => true]);
})->prefix('goods.GoodsTagController/')
    ->middleware([JwtAuth::class, CheckPermission::class])
    ->option([
        '_group_name' => '商品标签',
        '_group_code' => 'GoodsTag',
        '_group_name_desc' => '商品标签管理模块',
        '_parent' => 'SystemGoodsManagement',
        '_icon' => 'lucide:tags',
        '_path' => '/goods/tag',
        '_auth' => true,
        '_component' => '/goods/tag/index',
    ]);

// 商品评论管理
Route::group('goods/comment', function () {
    Route::get('list', 'list')->name('GoodsCommentList')->option(['_alias' => '评论列表', '_desc' => '获取商品评论列表', '_auth' => true]);
    Route::get('info/:id', 'info')->name('GoodsCommentInfo')->option(['_alias' => '评论详情', '_desc' => '获取评论详情', '_auth' => true]);
    Route::post('reply/:id', 'reply')->name('GoodsCommentReply')->option(['_alias' => '回复评论', '_desc' => '商家回复评论', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::put('updateStatus/:id', 'updateStatus')->name('GoodsCommentUpdateStatus')->option(['_alias' => '评论状态', '_desc' => '更新评论状态', '_auth' => true]);
    Route::delete('delete/:id', 'delete')->name('GoodsCommentDelete')->option(['_alias' => '删除评论', '_desc' => '删除评论', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
})->prefix('goods.GoodsCommentController/')
    ->middleware([JwtAuth::class, CheckPermission::class])
    ->option([
        '_group_name' => '商品评论',
        '_group_code' => 'GoodsComment',
        '_group_name_desc' => '商品评论管理模块',
        '_parent' => 'SystemGoodsManagement',
        '_icon' => 'lucide:message-square',
        '_path' => '/goods/comment',
        '_auth' => true,
        '_component' => '/goods/comment/index',
    ]);
