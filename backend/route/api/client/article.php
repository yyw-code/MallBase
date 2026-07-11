<?php

use app\middleware\client\OptionalJwtAuth;
use think\facade\Route;

Route::group('article/category', function () {
    Route::get('list', 'list');
})->prefix('client.content.ArticleCategoryController/');

Route::group('article', function () {
    Route::get('list', 'list');
    Route::get('info/:id', 'info')->middleware([OptionalJwtAuth::class]);
})->prefix('client.content.ArticleController/');
