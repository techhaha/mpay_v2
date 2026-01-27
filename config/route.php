<?php

/**
 * This file is part of webman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author    walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link      http://www.workerman.net/
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */

use Webman\Route;
use support\Response;
use support\Request;

// 管理后台路由
require_once base_path() . '/app/routes/admin.php';



// 默认路由兜底
Route::fallback(function (Request $request) {
    // 处理预检请求
    if (strtoupper($request->method()) === 'OPTIONS') {
        $response = response('', 204);
        $response->withHeaders([
            'Access-Control-Allow-Credentials' => 'true',
            'Access-Control-Max-Age' => '86400',
            'Access-Control-Allow-Origin' => $request->header('origin', '*'),
            'Access-Control-Allow-Methods' => $request->header('access-control-request-method', '*'),
            'Access-Control-Allow-Headers' => $request->header('access-control-request-headers', '*'),
        ]);
        return $response;
    }
});

/**
 * 关闭默认路由
 */
Route::disableDefaultRoute();
