<?php

declare(strict_types=1);

namespace app\common\payment;

use app\common\base\BasePayment;
use app\common\constant\PaymentPluginTypeConstant;
use app\common\constant\FileConstant;
use app\common\constant\PaymentPluginStatusConstant;
use app\common\interface\OnboardingPluginInterface;
use app\common\interface\PaymentInterface;
use app\common\interface\PayPluginInterface;
use app\common\sdk\lakala\LakalaOpenApiClient;
use app\common\sdk\lakala\LakalaSdkException;
use app\common\trait\DirectPaymentProductSelectorTrait;
use app\exception\PaymentException;
use GuzzleHttp\Client as HttpClient;
use support\Request;
use support\Response;

/**
 * 拉卡拉 OpenAPI 支付插件。
 *
 * 迁移自彩虹易支付 `lakala` 插件，并按 MPAY V2 插件契约重写：
 * 插件只负责调用拉卡拉接口和返回标准结构，订单状态、回调日志和商户通知由平台服务层处理。
 */
class LakalaApiPayment extends BasePayment implements PaymentInterface, PayPluginInterface, OnboardingPluginInterface
{
    use DirectPaymentProductSelectorTrait;

    private const PRODUCT_ALIPAY_SCAN = 'alipay_scan';
    private const PRODUCT_ALIPAY_JSAPI = 'alipay_jsapi';
    private const PRODUCT_WXPAY_SCAN = 'wxpay_scan';
    private const PRODUCT_WXPAY_JSAPI = 'wxpay_jsapi';
    private const PRODUCT_BANK_SCAN = 'bank_scan';
    private const PRODUCT_CASHIER = 'cashier';
    private const PRODUCT_MICROPAY = 'micropay';
    private const UPSTREAM_TRANS_JSAPI = '51';
    private const UPSTREAM_TRANS_MINI = '71';

    private ?LakalaOpenApiClient $client = null;

    /**
     * 插件元信息。
     *
     * @var array<string, mixed>
     */
    protected array $paymentInfo = [
        'code' => 'lakala_api',
        'name' => '拉卡拉OpenAPI支付',
        'plugin_type' => PaymentPluginTypeConstant::TYPE_DIRECT,
        'author' => 'MPAY',
        'version' => '1.0.0',
        'pay_types' => ['alipay', 'wxpay', 'bank'],
        'transfer_types' => [],
        'config_schema' => [],
    ];

    /**
     * 进件能力元信息。
     *
     * @var array<string, mixed>
     */
    protected array $onboardingInfo = [
        'types' => ['micro', 'individual', 'enterprise'],
        'title' => '拉卡拉服务商特约商户进件',
        'description' => '支持小微、个体工商户和企业商户入网。首期按拉卡拉商户入网核心闭环处理：入网校验、附件上传、新增商户进件、进件查询、回调、复议和卡 BIN 查询。',
        'ocr_placeholder' => true,
        'products' => [
            ['label' => '支付宝', 'value' => 'alipay'],
            ['label' => '微信支付', 'value' => 'wxpay'],
            ['label' => '银联/云闪付', 'value' => 'bank'],
        ],
        'capabilities' => [
            'verify' => true,
            'upload_file' => true,
            'submit' => true,
            'query' => true,
            'notify' => true,
            'reconsider' => true,
            'card_bin' => true,
            'cancel' => false,
        ],
        'reserved_capabilities' => [
            'replenish_file' => '附件补充上传能力已预留，首期按复议链路补充附件。',
            'add_terminal' => '增网增终能力预留，暂不开放完整流程。',
            'online_business_type' => '新增线上业务类型能力预留，暂不开放完整流程。',
            'settle_whitelist' => '付款商户出款白名单能力预留，暂不开放完整流程。',
            'foreign_card' => '终端增外卡业务能力预留，暂不开放完整流程。',
            'cooperation_info' => '合作补充信息能力预留，暂不开放完整流程。',
        ],
    ];

    /**
     * 获取进件元信息。
     *
     * @return array<string, mixed>
     */
    public function getOnboardingInfo(): array
    {
        return array_replace($this->onboardingInfo, [
            'config_schema' => $this->getOnboardingConfigSchema(),
            'form_schema' => $this->getOnboardingFormSchema(),
        ]);
    }

    /**
     * 获取拉卡拉进件配置 Schema。
     *
     * @return array<int, array<string, mixed>>
     */
    public function getOnboardingConfigSchema(): array
    {
        return [
            [
                'type' => 'input',
                'field' => 'app_id',
                'title' => '服务商APPID',
                'value' => '',
                'validate' => [['required' => true, 'message' => '服务商APPID不能为空']],
            ],
            [
                'type' => 'input',
                'field' => 'org_code',
                'title' => '机构号',
                'value' => '',
                'props' => ['placeholder' => '拉卡拉分配的机构号 orgCode'],
                'validate' => [['required' => true, 'message' => '机构号不能为空']],
            ],
            [
                'type' => 'switch',
                'field' => 'sandbox',
                'title' => '测试环境',
                'value' => false,
                'props' => ['checkedText' => '测试', 'uncheckedText' => '生产'],
            ],
            [
                'type' => 'switch',
                'field' => 'onboarding_verify_enabled',
                'title' => '提交前入网校验',
                'value' => true,
                'props' => ['checkedText' => '开启', 'uncheckedText' => '关闭'],
            ],
            [
                'type' => 'input',
                'field' => 'api_base_url',
                'title' => '自定义网关地址',
                'value' => '',
                'props' => ['placeholder' => '留空使用拉卡拉默认网关'],
            ],
            [
                'type' => 'upload',
                'field' => 'platform_cert_path',
                'title' => '拉卡拉平台证书',
                'value' => '',
                'props' => $this->uploadProps('.cer,.crt,.pem'),
                'validate' => [['required' => true, 'message' => '拉卡拉平台证书不能为空']],
            ],
            [
                'type' => 'upload',
                'field' => 'merchant_cert_path',
                'title' => '服务商证书',
                'value' => '',
                'props' => $this->uploadProps('.cer,.crt,.pem'),
                'validate' => [['required' => true, 'message' => '服务商证书不能为空']],
            ],
            [
                'type' => 'upload',
                'field' => 'merchant_private_key_path',
                'title' => '服务商私钥文件',
                'value' => '',
                'props' => $this->uploadProps('.pem,.key'),
                'validate' => [['required' => true, 'message' => '服务商私钥文件不能为空']],
            ],
        ];
    }

