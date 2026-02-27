<?php
/**
 * 测试脚本
 */

// 加载自动加载文件和引导文件
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/support/bootstrap.php';

use app\services\SystemSettingService;

$systemSettingService = container_get(SystemSettingService::class);
$tabs = $systemSettingService->getTabs();
echo json_encode($tabs, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);