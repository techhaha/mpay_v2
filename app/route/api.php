<?php

use Webman\Route;
use app\common\middleware\Cors;
use app\http\api\controller\adapter\EpayController;
use app\http\api\controller\notify\NotifyController;
use app\http\api\controller\trade\PayController;
use app\http\api\controller\trade\RefundController;
use app\http\api\controller\route\RouteController;
use app\http\api\controller\settlement\SettlementController;
use app\http\api\controller\trace\TraceController;

Route::any('/pay[/{path:.+}]', function () {
    return view('/public/cashier/index');
});

Route::group('', function () {
    Route::any('/submit.php', [EpayController::class, 'submit'])->name('epaySubmit')->setParams(['real_name' => 'Epay页面跳转支付']);
    Route::post('/mapi.php', [EpayController::class, 'mapi'])->name('epayMapi')->setParams(['real_name' => 'Epay接口支付']);
    Route::any('/api.php', [EpayController::class, 'api'])->name('epayApi')->setParams(['real_name' => 'Epay标准API']);
})->middleware([Cors::class]);

Route::group('/api', function () {
    Route::group('/pay', function () {
        Route::post('/prepare', [PayController::class, 'prepare'])->name('payPrepare')->setParams(['real_name' => '支付预下单']);
        Route::get('/{payNo}', [PayController::class, 'show'])->name('payDetail')->setParams(['real_name' => '查询支付单']);
        Route::post('/{payNo}/close', [PayController::class, 'close'])->name('payClose')->setParams(['real_name' => '关闭支付单']);
        Route::post('/{payNo}/timeout', [PayController::class, 'timeout'])->name('payTimeout')->setParams(['real_name' => '支付超时']);
        Route::any('/{payNo}/callback', [PayController::class, 'callback'])->name('payChannelCallback')->setParams(['real_name' => '第三方支付回调']);
        Route::post('/callback/mock', [PayController::class, 'callback'])->name('payCallbackMock')->setParams(['real_name' => '支付回调模拟入口']);
    });

    Route::group('/refunds', function () {
        Route::post('/', [RefundController::class, 'create'])->name('refundCreate')->setParams(['real_name' => '创建退款单']);
        Route::get('/{refundNo}', [RefundController::class, 'show'])->name('refundDetail')->setParams(['real_name' => '查询退款单']);
        Route::post('/{refundNo}/processing', [RefundController::class, 'processing'])->name('refundProcessing')->setParams(['real_name' => '退款处理中']);
        Route::post('/{refundNo}/retry', [RefundController::class, 'retry'])->name('refundRetry')->setParams(['real_name' => '退款重试']);
        Route::post('/{refundNo}/fail', [RefundController::class, 'markFail'])->name('refundFail')->setParams(['real_name' => '退款失败']);
    });

    Route::group('/settlements', function () {
        Route::post('/', [SettlementController::class, 'create'])->name('settlementCreate')->setParams(['real_name' => '创建清结算单']);
        Route::get('/{settleNo}', [SettlementController::class, 'show'])->name('settlementDetail')->setParams(['real_name' => '查询清结算单']);
        Route::post('/{settleNo}/complete', [SettlementController::class, 'complete'])->name('settlementComplete')->setParams(['real_name' => '清结算成功']);
        Route::post('/{settleNo}/fail', [SettlementController::class, 'failSettlement'])->name('settlementFail')->setParams(['real_name' => '清结算失败']);
    });

    Route::group('/routes', function () {
        Route::get('/resolve', [RouteController::class, 'resolve'])->name('routeResolve')->setParams(['real_name' => '解析路由']);
    });

    Route::group('/traces', function () {
        Route::get('/{traceNo}', [TraceController::class, 'show'])->name('traceDetail')->setParams(['real_name' => '追踪查询']);
    });

    Route::group('/notify', function () {
        Route::post('/channel', [NotifyController::class, 'channel'])->name('notifyChannel')->setParams(['real_name' => '渠道通知']);
        Route::post('/merchant', [NotifyController::class, 'merchant'])->name('notifyMerchant')->setParams(['real_name' => '商户通知']);
    });
})->middleware([Cors::class]);