    /**
     * 获取统一进件资料表单 Schema。
     *
     * @return array<int, array<string, mixed>>
     */
    public function getOnboardingFormSchema(): array
    {
        return [
            ['type' => 'input', 'field' => 'mer_reg_name', 'title' => '商户主体-商户注册名称', 'value' => '', 'validate' => [['required' => true, 'message' => '商户注册名称不能为空']]],
            ['type' => 'input', 'field' => 'mer_biz_name', 'title' => '商户主体-商户简称', 'value' => '', 'props' => ['placeholder' => '门店或经营简称']],
            ['type' => 'input', 'field' => 'mer_reg_dist_code', 'title' => '商户主体-注册地址地区码', 'value' => '', 'validate' => [['required' => true, 'message' => '注册地址地区码不能为空']]],
            ['type' => 'input', 'field' => 'mer_reg_addr', 'title' => '商户主体-注册地址', 'value' => '', 'validate' => [['required' => true, 'message' => '注册地址不能为空']]],
            ['type' => 'input', 'field' => 'mcc_code', 'title' => '商户主体-MCC 编码', 'value' => '', 'validate' => [['required' => true, 'message' => 'MCC 编码不能为空']]],
            ['type' => 'input', 'field' => 'mer_busi_content', 'title' => '商户主体-经营内容', 'value' => '', 'validate' => [['required' => true, 'message' => '经营内容不能为空']]],
            ['type' => 'input', 'field' => 'mer_contact_name', 'title' => '商户主体-联系人姓名', 'value' => '', 'validate' => [['required' => true, 'message' => '联系人姓名不能为空']]],
            ['type' => 'input', 'field' => 'mer_contact_mobile', 'title' => '商户主体-联系人手机号', 'value' => '', 'validate' => [['required' => true, 'message' => '联系人手机号不能为空']]],

            ['type' => 'input', 'field' => 'mer_blis_name', 'title' => '营业执照-注册名称', 'value' => '', 'props' => ['placeholder' => '个体/企业必填，小微可不填']],
            ['type' => 'input', 'field' => 'mer_blis', 'title' => '营业执照-统一社会信用代码', 'value' => '', 'props' => ['placeholder' => '个体/企业必填，小微可不填']],
            ['type' => 'DatePicker', 'field' => 'mer_blis_st_dt', 'title' => '营业执照-有效期开始', 'value' => ''],
            ['type' => 'DatePicker', 'field' => 'mer_blis_exp_dt', 'title' => '营业执照-有效期结束', 'value' => '', 'props' => ['placeholder' => '长期可填 9999-12-31']],
            ['type' => 'upload', 'field' => 'license_photo', 'title' => '营业执照-照片/扫描件', 'value' => '', 'props' => $this->onboardingFileUploadProps(), 'ocr_type' => 'business_license', 'convert' => ['mer_blis_name' => 'name', 'mer_blis' => 'license_no']],

            ['type' => 'input', 'field' => 'lar_name', 'title' => '法人证件-法人/负责人姓名', 'value' => '', 'validate' => [['required' => true, 'message' => '法人/负责人姓名不能为空']]],
            ['type' => 'select', 'field' => 'lar_id_type', 'title' => '法人证件-证件类型', 'value' => '01', 'options' => [['label' => '身份证', 'value' => '01']], 'validate' => [['required' => true, 'message' => '法人证件类型不能为空']]],
            ['type' => 'input', 'field' => 'lar_idcard', 'title' => '法人证件-证件号码', 'value' => '', 'validate' => [['required' => true, 'message' => '法人证件号码不能为空']]],
            ['type' => 'DatePicker', 'field' => 'lar_idcard_st_dt', 'title' => '法人证件-有效期开始', 'value' => '', 'validate' => [['required' => true, 'message' => '法人证件有效期开始不能为空']]],
            ['type' => 'DatePicker', 'field' => 'lar_idcard_exp_dt', 'title' => '法人证件-有效期结束', 'value' => '', 'props' => ['placeholder' => '长期可填 9999-12-31'], 'validate' => [['required' => true, 'message' => '法人证件有效期结束不能为空']]],
            ['type' => 'upload', 'field' => 'legal_id_front', 'title' => '法人证件-身份证人像面', 'value' => '', 'props' => $this->onboardingFileUploadProps(), 'ocr_type' => 'id_card_front', 'convert' => ['lar_name' => 'name', 'lar_idcard' => 'id_no'], 'validate' => [['required' => true, 'message' => '请上传法人身份证人像面']]],
            ['type' => 'upload', 'field' => 'legal_id_back', 'title' => '法人证件-身份证国徽面', 'value' => '', 'props' => $this->onboardingFileUploadProps(), 'ocr_type' => 'id_card_back', 'validate' => [['required' => true, 'message' => '请上传法人身份证国徽面']]],

            ['type' => 'select', 'field' => 'acct_type_code', 'title' => '结算账户-账户类型', 'value' => '58', 'options' => [['label' => '对私账户', 'value' => '58'], ['label' => '对公账户', 'value' => '57']], 'validate' => [['required' => true, 'message' => '结算账户类型不能为空']]],
            ['type' => 'input', 'field' => 'acct_no', 'title' => '结算账户-账号', 'value' => '', 'validate' => [['required' => true, 'message' => '结算账号不能为空']]],
            ['type' => 'input', 'field' => 'acct_name', 'title' => '结算账户-户名', 'value' => '', 'validate' => [['required' => true, 'message' => '结算账户户名不能为空']]],
            ['type' => 'input', 'field' => 'openning_bank_code', 'title' => '结算账户-开户行号', 'value' => '', 'validate' => [['required' => true, 'message' => '开户行号不能为空']]],
            ['type' => 'input', 'field' => 'openning_bank_name', 'title' => '结算账户-开户行名称', 'value' => '', 'validate' => [['required' => true, 'message' => '开户行名称不能为空']]],
            ['type' => 'input', 'field' => 'clearing_bank_code', 'title' => '结算账户-清算行号', 'value' => '', 'validate' => [['required' => true, 'message' => '清算行号不能为空']]],
            ['type' => 'input', 'field' => 'settle_period', 'title' => '结算账户-结算周期', 'value' => 'T+1', 'validate' => [['required' => true, 'message' => '结算周期不能为空']]],
            ['type' => 'input', 'field' => 'clear_dt', 'title' => '结算账户-日切时间', 'value' => '', 'props' => ['placeholder' => '默认 TWENTY_THREE']],
            ['type' => 'switch', 'field' => 'settlement_same_as_legal', 'title' => '结算账户-结算人同法人', 'value' => true, 'props' => ['checkedText' => '同法人', 'uncheckedText' => '非法人']],
            ['type' => 'input', 'field' => 'acct_id_type', 'title' => '结算账户-结算人证件类型', 'value' => '01'],
            ['type' => 'input', 'field' => 'acct_idcard', 'title' => '结算账户-结算人证件号', 'value' => '', 'props' => ['placeholder' => '非法人结算时必填']],
            ['type' => 'DatePicker', 'field' => 'acct_id_dt', 'title' => '结算账户-结算人证件有效期', 'value' => '', 'props' => ['placeholder' => '非法人结算时必填']],
            ['type' => 'upload', 'field' => 'settlement_id_front', 'title' => '结算账户-结算人身份证人像面', 'value' => '', 'props' => $this->onboardingFileUploadProps()],
            ['type' => 'upload', 'field' => 'settlement_id_back', 'title' => '结算账户-结算人身份证国徽面', 'value' => '', 'props' => $this->onboardingFileUploadProps()],
            ['type' => 'upload', 'field' => 'settlement_bank_card', 'title' => '结算账户-银行卡照片', 'value' => '', 'props' => $this->onboardingFileUploadProps(), 'validate' => [['required' => true, 'message' => '请上传结算银行卡照片']]],

            ['type' => 'input', 'field' => 'shop_name', 'title' => '网点-网点名称', 'value' => '', 'props' => ['placeholder' => '不填默认取商户注册名称']],
            ['type' => 'input', 'field' => 'shop_dist_code', 'title' => '网点-地址区划代码', 'value' => '', 'props' => ['placeholder' => '不填默认取商户地区代码']],
            ['type' => 'input', 'field' => 'shop_addr', 'title' => '网点-详细地址', 'value' => '', 'props' => ['placeholder' => '不填默认取商户详细地址']],
            ['type' => 'input', 'field' => 'shop_contact_name', 'title' => '网点-联系人名称', 'value' => '', 'props' => ['placeholder' => '不填默认取商户联系人姓名']],
            ['type' => 'input', 'field' => 'shop_contact_mobile', 'title' => '网点-联系人手机号', 'value' => '', 'props' => ['placeholder' => '不填默认取商户联系人手机号']],
            ['type' => 'upload', 'field' => 'store_front_photo', 'title' => '附件-门头照片 MERCHANT_PHOTO', 'value' => '', 'props' => $this->onboardingFileUploadProps(), 'upstream_att_type' => 'MERCHANT_PHOTO', 'validate' => [['required' => true, 'message' => '请上传门头照片']]],
            ['type' => 'upload', 'field' => 'store_inside_photo', 'title' => '附件-店内照片 SHOPINNER', 'value' => '', 'props' => $this->onboardingFileUploadProps(), 'upstream_att_type' => 'SHOPINNER', 'validate' => [['required' => true, 'message' => '请上传店内照片']]],

            ['type' => 'input', 'field' => 'pos_type', 'title' => '终端与费率-POS 类型', 'value' => 'GENERAL_POS', 'validate' => [['required' => true, 'message' => 'POS 类型不能为空']]],
            ['type' => 'input', 'field' => 'dev_serial_no', 'title' => '终端与费率-终端设备序列号', 'value' => ''],
            ['type' => 'input', 'field' => 'dev_type_name', 'title' => '终端与费率-设备型号', 'value' => ''],
            ['type' => 'input', 'field' => 'term_ver', 'title' => '终端与费率-终端版本号', 'value' => ''],
            ['type' => 'input', 'field' => 'sales_staff', 'title' => '终端与费率-销售人员', 'value' => ''],
            ['type' => 'inputNumber', 'field' => 'term_num', 'title' => '终端与费率-终端数量', 'value' => 1, 'props' => ['min' => 1, 'max' => 5, 'precision' => 0]],
            ['type' => 'input', 'field' => 'fee_rate_type_code', 'title' => '终端与费率-费率类型编码', 'value' => '', 'validate' => [['required' => true, 'message' => '费率类型编码不能为空']]],
            ['type' => 'input', 'field' => 'fee_rate_type_name', 'title' => '终端与费率-费率类型名称', 'value' => '', 'validate' => [['required' => true, 'message' => '费率类型名称不能为空']]],
            ['type' => 'input', 'field' => 'fee_rate_pct', 'title' => '终端与费率-费率值', 'value' => '', 'props' => ['placeholder' => '例如 0.38'], 'validate' => [['required' => true, 'message' => '费率值不能为空']]],
            ['type' => 'input', 'field' => 'fee_upper_amt_pcnt', 'title' => '终端与费率-封顶金额', 'value' => ''],
            ['type' => 'input', 'field' => 'fee_lower_amt_pcnt', 'title' => '终端与费率-保底金额', 'value' => ''],
            ['type' => 'DatePicker', 'field' => 'fee_rate_st_dt', 'title' => '终端与费率-费率生效日期', 'value' => ''],
            ['type' => 'input', 'field' => 'contract_no', 'title' => '扩展业务-电子合同编号', 'value' => ''],
            ['type' => 'input', 'field' => 'fee_assume_type', 'title' => '扩展业务-大额理财手续费承担方', 'value' => ''],
            ['type' => 'input', 'field' => 'amount_of_month', 'title' => '扩展业务-大额理财最小月交易额', 'value' => ''],
            ['type' => 'input', 'field' => 'service_fee', 'title' => '扩展业务-大额理财收取服务费', 'value' => ''],

            ['type' => 'upload', 'field' => 'agreement_file', 'title' => '补充附件-协议附件 XY', 'value' => '', 'props' => $this->onboardingFileUploadProps(), 'upstream_att_type' => 'XY'],
            ['type' => 'upload', 'field' => 'qualification_file', 'title' => '补充附件-资质证明 COOPERATION_QUALIFICATION_PROOF', 'value' => '', 'props' => $this->onboardingFileUploadProps(), 'upstream_att_type' => 'COOPERATION_QUALIFICATION_PROOF'],
            ['type' => 'upload', 'field' => 'other_file', 'title' => '补充附件-其他附件 OTHERS', 'value' => '', 'props' => $this->onboardingFileUploadProps(), 'upstream_att_type' => 'OTHERS'],
        ];
    }

