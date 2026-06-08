<?php

use app\middleware\client\JwtAuth;
use think\facade\Route;

// 商品(无需登录,匿名可访问)
Route::group('goods', function () {
    Route::get('list', 'list');
    Route::get('info/:id', 'info');
    Route::get('recommend', 'recommend');
})->prefix('client.goods.GoodsController/');

// 商品分类
Route::group('goods/category', function () {
    Route::get('tree', 'tree');
    Route::get('list', 'list');
})->prefix('client.goods.GoodsCategoryController/');

// 商品评论(无需登录,匿名可访问)
Route::group('review', function () {
    Route::get('list', 'list');
})->prefix('client.goods.GoodsCommentController/');

Route::group('review', function () {
    Route::post('create', 'create');
})->prefix('client.goods.GoodsCommentController/')->middleware([JwtAuth::class]);
