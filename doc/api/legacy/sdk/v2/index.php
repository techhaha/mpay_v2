<!DOCTYPE html>
<html lang="zh-CN">
<body>
<head>
	<meta charset="utf-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1" />
	<title>彩虹易支付接口测试</title>
    <link href="//lf26-cdn-tos.bytecdntp.com/cdn/expire-1-M/twitter-bootstrap/3.4.1/css/bootstrap.min.css" rel="stylesheet"/>
	<link rel="stylesheet" href="./assets/css/captcha.css" type="text/css" />
	<style>.form-group{margin-bottom:18px} #captcha{margin: auto;margin-bottom:16px}</style>
</head>
<div class="container">
<div class="col-xs-12 col-sm-10 col-lg-8 center-block" style="float: none;">
<div class="page-header">
  <h4>彩虹易支付接口测试</h4>
</div>
<div class="panel panel-primary">
<div class="panel-body">

<form name="alipayment" method="POST" action="epayapi.php" class="form-horizontal">
    <div class="form-group">
        <label class="col-sm-3 control-label">商户订单号</label>
        <div class="col-sm-8">
            <input class="form-control" type="text" name="out_trade_no" value="<?php echo date("YmdHis").mt_rand(100,999); ?>" autocomplete="off">
        </div>
    </div>
    <div class="form-group">
        <label class="col-sm-3 control-label">商品名称</label>
        <div class="col-sm-8">
            <input class="form-control" type="text" name="name" value="支付测试" autocomplete="off">
        </div>
    </div>
    <div class="form-group">
        <label class="col-sm-3 control-label">支付金额</label>
        <div class="col-sm-8">
            <input class="form-control" type="text" name="money" value="1" autocomplete="off">
        </div>
    </div>
    <div class="form-group">
        <label class="col-sm-3 control-label">支付方式</label>
        <div class="col-sm-8">
            <div class="radio">
                <label class="i-checks"><input type="radio" name="type" value="alipay" checked="checked"><i></i>支付宝</label>&nbsp;
                <label class="i-checks"><input type="radio" name="type" value="wxpay"><i></i>微信支付</label>&nbsp;
                <label class="i-checks"><input type="radio" name="type" value="qqpay"><i></i>QQ钱包</label>&nbsp;
                <label class="i-checks"><input type="radio" name="type" value="bank"><i></i>云闪付</label>&nbsp;
            </div>
        </div>
    </div>
    <div class="form-group">
        <div class="col-sm-offset-3 col-sm-8"><input type="submit" value="确 认" class="btn btn-primary form-control"><br>
        </div>
    </div>
</form>
</div>
<div class="panel-footer text-center">
<span class="text-muted">此页面只是为了方便商户测试而提供的样例页面，商户可以根据自己网站的需要，按照技术文档编写</span>
</div>
</div>
</div>
</div>