    /**
     * 获取后台配置表单。
     *
     * @return array<int, array<string, mixed>>
     */
    public function getConfigSchema(): array
    {
        return [
            [
                'type' => 'input',
                'field' => 'app_id',
                'title' => 'APPID',
                'value' => '',
                'validate' => [
                    ['required' => true, 'message' => 'APPID不能为空'],
                ],
            ],
            [
                'type' => 'input',
                'field' => 'merchant_no',
                'title' => '商户号',
                'value' => '',
                'validate' => [
                    ['required' => true, 'message' => '商户号不能为空'],
                ],
            ],
            [
                'type' => 'input',
                'field' => 'terminal_no',
                'title' => '终端号',
                'value' => '',
                'validate' => [
                    ['required' => true, 'message' => '终端号不能为空'],
                ],
            ],
            [
                'type' => 'checkbox',
                'field' => 'enabled_products',
                'title' => '已开通产品',
                'value' => [self::PRODUCT_ALIPAY_SCAN, self::PRODUCT_WXPAY_SCAN, self::PRODUCT_BANK_SCAN],
                'options' => [
                    ['label' => '支付宝扫码', 'value' => self::PRODUCT_ALIPAY_SCAN],
                    ['label' => '支付宝JSAPI', 'value' => self::PRODUCT_ALIPAY_JSAPI],
                    ['label' => '微信扫码', 'value' => self::PRODUCT_WXPAY_SCAN],
                    ['label' => '微信JSAPI/小程序', 'value' => self::PRODUCT_WXPAY_JSAPI],
                    ['label' => '云闪付扫码', 'value' => self::PRODUCT_BANK_SCAN],
                    ['label' => '聚合收银台', 'value' => self::PRODUCT_CASHIER],
                    ['label' => '付款码支付', 'value' => self::PRODUCT_MICROPAY],
                ],
                'validate' => [
                    ['required' => true, 'message' => '已开通产品不能为空'],
                ],
            ],
            [
                'type' => 'switch',
                'field' => 'sandbox',
                'title' => '测试环境',
                'value' => false,
                'props' => [
                    'checkedText' => '测试',
                    'uncheckedText' => '生产',
                ],
            ],
            [
                'type' => 'input',
                'field' => 'api_base_url',
                'title' => '自定义网关地址',
                'value' => '',
                'props' => [
                    'placeholder' => '留空使用拉卡拉默认网关',
                ],
            ],
            [
                'type' => 'upload',
                'field' => 'platform_cert_path',
                'title' => '拉卡拉平台证书',
                'value' => '',
                'props' => $this->uploadProps('.cer,.crt,.pem'),
                'validate' => [
                    ['required' => true, 'message' => '拉卡拉平台证书不能为空'],
                ],
            ],
            [
                'type' => 'upload',
                'field' => 'merchant_cert_path',
                'title' => '商户证书',
                'value' => '',
                'props' => $this->uploadProps('.cer,.crt,.pem'),
                'validate' => [
                    ['required' => true, 'message' => '商户证书不能为空'],
                ],
            ],
            [
                'type' => 'upload',
                'field' => 'merchant_private_key_path',
                'title' => '商户私钥文件',
                'value' => '',
                'props' => $this->uploadProps('.pem,.key'),
                'validate' => [
                    ['required' => true, 'message' => '商户私钥文件不能为空'],
                ],
            ],
        ];
    }

    /**
     * 发起支付。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed>
     */
    public function pay(array $order): array
    {
        $payType = (string) $order['pay_type_code'];

        return $this->executeDirectPaymentProduct($order, [
            'auth_code' => [
                'products' => [
                    'alipay' => self::PRODUCT_MICROPAY,
                    'wxpay' => self::PRODUCT_MICROPAY,
                    'bank' => self::PRODUCT_MICROPAY,
                ],

                'handler' => fn (): array => $this->micropay($order, $payType),
            ],
            'jsapi' => [
                'products' => ['alipay' => self::PRODUCT_ALIPAY_JSAPI, 'wxpay' => self::PRODUCT_WXPAY_JSAPI],
                'handler' => function () use ($order, $payType): array {
                    return match ($payType) {
                        'alipay' => $this->preorder($order, self::PRODUCT_ALIPAY_JSAPI, 'ALIPAY', '51'),
                        'wxpay' => $this->preorder($order, self::PRODUCT_WXPAY_JSAPI, 'WECHAT', $this->wxpayJsapiTransType($order)),
                    };
                },
            ],
            'h5' => [
                'products' => [
                    'alipay' => self::PRODUCT_CASHIER,
                    'wxpay' => self::PRODUCT_CASHIER,
                    'bank' => self::PRODUCT_CASHIER,
                ],

                'handler' => fn (): array => $this->cashierPay($order, $payType),
            ],
            'jump' => [
                'products' => [
                    'alipay' => self::PRODUCT_CASHIER,
                    'wxpay' => self::PRODUCT_CASHIER,
                    'bank' => self::PRODUCT_CASHIER,
                ],

                'handler' => fn (): array => $this->cashierPay($order, $payType),
            ],
            'web' => [
                'products' => [
                    'alipay' => self::PRODUCT_CASHIER,
                    'wxpay' => self::PRODUCT_CASHIER,
                    'bank' => self::PRODUCT_CASHIER,
                ],

                'handler' => fn (): array => $this->cashierPay($order, $payType),
            ],
            'qrcode' => [
                'products' => [
                    'bank' => self::PRODUCT_BANK_SCAN,
                    'wxpay' => self::PRODUCT_WXPAY_SCAN,
                    'alipay' => self::PRODUCT_ALIPAY_SCAN,
                ],

                'handler' => fn (): array => $this->preorderByType($order, $payType),
            ],
        ], '拉卡拉');
    }

    /**
     * 按支付方式选择拉卡拉扫码预下单产品。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param string $payType 支付方式
     * @return array<string, mixed>
     */
    private function preorderByType(array $order, string $payType): array
    {
        return match ($payType) {
            'bank' => $this->preorder($order, self::PRODUCT_BANK_SCAN, 'UQRCODEPAY', '41'),
            'wxpay' => $this->preorder($order, self::PRODUCT_WXPAY_SCAN, 'WECHAT', '41'),
            default => $this->preorder($order, self::PRODUCT_ALIPAY_SCAN, 'ALIPAY', '41'),
        };
    }

    /**
     * 微信 JSAPI 和小程序对应同一个后台开通项，上游交易类型按身份字段切换。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     */
    private function wxpayJsapiTransType(array $order): string
    {
        $payment = (array) ($order['extra']['payment'] ?? []);

        return (string) ($payment['mini_openid'] ?? '') !== ''
            ? self::UPSTREAM_TRANS_MINI
            : self::UPSTREAM_TRANS_JSAPI;
    }

    /**
     * 查询支付订单。
     *
     * @param array<string, mixed> $order 标准插件查单参数
     * @return array<string, mixed>
     */
    public function query(array $order): array
    {
        try {
            $data = $this->client()->execute('/api/v3/labs/query/tradequery', [
                'merchant_no' => $this->configText('merchant_no'),
                'term_no' => $this->configText('terminal_no'),
                'out_trade_no' => (string) $order['pay_no'],
            ]);
        } catch (LakalaSdkException $e) {
            return [
                'success' => false,
                'msg' => $e->getMessage(),
            ];
        }

        $status = $this->tradeStatus((string) ($data['trade_state'] ?? ''));

        return [
            'success' => true,
            'status' => $status,
            'channel_order_no' => (string) ($data['out_trade_no'] ?? $order['pay_no']),
            'channel_trade_no' => (string) ($data['trade_no'] ?? $order['chan_trade_no'] ?? $order['pay_no']),
            'channel_status' => (string) ($data['trade_state'] ?? ''),
            'message' => (string) ($data['trade_state_desc'] ?? $data['trade_state'] ?? ''),
            'paid_at' => $status === PaymentPluginStatusConstant::SUCCESS ? ($data['pay_time'] ?? null) : null,
            'raw_data' => $data,
        ];
    }

