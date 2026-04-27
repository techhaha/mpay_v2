<?php
if (!defined('IN_CRONLITE')) exit();

$html = '<form id="dopay" action="'.$siteurl.'submit.php" method="post">';
foreach ($query_arr as $k=>$v) {
    $html.= '<input type="hidden" name="'.$k.'" value="'.$v.'"/>';
}
$html .= '<input type="submit" value="Loading"></form>';
?>
<!DOCTYPE html>
<html>
<head>
	<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
	<title>正在进行支付安全验证，请稍候...</title>
	<style type="text/css">
body{margin:0;padding:0}
#waiting{position:absolute;left:50%;top:50%;height:35px;margin:-35px 0 0 -160px;padding:20px;font:16px/30px "Helvetica Neue",Helvetica,Arial,sans-serif;background:#f9fafc url(/assets/img/loading.gif) no-repeat 20px 20px;text-indent:40px;border:1px solid #c5d0dc}
	</style>
</head>
<body>
<p id="waiting">正在进行支付安全验证，请稍候...</p>
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
    captchaId: "99b142aaece96330d0f3ffb565ffb3ef",
    product: 'bind',
    protocol: 'https://',
    riskType: 'ai',
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