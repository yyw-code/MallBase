<?php

use app\client\middleware\JwtAuth;
use think\facade\Route;

// 前台用户认证相关（无需登录）
Route::group('client/user/auth', function () {
    Route::post('register', 'register');
    Route::post('login', 'login');
})->prefix('user/UserController/');

// 当前用户操作
Route::group('client/user/my', function () {
    Route::get('info', 'getMyInfo');
    Route::put('info', 'updateMyInfo');
    Route::put('password', 'updateMyPassword');
    Route::post('logout', 'logout');
})->prefix('user/UserController/')->middleware([JwtAuth::class]);
