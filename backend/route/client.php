<?php

use think\facade\Route;

Route::group('client', function () {

    $apiDir = __DIR__ . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'client';
    foreach (glob($apiDir . DIRECTORY_SEPARATOR . '*.php') as $file) {
        require $file;
    }

});
