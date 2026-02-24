<?php

/**
 * 管理后台路由定义
 *
 * 接口前缀：/adminapi
 * 跨域中间件：Cors
 */

use Webman\Route;
use app\http\admin\controller\AuthController;
use app\http\admin\controller\UserController;
use app\http\admin\controller\MenuController;
use app\http\admin\controller\SystemController;
use app\common\middleware\Cors;
use app\http\admin\middleware\AuthMiddleware;

Route::group('/adminapi', function () {
    // 认证相关（无需JWT验证）
    Route::get('/captcha', [AuthController::class, 'captcha']);
    Route::post('/login', [AuthController::class, 'login']);

    // 需要认证的路由组
    Route::group('', function () {
        // 用户相关（需要JWT验证）
        Route::get('/user/getUserInfo', [UserController::class, 'getUserInfo']);
        
        // 菜单相关（需要JWT验证）
        Route::get('/menu/getRouters', [MenuController::class, 'getRouters']);
        
        // 系统相关（需要JWT验证）
        Route::get('/system/getDict[/{code}]', [SystemController::class, 'getDict']);
        
        // 系统配置相关（需要JWT验证）
        Route::get('/system/base-config/tabs', [SystemController::class, 'getTabsConfig']);
        Route::get('/system/base-config/form/{tabKey}', [SystemController::class, 'getFormConfig']);
        Route::post('/system/base-config/submit/{tabKey}', [SystemController::class, 'submitConfig']);
    })->middleware([AuthMiddleware::class]);
})->middleware([Cors::class]);