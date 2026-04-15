<?php

use Webman\Route;
use app\common\middleware\Cors;
use app\http\mer\controller\system\AuthController;
use app\http\mer\controller\merchant\MerchantPortalController;
use app\http\mer\controller\trade\RefundOrderController;
use app\http\mer\controller\trade\PayOrderController;
use app\http\mer\controller\system\SystemController;
use app\http\mer\middleware\MerchantAuthMiddleware;

Route::any('/mer[/{path:.+}]', function () {
    return view('/public/mer/index');
});

Route::group('/merapi', function () {
    Route::post('/login', [AuthController::class, 'login'])->name('merchantApiAuthLogin')->setParams(['real_name' => '商户登录']);

    Route::group('', function () {
        Route::post('/logout', [AuthController::class, 'logout'])->name('merchantApiAuthLogout')->setParams(['real_name' => '退出登录']);
        Route::get('/user/profile', [AuthController::class, 'profile'])->name('merchantApiUserProfile')->setParams(['real_name' => '当前登录账号']);
        Route::get('/merchant/profile', [MerchantPortalController::class, 'profile'])->name('merchantApiPortalProfile')->setParams(['real_name' => '商户资料']);
        Route::put('/merchant/profile', [MerchantPortalController::class, 'updateProfile'])->name('merchantApiPortalProfileUpdate')->setParams(['real_name' => '更新商户资料']);
        Route::post('/merchant/change-password', [MerchantPortalController::class, 'changePassword'])->name('merchantApiPortalChangePassword')->setParams(['real_name' => '修改登录密码']);
        Route::get('/my-channels', [MerchantPortalController::class, 'myChannels'])->name('merchantApiPortalMyChannels')->setParams(['real_name' => '我的通道']);
        Route::get('/route-preview', [MerchantPortalController::class, 'routePreview'])->name('merchantApiPortalRoutePreview')->setParams(['real_name' => '路由预览']);
        Route::get('/api-credential', [MerchantPortalController::class, 'apiCredential'])->name('merchantApiPortalCredential')->setParams(['real_name' => '接口凭证']);
        Route::post('/api-credential/issue-credential', [MerchantPortalController::class, 'issueCredential'])->name('merchantApiPortalIssueCredential')->setParams(['real_name' => '生成或重置接口凭证']);
        Route::get('/settlement-records', [MerchantPortalController::class, 'settlementRecords'])->name('merchantApiPortalSettlementRecords')->setParams(['real_name' => '清算记录']);
        Route::get('/settlement-records/{settleNo}', [MerchantPortalController::class, 'settlementRecordShow'])->name('merchantApiPortalSettlementRecordShow')->setParams(['real_name' => '清算记录详情']);
        Route::get('/withdrawable-balance', [MerchantPortalController::class, 'withdrawableBalance'])->name('merchantApiPortalWithdrawableBalance')->setParams(['real_name' => '可提现余额']);
        Route::get('/balance-flows', [MerchantPortalController::class, 'balanceFlows'])->name('merchantApiPortalBalanceFlows')->setParams(['real_name' => '资金流水']);
        Route::get('/pay-orders', [PayOrderController::class, 'index'])->name('merchantApiPayOrdersIndex')->setParams(['real_name' => '支付订单']);
        Route::get('/refund-orders', [RefundOrderController::class, 'index'])->name('merchantApiRefundOrdersIndex')->setParams(['real_name' => '退款订单']);
        Route::get('/refund-orders/{refundNo}', [RefundOrderController::class, 'show'])->name('merchantApiRefundOrdersShow')->setParams(['real_name' => '退款订单详情']);
        Route::post('/refund-orders/{refundNo}/retry', [RefundOrderController::class, 'retry'])
            ->name('merchantApiRefundOrdersRetry')
            ->setParams(['real_name' => '退款重试']);

        Route::get('/system/menu-tree', [SystemController::class, 'menuTree'])->name('merchantApiMenuTree')->setParams(['real_name' => '菜单树']);
        Route::get('/system/dict-items', [SystemController::class, 'dictItems'])->name('merchantApiDictItems')->setParams(['real_name' => '字典项']);
    })->middleware([MerchantAuthMiddleware::class]);
})->middleware([Cors::class]);
