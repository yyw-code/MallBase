<?php

use app\middleware\admin\CheckPermission;
use app\middleware\admin\JwtAuth;
use app\model\auth\Permission;
use think\facade\Route;

Route::post('upload/asset/upload', 'admin.UploadController/single')
    ->name('SystemUploadAssetUpload')
    ->option(['_alias' => '上传素材', '_desc' => '上传文件并创建素材', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);

Route::group('upload/asset', function () {
    Route::get('list', 'list')->name('SystemUploadAssetList')->option(['_alias' => '素材列表', '_desc' => '获取素材列表', '_auth' => true]);
    Route::get('select', 'select')->name('SystemUploadAssetSelect')->option(['_alias' => '选择素材', '_desc' => '素材选择器列表', '_auth' => true]);
    Route::get('info/:id', 'info')->name('SystemUploadAssetInfo')->option(['_alias' => '素材详情', '_desc' => '获取素材详情', '_auth' => true]);
    Route::put('update/:id', 'update')->name('SystemUploadAssetUpdate')->option(['_alias' => '更新素材', '_desc' => '更新素材信息', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::put('move/:id', 'move')->name('SystemUploadAssetMove')->option(['_alias' => '移动素材', '_desc' => '移动素材分类', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::delete('delete/:id', 'delete')->name('SystemUploadAssetDelete')->option(['_alias' => '删除素材', '_desc' => '素材移入回收站', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::put('restore/:id', 'restore')->name('SystemUploadAssetRestore')->option(['_alias' => '恢复素材', '_desc' => '从回收站恢复素材', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::delete('purge/:id', 'purge')->name('SystemUploadAssetPurge')->option(['_alias' => '永久删除', '_desc' => '永久删除素材和存储对象', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::get('usage/:id', 'usage')->name('SystemUploadAssetUsage')->option(['_alias' => '素材引用', '_desc' => '查看素材引用关系', '_auth' => true]);
})->prefix('admin.upload.UploadAssetController/')
    ->middleware([JwtAuth::class, CheckPermission::class])
    ->option([
        '_group_name'      => '素材',
        '_group_code'      => 'SystemUploadAsset',
        '_group_name_desc' => '素材列表管理模块',
        '_parent'          => 'SystemUploadAssetManagement',
        '_icon'            => 'lucide:images',
        '_path'            => '/upload/asset',
        '_auth'            => true,
        '_component'       => '/upload/asset/index',
    ]);

Route::group('upload/asset/category', function () {
    Route::get('list', 'list')->name('SystemUploadAssetCategoryList')->option(['_alias' => '素材分类列表', '_desc' => '获取素材分类列表', '_auth' => true]);
    Route::get('tree', 'tree')->name('SystemUploadAssetCategoryTree')->option(['_alias' => '素材分类树', '_desc' => '获取素材分类树', '_auth' => true]);
    Route::post('create', 'create')->name('SystemUploadAssetCategoryCreate')->option(['_alias' => '创建素材分类', '_desc' => '创建素材分类', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::put('update/:id', 'update')->name('SystemUploadAssetCategoryUpdate')->option(['_alias' => '更新素材分类', '_desc' => '更新素材分类', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::delete('delete/:id', 'delete')->name('SystemUploadAssetCategoryDelete')->option(['_alias' => '删除素材分类', '_desc' => '删除素材分类', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
})->prefix('admin.upload.UploadAssetCategoryController/')
    ->middleware([JwtAuth::class, CheckPermission::class])
    ->option([
        '_group_name'      => '分类',
        '_group_code'      => 'SystemUploadAssetCategory',
        '_group_name_desc' => '素材分类管理模块',
        '_parent'          => 'SystemUploadAssetManagement',
        '_icon'            => 'lucide:folder-tree',
        '_path'            => '/upload/asset/category',
        '_auth'            => true,
        '_component'       => '/upload/asset/category/index',
    ]);

Route::group('upload/asset/migration', function () {
    Route::get('list', 'list')->name('SystemUploadAssetMigrationList')->option(['_alias' => '迁移任务列表', '_desc' => '获取素材迁移任务列表', '_auth' => true]);
    Route::post('create', 'create')->name('SystemUploadAssetMigrationCreate')->option(['_alias' => '创建迁移任务', '_desc' => '创建素材迁移任务', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::post('retry/:id', 'retry')->name('SystemUploadAssetMigrationRetry')->option(['_alias' => '重试迁移任务', '_desc' => '重新执行素材迁移任务', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::delete('cleanup', 'cleanup')->name('SystemUploadAssetMigrationCleanup')->option(['_alias' => '清理迁移任务', '_desc' => '清理已完成迁移任务', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
})->prefix('admin.upload.UploadAssetMigrationController/')
    ->middleware([JwtAuth::class, CheckPermission::class])
    ->option([
        '_group_name'      => '迁移',
        '_group_code'      => 'SystemUploadAssetMigration',
        '_group_name_desc' => '素材迁移任务管理模块',
        '_parent'          => 'SystemUploadAssetManagement',
        '_icon'            => 'lucide:shuffle',
        '_path'            => '/upload/asset/migration',
        '_auth'            => true,
        '_component'       => '/upload/asset/migration/index',
    ]);

Route::group('upload/asset/recycle', function () {
    Route::get('list', 'list')->name('SystemUploadAssetRecycleList')->option(['_alias' => '回收站列表', '_desc' => '获取回收站素材列表', '_auth' => true]);
})->prefix('admin.upload.UploadAssetController/')
    ->middleware([JwtAuth::class, CheckPermission::class])
    ->option([
        '_group_name'      => '回收站',
        '_group_code'      => 'SystemUploadAssetRecycle',
        '_group_name_desc' => '素材回收站管理模块',
        '_parent'          => 'SystemUploadAssetManagement',
        '_icon'            => 'lucide:trash-2',
        '_path'            => '/upload/asset/recycle',
        '_auth'            => true,
        '_component'       => '/upload/asset/index',
    ]);
