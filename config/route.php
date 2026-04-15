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
use support\Request;
use support\Response;

// 管理后台路由
require_once app_path('route/admin.php');

// 商户后台路由
require_once app_path('route/mer.php');

// 用户路由
require_once app_path('route/api.php');

// 预检路由
Route::options('[{path:.+}]', function (Request $request){
    $response = response('', 204);
    return $response->withHeaders([
        'Access-Control-Allow-Credentials' => 'true',
        'Access-Control-Allow-Origin' => $request->header('origin', '*'),
        'Access-Control-Allow-Methods' => $request->header('access-control-request-method', '*'),
        'Access-Control-Allow-Headers' => $request->header('access-control-request-headers', '*'),
    ]);
});

// 关闭默认路由
Route::disableDefaultRoute();





