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
use app\http\admin\controller\MerchantController;
use app\http\admin\controller\MerchantAppController;
use app\http\admin\controller\PayMethodController;
use app\http\admin\controller\PayPluginController;
use app\http\admin\controller\OrderController;
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

        // 商户管理
        Route::get('/merchant/list', [MerchantController::class, 'list'])->name('merchantList')->setParams(['real_name' => '商户列表']);
        Route::get('/merchant/detail', [MerchantController::class, 'detail'])->name('merchantDetail')->setParams(['real_name' => '商户详情']);
        Route::post('/merchant/save', [MerchantController::class, 'save'])->name('merchantSave')->setParams(['real_name' => '保存商户']);
        Route::post('/merchant/toggle', [MerchantController::class, 'toggle'])->name('merchantToggle')->setParams(['real_name' => '启用禁用商户']);

        // 商户应用管理
        Route::get('/merchant-app/list', [MerchantAppController::class, 'list'])->name('merchantAppList')->setParams(['real_name' => '商户应用列表']);
        Route::get('/merchant-app/detail', [MerchantAppController::class, 'detail'])->name('merchantAppDetail')->setParams(['real_name' => '商户应用详情']);
        Route::post('/merchant-app/save', [MerchantAppController::class, 'save'])->name('merchantAppSave')->setParams(['real_name' => '保存商户应用']);
        Route::post('/merchant-app/reset-secret', [MerchantAppController::class, 'resetSecret'])->name('merchantAppResetSecret')->setParams(['real_name' => '重置应用密钥']);
        Route::post('/merchant-app/toggle', [MerchantAppController::class, 'toggle'])->name('merchantAppToggle')->setParams(['real_name' => '启用禁用商户应用']);

        // 支付方式管理
        Route::get('/pay-method/list', [PayMethodController::class, 'list'])->name('payMethodList')->setParams(['real_name' => '支付方式列表']);
        Route::post('/pay-method/save', [PayMethodController::class, 'save'])->name('payMethodSave')->setParams(['real_name' => '保存支付方式']);
        Route::post('/pay-method/toggle', [PayMethodController::class, 'toggle'])->name('payMethodToggle')->setParams(['real_name' => '启用禁用支付方式']);

        // 插件注册表管理
        Route::get('/pay-plugin/list', [PayPluginController::class, 'list'])->name('payPluginList')->setParams(['real_name' => '支付插件注册表列表']);
        Route::post('/pay-plugin/save', [PayPluginController::class, 'save'])->name('payPluginSave')->setParams(['real_name' => '保存支付插件注册表']);
        Route::post('/pay-plugin/toggle', [PayPluginController::class, 'toggle'])->name('payPluginToggle')->setParams(['real_name' => '启用禁用支付插件']);

        // 订单管理
        Route::get('/order/list', [OrderController::class, 'list'])->name('orderList')->setParams(['real_name' => '订单列表']);
        Route::get('/order/detail', [OrderController::class, 'detail'])->name('orderDetail')->setParams(['real_name' => '订单详情']);
        Route::post('/order/refund', [OrderController::class, 'refund'])->name('orderRefund')->setParams(['real_name' => '订单退款']);
    })->middleware([AuthMiddleware::class]);
})->middleware([Cors::class]);