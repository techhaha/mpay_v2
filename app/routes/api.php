<?php

/**
 * API 路由定义（易支付兼容 + 通用回调）
 */

use Webman\Route;
use app\http\api\controller\EpayController;
use app\http\api\controller\PayController;

Route::group('', function () {
    // 页面跳转支付
    Route::any('/submit.php', [EpayController::class, 'submit']);

    // API接口支付
    Route::post('/mapi.php', [EpayController::class, 'mapi']);

    // API接口
    Route::get('/api.php', [EpayController::class, 'api']);

    // 第三方支付异步回调（按插件区分）
    Route::any('/notify/{pluginCode}', [PayController::class, 'notify']);
});