    /**
     * 撤销支付订单。
     *
     * @param array<string, mixed> $order 标准插件关单参数
     * @return array<string, mixed>
     */
    public function close(array $order): array
    {
        try {
            $data = $this->client()->execute('/api/v3/labs/relation/revoked', [
                'merchant_no' => $this->configText('merchant_no'),
                'term_no' => $this->configText('terminal_no'),
                'out_trade_no' => 'CLOSE' . date('YmdHis') . random_int(1000, 9999),
                'origin_out_trade_no' => (string) $order['pay_no'],
                'location_info' => [
                    'request_ip' => (string) ($order['client_ip'] ?? ''),
                ],
            ]);

            return [
                'success' => true,
                'msg' => '关单成功',
                'raw_data' => $data,
            ];
        } catch (LakalaSdkException $e) {
            return [
                'success' => false,
                'msg' => $e->getMessage(),
            ];
        }
    }

    /**
     * 申请退款。
     *
     * @param array<string, mixed> $order 标准插件退款参数
     * @return array<string, mixed>
     */
    public function refund(array $order): array
    {
        try {
            $data = $this->client()->execute('/api/v3/labs/relation/refund', [
                'merchant_no' => $this->configText('merchant_no'),
                'term_no' => $this->configText('terminal_no'),
                'out_trade_no' => (string) $order['refund_no'],
                'refund_amount' => (string) (int) $order['refund_amount'],
                'origin_out_trade_no' => (string) $order['pay_no'],
                'origin_trade_no' => (string) ($order['chan_trade_no'] ?? ''),
                'location_info' => [
                    'request_ip' => (string) ($order['client_ip'] ?? ''),
                ],
            ]);

            return [
                'success' => true,
                'msg' => '退款申请成功',
                'chan_refund_no' => (string) ($data['trade_no'] ?? $order['refund_no']),
                'raw_data' => $data,
            ];
        } catch (LakalaSdkException $e) {
            return [
                'success' => false,
                'msg' => $e->getMessage(),
            ];
        }
    }

    /**
     * 提交拉卡拉商户进件。
     *
     * @param array<string, mixed> $payload 标准进件上下文
     * @return array<string, mixed>
     */
    public function submitOnboarding(array $payload): array
    {
        $request = $this->buildLakalaAddMerPayload($payload);
        $this->assertLakalaAddMerPayload($request, (string) ($payload['subject_type'] ?? ''));

        try {
            // 官方链路先做入网校验，避免附件上传成功后才发现基础字段不满足上游规则。
            if ($this->configBool('onboarding_verify_enabled')) {
                $this->verifyLakalaContractInfo($request);
            }

            // 附件上传使用同一个 orderNo，确保 addMer 能在 24 小时有效期内引用 attFileId。
            $fileData = $this->uploadOnboardingAttachments($payload, (string) $request['orderNo']);
            if ($fileData !== []) {
                $request['fileData'] = $fileData;
            }

            $data = $this->client()->mms(LakalaOpenApiClient::MMS_SUBMIT_PATH, array_merge([
                'reqId' => (string) $payload['onboarding_no'],
            ], $request));
        } catch (LakalaSdkException $e) {
            throw new PaymentException('拉卡拉进件提交失败：' . $e->getMessage(), 40200);
        }

        return $this->standardOnboardingResult($data, $payload, '拉卡拉进件已提交');
    }

    /**
     * 查询拉卡拉商户进件状态。
     *
     * @param array<string, mixed> $payload 标准查询上下文
     * @return array<string, mixed>
     */
    public function queryOnboarding(array $payload): array
    {
        try {
            $data = $this->client()->mms(LakalaOpenApiClient::MMS_QUERY_PATH, [
                'reqId' => 'Q' . date('YmdHis') . random_int(1000, 9999),
                'version' => '1.0',
                'orderNo' => (string) ($payload['onboarding_no'] ?? $payload['upstream_apply_id'] ?? ''),
                'orgCode' => $this->orgCode(),
                'contractId' => (string) ($payload['upstream_contract_id'] ?? $payload['contract_id'] ?? ''),
            ]);
        } catch (LakalaSdkException $e) {
            throw new PaymentException('拉卡拉进件查询失败：' . $e->getMessage(), 40200);
        }

        return $this->standardOnboardingResult($data, $payload, '拉卡拉进件状态已同步');
    }

    /**
     * 取消拉卡拉商户进件。
     *
     * @param array<string, mixed> $payload 标准取消上下文
     * @return array<string, mixed>
     */
    public function cancelOnboarding(array $payload): array
    {
        // 官方商户入网文档未列出取消接口，拉卡拉侧不发起上游取消，仅由平台终止本地申请。
        return ['success' => true, 'status' => 'cancelled', 'message' => '拉卡拉商户入网暂不支持上游取消，已仅在本地取消'];
    }

    /**
     * 解析拉卡拉异步通知。
     *
     * @param Request $request 回调请求
     * @return array<string, mixed>
     */
    public function notify(Request $request): array
    {
        $body = $request->rawBody();
        $authorization = (string) $request->header('authorization', '');
        if ($body === '' || $authorization === '') {
            throw new PaymentException('拉卡拉回调缺少原始报文或签名头', 40200);
        }
        if (!$this->client()->verifyNotify($authorization, $body)) {
            throw new PaymentException('拉卡拉回调验签失败', 40200);
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new PaymentException('拉卡拉回调报文不是合法 JSON', 40200);
        }

        $outTradeNo = (string) ($data['out_trade_no'] ?? $data['out_order_no'] ?? '');
        $tradeInfo = (array) ($data['order_trade_info'] ?? []);
        $channelTradeNo = (string) ($data['trade_no'] ?? $tradeInfo['trade_no'] ?? $outTradeNo);
        $channelStatus = (string) ($data['trade_status'] ?? $data['order_status'] ?? '');
        $status = $this->notifyStatus($channelStatus);

        if ($outTradeNo === '') {
            throw new PaymentException('拉卡拉回调缺少商户订单号', 40200);
        }

        return [
            'status' => $status,
            'message' => $channelStatus,
            'channel_order_no' => $outTradeNo,
            'channel_trade_no' => $channelTradeNo !== '' ? $channelTradeNo : $outTradeNo,
            'channel_status' => $channelStatus,
            'paid_at' => $status === PaymentPluginStatusConstant::SUCCESS ? ($data['pay_time'] ?? null) : null,
        ];
    }

    /**
     * 解析拉卡拉进件异步通知。
     *
     * @param Request $request 回调请求
     * @return array<string, mixed>
     */
    public function notifyOnboarding(Request $request): array
    {
        $body = $request->rawBody();
        $authorization = (string) $request->header('authorization', '');
        if ($body === '' || $authorization === '') {
            throw new PaymentException('拉卡拉进件回调缺少原始报文或签名头', 40200);
        }
        if (!$this->client()->verifyNotify($authorization, $body)) {
            throw new PaymentException('拉卡拉进件回调验签失败', 40200);
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new PaymentException('拉卡拉进件回调报文不是合法 JSON', 40200);
        }
        // 官方进件通知把业务字段放在 data 下，兼容网关偶发的 respData 包裹。
        $payload = (array) ($data['data'] ?? $data['respData'] ?? $data['resp_data'] ?? $data);

        return [
            'onboarding_no' => (string) ($payload['orderNo'] ?? $payload['outOrderNo'] ?? ''),
            'status' => $this->onboardingStatus((string) ($payload['contractStatus'] ?? $payload['status'] ?? 'pending')),
            'upstream_apply_id' => (string) ($payload['orderNo'] ?? ''),
            'upstream_contract_id' => (string) ($payload['contractId'] ?? ''),
            'upstream_merchant_no' => (string) ($payload['merCupNo'] ?? $payload['merInnerNo'] ?? ''),
            'upstream_terminal_no' => $this->lakalaTerminalNo($payload),
            'upstream_status' => (string) ($payload['contractStatus'] ?? $payload['status'] ?? ''),
            'message' => (string) ($payload['contractMemo'] ?? $payload['message'] ?? $payload['msg'] ?? '拉卡拉进件通知'),
        ];
    }

    /**
     * 提交拉卡拉进件复议。
     *
     * @param array<string, mixed> $payload 标准进件上下文
     * @return array<string, mixed>
     */
    public function reconsiderOnboarding(array $payload): array
    {
        $request = $this->buildLakalaAddMerPayload($payload);
        $this->assertLakalaAddMerPayload($request, (string) ($payload['subject_type'] ?? ''));
        $request['contractId'] = (string) ($payload['upstream_contract_id'] ?? '');
        if ($request['contractId'] === '') {
            throw new PaymentException('拉卡拉进件复议缺少合同号', 40200);
        }

        try {
            $fileData = $this->uploadOnboardingAttachments($payload, (string) $request['orderNo']);
            if ($fileData !== []) {
                $request['fileData'] = $fileData;
            }
            $data = $this->client()->mms(LakalaOpenApiClient::MMS_RECONSIDER_PATH, array_merge([
                'reqId' => 'R' . date('YmdHis') . random_int(1000, 9999),
            ], $request));
        } catch (LakalaSdkException $e) {
            throw new PaymentException('拉卡拉进件复议失败：' . $e->getMessage(), 40200);
        }

        return $this->standardOnboardingResult($data, $payload, '拉卡拉进件复议已提交');
    }

