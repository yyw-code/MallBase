<?php

use app\admin\middleware\CheckPermission;
use app\admin\middleware\JwtAuth;
use app\admin\model\auth\Permission;
use think\facade\Route;

// 商品分类管理
Route::group('goods/category', function () {
    Route::get('list', 'list')->name('SystemGoodsCategoryList')->option(['_alias' => '分类列表', '_desc' => '获取商品分类列表', '_auth' => true]);
    Route::get('info/:id', 'info')->name('SystemGoodsCategoryInfo')->option(['_alias' => '分类详情', '_desc' => '获取分类详情', '_auth' => true]);
    Route::get('all', 'getAllCategories')->name('SystemGoodsCategoryAll')->option(['_alias' => '全部分类', '_desc' => '获取所有启用分类（树形）', '_auth' => true]);
    Route::post('create', 'create')->name('SystemGoodsCategoryCreate')->option(['_alias' => '创建分类', '_desc' => '创建商品分类', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::put('update/:id', 'update')->name('SystemGoodsCategoryUpdate')->option(['_alias' => '更新分类', '_desc' => '更新商品分类', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::delete('delete/:id', 'delete')->name('SystemGoodsCategoryDelete')->option(['_alias' => '删除分类', '_desc' => '删除商品分类', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::put('updateStatus/:id', 'updateStatus')->name('SystemGoodsCategoryUpdateStatus')->option(['_alias' => '分类状态', '_desc' => '更新分类状态', '_auth' => true]);
})->prefix('goods.GoodsCategoryController/')
    ->middleware([JwtAuth::class, CheckPermission::class])
    ->option([
        '_group_name' => '商品分类',
        '_group_code' => 'SystemGoodsCategory',
        '_group_name_desc' => '商品分类管理模块',
        '_parent' => 'SystemGoodsManagement',
        '_icon' => 'lucide:folder-tree',
        '_path' => '/goods/category',
        '_auth' => true,
        '_component' => '/goods/category/index',
    ]);

// 商品品牌管理
Route::group('goods/brand', function () {
    Route::get('list', 'list')->name('SystemGoodsBrandList')->option(['_alias' => '品牌列表', '_desc' => '获取品牌列表', '_auth' => true]);
    Route::get('info/:id', 'info')->name('SystemGoodsBrandInfo')->option(['_alias' => '品牌详情', '_desc' => '获取品牌详情', '_auth' => true]);
    Route::get('all', 'getAllBrands')->name('SystemGoodsBrandAll')->option(['_alias' => '全部品牌', '_desc' => '获取所有启用品牌', '_auth' => true]);
    Route::post('create', 'create')->name('SystemGoodsBrandCreate')->option(['_alias' => '创建品牌', '_desc' => '创建品牌', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::put('update/:id', 'update')->name('SystemGoodsBrandUpdate')->option(['_alias' => '更新品牌', '_desc' => '更新品牌', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::delete('delete/:id', 'delete')->name('SystemGoodsBrandDelete')->option(['_alias' => '删除品牌', '_desc' => '删除品牌', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::put('updateStatus/:id', 'updateStatus')->name('SystemGoodsBrandUpdateStatus')->option(['_alias' => '品牌状态', '_desc' => '更新品牌状态', '_auth' => true]);
})->prefix('goods.GoodsBrandController/')
    ->middleware([JwtAuth::class, CheckPermission::class])
    ->option([
        '_group_name' => '商品品牌',
        '_group_code' => 'SystemGoodsBrand',
        '_group_name_desc' => '商品品牌管理模块',
        '_parent' => 'SystemGoodsManagement',
        '_icon' => 'lucide:award',
        '_path' => '/goods/brand',
        '_auth' => true,
        '_component' => '/goods/brand/index',
    ]);

// 商品规格管理
Route::group('goods/spec', function () {
    Route::get('list', 'list')->name('SystemGoodsSpecList')->option(['_alias' => '规格列表', '_desc' => '获取规格列表', '_auth' => true]);
    Route::get('info/:id', 'info')->name('SystemGoodsSpecInfo')->option(['_alias' => '规格详情', '_desc' => '获取规格详情', '_auth' => true]);
    Route::get('all', 'getAllSpecs')->name('SystemGoodsSpecAll')->option(['_alias' => '全部规格', '_desc' => '获取所有启用规格（含规格值）', '_auth' => true]);
    Route::post('create', 'create')->name('SystemGoodsSpecCreate')->option(['_alias' => '创建规格', '_desc' => '创建规格', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::put('update/:id', 'update')->name('SystemGoodsSpecUpdate')->option(['_alias' => '更新规格', '_desc' => '更新规格', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::delete('delete/:id', 'delete')->name('SystemGoodsSpecDelete')->option(['_alias' => '删除规格', '_desc' => '删除规格', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::put('updateStatus/:id', 'updateStatus')->name('SystemGoodsSpecUpdateStatus')->option(['_alias' => '规格状态', '_desc' => '更新规格状态', '_auth' => true]);
    Route::post('createSpecValue', 'createSpecValue')->name('SystemGoodsSpecValueCreate')->option(['_alias' => '添加规格值', '_desc' => '添加规格值', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::post('batchCreateSpecValues', 'batchCreateSpecValues')->name('SystemGoodsSpecValueBatchCreate')->option(['_alias' => '批量添加规格值', '_desc' => '批量添加规格值', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::delete('deleteSpecValue/:id', 'deleteSpecValue')->name('SystemGoodsSpecValueDelete')->option(['_alias' => '删除规格值', '_desc' => '删除规格值', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
})->prefix('goods.GoodsSpecController/')
    ->middleware([JwtAuth::class, CheckPermission::class])
    ->option([
        '_group_name' => '商品规格',
        '_group_code' => 'SystemGoodsSpec',
        '_group_name_desc' => '商品规格管理模块',
        '_parent' => 'SystemGoodsManagement',
        '_icon' => 'lucide:sliders-horizontal',
        '_path' => '/goods/spec',
        '_auth' => true,
        '_component' => '/goods/spec/index',
    ]);

// 商品规格模板管理
Route::group('goods/spec-template', function () {
    Route::get('list', 'list')->name('SystemGoodsSpecTemplateList')->option(['_alias' => '模板列表', '_desc' => '获取规格模板列表', '_auth' => true]);
    Route::get('info/:id', 'info')->name('SystemGoodsSpecTemplateInfo')->option(['_alias' => '模板详情', '_desc' => '获取规格模板详情', '_auth' => true]);
    Route::get('all', 'all')->name('SystemGoodsSpecTemplateAll')->option(['_alias' => '全部模板', '_desc' => '获取所有启用规格模板', '_auth' => true]);
    Route::post('create', 'create')->name('SystemGoodsSpecTemplateCreate')->option(['_alias' => '创建模板', '_desc' => '创建规格模板', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::put('update/:id', 'update')->name('SystemGoodsSpecTemplateUpdate')->option(['_alias' => '更新模板', '_desc' => '更新规格模板', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::delete('delete/:id', 'delete')->name('SystemGoodsSpecTemplateDelete')->option(['_alias' => '删除模板', '_desc' => '删除规格模板', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::put('updateStatus/:id', 'updateStatus')->name('SystemGoodsSpecTemplateUpdateStatus')->option(['_alias' => '模板状态', '_desc' => '更新规格模板状态', '_auth' => true]);
})->prefix('goods.GoodsSpecTemplateController/')
    ->middleware([JwtAuth::class, CheckPermission::class])
    ->option([
        '_group_name' => '规格模板',
        '_group_code' => 'SystemGoodsSpecTemplate',
        '_group_name_desc' => '商品规格模板管理模块',
        '_parent' => 'SystemGoodsManagement',
        '_icon' => 'lucide:layout-template',
        '_path' => '/goods/spec-template',
        '_auth' => true,
        '_component' => '/goods/spec-template/index',
    ]);

// 商品管理
Route::group('goods/list', function () {
    Route::get('list', 'list')->name('SystemGoodsList')->option(['_alias' => '商品列表', '_desc' => '获取商品列表', '_auth' => true]);
    Route::get('info/:id', 'info')->name('SystemGoodsInfo')->option(['_alias' => '商品详情', '_desc' => '获取商品详情', '_auth' => true]);
    Route::post('create', 'create')->name('SystemGoodsCreate')->option(['_alias' => '创建商品', '_desc' => '创建商品', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::put('update/:id', 'update')->name('SystemGoodsUpdate')->option(['_alias' => '更新商品', '_desc' => '更新商品', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::delete('delete/:id', 'delete')->name('SystemGoodsDelete')->option(['_alias' => '删除商品', '_desc' => '删除商品', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::put('updateStatus/:id', 'updateStatus')->name('SystemGoodsUpdateStatus')->option(['_alias' => '商品状态', '_desc' => '更新商品状态', '_auth' => true]);
    Route::put('updateOnSale/:id', 'updateOnSale')->name('SystemGoodsUpdateOnSale')->option(['_alias' => '上下架', '_desc' => '商品上下架', '_auth' => true]);
})->prefix('goods.GoodsController/')
    ->middleware([JwtAuth::class, CheckPermission::class])
    ->option([
        '_group_name' => '商品管理',
        '_group_code' => 'SystemGoods',
        '_group_name_desc' => '商品管理模块',
        '_parent' => 'SystemGoodsManagement',
        '_icon' => 'lucide:package',
        '_path' => '/goods',
        '_auth' => true,
        '_component' => '/goods/goods/index',
    ]);

// 商品标签管理
Route::group('goods/tag', function () {
    Route::get('list', 'list')->name('SystemGoodsTagList')->option(['_alias' => '标签列表', '_desc' => '获取商品标签列表', '_auth' => true]);
    Route::get('info/:id', 'info')->name('SystemGoodsTagInfo')->option(['_alias' => '标签详情', '_desc' => '获取标签详情', '_auth' => true]);
    Route::get('all', 'getAllTags')->name('SystemGoodsTagAll')->option(['_alias' => '全部标签', '_desc' => '获取所有启用标签', '_auth' => true]);
    Route::post('create', 'create')->name('SystemGoodsTagCreate')->option(['_alias' => '创建标签', '_desc' => '创建商品标签', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::put('update/:id', 'update')->name('SystemGoodsTagUpdate')->option(['_alias' => '更新标签', '_desc' => '更新商品标签', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::delete('delete/:id', 'delete')->name('SystemGoodsTagDelete')->option(['_alias' => '删除标签', '_desc' => '删除商品标签', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::put('updateStatus/:id', 'updateStatus')->name('SystemGoodsTagUpdateStatus')->option(['_alias' => '标签状态', '_desc' => '更新标签状态', '_auth' => true]);
})->prefix('goods.GoodsTagController/')
    ->middleware([JwtAuth::class, CheckPermission::class])
    ->option([
        '_group_name' => '商品标签',
        '_group_code' => 'SystemGoodsTag',
        '_group_name_desc' => '商品标签管理模块',
        '_parent' => 'SystemGoodsManagement',
        '_icon' => 'lucide:tags',
        '_path' => '/goods/tag',
        '_auth' => true,
        '_component' => '/goods/tag/index',
    ]);

// 商品评论管理
Route::group('goods/comment', function () {
    Route::get('list', 'list')->name('SystemGoodsCommentList')->option(['_alias' => '评论列表', '_desc' => '获取商品评论列表', '_auth' => true]);
    Route::get('info/:id', 'info')->name('SystemGoodsCommentInfo')->option(['_alias' => '评论详情', '_desc' => '获取评论详情', '_auth' => true]);
    Route::post('reply/:id', 'reply')->name('SystemGoodsCommentReply')->option(['_alias' => '回复评论', '_desc' => '商家回复评论', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::put('updateStatus/:id', 'updateStatus')->name('SystemGoodsCommentUpdateStatus')->option(['_alias' => '评论状态', '_desc' => '更新评论状态', '_auth' => true]);
    Route::delete('delete/:id', 'delete')->name('SystemGoodsCommentDelete')->option(['_alias' => '删除评论', '_desc' => '删除评论', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
})->prefix('goods.GoodsCommentController/')
    ->middleware([JwtAuth::class, CheckPermission::class])
    ->option([
        '_group_name' => '商品评论',
        '_group_code' => 'SystemGoodsComment',
        '_group_name_desc' => '商品评论管理模块',
        '_parent' => 'SystemGoodsManagement',
        '_icon' => 'lucide:message-square',
        '_path' => '/goods/comment',
        '_auth' => true,
        '_component' => '/goods/comment/index',
    ]);
