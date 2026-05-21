<?php

use app\common\constant\EventConstant;
use app\listener\InstallCompletedListener;
use app\listener\PaymentChannelStatListener;
use app\listener\PaymentMerchantNotifyListener;
use app\listener\PaymentSettlementListener;
use app\listener\ReceiptWatcherListener;
use app\listener\SystemConfigChangedListener;

// 这里只注册当前启用的事件监听关系。
// EventConstant 中允许保留未注册的领域事件，作为后续扩展告警、风控、统计或补偿的挂载点。
return [
    EventConstant::SYSTEM_INSTALL_COMPLETED => [
        [InstallCompletedListener::class, 'requestRestart'],
    ],
    EventConstant::SYSTEM_CONFIG_CHANGED => [
        [SystemConfigChangedListener::class, 'refreshRuntimeCache'],
        [ReceiptWatcherListener::class, 'onConfigChanged'],
    ],
    EventConstant::PAYMENT_PAY_ORDER_SUCCEEDED => [
        [PaymentChannelStatListener::class, 'onPayOrderSucceeded'],
        [PaymentSettlementListener::class, 'onPayOrderSucceeded'],
        [PaymentMerchantNotifyListener::class, 'onPayOrderSucceeded'],
        [ReceiptWatcherListener::class, 'onPayOrderTerminated'],
    ],
    EventConstant::PAYMENT_PAY_ORDER_FAILED => [
        [PaymentChannelStatListener::class, 'onPayOrderFailed'],
        [ReceiptWatcherListener::class, 'onPayOrderTerminated'],
    ],
    EventConstant::PAYMENT_PAY_ORDER_CLOSED => [
        [PaymentChannelStatListener::class, 'onPayOrderFailed'],
        [ReceiptWatcherListener::class, 'onPayOrderTerminated'],
    ],
    EventConstant::PAYMENT_PAY_ORDER_TIMEOUT => [
        [PaymentChannelStatListener::class, 'onPayOrderFailed'],
        [ReceiptWatcherListener::class, 'onPayOrderTerminated'],
    ],
    EventConstant::PAYMENT_RECEIPT_WATCHER_CONFIG_CHANGED => [ReceiptWatcherListener::class, 'onConfigChanged'],
    EventConstant::REFUND_ORDER_SUCCEEDED => [
        [PaymentChannelStatListener::class, 'onRefundOrderSucceeded'],
        [PaymentMerchantNotifyListener::class, 'onRefundOrderSucceeded'],
    ],
    EventConstant::SETTLEMENT_ORDER_SUCCEEDED => [PaymentMerchantNotifyListener::class, 'onSettlementOrderSucceeded'],
];
