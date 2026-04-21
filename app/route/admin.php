<?php

use Webman\Route;
use app\common\middleware\Cors;
use app\http\admin\controller\system\AdminUserController;
use app\http\admin\controller\system\AuthController;
use app\http\admin\controller\account\MerchantAccountController;
use app\http\admin\controller\account\MerchantAccountLedgerController;
use app\http\admin\controller\ops\ChannelDailyStatController;
use app\http\admin\controller\ops\ChannelNotifyLogController;
use app\http\admin\controller\merchant\MerchantController;
use app\http\admin\controller\merchant\MerchantApiCredentialController;
use app\http\admin\controller\merchant\MerchantGroupController;
use app\http\admin\controller\merchant\MerchantPolicyController;
use app\http\admin\controller\payment\PaymentChannelController;
use app\http\admin\controller\payment\PaymentPluginController;
use app\http\admin\controller\payment\PaymentPluginConfController;
use app\http\admin\controller\payment\PaymentPollGroupController;
use app\http\admin\controller\payment\PaymentPollGroupBindController;
use app\http\admin\controller\payment\PaymentPollGroupChannelController;
use app\http\admin\controller\payment\PaymentTypeController;
use app\http\admin\controller\payment\RouteController;
use app\http\admin\controller\trade\PayOrderController;
use app\http\admin\controller\ops\PayCallbackLogController;
use app\http\admin\controller\file\FileRecordController;
use app\http\admin\controller\trade\RefundOrderController;
use app\http\admin\controller\trade\SettlementOrderController;
use app\http\admin\controller\system\SystemConfigPageController;
use app\http\admin\controller\system\SystemController;
use app\http\admin\middleware\AdminAuthMiddleware;

Route::any('/admin[/{path:.+}]', function () {
    return view('/public/admin/index');
});

