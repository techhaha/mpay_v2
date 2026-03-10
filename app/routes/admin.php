<?php

/**
 * 管理后台路由定义
 *
 * 接口前缀：/adminapi
 * 跨域中间件：Cors
 */

use Webman\Route;
use app\http\admin\controller\AuthController;
use app\http\admin\controller\AdminController;
use app\http\admin\controller\MenuController;
use app\http\admin\controller\SystemController;
use app\http\admin\controller\ChannelController;
use app\http\admin\controller\PluginController;
use app\common\middleware\Cors;
use app\http\admin\middleware\AuthMiddleware;

Route::group('/adminapi', function () {
    // 认证相关（无需JWT验证）
    Route::get('/captcha', [AuthController::class, 'captcha'])->name('captcha')->setParams(['real_name' => '验证码']);
    Route::post('/login', [AuthController::class, 'login'])->name('login')->setParams(['real_name' => '登录']);

    // 需要认证的路由组
    Route::group('', function () {
        // 用户相关（需要JWT验证）
        Route::get('/user/getUserInfo', [AdminController::class, 'getUserInfo'])->name('getUserInfo')->setParams(['real_name' => '获取管理员信息']);
        
        // 菜单相关（需要JWT验证）
        Route::get('/menu/getRouters', [MenuController::class, 'getRouters'])->name('getRouters')->setParams(['real_name' => '获取菜单']);
        
        // 系统相关（需要JWT验证）
        Route::get('/system/getDict[/{code}]', [SystemController::class, 'getDict'])->name('getDict')->setParams(['real_name' => '获取字典']);
        
        // 系统配置相关（需要JWT验证）
        Route::get('/system/base-config/tabs', [SystemController::class, 'getTabsConfig'])->name('getTabsConfig')->setParams(['real_name' => '获取系统配置tabs']);
        Route::get('/system/base-config/form/{tabKey}', [SystemController::class, 'getFormConfig'])->name('getFormConfig')->setParams(['real_name' => '获取系统配置form']);
        Route::post('/system/base-config/submit/{tabKey}', [SystemController::class, 'submitConfig'])->name('submitConfig')->setParams(['real_name' => '提交系统配置']);
        
        // 通道管理相关（需要JWT验证）
        Route::get('/channel/list', [ChannelController::class, 'list'])->name('list')->setParams(['real_name' => '获取通道列表']);
        Route::get('/channel/detail', [ChannelController::class, 'detail'])->name('detail')->setParams(['real_name' => '获取通道详情']);
        Route::post('/channel/save', [ChannelController::class, 'save'])->name('save')->setParams(['real_name' => '保存通道']);
        
        // 插件管理相关（需要JWT验证）
        Route::get('/channel/plugins', [PluginController::class, 'plugins'])->name('plugins')->setParams(['real_name' => '获取插件列表']);
        Route::get('/channel/plugin/config-schema', [PluginController::class, 'configSchema'])->name('configSchema')->setParams(['real_name' => '获取插件配置schema']);
        Route::get('/channel/plugin/products', [PluginController::class, 'products'])->name('products')->setParams(['real_name' => '获取插件产品列表']);
    })->middleware([AuthMiddleware::class]);
})->middleware([Cors::class]);