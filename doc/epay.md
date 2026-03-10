协议规则
请求数据格式：application/x-www-form-urlencoded

返回数据格式：JSON

签名算法：MD5

字符编码：UTF-8

页面跳转支付
此接口可用于用户前台直接发起支付，使用form表单跳转或拼接成url跳转。

URL地址：http://192.168.31.200:4000/submit.php

请求方式：POST 或 GET（推荐POST，不容易被劫持或屏蔽）

请求参数说明：

字段名	变量名	必填	类型	示例值	描述
商户ID	pid	是	Int	1001	
支付方式	type	否	String	alipay	支付方式列表
商户订单号	out_trade_no	是	String	20160806151343349	
异步通知地址	notify_url	是	String	http://www.pay.com/notify_url.php	服务器异步通知地址
跳转通知地址	return_url	是	String	http://www.pay.com/return_url.php	页面跳转通知地址
商品名称	name	是	String	VIP会员	如超过127个字节会自动截取
商品金额	money	是	String	1.00	单位：元，最大2位小数
业务扩展参数	param	否	String	没有请留空	支付后原样返回
签名字符串	sign	是	String	202cb962ac59075b964b07152d234b70	签名算法点此查看
签名类型	sign_type	是	String	MD5	默认为MD5
支付方式（type）不传会跳转到收银台支付

API接口支付
此接口可用于服务器后端发起支付请求，会返回支付二维码链接或支付跳转url。

URL地址：http://192.168.31.200:4000/mapi.php

请求方式：POST

请求参数说明：

字段名	变量名	必填	类型	示例值	描述
商户ID	pid	是	Int	1001	
支付方式	type	是	String	alipay	支付方式列表
商户订单号	out_trade_no	是	String	20160806151343349	
异步通知地址	notify_url	是	String	http://www.pay.com/notify_url.php	服务器异步通知地址
跳转通知地址	return_url	否	String	http://www.pay.com/return_url.php	页面跳转通知地址
商品名称	name	是	String	VIP会员	如超过127个字节会自动截取
商品金额	money	是	String	1.00	单位：元，最大2位小数
用户IP地址	clientip	是	String	192.168.1.100	用户发起支付的IP地址
设备类型	device	否	String	pc	根据用户浏览器的UA判断，
传入用户所使用的浏览器
或设备类型，默认为pc
设备类型列表
业务扩展参数	param	否	String	没有请留空	支付后原样返回
签名字符串	sign	是	String	202cb962ac59075b964b07152d234b70	签名算法点此查看
签名类型	sign_type	是	String	MD5	默认为MD5
返回结果（json）：

字段名	变量名	类型	示例值	描述
返回状态码	code	Int	1	1为成功，其它值为失败
返回信息	msg	String		失败时返回原因
订单号	trade_no	String	20160806151343349	支付订单号
支付跳转url	payurl	String	http://192.168.31.200:4000/pay/wxpay/202010903/	如果返回该字段，则直接跳转到该url支付
二维码链接	qrcode	String	weixin://wxpay/bizpayurl?pr=04IPMKM	如果返回该字段，则根据该url生成二维码
小程序跳转url	urlscheme	String	weixin://dl/business/?ticket=xxx	如果返回该字段，则使用js跳转该url，可发起微信小程序支付
注：payurl、qrcode、urlscheme 三个参数只会返回其中一个

支付结果通知
通知类型：服务器异步通知（notify_url）、页面跳转通知（return_url）

请求方式：GET

请求参数说明：

字段名	变量名	必填	类型	示例值	描述
商户ID	pid	是	Int	1001	
易支付订单号	trade_no	是	String	20160806151343349021	聚合支付平台订单号
商户订单号	out_trade_no	是	String	20160806151343349	商户系统内部的订单号
支付方式	type	是	String	alipay	支付方式列表
商品名称	name	是	String	VIP会员	
商品金额	money	是	String	1.00	
支付状态	trade_status	是	String	TRADE_SUCCESS	只有TRADE_SUCCESS是成功
业务扩展参数	param	否	String		
签名字符串	sign	是	String	202cb962ac59075b964b07152d234b70	签名算法点此查看
签名类型	sign_type	是	String	MD5	默认为MD5
收到异步通知后，需返回success以表示服务器接收到了订单通知

MD5签名算法
1、将发送或接收到的所有参数按照参数名ASCII码从小到大排序（a-z），sign、sign_type、和空值不参与签名！

2、将排序后的参数拼接成URL键值对的格式，例如 a=b&c=d&e=f，参数值不要进行url编码。

3、再将拼接好的字符串与商户密钥KEY进行MD5加密得出sign签名参数，sign = md5 ( a=b&c=d&e=f + KEY ) （注意：+ 为各语言的拼接符，不是字符！），md5结果为小写。

4、具体签名与发起支付的示例代码可下载SDK查看。

支付方式列表
调用值	描述
alipay	支付宝
wxpay	微信支付
qqpay	QQ钱包
设备类型列表
调用值	描述
pc	电脑浏览器
mobile	手机浏览器
qq	手机QQ内浏览器
wechat	微信内浏览器
alipay	支付宝客户端
jump	仅返回支付跳转url
[API]查询商户信息
URL地址：http://192.168.31.200:4000/api.php?act=query&pid={商户ID}&key={商户密钥}

