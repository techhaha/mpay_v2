<?php
/**
 * 查询订单
 */
require_once("lib/epay.config.php");
require_once("lib/EpayCore.class.php");

$trade_no = '2024071519404366151';
$epay = new EpayCore($epay_config);
try{
    $result = $epay->queryOrder($trade_no);
}catch(Exception $e){
    echo $e->getMessage();
    exit;
}

print_r($result);