    /**
     * 查询银行卡 BIN 信息，用于回填开户行和清算行字段。
     *
     * @param array<string, mixed> $payload 查询参数
     * @return array<string, mixed>
     */
    public function cardBin(array $payload): array
    {
        $cardNo = preg_replace('/\s+/', '', (string) ($payload['card_no'] ?? $payload['acct_no'] ?? '')) ?: '';
        if ($cardNo === '') {
            throw new PaymentException('银行卡号不能为空', 40200);
        }

        try {
            $data = $this->client()->mms(LakalaOpenApiClient::MMS_CARD_BIN_PATH, [
                'reqId' => 'CB' . date('YmdHis') . random_int(1000, 9999),
                'version' => '1.0',
                'orgCode' => $this->orgCode(),
                'cardNo' => $cardNo,
            ]);
        } catch (LakalaSdkException $e) {
            throw new PaymentException('拉卡拉卡 BIN 查询失败：' . $e->getMessage(), 40200);
        }

        return [
            'success' => true,
            'card_no_masked' => $this->maskCardNo($cardNo),
            'openning_bank_code' => (string) ($data['openningBankCode'] ?? $data['openingBankCode'] ?? $data['bankCode'] ?? ''),
            'openning_bank_name' => (string) ($data['openningBankName'] ?? $data['openingBankName'] ?? $data['bankName'] ?? ''),
            'clearing_bank_code' => (string) ($data['clearingBankCode'] ?? $data['clearBankCode'] ?? ''),
            'bank_name' => (string) ($data['bankName'] ?? $data['openningBankName'] ?? ''),
            'raw_data' => $data,
        ];
    }

    /**
     * 返回拉卡拉成功应答。
     */
    public function notifySuccess(): string|Response
    {
        return 'success';
    }

    /**
     * 返回拉卡拉失败应答。
     */
    public function notifyFail(): string|Response
    {
        return 'fail';
    }