Route::group('/adminapi', function () {
    Route::post('/login', [AuthController::class, 'login'])->name('adminApiAuthLogin')->setParams(['real_name' => '管理员登录']);

    Route::group('', function () {
        Route::post('/logout', [AuthController::class, 'logout'])->name('adminApiAuthLogout')->setParams(['real_name' => '退出登录']);
        Route::get('/user/profile', [AuthController::class, 'profile'])->name('adminApiUserProfile')->setParams(['real_name' => '当前用户资料']);

        Route::get('/merchants', [MerchantController::class, 'index'])->name('adminApiMerchantsIndex')->setParams(['real_name' => '商户列表']);
        Route::get('/merchants/options', [MerchantController::class, 'options'])->name('adminApiMerchantsOptions')->setParams(['real_name' => '商户选项']);
        Route::get('/merchants/select-options', [MerchantController::class, 'selectOptions'])->name('adminApiMerchantsSelectOptions')->setParams(['real_name' => '商户选择选项']);
        Route::get('/merchants/{id}', [MerchantController::class, 'show'])->name('adminApiMerchantsShow')->setParams(['real_name' => '商户详情']);
        Route::get('/merchants/{id}/overview', [MerchantController::class, 'overview'])->name('adminApiMerchantsOverview')->setParams(['real_name' => '商户总览']);
        Route::post('/merchants', [MerchantController::class, 'store'])->name('adminApiMerchantsStore')->setParams(['real_name' => '新增商户']);
        Route::put('/merchants/{id}', [MerchantController::class, 'update'])->name('adminApiMerchantsUpdate')->setParams(['real_name' => '更新商户']);
        Route::delete('/merchants/{id}', [MerchantController::class, 'destroy'])->name('adminApiMerchantsDestroy')->setParams(['real_name' => '删除商户']);
        Route::post('/merchants/{id}/reset-password', [MerchantController::class, 'resetPassword'])->name('adminApiMerchantsResetPassword')->setParams(['real_name' => '重置商户密码']);
        Route::post('/merchants/{id}/issue-credential', [MerchantController::class, 'issueCredential'])->name('adminApiMerchantsIssueCredential')->setParams(['real_name' => '生成或重置商户 API 凭证']);

        Route::get('/admin-users', [AdminUserController::class, 'index'])->name('adminApiAdminUsersIndex')->setParams(['real_name' => '管理员列表']);
        Route::get('/admin-users/{id}', [AdminUserController::class, 'show'])->name('adminApiAdminUsersShow')->setParams(['real_name' => '管理员详情']);
        Route::post('/admin-users', [AdminUserController::class, 'store'])->name('adminApiAdminUsersStore')->setParams(['real_name' => '新增管理员']);
        Route::put('/admin-users/{id}', [AdminUserController::class, 'update'])->name('adminApiAdminUsersUpdate')->setParams(['real_name' => '更新管理员']);
        Route::delete('/admin-users/{id}', [AdminUserController::class, 'destroy'])->name('adminApiAdminUsersDestroy')->setParams(['real_name' => '删除管理员']);


        Route::get('/merchant-api-credentials', [MerchantApiCredentialController::class, 'index'])->name('adminApiMerchantApiCredentialsIndex')->setParams(['real_name' => '商户 API 凭证列表']);
        Route::get('/merchant-api-credentials/{id}', [MerchantApiCredentialController::class, 'show'])->name('adminApiMerchantApiCredentialsShow')->setParams(['real_name' => '商户 API 凭证详情']);
        Route::post('/merchant-api-credentials', [MerchantApiCredentialController::class, 'store'])->name('adminApiMerchantApiCredentialsStore')->setParams(['real_name' => '开通商户 API 凭证']);
        Route::put('/merchant-api-credentials/{id}', [MerchantApiCredentialController::class, 'update'])->name('adminApiMerchantApiCredentialsUpdate')->setParams(['real_name' => '更新商户 API 凭证']);
        Route::delete('/merchant-api-credentials/{id}', [MerchantApiCredentialController::class, 'destroy'])->name('adminApiMerchantApiCredentialsDestroy')->setParams(['real_name' => '删除商户 API 凭证']);

        Route::get('/merchant-groups', [MerchantGroupController::class, 'index'])->name('adminApiMerchantGroupsIndex')->setParams(['real_name' => '商户分组列表']);
        Route::get('/merchant-groups/options', [MerchantGroupController::class, 'options'])->name('adminApiMerchantGroupsOptions')->setParams(['real_name' => '商户分组选项']);
        Route::get('/merchant-groups/{id}', [MerchantGroupController::class, 'show'])->name('adminApiMerchantGroupsShow')->setParams(['real_name' => '商户分组详情']);
        Route::post('/merchant-groups', [MerchantGroupController::class, 'store'])->name('adminApiMerchantGroupsStore')->setParams(['real_name' => '新增商户分组']);
        Route::put('/merchant-groups/{id}', [MerchantGroupController::class, 'update'])->name('adminApiMerchantGroupsUpdate')->setParams(['real_name' => '更新商户分组']);
        Route::delete('/merchant-groups/{id}', [MerchantGroupController::class, 'destroy'])->name('adminApiMerchantGroupsDestroy')->setParams(['real_name' => '删除商户分组']);

        Route::get('/merchant-policies', [MerchantPolicyController::class, 'index'])->name('adminApiMerchantPoliciesIndex')->setParams(['real_name' => '商户策略列表']);
        Route::get('/merchant-policies/{merchantId}', [MerchantPolicyController::class, 'show'])->name('adminApiMerchantPoliciesShow')->setParams(['real_name' => '商户策略详情']);
        Route::post('/merchant-policies', [MerchantPolicyController::class, 'store'])->name('adminApiMerchantPoliciesStore')->setParams(['real_name' => '新增商户策略']);
        Route::put('/merchant-policies/{merchantId}', [MerchantPolicyController::class, 'update'])->name('adminApiMerchantPoliciesUpdate')->setParams(['real_name' => '更新商户策略']);
        Route::delete('/merchant-policies/{merchantId}', [MerchantPolicyController::class, 'destroy'])->name('adminApiMerchantPoliciesDestroy')->setParams(['real_name' => '删除商户策略']);

        Route::get('/payment-types', [PaymentTypeController::class, 'index'])->name('adminApiPaymentTypesIndex')->setParams(['real_name' => '支付方式列表']);
        Route::get('/payment-types/options', [PaymentTypeController::class, 'options'])->name('adminApiPaymentTypesOptions')->setParams(['real_name' => '支付方式选项']);
        Route::get('/payment-types/{id}', [PaymentTypeController::class, 'show'])->name('adminApiPaymentTypesShow')->setParams(['real_name' => '支付方式详情']);
        Route::post('/payment-types', [PaymentTypeController::class, 'store'])->name('adminApiPaymentTypesStore')->setParams(['real_name' => '新增支付方式']);
        Route::put('/payment-types/{id}', [PaymentTypeController::class, 'update'])->name('adminApiPaymentTypesUpdate')->setParams(['real_name' => '更新支付方式']);
        Route::delete('/payment-types/{id}', [PaymentTypeController::class, 'destroy'])->name('adminApiPaymentTypesDestroy')->setParams(['real_name' => '删除支付方式']);

        Route::get('/payment-plugins', [PaymentPluginController::class, 'index'])->name('adminApiPaymentPluginsIndex')->setParams(['real_name' => '支付插件列表']);
        Route::get('/payment-plugins/options', [PaymentPluginController::class, 'options'])->name('adminApiPaymentPluginsOptions')->setParams(['real_name' => '支付插件选项']);
        Route::get('/payment-plugins/select-options', [PaymentPluginController::class, 'selectOptions'])->name('adminApiPaymentPluginsSelectOptions')->setParams(['real_name' => '支付插件选择项']);
        Route::get('/payment-plugins/channel-options', [PaymentPluginController::class, 'channelOptions'])->name('adminApiPaymentPluginsChannelOptions')->setParams(['real_name' => '支付插件通道选项']);
        Route::get('/payment-plugins/{code}/schema', [PaymentPluginController::class, 'schema'])->name('adminApiPaymentPluginsSchema')->setParams(['real_name' => '支付插件配置结构']);
        Route::get('/payment-plugins/{code}', [PaymentPluginController::class, 'show'])->name('adminApiPaymentPluginsShow')->setParams(['real_name' => '支付插件详情']);
        Route::post('/payment-plugins/refresh', [PaymentPluginController::class, 'refresh'])->name('adminApiPaymentPluginsRefresh')->setParams(['real_name' => '刷新支付插件']);
        Route::put('/payment-plugins/{code}', [PaymentPluginController::class, 'update'])->name('adminApiPaymentPluginsUpdate')->setParams(['real_name' => '更新支付插件']);

        Route::get('/payment-plugin-confs', [PaymentPluginConfController::class, 'index'])->name('adminApiPaymentPluginConfsIndex')->setParams(['real_name' => '支付插件配置列表']);
        Route::get('/payment-plugin-confs/options', [PaymentPluginConfController::class, 'options'])->name('adminApiPaymentPluginConfsOptions')->setParams(['real_name' => '支付插件配置选项']);
        Route::get('/payment-plugin-confs/select-options', [PaymentPluginConfController::class, 'selectOptions'])->name('adminApiPaymentPluginConfsSelectOptions')->setParams(['real_name' => '支付插件配置选择项']);
        Route::get('/payment-plugin-confs/{id}', [PaymentPluginConfController::class, 'show'])->name('adminApiPaymentPluginConfsShow')->setParams(['real_name' => '支付插件配置详情']);
        Route::post('/payment-plugin-confs', [PaymentPluginConfController::class, 'store'])->name('adminApiPaymentPluginConfsStore')->setParams(['real_name' => '新增支付插件配置']);
        Route::put('/payment-plugin-confs/{id}', [PaymentPluginConfController::class, 'update'])->name('adminApiPaymentPluginConfsUpdate')->setParams(['real_name' => '更新支付插件配置']);
        Route::delete('/payment-plugin-confs/{id}', [PaymentPluginConfController::class, 'destroy'])->name('adminApiPaymentPluginConfsDestroy')->setParams(['real_name' => '删除支付插件配置']);

        Route::get('/payment-channels', [PaymentChannelController::class, 'index'])->name('adminApiPaymentChannelsIndex')->setParams(['real_name' => '支付通道列表']);
        Route::get('/payment-channels/options', [PaymentChannelController::class, 'options'])->name('adminApiPaymentChannelsOptions')->setParams(['real_name' => '支付通道选项']);
        Route::get('/payment-channels/select-options', [PaymentChannelController::class, 'selectOptions'])->name('adminApiPaymentChannelsSelectOptions')->setParams(['real_name' => '支付通道选择项']);
        Route::get('/payment-channels/route-options', [PaymentChannelController::class, 'routeOptions'])->name('adminApiPaymentChannelsRouteOptions')->setParams(['real_name' => '支付通道路由选项']);
        Route::get('/payment-channels/{id}', [PaymentChannelController::class, 'show'])->name('adminApiPaymentChannelsShow')->setParams(['real_name' => '支付通道详情']);
        Route::post('/payment-channels', [PaymentChannelController::class, 'store'])->name('adminApiPaymentChannelsStore')->setParams(['real_name' => '新增支付通道']);
        Route::put('/payment-channels/{id}', [PaymentChannelController::class, 'update'])->name('adminApiPaymentChannelsUpdate')->setParams(['real_name' => '更新支付通道']);
        Route::delete('/payment-channels/{id}', [PaymentChannelController::class, 'destroy'])->name('adminApiPaymentChannelsDestroy')->setParams(['real_name' => '删除支付通道']);

        Route::get('/payment-poll-groups', [PaymentPollGroupController::class, 'index'])->name('adminApiPaymentPollGroupsIndex')->setParams(['real_name' => '轮询组列表']);
        Route::get('/payment-poll-groups/options', [PaymentPollGroupController::class, 'options'])->name('adminApiPaymentPollGroupsOptions')->setParams(['real_name' => '轮询组选项']);
        Route::get('/payment-poll-groups/{id}', [PaymentPollGroupController::class, 'show'])->name('adminApiPaymentPollGroupsShow')->setParams(['real_name' => '轮询组详情']);
        Route::post('/payment-poll-groups', [PaymentPollGroupController::class, 'store'])->name('adminApiPaymentPollGroupsStore')->setParams(['real_name' => '新增轮询组']);
        Route::put('/payment-poll-groups/{id}', [PaymentPollGroupController::class, 'update'])->name('adminApiPaymentPollGroupsUpdate')->setParams(['real_name' => '更新轮询组']);
        Route::delete('/payment-poll-groups/{id}', [PaymentPollGroupController::class, 'destroy'])->name('adminApiPaymentPollGroupsDestroy')->setParams(['real_name' => '删除轮询组']);

        Route::get('/payment-poll-group-channels', [PaymentPollGroupChannelController::class, 'index'])->name('adminApiPaymentPollGroupChannelsIndex')->setParams(['real_name' => '轮询组通道列表']);
        Route::get('/payment-poll-group-channels/{id}', [PaymentPollGroupChannelController::class, 'show'])->name('adminApiPaymentPollGroupChannelsShow')->setParams(['real_name' => '轮询组通道详情']);
        Route::post('/payment-poll-group-channels', [PaymentPollGroupChannelController::class, 'store'])->name('adminApiPaymentPollGroupChannelsStore')->setParams(['real_name' => '新增轮询组通道']);
        Route::put('/payment-poll-group-channels/{id}', [PaymentPollGroupChannelController::class, 'update'])->name('adminApiPaymentPollGroupChannelsUpdate')->setParams(['real_name' => '更新轮询组通道']);
        Route::delete('/payment-poll-group-channels/{id}', [PaymentPollGroupChannelController::class, 'destroy'])->name('adminApiPaymentPollGroupChannelsDestroy')->setParams(['real_name' => '删除轮询组通道']);

        Route::get('/payment-poll-group-binds', [PaymentPollGroupBindController::class, 'index'])->name('adminApiPaymentPollGroupBindsIndex')->setParams(['real_name' => '轮询组绑定列表']);
        Route::get('/payment-poll-group-binds/{id}', [PaymentPollGroupBindController::class, 'show'])->name('adminApiPaymentPollGroupBindsShow')->setParams(['real_name' => '轮询组绑定详情']);
        Route::post('/payment-poll-group-binds', [PaymentPollGroupBindController::class, 'store'])->name('adminApiPaymentPollGroupBindsStore')->setParams(['real_name' => '新增轮询组绑定']);
        Route::put('/payment-poll-group-binds/{id}', [PaymentPollGroupBindController::class, 'update'])->name('adminApiPaymentPollGroupBindsUpdate')->setParams(['real_name' => '更新轮询组绑定']);
        Route::delete('/payment-poll-group-binds/{id}', [PaymentPollGroupBindController::class, 'destroy'])->name('adminApiPaymentPollGroupBindsDestroy')->setParams(['real_name' => '删除轮询组绑定']);

        Route::get('/routes/resolve', [RouteController::class, 'resolve'])->name('adminApiRoutesResolve')->setParams(['real_name' => '解析路由']);

        Route::get('/channel-daily-stats', [ChannelDailyStatController::class, 'index'])->name('adminApiChannelDailyStatsIndex')->setParams(['real_name' => '渠道日统计列表']);
        Route::get('/channel-daily-stats/{id}', [ChannelDailyStatController::class, 'show'])->name('adminApiChannelDailyStatsShow')->setParams(['real_name' => '渠道日统计详情']);

        Route::get('/file-asset/options', [FileRecordController::class, 'options'])->name('adminApiFileRecordOptions')->setParams(['real_name' => '文件选项']);
        Route::get('/file-asset', [FileRecordController::class, 'index'])->name('adminApiFileRecordIndex')->setParams(['real_name' => '文件列表']);
        Route::post('/file-asset/upload', [FileRecordController::class, 'upload'])->name('adminApiFileRecordUpload')->setParams(['real_name' => '上传文件']);
        Route::post('/file-asset/import-remote', [FileRecordController::class, 'importRemote'])->name('adminApiFileRecordImportRemote')->setParams(['real_name' => '导入远程文件']);
        Route::get('/file-asset/{id}/preview', [FileRecordController::class, 'preview'])->name('adminApiFileRecordPreview')->setParams(['real_name' => '文件预览']);
        Route::get('/file-asset/{id}/download', [FileRecordController::class, 'download'])->name('adminApiFileRecordDownload')->setParams(['real_name' => '文件下载']);
        Route::get('/file-asset/{id}', [FileRecordController::class, 'show'])->name('adminApiFileRecordShow')->setParams(['real_name' => '文件详情']);
        Route::delete('/file-asset/{id}', [FileRecordController::class, 'destroy'])->name('adminApiFileRecordDestroy')->setParams(['real_name' => '删除文件']);

        Route::get('/pay-orders', [PayOrderController::class, 'index'])->name('adminApiPayOrdersIndex')->setParams(['real_name' => '支付订单列表']);
        Route::get('/refund-orders', [RefundOrderController::class, 'index'])->name('adminApiRefundOrdersIndex')->setParams(['real_name' => '退款订单列表']);
        Route::get('/refund-orders/{refundNo}', [RefundOrderController::class, 'show'])->name('adminApiRefundOrdersShow')->setParams(['real_name' => '退款订单详情']);
        Route::post('/refund-orders/{refundNo}/retry', [RefundOrderController::class, 'retry'])->name('adminApiRefundOrdersRetry')->setParams(['real_name' => '退款重试']);

        Route::get('/settlement-orders', [SettlementOrderController::class, 'index'])->name('adminApiSettlementOrdersIndex')->setParams(['real_name' => '清算订单列表']);
        Route::get('/settlement-orders/{settleNo}', [SettlementOrderController::class, 'show'])->name('adminApiSettlementOrdersShow')->setParams(['real_name' => '清算订单详情']);

        Route::get('/channel-notify-logs', [ChannelNotifyLogController::class, 'index'])->name('adminApiChannelNotifyLogsIndex')->setParams(['real_name' => '渠道通知日志列表']);
        Route::get('/channel-notify-logs/{id}', [ChannelNotifyLogController::class, 'show'])->name('adminApiChannelNotifyLogsShow')->setParams(['real_name' => '渠道通知日志详情']);

        Route::get('/pay-callback-logs', [PayCallbackLogController::class, 'index'])->name('adminApiPayCallbackLogsIndex')->setParams(['real_name' => '支付回调日志列表']);
        Route::get('/pay-callback-logs/{id}', [PayCallbackLogController::class, 'show'])->name('adminApiPayCallbackLogsShow')->setParams(['real_name' => '支付回调日志详情']);

        Route::get('/merchant-accounts', [MerchantAccountController::class, 'index'])->name('adminApiMerchantAccountsIndex')->setParams(['real_name' => '资金账户列表']);
        Route::get('/merchant-accounts/summary', [MerchantAccountController::class, 'summary'])->name('adminApiMerchantAccountsSummary')->setParams(['real_name' => '资金账户总览']);
        Route::get('/merchant-accounts/{id}', [MerchantAccountController::class, 'show'])->name('adminApiMerchantAccountsShow')->setParams(['real_name' => '资金账户详情']);

        Route::get('/account-ledgers', [MerchantAccountLedgerController::class, 'index'])->name('adminApiAccountLedgersIndex')->setParams(['real_name' => '资金流水列表']);
        Route::get('/account-ledgers/{id}', [MerchantAccountLedgerController::class, 'show'])->name('adminApiAccountLedgersShow')->setParams(['real_name' => '资金流水详情']);

        Route::get('/system/menu-tree', [SystemController::class, 'menuTree'])->name('adminApiMenuTree')->setParams(['real_name' => '菜单树']);
        Route::get('/system/dict-items', [SystemController::class, 'dictItems'])->name('adminApiDictItems')->setParams(['real_name' => '字典项']);

        Route::get('/system-config-pages', [SystemConfigPageController::class, 'index'])->name('adminApiSystemConfigPagesIndex')->setParams(['real_name' => '系统配置页面列表']);
        Route::get('/system-config-pages/{groupCode}', [SystemConfigPageController::class, 'show'])->name('adminApiSystemConfigPagesShow')->setParams(['real_name' => '系统配置页面详情']);
        Route::post('/system-config-pages/{groupCode}', [SystemConfigPageController::class, 'store'])->name('adminApiSystemConfigPagesStore')->setParams(['real_name' => '保存系统配置页面']);

    })->middleware([AdminAuthMiddleware::class]);
})->middleware([Cors::class]);
