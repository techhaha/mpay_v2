<?php

use Webman\Route;
use app\common\middleware\Cors;
use app\http\api\controller\cashier\CashierController;
use app\http\api\controller\epay\EpayV1Controller;
use app\http\api\controller\epay\EpayV2Controller;

$serveCashierApp = static function () {
    $indexPath = public_path('cashier/index.html');
    if (!is_file($indexPath)) {
        return response('Cashier page not found', 404);
    }

    return response(file_get_contents($indexPath), 200, [
        'Content-Type' => 'text/html; charset=utf-8',
    ]);
};

// ePay V1 旧版接口
Route::group('', function () {
    Route::any('/submit.php', [EpayV1Controller::class, 'submit'])->name('epayV1Submit')->setParams(['real_name' => 'ePay V1 页面跳转支付']);
    Route::post('/mapi.php', [EpayV1Controller::class, 'mapi'])->name('epayV1Mapi')->setParams(['real_name' => 'ePay V1 接口支付']);
    Route::any('/api.php', [EpayV1Controller::class, 'api'])->name('epayV1Api')->setParams(['real_name' => 'ePay V1 标准 API']);
})->middleware([Cors::class]);

// 收银台路由
Route::group('/api/cashier', function () {
    Route::get('/context', [CashierController::class, 'context'])->name('cashierContext')->setParams(['real_name' => '收银台上下文']);
    Route::post('/confirm', [CashierController::class, 'confirm'])->name('cashierConfirm')->setParams(['real_name' => '收银台确认支付']);
    Route::get('/pay-order', [CashierController::class, 'payOrder'])->name('cashierPayOrder')->setParams(['real_name' => '收银台支付单详情']);
})->middleware([Cors::class]);

Route::group('/cashier', function () use ($serveCashierApp) {
    Route::get('', $serveCashierApp)->name('cashierIndex')->setParams(['real_name' => '收银台首页']);
    Route::any('/{bizNo:.+}', $serveCashierApp)->name('cashierDetail')->setParams(['real_name' => '收银台详情页']);
});

Route::group('/payment', function () use ($serveCashierApp) {
    Route::get('', $serveCashierApp)->name('paymentIndex')->setParams(['real_name' => '支付页首页']);
    Route::any('/{path:.+}', $serveCashierApp)->name('paymentDetail')->setParams(['real_name' => '支付页详情']);
});

// ePay V2 新版接口
Route::group('/api', function () {
    // 支付模块
    Route::group('/pay', function () {
        // 文档约定是 POST，同时兼容旧版 SDK `getPayLink()` 生成的 GET 请求。
        Route::any('/submit', [EpayV2Controller::class, 'submit'])->name('epayV2PaySubmit')->setParams(['real_name' => 'ePay V2 页面跳转支付']);
        Route::post('/create', [EpayV2Controller::class, 'create'])->name('epayV2PayCreate')->setParams(['real_name' => 'ePay V2 创建订单']);
        Route::post('/query', [EpayV2Controller::class, 'query'])->name('epayV2PayQuery')->setParams(['real_name' => 'ePay V2 查询订单']);
        Route::post('/refund', [EpayV2Controller::class, 'refund'])->name('epayV2PayRefund')->setParams(['real_name' => 'ePay V2 退款']);
        Route::post('/refundquery', [EpayV2Controller::class, 'refundQuery'])->name('epayV2PayRefundQuery')->setParams(['real_name' => 'ePay V2 退款查询']);
        Route::post('/close', [EpayV2Controller::class, 'close'])->name('epayV2PayClose')->setParams(['real_name' => 'ePay V2 关闭订单']);
        Route::any('/{payNo}/callback', [EpayV2Controller::class, 'callback'])->name('epayPayCallback')->setParams(['real_name' => '支付渠道回调']);
    });

    // 商户模块
    Route::group('/merchant', function () {
        Route::post('/info', [EpayV2Controller::class, 'merchantInfo'])->name('epayV2MerchantInfo')->setParams(['real_name' => 'ePay V2 商户信息']);
        Route::post('/orders', [EpayV2Controller::class, 'merchantOrders'])->name('epayV2MerchantOrders')->setParams(['real_name' => 'ePay V2 商户订单']);
    });

    // 转账模块
    Route::group('/transfer', function () {
        Route::post('/submit', [EpayV2Controller::class, 'transferSubmit'])->name('epayV2TransferSubmit')->setParams(['real_name' => 'ePay V2 转账提交']);
        Route::post('/query', [EpayV2Controller::class, 'transferQuery'])->name('epayV2TransferQuery')->setParams(['real_name' => 'ePay V2 转账查询']);
        Route::post('/balance', [EpayV2Controller::class, 'transferBalance'])->name('epayV2TransferBalance')->setParams(['real_name' => 'ePay V2 转账余额']);
    });
})->middleware([Cors::class]);