    /**
     * 返回拉卡拉进件回调成功 JSON。
     */
    public function notifyOnboardingSuccess(): string
    {
        return json_encode(['code' => 'SUCCESS', 'message' => '成功'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{"code":"SUCCESS","message":"成功"}';
    }

    /**
     * 返回拉卡拉进件回调失败 JSON。
     */
    public function notifyOnboardingFail(): string
    {
        return json_encode(['code' => 'FAIL', 'message' => '失败'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{"code":"FAIL","message":"失败"}';
    }

    /**
     * 聚合预下单。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param string $product 插件产品
     * @param string $accountType 拉卡拉账户类型
     * @param string $transType 拉卡拉交易类型
     * @return array<string, mixed>
     */
    private function preorder(array $order, string $product, string $accountType, string $transType): array
    {
        $this->ensureProduct($product);

        $payload = [
            'merchant_no' => $this->configText('merchant_no'),
            'term_no' => $this->configText('terminal_no'),
            'out_trade_no' => (string) $order['pay_no'],
            'account_type' => $accountType,
            'trans_type' => $transType,
            'total_amount' => (string) (int) $order['amount'],
            'location_info' => [
                'request_ip' => (string) $order['client_ip'],
            ],
            'subject' => mb_strcut((string) $order['subject'], 0, 127, 'UTF-8'),
            'notify_url' => (string) $order['callback_url'],
        ];

        $extend = $this->identityFields($order);
        if ($extend !== []) {
            $payload['acc_busi_fields'] = $extend;
        }

        try {
            $data = $this->client()->execute('/api/v3/labs/trans/preorder', $payload);
        } catch (LakalaSdkException $e) {
            throw new PaymentException('拉卡拉下单失败：' . $e->getMessage(), 40200);
        }

        $fields = (array) ($data['acc_resp_fields'] ?? []);
        $payPage = str_ends_with($product, '_jsapi') ? 'jsapi' : 'qrcode';
        $payParams = $payPage === 'jsapi'
            ? array_replace($fields, ['raw' => $data])
            : [
                'qrcode' => (string) ($fields['code'] ?? $fields['redirect_url'] ?? ''),
                'raw' => $data,
            ];

        if ($payPage === 'qrcode' && $payParams['qrcode'] === '') {
            throw new PaymentException('拉卡拉未返回二维码内容', 40200, ['response' => $data]);
        }

        return [
            'pay_page' => $payPage,
            'pay_type' => (string) $order['pay_type_code'],
            'pay_product' => $product,
            'pay_action' => 'preorder',
            'pay_params' => $payParams,
            'chan_order_no' => (string) $order['pay_no'],
            'chan_trade_no' => (string) ($data['trade_no'] ?? ''),
        ];
    }

    /**
     * 聚合收银台下单。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param string $payType 支付方式
     * @return array<string, mixed>
     */
    private function cashierPay(array $order, string $payType): array
    {
        $this->ensureProduct(self::PRODUCT_CASHIER);

        $payMode = match ($payType) {
            'wxpay' => 'WECHAT',
            'bank' => 'UNION',
            default => 'ALIPAY',
        };

        try {
            $data = $this->client()->cashier('/api/v3/ccss/counter/order/special_create', [
                'out_order_no' => (string) $order['pay_no'],
                'merchant_no' => $this->configText('merchant_no'),
                'total_amount' => (string) (int) $order['amount'],
                'order_efficient_time' => date('YmdHis', time() + 1200),
                'notify_url' => (string) $order['callback_url'],
                'support_refund' => 1,
                'callback_url' => (string) $order['return_url'],
                'order_info' => mb_strcut((string) $order['subject'], 0, 127, 'UTF-8'),
                'counter_param' => json_encode(['pay_mode' => $payMode], JSON_UNESCAPED_UNICODE),
            ]);
        } catch (LakalaSdkException $e) {
            throw new PaymentException('拉卡拉收银台下单失败：' . $e->getMessage(), 40200);
        }

        $url = (string) ($data['counter_url'] ?? '');
        if ($url === '') {
            throw new PaymentException('拉卡拉收银台未返回支付地址', 40200, ['response' => $data]);
        }

        return [
            'pay_page' => 'jump',
            'pay_type' => $payType,
            'pay_product' => self::PRODUCT_CASHIER,
            'pay_action' => 'cashierPay',
            'pay_params' => [
                'url' => $url,
                'raw' => $data,
            ],
            'chan_order_no' => (string) $order['pay_no'],
            'chan_trade_no' => (string) ($data['pay_order_no'] ?? ''),
        ];
    }

    /**
     * 付款码支付。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param string $payType 支付方式
     * @return array<string, mixed>
     */
    private function micropay(array $order, string $payType): array
    {
        $this->ensureProduct(self::PRODUCT_MICROPAY);

        $authCode = (string) ($order['extra']['payment']['auth_code'] ?? '');
        if ($authCode === '') {
            throw new PaymentException('付款码不能为空', 40200);
        }

        try {
            $data = $this->client()->execute('/api/v3/labs/trans/micropay', [
                'merchant_no' => $this->configText('merchant_no'),
                'term_no' => $this->configText('terminal_no'),
                'out_trade_no' => (string) $order['pay_no'],
                'auth_code' => $authCode,
                'total_amount' => (string) (int) $order['amount'],
                'location_info' => [
                    'request_ip' => (string) $order['client_ip'],
                ],
                'subject' => mb_strcut((string) $order['subject'], 0, 127, 'UTF-8'),
                'notify_url' => (string) $order['callback_url'],
            ]);
        } catch (LakalaSdkException $e) {
            throw new PaymentException('拉卡拉付款码支付失败：' . $e->getMessage(), 40200);
        }

        $status = strtoupper((string) ($data['trade_state'] ?? ''));

        return [
            'pay_page' => $status === 'SUCCESS' ? 'ok' : 'page',
            'pay_type' => $payType,
            'pay_product' => self::PRODUCT_MICROPAY,
            'pay_action' => 'micropay',
            'pay_params' => [
                '_page' => 'ok',
                'raw' => $data,
            ],
            'chan_order_no' => (string) $order['pay_no'],
            'chan_trade_no' => (string) ($data['trade_no'] ?? ''),
        ];
    }

    /**
     * 构造 JSAPI 身份参数。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed>
     */
    private function identityFields(array $order): array
    {
        $payment = (array) ($order['extra']['payment'] ?? []);
        $userId = (string) ($payment['buyer_id'] ?? $payment['mini_openid'] ?? $payment['openid'] ?? $payment['sub_openid'] ?? '');
        if ($userId === '') {
            return [];
        }

        $fields = ['user_id' => $userId];
        $subAppId = (string) ($payment['sub_appid'] ?? '');
        if ($subAppId !== '') {
            $fields['sub_appid'] = $subAppId;
        }

        return $fields;
    }

    /**
     * 构造拉卡拉 addMer / verifyContractInfo 入网资料。
     *
     * @param array<string, mixed> $payload 标准进件上下文
     * @return array<string, mixed>
     */
    private function buildLakalaAddMerPayload(array $payload): array
    {
        $form = (array) ($payload['form_data'] ?? []);
        $request = [
            'version' => '1.0',
            'orderNo' => (string) ($payload['onboarding_no'] ?? ''),
            'posType' => $this->formText($form, 'pos_type') ?: 'GENERAL_POS',
            'termNum' => $this->formText($form, 'term_num'),
            'orgCode' => $this->orgCode(),
            'merRegName' => $this->formText($form, 'mer_reg_name'),
            'merBizName' => $this->formText($form, 'mer_biz_name'),
            'merRegDistCode' => $this->formText($form, 'mer_reg_dist_code'),
            'merRegAddr' => $this->formText($form, 'mer_reg_addr'),
            'mccCode' => $this->formText($form, 'mcc_code'),
            'merBusiContent' => $this->formText($form, 'mer_busi_content'),
            'larName' => $this->formText($form, 'lar_name'),
            'larIdType' => $this->formText($form, 'lar_id_type') ?: '01',
            'larIdcard' => $this->formText($form, 'lar_idcard'),
            'larIdcardStDt' => $this->dateText($form, 'lar_idcard_st_dt'),
            'larIdcardExpDt' => $this->dateText($form, 'lar_idcard_exp_dt'),
            'merContactMobile' => $this->formText($form, 'mer_contact_mobile'),
            'merContactName' => $this->formText($form, 'mer_contact_name'),
            'shopName' => $this->formText($form, 'shop_name'),
            'shopDistCode' => $this->formText($form, 'shop_dist_code'),
            'shopAddr' => $this->formText($form, 'shop_addr'),
            'shopContactName' => $this->formText($form, 'shop_contact_name'),
            'shopContactMobile' => $this->formText($form, 'shop_contact_mobile'),
            'openningBankCode' => $this->formText($form, 'openning_bank_code'),
            'openningBankName' => $this->formText($form, 'openning_bank_name'),
            'clearingBankCode' => $this->formText($form, 'clearing_bank_code'),
            'acctNo' => $this->formText($form, 'acct_no'),
            'acctName' => $this->formText($form, 'acct_name'),
            'acctTypeCode' => $this->formText($form, 'acct_type_code') ?: '58',
            'settlePeriod' => $this->formText($form, 'settle_period') ?: 'T+1',
            'clearDt' => $this->formText($form, 'clear_dt'),
            'devSerialNo' => $this->formText($form, 'dev_serial_no'),
            'devTypeName' => $this->formText($form, 'dev_type_name'),
            'termVer' => $this->formText($form, 'term_ver'),
            'salesStaff' => $this->formText($form, 'sales_staff'),
            'contractNo' => $this->formText($form, 'contract_no'),
            'feeAssumeType' => $this->formText($form, 'fee_assume_type'),
            'amountOfMonth' => $this->formText($form, 'amount_of_month'),
            'serviceFee' => $this->formText($form, 'service_fee'),
            'retUrl' => (string) ($payload['notify_url'] ?? ''),
            'feeData' => [$this->lakalaFeeData($form, (array) ($payload['rate_config'] ?? []))],
        ];

        // 小微商户不强制营业执照，个体和企业在 assert 阶段会要求这些字段完整。
        foreach (['mer_blis_name' => 'merBlisName', 'mer_blis' => 'merBlis', 'mer_blis_st_dt' => 'merBlisStDt', 'mer_blis_exp_dt' => 'merBlisExpDt'] as $source => $target) {
            $value = str_contains($source, '_dt') ? $this->dateText($form, $source) : $this->formText($form, $source);
            if ($value !== '') {
                $request[$target] = $value;
            }
        }

        // 非法人结算时补充结算人证件信息，便于上游审核结算账户归属。
        if (!$this->settlementSameAsLegal($form)) {
            $request['acctIdType'] = $this->formText($form, 'acct_id_type') ?: '01';
            $request['acctIdcard'] = $this->formText($form, 'acct_idcard');
            $request['acctIdDt'] = $this->dateText($form, 'acct_id_dt');
        }

        return $this->compactArrayRecursive($request);
    }

    /**
     * 校验拉卡拉入网核心字段和条件字段。
     *
     * @param array<string, mixed> $request 上游 reqData
     * @param string $subjectType 平台主体类型
     */
    private function assertLakalaAddMerPayload(array $request, string $subjectType): void
    {
        $required = [
            'orderNo' => '平台申请单号',
            'posType' => 'POS 类型',
            'orgCode' => '机构号',
            'merRegName' => '商户注册名称',
            'merRegDistCode' => '注册地址地区码',
            'merRegAddr' => '注册地址',
            'mccCode' => 'MCC 编码',
            'merBusiContent' => '经营内容',
            'larName' => '法人/负责人姓名',
            'larIdType' => '法人证件类型',
            'larIdcard' => '法人证件号码',
            'larIdcardStDt' => '法人证件有效期开始',
            'larIdcardExpDt' => '法人证件有效期结束',
            'merContactMobile' => '联系人手机号',
            'merContactName' => '联系人姓名',
            'openningBankCode' => '开户行号',
            'openningBankName' => '开户行名称',
            'clearingBankCode' => '清算行号',
            'acctNo' => '结算账号',
            'acctName' => '结算账户名',
            'acctTypeCode' => '结算账户类型',
            'settlePeriod' => '结算周期',
            'retUrl' => '进件回调地址',
        ];

        if ($subjectType !== 'micro') {
            $required += [
                'merBlisName' => '营业执照注册名称',
                'merBlis' => '统一社会信用代码',
                'merBlisStDt' => '营业执照有效期开始',
                'merBlisExpDt' => '营业执照有效期结束',
            ];
        }

        foreach ($required as $field => $label) {
            if ($this->isBlank($request[$field] ?? null)) {
                throw new PaymentException('拉卡拉进件资料缺少：' . $label, 40200, ['field' => $field]);
            }
        }

        if (!is_array($request['feeData'] ?? null) || $request['feeData'] === []) {
            throw new PaymentException('拉卡拉进件资料缺少：费率信息', 40200, ['field' => 'feeData']);
        }
        $fee = (array) ($request['feeData'][0] ?? []);
        foreach (['feeRateTypeCode' => '费率类型编码', 'feeRateTypeName' => '费率类型名称', 'feeRatePct' => '费率值'] as $field => $label) {
            if ($this->isBlank($fee[$field] ?? null)) {
                throw new PaymentException('拉卡拉进件资料缺少：' . $label, 40200, ['field' => $field]);
            }
        }
    }

    /**
     * 调用官方进件校验接口。
     *
     * @param array<string, mixed> $request addMer reqData
     * @throws LakalaSdkException
     */
    private function verifyLakalaContractInfo(array $request): void
    {
        $this->client()->mms(LakalaOpenApiClient::MMS_VERIFY_PATH, array_merge([
            'reqId' => 'V' . date('YmdHis') . random_int(1000, 9999),
        ], $request));
    }

    /**
     * 上传进件附件并返回 addMer fileData。
     *
     * @param array<string, mixed> $payload 标准进件上下文
     * @param string $orderNo 平台申请单号
     * @return array<int, array<string, string>>
     * @throws LakalaSdkException
     */
    private function uploadOnboardingAttachments(array $payload, string $orderNo): array
    {
        $form = (array) ($payload['form_data'] ?? []);
        $assets = (array) ($payload['file_assets'] ?? []);
        $subjectType = (string) ($payload['subject_type'] ?? '');
        $definitions = $this->lakalaAttachmentDefinitions($form, $subjectType);
        $fileData = [];

        foreach ($definitions as $definition) {
            $field = (string) $definition['field'];
            $required = (bool) ($definition['required'] ?? false);
            $value = $form[$field] ?? $assets[$field] ?? null;
            if ($this->isEmptyUploadValue($value)) {
                if ($required) {
                    throw new PaymentException('请上传' . (string) $definition['title'], 40200, ['field' => $field]);
                }
                continue;
            }

            $file = $this->readOnboardingUploadFile($value);
            $data = $this->client()->mms(LakalaOpenApiClient::MMS_UPLOAD_PATH, [
                'reqId' => 'U' . date('YmdHis') . random_int(1000, 9999),
                'version' => '1.0',
                'orderNo' => $orderNo,
                'orgCode' => $this->orgCode(),
                'attType' => (string) $definition['attType'],
                'attExtName' => $file['ext'],
                // attContext 是唯一包含文件内容的字段，只放到上游请求体，不进入业务日志摘要。
                'attContext' => base64_encode($file['content']),
            ]);

            $attFileId = (string) ($data['attFileId'] ?? $data['fileId'] ?? '');
            if ($attFileId === '') {
                throw new PaymentException('拉卡拉附件上传未返回文件 ID：' . (string) $definition['title'], 40200);
            }

            $fileData[] = [
                'attFileId' => $attFileId,
                'attType' => (string) $definition['attType'],
            ];
        }

        return $fileData;
    }

    /**
     * 拉卡拉官方附件枚举映射。
     *
     * @param array<string, mixed> $form 表单数据
     * @return array<int, array{field: string, attType: string, title: string, required: bool}>
     */
    private function lakalaAttachmentDefinitions(array $form, string $subjectType): array
    {
        $nonLegalSettlement = !$this->settlementSameAsLegal($form);

        return [
            ['field' => 'legal_id_front', 'attType' => 'FR_ID_CARD_FRONT', 'title' => '法人身份证人像面', 'required' => true],
            ['field' => 'legal_id_back', 'attType' => 'FR_ID_CARD_BEHIND', 'title' => '法人身份证国徽面', 'required' => true],
            ['field' => 'settlement_id_front', 'attType' => 'ID_CARD_FRONT', 'title' => '结算人身份证人像面', 'required' => $nonLegalSettlement],
            ['field' => 'settlement_id_back', 'attType' => 'ID_CARD_BEHIND', 'title' => '结算人身份证国徽面', 'required' => $nonLegalSettlement],
            ['field' => 'settlement_bank_card', 'attType' => 'BANK_CARD', 'title' => '结算银行卡照片', 'required' => true],
            ['field' => 'license_photo', 'attType' => 'BUSINESS_LICENCE', 'title' => '营业执照照片', 'required' => $subjectType !== 'micro'],
            ['field' => 'store_front_photo', 'attType' => 'MERCHANT_PHOTO', 'title' => '门头照片', 'required' => true],
            ['field' => 'store_inside_photo', 'attType' => 'SHOPINNER', 'title' => '店内照片', 'required' => true],
            ['field' => 'agreement_file', 'attType' => 'XY', 'title' => '协议附件', 'required' => false],
            ['field' => 'qualification_file', 'attType' => 'COOPERATION_QUALIFICATION_PROOF', 'title' => '资质证明', 'required' => false],
            ['field' => 'other_file', 'attType' => 'OTHERS', 'title' => '其他附件', 'required' => false],
        ];
    }

    /**
     * 读取上传附件内容。
     *
     * @param mixed $value 上传组件回填值
     * @return array{content: string, ext: string}
     */
    private function readOnboardingUploadFile(mixed $value): array
    {
        $path = $this->uploadFieldValue($value);
        if ($path === '') {
            throw new PaymentException('上传文件引用为空', 40200);
        }

        if (preg_match('#^https?://#i', $path) === 1) {
            return $this->downloadRemoteUpload($path);
        }

        $localPath = $this->resolveLocalUploadPath($path);
        if ($localPath === '') {
            throw new PaymentException('上传文件不存在或不可读取', 40200, ['path' => $path]);
        }

        $content = file_get_contents($localPath);
        if ($content === false) {
            throw new PaymentException('上传文件读取失败', 40200, ['path' => $path]);
        }

        return [
            'content' => $this->assertOnboardingUploadContent($content),
            'ext' => $this->normalizeUploadExt(pathinfo($localPath, PATHINFO_EXTENSION)),
        ];
    }

    /**
     * 解析上传组件可能返回的 URL、object_key、response 或列表结构。
     */
    private function uploadFieldValue(mixed $value): string
    {
        if (is_scalar($value)) {
            return trim((string) $value);
        }
        if (!is_array($value)) {
            return '';
        }

        $keys = array_keys($value);
        if ($keys === range(0, count($value) - 1)) {
            return $this->uploadFieldValue($value[0] ?? '');
        }

        foreach (['object_key', 'path', 'url', 'value', 'preview_url', 'public_url', 'file_id'] as $key) {
            if (array_key_exists($key, $value)) {
                $resolved = $this->uploadFieldValue($value[$key]);
                if ($resolved !== '') {
                    return $resolved;
                }
            }
        }

        foreach (['response', 'data', 'file'] as $key) {
            if (isset($value[$key])) {
                $resolved = $this->uploadFieldValue($value[$key]);
                if ($resolved !== '') {
                    return $resolved;
                }
            }
        }

        return '';
    }

    /**
     * 将公开上传 URL 或 object_key 转成本机文件路径。
     */
    private function resolveLocalUploadPath(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path));
        if ($path === '') {
            return '';
        }
        if (preg_match('/^[A-Za-z]:\//', $path) === 1 || str_starts_with($path, '/') || str_starts_with($path, '//')) {
            if (is_file(str_replace('/', DIRECTORY_SEPARATOR, $path))) {
                return str_replace('/', DIRECTORY_SEPARATOR, $path);
            }
        }

        $relative = preg_replace('#^https?://[^/]+/#i', '', $path) ?? $path;
        $relative = ltrim($relative, '/');
        $candidates = [
            public_path($relative),
            public_path(preg_replace('#^storage/#', '', $relative) ?? $relative),
            public_path(preg_replace('#^public/#', '', $relative) ?? $relative),
            runtime_path($relative),
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && is_file($candidate)) {
                return $candidate;
            }
        }

        return '';
    }

    /**
     * 远程公开 URL 附件兜底下载。
     *
     * @return array{content: string, ext: string}
     */
    private function downloadRemoteUpload(string $url): array
    {
        try {
            $response = (new HttpClient([
                'timeout' => 10,
                'connect_timeout' => 5,
                'http_errors' => false,
                'verify' => true,
            ]))->get($url);
        } catch (\Throwable $e) {
            throw new PaymentException('远程附件下载失败：' . $e->getMessage(), 40200);
        }

        if ($response->getStatusCode() >= 400) {
            throw new PaymentException('远程附件下载失败，HTTP ' . $response->getStatusCode(), 40200);
        }

        $content = (string) $response->getBody();
        $path = (string) parse_url($url, PHP_URL_PATH);

        return [
            'content' => $this->assertOnboardingUploadContent($content),
            'ext' => $this->normalizeUploadExt(pathinfo($path, PATHINFO_EXTENSION)),
        ];
    }

    /**
     * 校验附件大小。
     */
    private function assertOnboardingUploadContent(string $content): string
    {
        if ($content === '') {
            throw new PaymentException('上传附件内容为空', 40200);
        }
        if (strlen($content) > LakalaOpenApiClient::MMS_UPLOAD_MAX_BYTES) {
            throw new PaymentException('拉卡拉进件附件单文件不能超过 5M', 40200);
        }

        return $content;
    }

    /**
     * 归一化官方允许的附件后缀。
     */
    private function normalizeUploadExt(string $ext): string
    {
        $ext = strtolower(ltrim(trim($ext), '.'));
        $ext = $ext === 'jpeg' ? 'jpg' : $ext;
        if (!in_array($ext, ['jpg', 'png', 'pdf'], true)) {
            throw new PaymentException('拉卡拉进件附件仅支持 jpg、png、pdf', 40200, ['ext' => $ext]);
        }

        return $ext;
    }

    /**
     * 构造官方 feeData 明细。
     *
     * @param array<string, mixed> $form 表单数据
     * @param array<string, mixed> $rateConfig 后台预设费率
     * @return array<string, mixed>
     */
    private function lakalaFeeData(array $form, array $rateConfig): array
    {
        // 表单值优先，后台进件渠道预设可作为默认值减少商户重复填写。
        return $this->compactArrayRecursive([
            'feeRateTypeCode' => $this->formText($form, 'fee_rate_type_code') ?: (string) ($rateConfig['fee_rate_type_code'] ?? ''),
            'feeRateTypeName' => $this->formText($form, 'fee_rate_type_name') ?: (string) ($rateConfig['fee_rate_type_name'] ?? ''),
            'feeRatePct' => $this->formText($form, 'fee_rate_pct') ?: (string) ($rateConfig['fee_rate_pct'] ?? ''),
            'feeUpperAmtPcnt' => $this->formText($form, 'fee_upper_amt_pcnt') ?: (string) ($rateConfig['fee_upper_amt_pcnt'] ?? ''),
            'feeLowerAmtPcnt' => $this->formText($form, 'fee_lower_amt_pcnt') ?: (string) ($rateConfig['fee_lower_amt_pcnt'] ?? ''),
            'feeRateStDt' => $this->dateText($form, 'fee_rate_st_dt') ?: (string) ($rateConfig['fee_rate_st_dt'] ?? ''),
        ]);
    }

    /**
     * 标准化插件进件返回结果。
     *
     * @param array<string, mixed> $data 上游响应业务数据
     * @param array<string, mixed> $payload 标准进件上下文
     * @return array<string, mixed>
     */
    private function standardOnboardingResult(array $data, array $payload, string $defaultMessage): array
    {
        $status = (string) ($data['contractStatus'] ?? $data['status'] ?? $data['auditStatus'] ?? 'pending');

        return [
            'success' => true,
            'status' => $this->onboardingStatus($status),
            'upstream_apply_id' => (string) ($data['orderNo'] ?? $payload['onboarding_no'] ?? ''),
            'upstream_contract_id' => (string) ($data['contractId'] ?? $payload['upstream_contract_id'] ?? ''),
            'upstream_merchant_no' => (string) ($data['merCupNo'] ?? $data['merInnerNo'] ?? $data['merchantNo'] ?? ''),
            'upstream_terminal_no' => $this->lakalaTerminalNo($data),
            'upstream_status' => $status,
            'message' => (string) ($data['contractMemo'] ?? $data['message'] ?? $data['msg'] ?? $defaultMessage),
        ];
    }

    /**
     * 读取表单字符串。
     *
     * @param array<string, mixed> $form 表单数据
     */
    private function formText(array $form, string $key): string
    {
        $value = $form[$key] ?? '';
        if (is_array($value)) {
            // 兼容输入组件、上传组件和 response 包裹对象的不同回填形态。
            $value = $this->uploadFieldValue($value);
        }

        return trim((string) $value);
    }

    /**
     * 读取日期字段，统一裁剪为 yyyy-MM-dd。
     *
     * @param array<string, mixed> $form 表单数据
     */
    private function dateText(array $form, string $key): string
    {
        $value = $this->formText($form, $key);
        if ($value === '') {
            return '';
        }

        return substr(str_replace('/', '-', $value), 0, 10);
    }

    /**
     * 判断普通字段是否为空。
     */
    private function isBlank(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }
        if (is_string($value)) {
            return trim($value) === '';
        }
        if (is_array($value)) {
            return $value === [];
        }

        return false;
    }

