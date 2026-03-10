<?php
declare(strict_types=1);

namespace app\validation;

use support\validation\Validator;

/**
 * 易支付参数验证器
 *
 * 根据 doc/epay.md 定义各接口所需参数规则
 */
class EpayValidator extends Validator
{
    /**
     * 通用规则定义
     *
     * 通过场景选择实际需要的字段
     */
    protected array $rules = [
        // 基础认证相关
        'pid'        => 'required|integer',
        'key'        => 'sometimes|string',

        // 支付相关
        'type'       => 'sometimes|string',
        'out_trade_no' => 'required|string|max:64',
        'trade_no'   => 'sometimes|string|max:64',
        'notify_url' => 'required|url|max:255',
        'return_url' => 'sometimes|url|max:255',
        'name'       => 'required|string|max:127',
        'money'      => 'required|numeric|min:0.01',
        'clientip'   => 'sometimes|ip',
        'device'     => 'sometimes|string|in:pc,mobile,qq,wechat,alipay,jump',
        'param'      => 'sometimes|string|max:255',

        // 签名相关
        'sign'       => 'required|string|size:32',
        'sign_type'  => 'required|string|in:MD5,md5',

        // API 动作
        'act'        => 'required|string',
        'limit'      => 'sometimes|integer|min:1|max:50',
        'page'       => 'sometimes|integer|min:1',
    ];

    protected array $messages = [];

    protected array $attributes = [
        'pid'          => '商户ID',
        'key'          => '商户密钥',
        'type'         => '支付方式',
        'out_trade_no' => '商户订单号',
        'trade_no'     => '系统订单号',
        'notify_url'   => '异步通知地址',
        'return_url'   => '跳转通知地址',
        'name'         => '商品名称',
        'money'        => '商品金额',
        'clientip'     => '用户IP地址',
        'device'       => '设备类型',
        'param'        => '业务扩展参数',
        'sign'         => '签名字符串',
        'sign_type'    => '签名类型',
        'act'          => '操作类型',
        'limit'        => '查询数量',
        'page'         => '页码',
    ];

    /**
     * 不同接口场景
     */
    protected array $scenes = [
        // 页面跳转支付 submit.php
        'submit' => [
            'pid',
            'type',
            'out_trade_no',
            'notify_url',
            'return_url',
            'name',
            'money',
            'param',
            'sign',
            'sign_type',
        ],

        // API 接口支付 mapi.php
        'mapi' => [
            'pid',
            'type',
            'out_trade_no',
            'notify_url',
            'return_url',
            'name',
            'money',
            'clientip',
            'device',
            'param',
            'sign',
            'sign_type',
        ],

        // api.php?act=order 查询单个订单
        'api_order' => [
            'act',
            'pid',
            'key',
            // trade_no 与 out_trade_no 至少一个，由业务层进一步校验
        ],

        // api.php?act=refund 提交退款
        'api_refund' => [
            'act',
            'pid',
            'key',
            'money',
            // trade_no/out_trade_no 至少一个
        ],
    ];
}


