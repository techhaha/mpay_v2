<?php

use Webman\Route;
use app\common\middleware\Cors;
use app\http\admin\controller\account\MerchantAccountController;
use app\http\admin\controller\account\MerchantAccountLedgerController;
use app\http\admin\controller\account\MerchantFundFreezeController;
use app\http\admin\controller\file\FileRecordController;
use app\http\admin\controller\merchant\MerchantApiCredentialController;
use app\http\admin\controller\merchant\MerchantController;
use app\http\admin\controller\merchant\MerchantGroupController;
use app\http\admin\controller\merchant\MerchantPolicyController;
use app\http\admin\controller\ops\AdminDashboardController;
use app\http\admin\controller\ops\ChannelDailyStatController;
use app\http\admin\controller\ops\ChannelNotifyLogController;
use app\http\admin\controller\ops\MerchantNotifyTaskController;
use app\http\admin\controller\ops\PayCallbackLogController;
use app\http\admin\controller\payment\PaymentChannelController;
use app\http\admin\controller\payment\PaymentPluginConfController;
use app\http\admin\controller\payment\PaymentPluginController;
use app\http\admin\controller\payment\PaymentPollGroupBindController;
use app\http\admin\controller\payment\PaymentPollGroupChannelController;
use app\http\admin\controller\payment\PaymentPollGroupController;
use app\http\admin\controller\payment\PaymentTypeController;
use app\http\admin\controller\payment\RouteController;
use app\http\admin\controller\system\AdminUserController;
use app\http\admin\controller\system\AuthController;
use app\http\admin\controller\system\InstallController;
use app\http\admin\controller\system\SystemConfigPageController;
use app\http\admin\controller\system\SystemController;
use app\http\admin\controller\system\SystemOpsController;
use app\http\admin\controller\trade\PayOrderController;
use app\http\admin\controller\trade\RefundOrderController;
use app\http\admin\controller\trade\SettlementOrderController;
use app\http\admin\middleware\AdminAuthMiddleware;

$serveAdminApp = static function () {
    $indexPath = public_path('admin/index.html');
    if (!is_file($indexPath)) {
        return response('Admin page not found', 404);
    }

    return response(file_get_contents($indexPath), 200, [
        'Content-Type' => 'text/html; charset=utf-8',
        'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        'Pragma' => 'no-cache',
        'Expires' => '0',
    ]);
};

$serveHomeApp = static function () {
    $indexPath = public_path('index.html');
    if (!is_file($indexPath)) {
        return response('Home page not found', 404);
    }

    return response(file_get_contents($indexPath), 200, [
        'Content-Type' => 'text/html; charset=utf-8',
    ]);
};

$serveDocsApp = static function () {
    $indexPath = public_path('docs/index.html');
    if (!is_file($indexPath)) {
        return response('Docs page not found', 404);
    }

    return response(file_get_contents($indexPath), 200, [
        'Content-Type' => 'text/html; charset=utf-8',
    ]);
};

$serveInstallApp = static function () {
    $indexPath = public_path('install/index.html');
    if (!is_file($indexPath)) {
        return response('Install page not found', 404);
    }

    return response(file_get_contents($indexPath), 200, [
        'Content-Type' => 'text/html; charset=utf-8',
    ]);
};

// 互联网公开首页。
Route::any('/', $serveHomeApp);
Route::any('/home', $serveHomeApp);
Route::any('/home/', $serveHomeApp);

// 公开接入文档：展示 ePay V1/V2 双协议接口说明。
Route::any('/docs', $serveDocsApp);
Route::any('/docs/', $serveDocsApp);

// 安装向导页面：独立静态页，不进入 Vue 构建链路。
Route::any('/install', $serveInstallApp);
Route::any('/install/', $serveInstallApp);

// 管理后台项目：页面路由
Route::any('/admin', $serveAdminApp);
Route::any('/admin/', $serveAdminApp);
Route::any('/admin/{path:.+}', $serveAdminApp);

