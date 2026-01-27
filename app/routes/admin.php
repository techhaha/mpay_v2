<?php

/**
 * 管理后台路由定义
 */

use Webman\Route;
use app\http\admin\controller\AuthController;

Route::group('/admin', function () {
    // 登录相关
    Route::post('/mock/login', [AuthController::class, 'login']);
    Route::get('/mock/user/getUserInfo', [AuthController::class, 'getUserInfo']);
});
