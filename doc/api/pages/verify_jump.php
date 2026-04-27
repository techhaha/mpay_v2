<?php
if (!defined('IN_CRONLITE')) exit();
$x = new \lib\hieroglyphy();
$key_enc = $x->hieroglyphyString($key);

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
<script>
    var key = <?php echo $key_enc;?>;
    window.onload=function(){
        var elem = document.getElementById("dopay");
        var input=document.createElement("input");  
        input.type="hidden";  
        input.name="__defend";
        input.value=key;
        elem.appendChild(input);
        elem.submit();
    }
</script>
</body>
</html>