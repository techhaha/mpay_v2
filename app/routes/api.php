<?php

/**
 * API 路由定义（易支付接口标准）
 */

use Webman\Route;
use app\http\api\controller\EpayController;

Route::group('', function () {
    // 页面跳转支付
    Route::any('/submit.php', [EpayController::class, 'submit']);

    // API接口支付
    Route::post('/mapi.php', [EpayController::class, 'mapi']);

    // API接口
    Route::get('/api.php', [EpayController::class, 'api']);
});
