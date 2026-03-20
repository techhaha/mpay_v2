<?php

use app\common\middleware\Cors;
use app\http\admin\controller\AdminController;
use app\http\admin\controller\AuthController;
use app\http\admin\controller\ChannelController;
use app\http\admin\controller\FinanceController;
use app\http\admin\controller\MenuController;
use app\http\admin\controller\MerchantAppController;
use app\http\admin\controller\MerchantController;
use app\http\admin\controller\OrderController;
use app\http\admin\controller\PayMethodController;
use app\http\admin\controller\PayPluginController;
use app\http\admin\controller\PluginController;
use app\http\admin\controller\SystemController;
use app\http\admin\middleware\AuthMiddleware;
use Webman\Route;

Route::group('/adminapi', function () {
    Route::get('/captcha', [AuthController::class, 'captcha'])
        ->name('captcha')
        ->setParams(['real_name' => 'adminCaptcha']);
    Route::post('/login', [AuthController::class, 'login'])
        ->name('login')
        ->setParams(['real_name' => 'adminLogin']);

    Route::group('', function () {
        Route::get('/user/getUserInfo', [AdminController::class, 'getUserInfo'])
            ->name('getUserInfo')
            ->setParams(['real_name' => 'getUserInfo']);
        Route::get('/menu/getRouters', [MenuController::class, 'getRouters'])
            ->name('getRouters')
            ->setParams(['real_name' => 'getRouters']);

        Route::get('/system/getDict[/{code}]', [SystemController::class, 'getDict'])
            ->name('getDict')
            ->setParams(['real_name' => 'getSystemDict']);
        Route::get('/system/base-config/tabs', [SystemController::class, 'getTabsConfig'])
            ->name('getTabsConfig')
            ->setParams(['real_name' => 'getSystemTabs']);
        Route::get('/system/base-config/form/{tabKey}', [SystemController::class, 'getFormConfig'])
            ->name('getFormConfig')
            ->setParams(['real_name' => 'getSystemForm']);
        Route::post('/system/base-config/submit/{tabKey}', [SystemController::class, 'submitConfig'])
            ->name('submitConfig')
            ->setParams(['real_name' => 'submitSystemConfig']);
        Route::get('/system/log/files', [SystemController::class, 'logFiles'])
            ->name('systemLogFiles')
            ->setParams(['real_name' => 'systemLogFiles']);
        Route::get('/system/log/summary', [SystemController::class, 'logSummary'])
            ->name('systemLogSummary')
            ->setParams(['real_name' => 'systemLogSummary']);
        Route::get('/system/log/content', [SystemController::class, 'logContent'])
            ->name('systemLogContent')
            ->setParams(['real_name' => 'systemLogContent']);
        Route::get('/system/notice/overview', [SystemController::class, 'noticeOverview'])
            ->name('systemNoticeOverview')
            ->setParams(['real_name' => 'systemNoticeOverview']);
        Route::post('/system/notice/test', [SystemController::class, 'noticeTest'])
            ->name('systemNoticeTest')
            ->setParams(['real_name' => 'systemNoticeTest']);

        Route::get('/finance/reconciliation', [FinanceController::class, 'reconciliation'])
            ->name('financeReconciliation')
            ->setParams(['real_name' => 'financeReconciliation']);
        Route::get('/finance/settlement', [FinanceController::class, 'settlement'])
            ->name('financeSettlement')
            ->setParams(['real_name' => 'financeSettlement']);
        Route::get('/finance/batch-settlement', [FinanceController::class, 'batchSettlement'])
            ->name('financeBatchSettlement')
            ->setParams(['real_name' => 'financeBatchSettlement']);
        Route::get('/finance/settlement-record', [FinanceController::class, 'settlementRecord'])
            ->name('financeSettlementRecord')
            ->setParams(['real_name' => 'financeSettlementRecord']);
        Route::get('/finance/split', [FinanceController::class, 'split'])
            ->name('financeSplit')
            ->setParams(['real_name' => 'financeSplit']);
        Route::get('/finance/fee', [FinanceController::class, 'fee'])
            ->name('financeFee')
            ->setParams(['real_name' => 'financeFee']);
        Route::get('/finance/invoice', [FinanceController::class, 'invoice'])
            ->name('financeInvoice')
            ->setParams(['real_name' => 'financeInvoice']);

        Route::get('/channel/list', [ChannelController::class, 'list'])
            ->name('channelList')
            ->setParams(['real_name' => 'channelList']);
        Route::get('/channel/detail', [ChannelController::class, 'detail'])
            ->name('channelDetail')
            ->setParams(['real_name' => 'channelDetail']);
        Route::get('/channel/monitor', [ChannelController::class, 'monitor'])
            ->name('channelMonitor')
            ->setParams(['real_name' => 'channelMonitor']);
        Route::get('/channel/polling', [ChannelController::class, 'polling'])
            ->name('channelPolling')
            ->setParams(['real_name' => 'channelPolling']);
        Route::get('/channel/policy/list', [ChannelController::class, 'policyList'])
            ->name('channelPolicyList')
            ->setParams(['real_name' => 'channelPolicyList']);
        Route::post('/channel/save', [ChannelController::class, 'save'])
            ->name('channelSave')
            ->setParams(['real_name' => 'channelSave']);
        Route::post('/channel/toggle', [ChannelController::class, 'toggle'])
            ->name('channelToggle')
            ->setParams(['real_name' => 'channelToggle']);
        Route::post('/channel/policy/save', [ChannelController::class, 'policySave'])
            ->name('channelPolicySave')
            ->setParams(['real_name' => 'channelPolicySave']);
        Route::post('/channel/policy/preview', [ChannelController::class, 'policyPreview'])
            ->name('channelPolicyPreview')
            ->setParams(['real_name' => 'channelPolicyPreview']);
        Route::post('/channel/policy/delete', [ChannelController::class, 'policyDelete'])
            ->name('channelPolicyDelete')
            ->setParams(['real_name' => 'channelPolicyDelete']);

        Route::get('/channel/plugins', [PluginController::class, 'plugins'])
            ->name('channelPlugins')
            ->setParams(['real_name' => 'channelPlugins']);
        Route::get('/channel/plugin/config-schema', [PluginController::class, 'configSchema'])
            ->name('channelPluginConfigSchema')
            ->setParams(['real_name' => 'channelPluginConfigSchema']);
        Route::get('/channel/plugin/products', [PluginController::class, 'products'])
            ->name('channelPluginProducts')
            ->setParams(['real_name' => 'channelPluginProducts']);

        Route::get('/merchant/list', [MerchantController::class, 'list'])
            ->name('merchantList')
            ->setParams(['real_name' => 'merchantList']);
        Route::get('/merchant/detail', [MerchantController::class, 'detail'])
            ->name('merchantDetail')
            ->setParams(['real_name' => 'merchantDetail']);
        Route::get('/merchant/profile/detail', [MerchantController::class, 'profileDetail'])
            ->name('merchantProfileDetail')
            ->setParams(['real_name' => 'merchantProfileDetail']);
        Route::get('/merchant/statistics', [MerchantController::class, 'statistics'])
            ->name('merchantStatistics')
            ->setParams(['real_name' => 'merchantStatistics']);
        Route::get('/merchant/funds', [MerchantController::class, 'funds'])
            ->name('merchantFunds')
            ->setParams(['real_name' => 'merchantFunds']);
        Route::get('/merchant/audit', [MerchantController::class, 'audit'])
            ->name('merchantAudit')
            ->setParams(['real_name' => 'merchantAudit']);
        Route::get('/merchant/group/list', [MerchantController::class, 'groupList'])
            ->name('merchantGroupList')
            ->setParams(['real_name' => 'merchantGroupList']);
        Route::post('/merchant/group/save', [MerchantController::class, 'groupSave'])
            ->name('merchantGroupSave')
            ->setParams(['real_name' => 'merchantGroupSave']);
        Route::post('/merchant/group/delete', [MerchantController::class, 'groupDelete'])
            ->name('merchantGroupDelete')
            ->setParams(['real_name' => 'merchantGroupDelete']);
        Route::get('/merchant/package/list', [MerchantController::class, 'packageList'])
            ->name('merchantPackageList')
            ->setParams(['real_name' => 'merchantPackageList']);
        Route::post('/merchant/package/save', [MerchantController::class, 'packageSave'])
            ->name('merchantPackageSave')
            ->setParams(['real_name' => 'merchantPackageSave']);
        Route::post('/merchant/package/delete', [MerchantController::class, 'packageDelete'])
            ->name('merchantPackageDelete')
            ->setParams(['real_name' => 'merchantPackageDelete']);
        Route::post('/merchant/save', [MerchantController::class, 'save'])
            ->name('merchantSave')
            ->setParams(['real_name' => 'merchantSave']);
        Route::post('/merchant/profile/save', [MerchantController::class, 'profileSave'])
            ->name('merchantProfileSave')
            ->setParams(['real_name' => 'merchantProfileSave']);
        Route::post('/merchant/audit-action', [MerchantController::class, 'auditAction'])
            ->name('merchantAuditAction')
            ->setParams(['real_name' => 'merchantAuditAction']);
        Route::post('/merchant/toggle', [MerchantController::class, 'toggle'])
            ->name('merchantToggle')
            ->setParams(['real_name' => 'merchantToggle']);

        Route::get('/merchant-app/list', [MerchantAppController::class, 'list'])
            ->name('merchantAppList')
            ->setParams(['real_name' => 'merchantAppList']);
        Route::get('/merchant-app/detail', [MerchantAppController::class, 'detail'])
            ->name('merchantAppDetail')
            ->setParams(['real_name' => 'merchantAppDetail']);
        Route::get('/merchant-app/config/detail', [MerchantAppController::class, 'configDetail'])
            ->name('merchantAppConfigDetail')
            ->setParams(['real_name' => 'merchantAppConfigDetail']);
        Route::post('/merchant-app/save', [MerchantAppController::class, 'save'])
            ->name('merchantAppSave')
            ->setParams(['real_name' => 'merchantAppSave']);
        Route::post('/merchant-app/config/save', [MerchantAppController::class, 'configSave'])
            ->name('merchantAppConfigSave')
            ->setParams(['real_name' => 'merchantAppConfigSave']);
        Route::post('/merchant-app/reset-secret', [MerchantAppController::class, 'resetSecret'])
            ->name('merchantAppResetSecret')
            ->setParams(['real_name' => 'merchantAppResetSecret']);
        Route::post('/merchant-app/toggle', [MerchantAppController::class, 'toggle'])
            ->name('merchantAppToggle')
            ->setParams(['real_name' => 'merchantAppToggle']);

        Route::get('/pay-method/list', [PayMethodController::class, 'list'])
            ->name('payMethodList')
            ->setParams(['real_name' => 'payMethodList']);
        Route::post('/pay-method/save', [PayMethodController::class, 'save'])
            ->name('payMethodSave')
            ->setParams(['real_name' => 'payMethodSave']);
        Route::post('/pay-method/toggle', [PayMethodController::class, 'toggle'])
            ->name('payMethodToggle')
            ->setParams(['real_name' => 'payMethodToggle']);

        Route::get('/pay-plugin/list', [PayPluginController::class, 'list'])
            ->name('payPluginList')
            ->setParams(['real_name' => 'payPluginList']);
        Route::post('/pay-plugin/save', [PayPluginController::class, 'save'])
            ->name('payPluginSave')
            ->setParams(['real_name' => 'payPluginSave']);
        Route::post('/pay-plugin/toggle', [PayPluginController::class, 'toggle'])
            ->name('payPluginToggle')
            ->setParams(['real_name' => 'payPluginToggle']);

        Route::get('/order/list', [OrderController::class, 'list'])
            ->name('orderList')
            ->setParams(['real_name' => 'orderList']);
        Route::get('/order/export', [OrderController::class, 'export'])
            ->name('orderExport')
            ->setParams(['real_name' => 'orderExport']);
        Route::get('/order/detail', [OrderController::class, 'detail'])
            ->name('orderDetail')
            ->setParams(['real_name' => 'orderDetail']);
        Route::post('/order/refund', [OrderController::class, 'refund'])
            ->name('orderRefund')
            ->setParams(['real_name' => 'orderRefund']);
    })->middleware([AuthMiddleware::class]);
})->middleware([Cors::class]);
