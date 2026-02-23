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

// 匹配所有options路由（CORS 预检请求）
Route::options('[{path:.+}]', function (Request $request){
    $response = response('', 204);
    return $response->withHeaders([
        'Access-Control-Allow-Credentials' => 'true',
        'Access-Control-Allow-Origin' => $request->header('origin', '*'),
        'Access-Control-Allow-Methods' => $request->header('access-control-request-method', '*'),
        'Access-Control-Allow-Headers' => $request->header('access-control-request-headers', '*'),
    ]);
});

// 管理后台路由
require_once base_path() . '/app/routes/admin.php';

// API 路由
require_once base_path() . '/app/routes/api.php';

/**
 * 关闭默认路由
 */
Route::disableDefaultRoute();
