<?php

use app\middleware\admin\CheckPermission;
use app\middleware\admin\JwtAuth;
use app\model\auth\Permission;
use think\facade\Route;

Route::group('content/article-category', function () {
    Route::get('list', 'list')->name('SystemArticleCategoryList')->option(['_alias' => '分类列表', '_desc' => '获取文章分类列表', '_auth' => true, '_type' => Permission::TYPE_MENU]);
    Route::get('all', 'all')->name('SystemArticleCategoryAll')->option(['_alias' => '全部分类', '_desc' => '获取全部启用文章分类', '_auth' => true, '_type' => Permission::TYPE_MENU]);
    Route::get('info/:id', 'info')->name('SystemArticleCategoryInfo')->option(['_alias' => '分类详情', '_desc' => '获取文章分类详情', '_auth' => true, '_type' => Permission::TYPE_MENU]);
    Route::post('create', 'create')->name('SystemArticleCategoryCreate')->option(['_alias' => '创建分类', '_desc' => '创建文章分类', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::put('update/:id', 'update')->name('SystemArticleCategoryUpdate')->option(['_alias' => '更新分类', '_desc' => '更新文章分类', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::delete('delete/:id', 'delete')->name('SystemArticleCategoryDelete')->option(['_alias' => '删除分类', '_desc' => '删除文章分类', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::put('updateStatus/:id', 'updateStatus')->name('SystemArticleCategoryUpdateStatus')->option(['_alias' => '分类状态', '_desc' => '更新文章分类状态', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
})->prefix('admin.content.ArticleCategoryController/')
    ->middleware([JwtAuth::class, CheckPermission::class])
    ->option([
        '_group_name' => '文章分类',
        '_group_code' => 'SystemArticleCategory',
        '_group_name_desc' => '文章分类管理',
        '_parent' => 'SystemContentManagement',
        '_icon' => 'lucide:folder-tree',
        '_path' => '/content/article-category',
        '_auth' => true,
        '_component' => '/content/article-category/index',
        '_sort' => 10,
    ]);

Route::group('content/article', function () {
    Route::get('list', 'list')->name('SystemArticleList')->option(['_alias' => '文章列表', '_desc' => '获取文章列表', '_auth' => true, '_type' => Permission::TYPE_MENU]);
    Route::get('info/:id', 'info')->name('SystemArticleInfo')->option(['_alias' => '文章详情', '_desc' => '获取文章详情', '_auth' => true, '_type' => Permission::TYPE_MENU]);
    Route::get('read-records', 'readRecords')->name('SystemArticleReadRecords')->option(['_alias' => '阅读记录', '_desc' => '查看文章用户阅读记录', '_auth' => true, '_type' => Permission::TYPE_MENU]);
    Route::post('create', 'create')->name('SystemArticleCreate')->option(['_alias' => '创建文章', '_desc' => '创建文章', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::put('update/:id', 'update')->name('SystemArticleUpdate')->option(['_alias' => '更新文章', '_desc' => '更新文章', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::delete('delete/:id', 'delete')->name('SystemArticleDelete')->option(['_alias' => '删除文章', '_desc' => '删除文章', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
    Route::put('updateStatus/:id', 'updateStatus')->name('SystemArticleUpdateStatus')->option(['_alias' => '文章状态', '_desc' => '更新文章状态', '_auth' => true, '_type' => Permission::TYPE_BUTTON]);
})->prefix('admin.content.ArticleController/')
    ->middleware([JwtAuth::class, CheckPermission::class])
    ->option([
        '_group_name' => '文章管理',
        '_group_code' => 'SystemArticle',
        '_group_name_desc' => '文章管理',
        '_parent' => 'SystemContentManagement',
        '_icon' => 'lucide:file-text',
        '_path' => '/content/article',
        '_auth' => true,
        '_component' => '/content/article/index',
        '_sort' => 20,
    ]);
