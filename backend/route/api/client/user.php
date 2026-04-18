<?php

use app\middleware\client\JwtAuth;
use think\facade\Route;

Route::group('user/auth', function () {
    Route::post('register', 'register');
    Route::post('login', 'login');
    Route::post('wechat', 'wechatLogin');
    Route::post('bindMobile', 'bindMobile');
    Route::post('decryptPhone', 'decryptPhoneNumber');
})->prefix('client.user.UserController/');

Route::group('user/my', function () {
    Route::get('info', 'getMyInfo');
    Route::put('info', 'updateMyInfo');
    Route::put('password', 'updateMyPassword');
    Route::post('logout', 'logout');
})->prefix('client.user.UserController/')->middleware([JwtAuth::class]);

Route::group('user/address', function () {
    Route::get('list', 'list');
    Route::get('info/:id', 'info');
    Route::post('create', 'create');
    Route::put('update/:id', 'update');
    Route::delete('delete/:id', 'delete');
    Route::put('setDefault/:id', 'setDefault');
})->prefix('client.user.UserAddressController/')->middleware([JwtAuth::class]);
