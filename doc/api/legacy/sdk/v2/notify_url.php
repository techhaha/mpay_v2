<?php
/* *
 * 功能：彩虹易支付异步通知页面
 * 说明：
 * 以下代码只是为了方便商户测试而提供的样例代码，商户可以根据自己网站的需要，按照技术文档编写,并非一定要使用该代码。
 */

require_once("lib/epay.config.php");
require_once("lib/EpayCore.class.php");

//计算得出通知验证结果
$epay = new EpayCore($epay_config);
$verify_result = $epay->verify($_GET);

if($verify_result) {//验证成功

	//商户订单号
	$out_trade_no = $_GET['out_trade_no'];

	//彩虹易支付交易号
	$trade_no = $_GET['trade_no'];

	//交易状态
	$trade_status = $_GET['trade_status'];

	//支付方式
	$type = $_GET['type'];

	//支付金额
	$money = $_GET['money'];

	if ($_GET['trade_status'] == 'TRADE_SUCCESS') {
		//判断该笔订单是否在商户网站中已经做过处理
		//如果没有做过处理，根据订单号（out_trade_no）在商户网站的订单系统中查到该笔订单的详细，并执行商户的业务程序
		//如果有做过处理，不执行商户的业务程序
	}

	//验证成功返回
	echo "success";
}
else {
	//验证失败
	echo "fail";
}
?>