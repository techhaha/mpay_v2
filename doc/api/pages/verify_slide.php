<?php
if (!defined('IN_CRONLITE')) exit();

$html = '<form id="dopay" action="'.$siteurl.'submit.php" method="post">';
foreach ($query_arr as $k=>$v) {
    $html.= '<input type="hidden" name="'.$k.'" value="'.$v.'"/>';
}
$html .= '<input type="submit" value="Loading" style="display:none"></form>';
?>
<!DOCTYPE html>
<html>
<head>
	<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
	<title>支付环境安全验证</title>
	<style type="text/css">
body{font-family:"微软雅黑";height:auto!important;height:555px;min-height:555px;margin:0}
.container{margin:0 auto;margin-top:100px;background:#fff;text-align:center}
.header>p{margin:0;margin-top:24px;font-size:18px;line-height:1.7;color:#5d5d5d}
strong{color:#3190e6}
@media screen and (max-width:767px){.container{margin-top:10px}
.header>p{margin:0;padding:20px;font-size:20px;line-height:1.7;color:#5d5d5d}
}
@media screen and (max-width:320px){.container{margin-top:0}
.header>p{margin:0;padding:20px;font-size:18px;line-height:1.7;color:#5d5d5d}
}
	</style>
</head>
<body>
<div class="container">
    <div class="header">
    <p>
        很抱歉，当前支付人数过多，请完成<strong>“滑动验证”</strong>后继续支付
    </p>
    </div>
</div>
<?php echo $html?>
<script src="<?php echo $cdnpublic?>jquery/1.12.4/jquery.min.js"></script>
<script src="https://static.geetest.com/v4/gt4.js"></script>
<script>
window.appendChildOrg = Element.prototype.appendChild;
Element.prototype.appendChild = function() {
    if(arguments[0].tagName == 'SCRIPT'){
        arguments[0].setAttribute('referrerpolicy', 'no-referrer');
    }
    return window.appendChildOrg.apply(this, arguments);
};
initGeetest4({
    captchaId: "54088bb07d2df3c46b79f80300b0abbe",
    product: 'bind',
    protocol: 'https://',
    riskType: 'slide',
    hideSuccess: true
},function (captcha) {
    captcha.onReady(function(){
        captcha.showCaptcha();
    }).onSuccess(function(){
        var result = captcha.getValidate();
        result.pid = '<?php echo $query_arr['pid']?>';
        result.trade_no = '<?php echo $query_arr['out_trade_no']?>';
        $.ajax({
            url: 'getshop.php?act=captcha_verify',
            type: 'post',
            dataType: 'json',
            data: result,
            cache: false,
            success: function (data) {
                if(data.code == 0){
                    var elem = document.getElementById("dopay");
                    var input = document.createElement("input");  
                    input.type="hidden";  
                    input.name="__defend";
                    input.value=data.key;
                    elem.appendChild(input);
                    elem.submit();
                }else{
                    alert(data.msg);
                }
            },
            error: function () {
                alert('服务器错误');
            }
        });
    }).onError(function(){
        alert('验证码加载失败，请刷新页面重试');
    })
});
</script>
</body>
</html>