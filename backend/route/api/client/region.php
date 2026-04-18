<?php

use think\facade\Route;

Route::group('region', function () {
    Route::get('children', 'children');
    Route::get('path/:id', 'path');
})->prefix('client.region.RegionController/');