请求参数说明：

字段名	变量名	必填	类型	示例值	描述
操作类型	act	是	String	query	此API固定值
商户ID	pid	是	Int	1001	
商户密钥	key	是	String	89unJUB8HZ54Hj7x4nUj56HN4nUzUJ8i	
返回结果：

字段名	变量名	类型	示例值	描述
返回状态码	code	Int	1	1为成功，其它值为失败
商户ID	pid	Int	1001	商户ID
商户密钥	key	String(32)	89unJUB8HZ54Hj7x4nUj56HN4nUzUJ8i	商户密钥
商户状态	active	Int	1	1为正常，0为封禁
商户余额	money	String	0.00	商户所拥有的余额
结算方式	type	Int	1	1:支付宝,2:微信,3:QQ,4:银行卡
结算账号	account	String	admin@pay.com	结算的支付宝账号
结算姓名	username	String	张三	结算的支付宝姓名
订单总数	orders	Int	30	订单总数统计
今日订单	order_today	Int	15	今日订单数量
昨日订单	order_lastday	Int	15	昨日订单数量
[API]查询结算记录
URL地址：http://192.168.31.200:4000/api.php?act=settle&pid={商户ID}&key={商户密钥}

请求参数说明：

字段名	变量名	必填	类型	示例值	描述
操作类型	act	是	String	settle	此API固定值
商户ID	pid	是	Int	1001	
商户密钥	key	是	String	89unJUB8HZ54Hj7x4nUj56HN4nUzUJ8i	
返回结果：

字段名	变量名	类型	示例值	描述
返回状态码	code	Int	1	1为成功，其它值为失败
返回信息	msg	String	查询结算记录成功！	
结算记录	data	Array	结算记录列表	
[API]查询单个订单
URL地址：http://192.168.31.200:4000/api.php?act=order&pid={商户ID}&key={商户密钥}&out_trade_no={商户订单号}

请求参数说明：

字段名	变量名	必填	类型	示例值	描述
操作类型	act	是	String	order	此API固定值
商户ID	pid	是	Int	1001	
商户密钥	key	是	String	89unJUB8HZ54Hj7x4nUj56HN4nUzUJ8i	
系统订单号	trade_no	选择	String	20160806151343312	
商户订单号	out_trade_no	选择	String	20160806151343349	
提示：系统订单号 和 商户订单号 二选一传入即可，如果都传入以系统订单号为准！

返回结果：

字段名	变量名	类型	示例值	描述
返回状态码	code	Int	1	1为成功，其它值为失败
返回信息	msg	String	查询订单号成功！	
易支付订单号	trade_no	String	2016080622555342651	聚合支付平台订单号
商户订单号	out_trade_no	String	20160806151343349	商户系统内部的订单号
第三方订单号	api_trade_no	String	20160806151343349	支付宝微信等接口方订单号
支付方式	type	String	alipay	支付方式列表
商户ID	pid	Int	1001	发起支付的商户ID
创建订单时间	addtime	String	2016-08-06 22:55:52	
完成交易时间	endtime	String	2016-08-06 22:55:52	
商品名称	name	String	VIP会员	
商品金额	money	String	1.00	
支付状态	status	Int	0	1为支付成功，0为未支付
业务扩展参数	param	String		默认留空
支付者账号	buyer	String		默认留空
[API]批量查询订单
URL地址：http://192.168.31.200:4000/api.php?act=orders&pid={商户ID}&key={商户密钥}

请求参数说明：

字段名	变量名	必填	类型	示例值	描述
操作类型	act	是	String	orders	此API固定值
商户ID	pid	是	Int	1001	
商户密钥	key	是	String	89unJUB8HZ54Hj7x4nUj56HN4nUzUJ8i	
查询订单数量	limit	否	Int	20	返回的订单数量，最大50
页码	page	否	Int	1	当前查询的页码
返回结果：

字段名	变量名	类型	示例值	描述
返回状态码	code	Int	1	1为成功，其它值为失败
返回信息	msg	String	查询结算记录成功！	
订单列表	data	Array		订单列表
[API]提交订单退款
需要先在商户后台开启订单退款API接口开关，才能调用该接口发起订单退款

URL地址：http://192.168.31.200:4000/api.php?act=refund

请求方式：POST

请求参数说明：

字段名	变量名	必填	类型	示例值	描述
商户ID	pid	是	Int	1001	
商户密钥	key	是	String	89unJUB8HZ54Hj7x4nUj56HN4nUzUJ8i	
易支付订单号	trade_no	特殊可选	String	20160806151343349021	易支付订单号
商户订单号	out_trade_no	特殊可选	String	20160806151343349	订单支付时传入的商户订单号，商家自定义且保证商家系统中唯一
退款金额	money	是	String	1.50	少数通道需要与原订单金额一致
注：trade_no、out_trade_no 不能同时为空，如果都传了以trade_no为准

返回结果：

字段名	变量名	类型	示例值	描述
返回状态码	code	Int	0	0为成功，其它值为失败
返回信息	msg	String	退款成功	