<?php

use Webman\Route;
use app\common\middleware\Cors;
use app\http\mer\controller\file\FileRecordController;
use app\http\mer\controller\merchant\MerchantPortalController;
use app\http\mer\controller\system\AuthController;
use app\http\mer\controller\system\SystemController;
use app\http\mer\controller\trade\PayOrderController;
use app\http\mer\controller\trade\RefundOrderController;
use app\http\mer\middleware\MerchantAuthMiddleware;

$serveMerchantApp = static function () {
    $indexPath = public_path('mer/index.html');
    if (!is_file($indexPath)) {
        return response('Merchant page not found', 404);
    }

    return response(file_get_contents($indexPath), 200, [
        'Content-Type' => 'text/html; charset=utf-8',
    ]);
};

// 商户后台项目：页面路由
Route::any('/mer', $serveMerchantApp);
Route::any('/mer/', $serveMerchantApp);
Route::any('/mer/{path:.+}', $serveMerchantApp);

// 商户后台项目：接口路由
Route::group('/merapi', function () {
    // 公开接口
    Route::post('/login', [AuthController::class, 'login'])->name('merchantApiAuthLogin')->setParams(['real_name' => '商户登录']);
    Route::get('/system/public-config', [SystemController::class, 'publicConfig'])->name('merchantApiSystemPublicConfig')->setParams(['real_name' => '商户后台公开配置']);

    Route::group('', function () {
        // 会话与当前账号
        Route::post('/logout', [AuthController::class, 'logout'])->name('merchantApiAuthLogout')->setParams(['real_name' => '退出登录']);
        Route::get('/user/profile', [AuthController::class, 'profile'])->name('merchantApiUserProfile')->setParams(['real_name' => '当前登录账号']);

        // 商户资料
        Route::group('/merchant', function () {
            Route::get('/profile', [MerchantPortalController::class, 'profile'])->name('merchantApiPortalProfile')->setParams(['real_name' => '商户资料']);
            Route::put('/profile', [MerchantPortalController::class, 'updateProfile'])->name('merchantApiPortalProfileUpdate')->setParams(['real_name' => '更新商户资料']);
            Route::post('/change-password', [MerchantPortalController::class, 'changePassword'])->name('merchantApiPortalChangePassword')->setParams(['real_name' => '修改登录密码']);
        });

        // 通道与插件配置
        Route::group('/my-channels', function () {
            Route::get('', [MerchantPortalController::class, 'myChannels'])->name('merchantApiPortalMyChannels')->setParams(['real_name' => '我的通道']);
            Route::get('/create-meta', [MerchantPortalController::class, 'channelCreateMeta'])->name('merchantApiPortalChannelCreateMeta')->setParams(['real_name' => '商户通道配置元数据']);
            Route::post('', [MerchantPortalController::class, 'createChannel'])->name('merchantApiPortalChannelCreate')->setParams(['real_name' => '新增商户通道']);
            Route::put('/{id}', [MerchantPortalController::class, 'updateChannel'])->name('merchantApiPortalChannelUpdate')->setParams(['real_name' => '修改商户通道']);
            Route::delete('/{id}', [MerchantPortalController::class, 'deleteChannel'])->name('merchantApiPortalChannelDelete')->setParams(['real_name' => '删除商户通道']);
        });

        Route::group('/plugin-configs', function () {
            Route::get('', [MerchantPortalController::class, 'pluginConfigs'])->name('merchantApiPortalPluginConfigs')->setParams(['real_name' => '商户插件配置']);
            Route::get('/options', [MerchantPortalController::class, 'pluginConfigOptions'])->name('merchantApiPortalPluginConfigOptions')->setParams(['real_name' => '商户插件配置选项']);
            Route::post('', [MerchantPortalController::class, 'createPluginConfig'])->name('merchantApiPortalPluginConfigCreate')->setParams(['real_name' => '新增商户插件配置']);
            Route::put('/{id}', [MerchantPortalController::class, 'updatePluginConfig'])->name('merchantApiPortalPluginConfigUpdate')->setParams(['real_name' => '修改商户插件配置']);
            Route::delete('/{id}', [MerchantPortalController::class, 'deletePluginConfig'])->name('merchantApiPortalPluginConfigDelete')->setParams(['real_name' => '删除商户插件配置']);
        });

        Route::get('/payment-plugins/{code}/schema', [MerchantPortalController::class, 'pluginSchema'])->name('merchantApiPortalPluginSchema')->setParams(['real_name' => '商户插件配置结构']);
        Route::get('/route-preview', [MerchantPortalController::class, 'routePreview'])->name('merchantApiPortalRoutePreview')->setParams(['real_name' => '路由解析']);

        // 文件
        Route::group('/file-asset', function () {
            Route::post('/upload', [FileRecordController::class, 'upload'])->name('merchantApiFileRecordUpload')->setParams(['real_name' => '上传文件']);
            Route::get('/{id}/preview', [FileRecordController::class, 'preview'])->name('merchantApiFileRecordPreview')->setParams(['real_name' => '文件预览']);
            Route::get('/{id}/download', [FileRecordController::class, 'download'])->name('merchantApiFileRecordDownload')->setParams(['real_name' => '文件下载']);
        });

        // API 凭证
        Route::group('/api-credential', function () {
            Route::get('', [MerchantPortalController::class, 'apiCredential'])->name('merchantApiPortalCredential')->setParams(['real_name' => '商户 API 凭证']);
            Route::post('/issue-credential', [MerchantPortalController::class, 'issueCredential'])->name('merchantApiPortalIssueCredential')->setParams(['real_name' => '生成或重置商户 API 凭证']);
        });

        // 资金与清算
        Route::group('/settlement-records', function () {
            Route::get('', [MerchantPortalController::class, 'settlementRecords'])->name('merchantApiPortalSettlementRecords')->setParams(['real_name' => '清算记录']);
            Route::get('/{settleNo}', [MerchantPortalController::class, 'settlementRecordShow'])->name('merchantApiPortalSettlementRecordShow')->setParams(['real_name' => '清算记录详情']);
        });

        Route::get('/withdrawable-balance', [MerchantPortalController::class, 'withdrawableBalance'])->name('merchantApiPortalWithdrawableBalance')->setParams(['real_name' => '可提现余额']);
        Route::get('/balance-flows', [MerchantPortalController::class, 'balanceFlows'])->name('merchantApiPortalBalanceFlows')->setParams(['real_name' => '资金流水']);

        // 交易订单
        Route::get('/pay-orders', [PayOrderController::class, 'index'])->name('merchantApiPayOrdersIndex')->setParams(['real_name' => '支付订单']);

        Route::group('/refund-orders', function () {
            Route::get('', [RefundOrderController::class, 'index'])->name('merchantApiRefundOrdersIndex')->setParams(['real_name' => '退款订单']);
            Route::get('/{refundNo}', [RefundOrderController::class, 'show'])->name('merchantApiRefundOrdersShow')->setParams(['real_name' => '退款订单详情']);
            Route::post('/{refundNo}/retry', [RefundOrderController::class, 'retry'])->name('merchantApiRefundOrdersRetry')->setParams(['real_name' => '退款重试']);
        });

        // 系统
        Route::group('/system', function () {
            Route::get('/menu-tree', [SystemController::class, 'menuTree'])->name('merchantApiMenuTree')->setParams(['real_name' => '菜单树']);
            Route::get('/dict-items', [SystemController::class, 'dictItems'])->name('merchantApiDictItems')->setParams(['real_name' => '字典项']);
        });
    })->middleware([MerchantAuthMiddleware::class]);
})->middleware([Cors::class]);
