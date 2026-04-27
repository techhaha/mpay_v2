<?php
/**
 * 退款
 */
require_once("lib/epay.config.php");
require_once("lib/EpayCore.class.php");

$out_refund_no = date("YmdHis").rand(111,999);
$trade_no = '2024071519404366151';
$money = '1.00';
$epay = new EpayCore($epay_config);
try{
    $result = $epay->refund($out_refund_no, $trade_no, $money);
}catch(Exception $e){
    echo $e->getMessage();
    exit;
}

print_r($result);