    /**
     * 判断上传字段是否为空。
     */
    private function isEmptyUploadValue(mixed $value): bool
    {
        return $this->uploadFieldValue($value) === '';
    }

    /**
     * 判断结算人是否同法人。
     *
     * @param array<string, mixed> $form 表单数据
     */
    private function settlementSameAsLegal(array $form): bool
    {
        $value = $form['settlement_same_as_legal'] ?? true;
        if (is_bool($value)) {
            return $value;
        }

        return !in_array(strtolower(trim((string) $value)), ['0', 'false', 'off', 'no', '否', '非法人'], true);
    }

    /**
     * 递归移除空字符串、null 和空数组，避免上游收到无意义字段。
     *
     * @param array<string|int, mixed> $data 待清理数组
     * @return array<string|int, mixed>
     */
    private function compactArrayRecursive(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $value = $this->compactArrayRecursive($value);
                $data[$key] = $value;
            }

            if ($data[$key] === '' || $data[$key] === null || (is_array($data[$key]) && $data[$key] === [])) {
                unset($data[$key]);
            }
        }

        return $data;
    }

    /**
     * 获取拉卡拉机构号。
     */
    private function orgCode(): string
    {
        $orgCode = $this->configText('org_code');
        if ($orgCode === '') {
            throw new PaymentException('拉卡拉机构号不能为空', 40200);
        }

        return $orgCode;
    }

    /**
     * 从拉卡拉响应里提取终端号。
     *
     * @param array<string, mixed> $data 上游响应或回调数据
     */
    private function lakalaTerminalNo(array $data): string
    {
        foreach (['termNo', 'terminalNo', 'terminal_no'] as $key) {
            $value = trim((string) ($data[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        $termInfo = $data['termInfo'] ?? $data['termList'] ?? [];
        if (is_array($termInfo)) {
            $keys = array_keys($termInfo);
            $first = $keys === range(0, count($termInfo) - 1) ? ($termInfo[0] ?? []) : $termInfo;
            if (is_array($first)) {
                foreach (['termNo', 'terminalNo', 'terminal_no'] as $key) {
                    $value = trim((string) ($first[$key] ?? ''));
                    if ($value !== '') {
                        return $value;
                    }
                }
            }
        }

        return '';
    }

    /**
     * 脱敏银行卡号。
     */
    private function maskCardNo(string $cardNo): string
    {
        $len = strlen($cardNo);
        if ($len <= 8) {
            return str_repeat('*', $len);
        }

        return substr($cardNo, 0, 4) . str_repeat('*', max(0, $len - 8)) . substr($cardNo, -4);
    }

    /**
     * 映射进件状态为平台标准字符串。
     */
    private function onboardingStatus(string $status): string
    {
        $status = strtoupper(trim($status));
        // 插件统一返回标准字符串，服务层再映射成本地状态码。
        return match ($status) {
            'WAIT_FOR_CONTACT', 'SUCCESS', 'SIGNED', 'APPROVED', 'OPENED', '2', '3' => 'signed',
            'COMMIT_FAIL', 'INNER_CHECK_REJECTED', 'REJECT', 'REJECTED', 'RETURNED', 'FAILED', 'FAIL', '4', '5' => 'rejected',
            'CANCEL', 'CANCELLED', 'CANCELED' => 'cancelled',
            'NO_COMMIT', 'COMMIT', 'MANUAL_AUDIT', 'REVIEW_ING', 'PENDING', 'PROCESSING', 'SUBMITTED' => 'pending',
            default => 'pending',
        };
    }

    /**
     * 获取 SDK 客户端。
     */
    private function client(): LakalaOpenApiClient
    {
        if ($this->client === null) {
            $this->client = new LakalaOpenApiClient([
                'app_id' => $this->configText('app_id'),
                'merchant_no' => $this->configText('merchant_no'),
                'terminal_no' => $this->configText('terminal_no'),
                'platform_cert_path' => $this->uploadedPrivateFilePath($this->configText('platform_cert_path')),
                'merchant_cert_path' => $this->uploadedPrivateFilePath($this->configText('merchant_cert_path')),
                'merchant_private_key_path' => $this->uploadedPrivateFilePath($this->configText('merchant_private_key_path')),
                'sandbox' => $this->configBool('sandbox'),
                'api_base_url' => $this->configText('api_base_url'),
            ]);
        }

        return $this->client;
    }

    /**
     * 将上传组件保存的 object_key 转为可读本机路径。
     */
    private function uploadedPrivateFilePath(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path));
        if ($path === '') {
            return '';
        }
        if (preg_match('/^[A-Za-z]:\//', $path) === 1 || str_starts_with($path, '/') || str_starts_with($path, '//')) {
            // 已经是绝对路径时直接按本机目录分隔符返回。
            return str_replace('/', DIRECTORY_SEPARATOR, $path);
        }

        // 文件中心私有文件默认落在 runtime 下，插件运行时只需要本地可读路径。
        return runtime_path(trim($path, '/'));
    }

    /**
     * 构造上传字段配置。
     *
     * @param string $accept 允许文件后缀
     * @return array<string, mixed>
     */
    private function uploadProps(string $accept): array
    {
        return [
            'fileUpload' => [
                'scene' => FileConstant::SCENE_CERTIFICATE,
                'visibility' => FileConstant::VISIBILITY_PRIVATE,
                'storageEngine' => FileConstant::STORAGE_LOCAL,
                'getKey' => 'object_key',
                'accept' => $accept,
                'limit' => 1,
                'multiple' => false,
                'showFileList' => true,
            ],
        ];
    }

    /**
     * 构造进件附件上传字段配置。
     *
     * @return array<string, mixed>
     */
    private function onboardingFileUploadProps(): array
    {
        return [
            'fileUpload' => [
                'selectorType' => 'image',
                'scene' => FileConstant::SCENE_IMAGE,
                'visibility' => FileConstant::VISIBILITY_PUBLIC,
                'storageEngine' => FileConstant::STORAGE_LOCAL,
                'getKey' => 'url',
                'accept' => '.jpg,.png,.pdf',
                'listType' => 'picture-card',
                'showFileList' => true,
                'imagePreview' => true,
                'limit' => 1,
                'multiple' => false,
            ],
            'tip' => '可点击 OCR 按钮预留识别入口，一期识别服务暂未启用。',
        ];
    }

    /**
     * 判断产品是否启用。
     */
    private function productEnabled(string $product): bool
    {
        return in_array($product, $this->enabledProducts(), true);
    }

    /**
     * 校验产品是否启用。
     */
    private function ensureProduct(string $product): void
    {
        if (!$this->productEnabled($product)) {
            throw new PaymentException('当前拉卡拉通道未开启该支付产品', 40200, ['product' => $product]);
        }
    }

    /**
     * 获取启用产品列表。
     *
     * @return array<int, string>
     */
    private function enabledProducts(): array
    {
        $products = $this->getConfig('enabled_products', []);
        if (is_string($products)) {
            $decoded = json_decode($products, true);
            $products = is_array($decoded) ? $decoded : explode(',', $products);
        }
        if (!is_array($products)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn ($value): string => trim((string) $value),
            $products
        )));
    }

    /**
     * 映射查单状态。
     */
    private function tradeStatus(string $status): string
    {
        return match (strtoupper($status)) {
            'SUCCESS' => PaymentPluginStatusConstant::SUCCESS,
            'CLOSED', 'REVOKED', 'FAIL', 'FAILED' => PaymentPluginStatusConstant::FAILED,
            default => PaymentPluginStatusConstant::PENDING,
        };
    }

    /**
     * 映射通知状态。
     */
    private function notifyStatus(string $status): string
    {
        $status = strtoupper($status);
        if ($status === 'SUCCESS' || $status === '2') {
            return PaymentPluginStatusConstant::SUCCESS;
        }

        return in_array($status, ['CLOSED', 'REVOKED', 'FAIL', 'FAILED', '3'], true)
            ? PaymentPluginStatusConstant::FAILED
            : PaymentPluginStatusConstant::PENDING;
    }

    /**
     * 获取字符串配置。
     */
    private function configText(string $key): string
    {
        return trim((string) $this->getConfig($key, ''));
    }

    /**
     * 获取布尔配置。
     */
    private function configBool(string $key): bool
    {
        return in_array($this->getConfig($key, false), [true, 1, '1', 'true', 'on'], true);
    }
}