// 管理后台项目：接口路由
Route::group('/adminapi', function () {
    // 公开接口
    Route::post('/login', [AuthController::class, 'login'])->name('adminApiAuthLogin')->setParams(['real_name' => '管理员登录']);
    Route::get('/system/public-config', [SystemController::class, 'publicConfig'])->name('adminApiSystemPublicConfig')->setParams(['real_name' => '管理后台公开配置']);
    Route::group('/install', function () {
        Route::get('/status', [InstallController::class, 'status'])->name('adminApiInstallStatus')->setParams(['real_name' => '安装状态']);
        Route::get('/check-env', [InstallController::class, 'checkEnv'])->name('adminApiInstallCheckEnv')->setParams(['real_name' => '安装环境检测']);
        Route::get('/secrets', [InstallController::class, 'secrets'])->name('adminApiInstallSecrets')->setParams(['real_name' => '生成安装密钥']);
        Route::post('/test-db', [InstallController::class, 'testDb'])->name('adminApiInstallTestDb')->setParams(['real_name' => '安装数据库测试']);
        Route::post('/test-redis', [InstallController::class, 'testRedis'])->name('adminApiInstallTestRedis')->setParams(['real_name' => '安装Redis测试']);
        Route::post('/run', [InstallController::class, 'run'])->name('adminApiInstallRun')->setParams(['real_name' => '执行安装']);
    });

    Route::group('', function () {
        // 会话与当前用户
        Route::post('/logout', [AuthController::class, 'logout'])->name('adminApiAuthLogout')->setParams(['real_name' => '退出登录']);
        Route::get('/user/profile', [AuthController::class, 'profile'])->name('adminApiUserProfile')->setParams(['real_name' => '当前用户资料']);
        Route::post('/user/change-password', [AuthController::class, 'changePassword'])->name('adminApiUserChangePassword')->setParams(['real_name' => '修改当前管理员密码']);
        Route::get('/dashboard/overview', [AdminDashboardController::class, 'overview'])->name('adminApiDashboardOverview')->setParams(['real_name' => '运营首页总览']);

        // 商户档案
        Route::group('/merchants', function () {
            Route::get('', [MerchantController::class, 'index'])->name('adminApiMerchantsIndex')->setParams(['real_name' => '商户列表']);
            Route::get('/options', [MerchantController::class, 'options'])->name('adminApiMerchantsOptions')->setParams(['real_name' => '商户选项']);
            Route::get('/select-options', [MerchantController::class, 'selectOptions'])->name('adminApiMerchantsSelectOptions')->setParams(['real_name' => '商户选择选项']);
            Route::post('', [MerchantController::class, 'store'])->name('adminApiMerchantsStore')->setParams(['real_name' => '新增商户']);
            Route::get('/{id}/overview', [MerchantController::class, 'overview'])->name('adminApiMerchantsOverview')->setParams(['real_name' => '商户总览']);
            Route::post('/{id}/reset-password', [MerchantController::class, 'resetPassword'])->name('adminApiMerchantsResetPassword')->setParams(['real_name' => '重置商户密码']);
            Route::post('/{id}/login-token', [MerchantController::class, 'loginToken'])->name('adminApiMerchantsLoginToken')->setParams(['real_name' => '商户后台登录令牌']);
            Route::post('/{id}/issue-credential', [MerchantController::class, 'issueCredential'])->name('adminApiMerchantsIssueCredential')->setParams(['real_name' => '生成或重置商户 API 凭证']);
            Route::get('/{id}', [MerchantController::class, 'show'])->name('adminApiMerchantsShow')->setParams(['real_name' => '商户详情']);
            Route::put('/{id}', [MerchantController::class, 'update'])->name('adminApiMerchantsUpdate')->setParams(['real_name' => '更新商户']);
            Route::delete('/{id}', [MerchantController::class, 'destroy'])->name('adminApiMerchantsDestroy')->setParams(['real_name' => '删除商户']);
        });

        Route::group('/merchant-api-credentials', function () {
            Route::get('', [MerchantApiCredentialController::class, 'index'])->name('adminApiMerchantApiCredentialsIndex')->setParams(['real_name' => '商户 API 凭证列表']);
            Route::post('', [MerchantApiCredentialController::class, 'store'])->name('adminApiMerchantApiCredentialsStore')->setParams(['real_name' => '开通商户 API 凭证']);
            Route::get('/{id}', [MerchantApiCredentialController::class, 'show'])->name('adminApiMerchantApiCredentialsShow')->setParams(['real_name' => '商户 API 凭证详情']);
            Route::put('/{id}', [MerchantApiCredentialController::class, 'update'])->name('adminApiMerchantApiCredentialsUpdate')->setParams(['real_name' => '更新商户 API 凭证']);
            Route::delete('/{id}', [MerchantApiCredentialController::class, 'destroy'])->name('adminApiMerchantApiCredentialsDestroy')->setParams(['real_name' => '删除商户 API 凭证']);
        });

        Route::group('/merchant-groups', function () {
            Route::get('', [MerchantGroupController::class, 'index'])->name('adminApiMerchantGroupsIndex')->setParams(['real_name' => '商户分组列表']);
            Route::get('/options', [MerchantGroupController::class, 'options'])->name('adminApiMerchantGroupsOptions')->setParams(['real_name' => '商户分组选项']);
            Route::post('', [MerchantGroupController::class, 'store'])->name('adminApiMerchantGroupsStore')->setParams(['real_name' => '新增商户分组']);
            Route::get('/{id}', [MerchantGroupController::class, 'show'])->name('adminApiMerchantGroupsShow')->setParams(['real_name' => '商户分组详情']);
            Route::put('/{id}', [MerchantGroupController::class, 'update'])->name('adminApiMerchantGroupsUpdate')->setParams(['real_name' => '更新商户分组']);
            Route::delete('/{id}', [MerchantGroupController::class, 'destroy'])->name('adminApiMerchantGroupsDestroy')->setParams(['real_name' => '删除商户分组']);
        });

        Route::group('/merchant-policies', function () {
            Route::get('', [MerchantPolicyController::class, 'index'])->name('adminApiMerchantPoliciesIndex')->setParams(['real_name' => '商户策略列表']);
            Route::post('', [MerchantPolicyController::class, 'store'])->name('adminApiMerchantPoliciesStore')->setParams(['real_name' => '新增商户策略']);
            Route::get('/{merchantId}', [MerchantPolicyController::class, 'show'])->name('adminApiMerchantPoliciesShow')->setParams(['real_name' => '商户策略详情']);
            Route::put('/{merchantId}', [MerchantPolicyController::class, 'update'])->name('adminApiMerchantPoliciesUpdate')->setParams(['real_name' => '更新商户策略']);
            Route::delete('/{merchantId}', [MerchantPolicyController::class, 'destroy'])->name('adminApiMerchantPoliciesDestroy')->setParams(['real_name' => '删除商户策略']);
        });

        // 支付配置
        Route::group('/payment-types', function () {
            Route::get('', [PaymentTypeController::class, 'index'])->name('adminApiPaymentTypesIndex')->setParams(['real_name' => '支付方式列表']);
            Route::get('/options', [PaymentTypeController::class, 'options'])->name('adminApiPaymentTypesOptions')->setParams(['real_name' => '支付方式选项']);
            Route::post('', [PaymentTypeController::class, 'store'])->name('adminApiPaymentTypesStore')->setParams(['real_name' => '新增支付方式']);
            Route::get('/{id}', [PaymentTypeController::class, 'show'])->name('adminApiPaymentTypesShow')->setParams(['real_name' => '支付方式详情']);
            Route::put('/{id}', [PaymentTypeController::class, 'update'])->name('adminApiPaymentTypesUpdate')->setParams(['real_name' => '更新支付方式']);
            Route::delete('/{id}', [PaymentTypeController::class, 'destroy'])->name('adminApiPaymentTypesDestroy')->setParams(['real_name' => '删除支付方式']);
        });

        Route::group('/payment-plugins', function () {
            Route::get('', [PaymentPluginController::class, 'index'])->name('adminApiPaymentPluginsIndex')->setParams(['real_name' => '支付插件列表']);
            Route::get('/options', [PaymentPluginController::class, 'options'])->name('adminApiPaymentPluginsOptions')->setParams(['real_name' => '支付插件选项']);
            Route::get('/select-options', [PaymentPluginController::class, 'selectOptions'])->name('adminApiPaymentPluginsSelectOptions')->setParams(['real_name' => '支付插件选择项']);
            Route::get('/channel-options', [PaymentPluginController::class, 'channelOptions'])->name('adminApiPaymentPluginsChannelOptions')->setParams(['real_name' => '支付插件通道选项']);
            Route::post('/refresh', [PaymentPluginController::class, 'refresh'])->name('adminApiPaymentPluginsRefresh')->setParams(['real_name' => '刷新支付插件']);
            Route::get('/{code}/schema', [PaymentPluginController::class, 'schema'])->name('adminApiPaymentPluginsSchema')->setParams(['real_name' => '支付插件配置结构']);
            Route::get('/{code}', [PaymentPluginController::class, 'show'])->name('adminApiPaymentPluginsShow')->setParams(['real_name' => '支付插件详情']);
            Route::put('/{code}', [PaymentPluginController::class, 'update'])->name('adminApiPaymentPluginsUpdate')->setParams(['real_name' => '更新支付插件']);
        });

        Route::group('/payment-plugin-confs', function () {
            Route::get('', [PaymentPluginConfController::class, 'index'])->name('adminApiPaymentPluginConfsIndex')->setParams(['real_name' => '支付插件配置列表']);
            Route::get('/options', [PaymentPluginConfController::class, 'options'])->name('adminApiPaymentPluginConfsOptions')->setParams(['real_name' => '支付插件配置选项']);
            Route::get('/select-options', [PaymentPluginConfController::class, 'selectOptions'])->name('adminApiPaymentPluginConfsSelectOptions')->setParams(['real_name' => '支付插件配置选择项']);
            Route::post('', [PaymentPluginConfController::class, 'store'])->name('adminApiPaymentPluginConfsStore')->setParams(['real_name' => '新增支付插件配置']);
            Route::get('/{id}', [PaymentPluginConfController::class, 'show'])->name('adminApiPaymentPluginConfsShow')->setParams(['real_name' => '支付插件配置详情']);
            Route::put('/{id}', [PaymentPluginConfController::class, 'update'])->name('adminApiPaymentPluginConfsUpdate')->setParams(['real_name' => '更新支付插件配置']);
            Route::delete('/{id}', [PaymentPluginConfController::class, 'destroy'])->name('adminApiPaymentPluginConfsDestroy')->setParams(['real_name' => '删除支付插件配置']);
        });

        Route::group('/payment-channels', function () {
            Route::get('', [PaymentChannelController::class, 'index'])->name('adminApiPaymentChannelsIndex')->setParams(['real_name' => '支付通道列表']);
            Route::get('/options', [PaymentChannelController::class, 'options'])->name('adminApiPaymentChannelsOptions')->setParams(['real_name' => '支付通道选项']);
            Route::get('/select-options', [PaymentChannelController::class, 'selectOptions'])->name('adminApiPaymentChannelsSelectOptions')->setParams(['real_name' => '支付通道选择项']);
            Route::get('/route-options', [PaymentChannelController::class, 'routeOptions'])->name('adminApiPaymentChannelsRouteOptions')->setParams(['real_name' => '支付通道路由选项']);
            Route::get('/test-records', [PaymentChannelController::class, 'testRecords'])->name('adminApiPaymentChannelsTestRecords')->setParams(['real_name' => '支付通道测试记录']);
            Route::post('', [PaymentChannelController::class, 'store'])->name('adminApiPaymentChannelsStore')->setParams(['real_name' => '新增支付通道']);
            Route::post('/{id}/test', [PaymentChannelController::class, 'test'])->name('adminApiPaymentChannelsTest')->setParams(['real_name' => '测试支付通道']);
            Route::get('/{id}', [PaymentChannelController::class, 'show'])->name('adminApiPaymentChannelsShow')->setParams(['real_name' => '支付通道详情']);
            Route::put('/{id}', [PaymentChannelController::class, 'update'])->name('adminApiPaymentChannelsUpdate')->setParams(['real_name' => '更新支付通道']);
            Route::delete('/{id}', [PaymentChannelController::class, 'destroy'])->name('adminApiPaymentChannelsDestroy')->setParams(['real_name' => '删除支付通道']);
        });

        Route::group('/payment-poll-groups', function () {
            Route::get('', [PaymentPollGroupController::class, 'index'])->name('adminApiPaymentPollGroupsIndex')->setParams(['real_name' => '轮询组列表']);
            Route::get('/options', [PaymentPollGroupController::class, 'options'])->name('adminApiPaymentPollGroupsOptions')->setParams(['real_name' => '轮询组选项']);
            Route::post('', [PaymentPollGroupController::class, 'store'])->name('adminApiPaymentPollGroupsStore')->setParams(['real_name' => '新增轮询组']);
            Route::get('/{id}', [PaymentPollGroupController::class, 'show'])->name('adminApiPaymentPollGroupsShow')->setParams(['real_name' => '轮询组详情']);
            Route::put('/{id}', [PaymentPollGroupController::class, 'update'])->name('adminApiPaymentPollGroupsUpdate')->setParams(['real_name' => '更新轮询组']);
            Route::delete('/{id}', [PaymentPollGroupController::class, 'destroy'])->name('adminApiPaymentPollGroupsDestroy')->setParams(['real_name' => '删除轮询组']);
        });

        Route::group('/payment-poll-group-channels', function () {
            Route::get('', [PaymentPollGroupChannelController::class, 'index'])->name('adminApiPaymentPollGroupChannelsIndex')->setParams(['real_name' => '轮询组通道列表']);
            Route::post('', [PaymentPollGroupChannelController::class, 'store'])->name('adminApiPaymentPollGroupChannelsStore')->setParams(['real_name' => '新增轮询组通道']);
            Route::get('/{id}', [PaymentPollGroupChannelController::class, 'show'])->name('adminApiPaymentPollGroupChannelsShow')->setParams(['real_name' => '轮询组通道详情']);
            Route::put('/{id}', [PaymentPollGroupChannelController::class, 'update'])->name('adminApiPaymentPollGroupChannelsUpdate')->setParams(['real_name' => '更新轮询组通道']);
            Route::delete('/{id}', [PaymentPollGroupChannelController::class, 'destroy'])->name('adminApiPaymentPollGroupChannelsDestroy')->setParams(['real_name' => '删除轮询组通道']);
        });

        Route::group('/payment-poll-group-binds', function () {
            Route::get('', [PaymentPollGroupBindController::class, 'index'])->name('adminApiPaymentPollGroupBindsIndex')->setParams(['real_name' => '轮询组绑定列表']);
            Route::post('', [PaymentPollGroupBindController::class, 'store'])->name('adminApiPaymentPollGroupBindsStore')->setParams(['real_name' => '新增轮询组绑定']);
            Route::get('/{id}', [PaymentPollGroupBindController::class, 'show'])->name('adminApiPaymentPollGroupBindsShow')->setParams(['real_name' => '轮询组绑定详情']);
            Route::put('/{id}', [PaymentPollGroupBindController::class, 'update'])->name('adminApiPaymentPollGroupBindsUpdate')->setParams(['real_name' => '更新轮询组绑定']);
            Route::delete('/{id}', [PaymentPollGroupBindController::class, 'destroy'])->name('adminApiPaymentPollGroupBindsDestroy')->setParams(['real_name' => '删除轮询组绑定']);
        });

        Route::get('/routes/resolve', [RouteController::class, 'resolve'])->name('adminApiRoutesResolve')->setParams(['real_name' => '解析路由']);
        Route::get('/routes/change-records', [RouteController::class, 'changeRecords'])->name('adminApiRoutesChangeRecords')->setParams(['real_name' => '路由变更记录']);

        // 文件存储
        Route::group('/file-asset', function () {
            Route::get('', [FileRecordController::class, 'index'])->name('adminApiFileRecordIndex')->setParams(['real_name' => '文件列表']);
            Route::get('/options', [FileRecordController::class, 'options'])->name('adminApiFileRecordOptions')->setParams(['real_name' => '文件选项']);
            Route::post('/upload', [FileRecordController::class, 'upload'])->name('adminApiFileRecordUpload')->setParams(['real_name' => '上传文件']);
            Route::post('/import-remote', [FileRecordController::class, 'importRemote'])->name('adminApiFileRecordImportRemote')->setParams(['real_name' => '导入远程文件']);
            Route::get('/{id}/preview', [FileRecordController::class, 'preview'])->name('adminApiFileRecordPreview')->setParams(['real_name' => '文件预览']);
            Route::get('/{id}/download', [FileRecordController::class, 'download'])->name('adminApiFileRecordDownload')->setParams(['real_name' => '文件下载']);
            Route::get('/{id}', [FileRecordController::class, 'show'])->name('adminApiFileRecordShow')->setParams(['real_name' => '文件详情']);
            Route::delete('/{id}', [FileRecordController::class, 'destroy'])->name('adminApiFileRecordDestroy')->setParams(['real_name' => '删除文件']);
        });

        // 交易订单
        Route::group('/pay-orders', function () {
            Route::get('', [PayOrderController::class, 'index'])->name('adminApiPayOrdersIndex')->setParams(['real_name' => '支付订单列表']);
            Route::get('/{payNo}/actions', [PayOrderController::class, 'actions'])->name('adminApiPayOrdersActions')->setParams(['real_name' => '支付订单可操作项']);
            Route::post('/{payNo}/renotify', [PayOrderController::class, 'renotify'])->name('adminApiPayOrdersRenotify')->setParams(['real_name' => '支付订单重新通知']);
            Route::post('/{payNo}/query', [PayOrderController::class, 'activeQuery'])->name('adminApiPayOrdersActiveQuery')->setParams(['real_name' => '支付订单主动查询']);
            Route::post('/{payNo}/api-refund', [PayOrderController::class, 'apiRefund'])->name('adminApiPayOrdersApiRefund')->setParams(['real_name' => '支付订单 API 退款']);
            Route::post('/{payNo}/manual-refund', [PayOrderController::class, 'manualRefund'])->name('adminApiPayOrdersManualRefund')->setParams(['real_name' => '支付订单手动退款']);
            Route::post('/{payNo}/manual-success', [PayOrderController::class, 'manualSuccess'])->name('adminApiPayOrdersManualSuccess')->setParams(['real_name' => '支付订单手动补单']);
            Route::post('/{payNo}/freeze', [PayOrderController::class, 'freeze'])->name('adminApiPayOrdersFreeze')->setParams(['real_name' => '支付订单冻结']);
            Route::post('/{payNo}/unfreeze', [PayOrderController::class, 'unfreeze'])->name('adminApiPayOrdersUnfreeze')->setParams(['real_name' => '支付订单解冻']);
            Route::get('/{payNo}', [PayOrderController::class, 'show'])->name('adminApiPayOrdersShow')->setParams(['real_name' => '支付订单详情']);
        });

        Route::group('/refund-orders', function () {
            Route::get('', [RefundOrderController::class, 'index'])->name('adminApiRefundOrdersIndex')->setParams(['real_name' => '退款订单列表']);
            Route::get('/{refundNo}', [RefundOrderController::class, 'show'])->name('adminApiRefundOrdersShow')->setParams(['real_name' => '退款订单详情']);
            Route::post('/{refundNo}/retry', [RefundOrderController::class, 'retry'])->name('adminApiRefundOrdersRetry')->setParams(['real_name' => '退款重试']);
        });

        Route::group('/settlement-orders', function () {
            Route::get('', [SettlementOrderController::class, 'index'])->name('adminApiSettlementOrdersIndex')->setParams(['real_name' => '清算订单列表']);
            Route::post('/{settleNo}/complete', [SettlementOrderController::class, 'complete'])->name('adminApiSettlementOrdersComplete')->setParams(['real_name' => '清算订单入账']);
            Route::post('/{settleNo}/fail', [SettlementOrderController::class, 'markFailed'])->name('adminApiSettlementOrdersFail')->setParams(['real_name' => '清算订单冲正']);
            Route::get('/{settleNo}', [SettlementOrderController::class, 'show'])->name('adminApiSettlementOrdersShow')->setParams(['real_name' => '清算订单详情']);
        });

        // 运维与日志
        Route::group('/channel-daily-stats', function () {
            Route::get('', [ChannelDailyStatController::class, 'index'])->name('adminApiChannelDailyStatsIndex')->setParams(['real_name' => '渠道日统计列表']);
            Route::get('/{id}', [ChannelDailyStatController::class, 'show'])->name('adminApiChannelDailyStatsShow')->setParams(['real_name' => '渠道日统计详情']);
        });

        Route::group('/channel-notify-logs', function () {
            Route::get('', [ChannelNotifyLogController::class, 'index'])->name('adminApiChannelNotifyLogsIndex')->setParams(['real_name' => '渠道通知日志列表']);
            Route::get('/{id}', [ChannelNotifyLogController::class, 'show'])->name('adminApiChannelNotifyLogsShow')->setParams(['real_name' => '渠道通知日志详情']);
        });

        Route::group('/pay-callback-logs', function () {
            Route::get('', [PayCallbackLogController::class, 'index'])->name('adminApiPayCallbackLogsIndex')->setParams(['real_name' => '支付回调日志列表']);
            Route::get('/{id}', [PayCallbackLogController::class, 'show'])->name('adminApiPayCallbackLogsShow')->setParams(['real_name' => '支付回调日志详情']);
        });

        Route::group('/merchant-notify-tasks', function () {
            Route::get('', [MerchantNotifyTaskController::class, 'index'])->name('adminApiMerchantNotifyTasksIndex')->setParams(['real_name' => '商户通知任务列表']);
            Route::get('/{notifyNo}', [MerchantNotifyTaskController::class, 'show'])->name('adminApiMerchantNotifyTasksShow')->setParams(['real_name' => '商户通知任务详情']);
            Route::post('/{notifyNo}/retry', [MerchantNotifyTaskController::class, 'retry'])->name('adminApiMerchantNotifyTasksRetry')->setParams(['real_name' => '商户通知任务重试']);
        });

        // 资金账户
        Route::group('/merchant-accounts', function () {
            Route::get('', [MerchantAccountController::class, 'index'])->name('adminApiMerchantAccountsIndex')->setParams(['real_name' => '资金账户列表']);
            Route::get('/summary', [MerchantAccountController::class, 'summary'])->name('adminApiMerchantAccountsSummary')->setParams(['real_name' => '资金账户总览']);
            Route::get('/reconciliation', [MerchantAccountController::class, 'reconciliation'])->name('adminApiMerchantAccountsReconciliation')->setParams(['real_name' => '资金账户完整对账']);
            Route::get('/export', [MerchantAccountController::class, 'export'])->name('adminApiMerchantAccountsExport')->setParams(['real_name' => '资金账户导出']);
            Route::get('/{id}', [MerchantAccountController::class, 'show'])->name('adminApiMerchantAccountsShow')->setParams(['real_name' => '资金账户详情']);
        });

        Route::group('/account-ledgers', function () {
            Route::get('', [MerchantAccountLedgerController::class, 'index'])->name('adminApiAccountLedgersIndex')->setParams(['real_name' => '资金流水列表']);
            Route::get('/export', [MerchantAccountLedgerController::class, 'export'])->name('adminApiAccountLedgersExport')->setParams(['real_name' => '资金流水导出']);
            Route::get('/{id}', [MerchantAccountLedgerController::class, 'show'])->name('adminApiAccountLedgersShow')->setParams(['real_name' => '资金流水详情']);
        });

        Route::group('/fund-freezes', function () {
            Route::get('', [MerchantFundFreezeController::class, 'index'])->name('adminApiFundFreezesIndex')->setParams(['real_name' => '资金冻结明细列表']);
            Route::get('/reconciliation', [MerchantFundFreezeController::class, 'reconciliation'])->name('adminApiFundFreezesReconciliation')->setParams(['real_name' => '资金冻结对账摘要']);
            Route::get('/export', [MerchantFundFreezeController::class, 'export'])->name('adminApiFundFreezesExport')->setParams(['real_name' => '资金冻结明细导出']);
            Route::get('/{id}', [MerchantFundFreezeController::class, 'show'])->name('adminApiFundFreezesShow')->setParams(['real_name' => '资金冻结明细详情']);
        });

        // 系统管理
        Route::group('/admin-users', function () {
            Route::get('', [AdminUserController::class, 'index'])->name('adminApiAdminUsersIndex')->setParams(['real_name' => '管理员列表']);
            Route::post('', [AdminUserController::class, 'store'])->name('adminApiAdminUsersStore')->setParams(['real_name' => '新增管理员']);
            Route::get('/{id}', [AdminUserController::class, 'show'])->name('adminApiAdminUsersShow')->setParams(['real_name' => '管理员详情']);
            Route::put('/{id}', [AdminUserController::class, 'update'])->name('adminApiAdminUsersUpdate')->setParams(['real_name' => '更新管理员']);
            Route::delete('/{id}', [AdminUserController::class, 'destroy'])->name('adminApiAdminUsersDestroy')->setParams(['real_name' => '删除管理员']);
        });

        Route::group('/system', function () {
            Route::get('/menu-tree', [SystemController::class, 'menuTree'])->name('adminApiMenuTree')->setParams(['real_name' => '菜单树']);
            Route::get('/dict-items', [SystemController::class, 'dictItems'])->name('adminApiDictItems')->setParams(['real_name' => '字典项']);
            // 运行监控只暴露总览和白名单运维动作，命令安全校验统一放在服务层。
            Route::get('/ops/overview', [SystemOpsController::class, 'overview'])->name('adminApiSystemOpsOverview')->setParams(['real_name' => '运行监控总览']);
            Route::post('/ops/reload', [SystemOpsController::class, 'reload'])->name('adminApiSystemOpsReload')->setParams(['real_name' => '平滑重载服务']);
            Route::post('/ops/restart', [SystemOpsController::class, 'restart'])->name('adminApiSystemOpsRestart')->setParams(['real_name' => '重启服务']);
        });

        Route::group('/system-config-pages', function () {
            Route::get('', [SystemConfigPageController::class, 'index'])->name('adminApiSystemConfigPagesIndex')->setParams(['real_name' => '系统配置页面列表']);
            Route::get('/{groupCode}', [SystemConfigPageController::class, 'show'])->name('adminApiSystemConfigPagesShow')->setParams(['real_name' => '系统配置页面详情']);
            Route::post('/{groupCode}', [SystemConfigPageController::class, 'store'])->name('adminApiSystemConfigPagesStore')->setParams(['real_name' => '保存系统配置页面']);
        });
    })->middleware([AdminAuthMiddleware::class]);
})->middleware([Cors::class]);
