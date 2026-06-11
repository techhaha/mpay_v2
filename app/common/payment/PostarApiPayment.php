<?php

declare(strict_types=1);

namespace app\common\payment;

use app\common\base\BasePayment;
use app\common\constant\FileConstant;
use app\common\constant\PaymentPluginStatusConstant;
use app\common\constant\PaymentPluginTypeConstant;
use app\common\interface\OnboardingPluginInterface;
use app\common\interface\PaymentInterface;
use app\common\interface\PayPluginInterface;
use app\exception\PaymentException;
use support\Request;
use support\Response;

/**
 * 星驿付 API 支付插件预留。
 *
 * 一期用于声明进件能力和后续 API 支付接入位置；是否启用由插件表状态控制。
 */
class PostarApiPayment extends BasePayment implements PaymentInterface, PayPluginInterface, OnboardingPluginInterface
{
    protected array $paymentInfo = [
        'code' => 'postar_api',
        'name' => '星驿付API支付',
        'plugin_type' => PaymentPluginTypeConstant::TYPE_DIRECT,
        'author' => 'MPAY',
        'version' => '0.1.0',
        'pay_types' => ['alipay', 'wxpay', 'bank'],
        'transfer_types' => [],
        'config_schema' => [],
    ];

    protected array $onboardingInfo = [
        'types' => ['micro', 'individual', 'enterprise'],
        'title' => '星驿付服务商进件',
        'description' => '星驿付 API 进件能力预留，一期仅声明配置和申请资料结构。',
        'ocr_placeholder' => true,
        'products' => [
            ['label' => '支付宝', 'value' => 'alipay'],
            ['label' => '微信支付', 'value' => 'wxpay'],
            ['label' => '银联/云闪付', 'value' => 'bank'],
        ],
    ];

    /**
     * 获取支付配置结构。
     *
     * 当前仅保留后续 API 支付接入所需的最小配置占位。
     *
     * @return array<int, array<string, mixed>>
     */
    public function getConfigSchema(): array
    {
        return [
            ['type' => 'input', 'field' => 'merchant_no', 'title' => '商户号', 'value' => ''],
            ['type' => 'password', 'field' => 'api_key', 'title' => '接口密钥', 'value' => ''],
            ['type' => 'input', 'field' => 'api_base_url', 'title' => '接口网关', 'value' => ''],
        ];
    }

    /**
     * 获取完整进件声明。
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
     * 获取进件接口配置结构。
     *
     * @return array<int, array<string, mixed>>
     */
    public function getOnboardingConfigSchema(): array
    {
        return [
            ['type' => 'input', 'field' => 'agent_no', 'title' => '服务商编号', 'value' => ''],
            ['type' => 'password', 'field' => 'api_key', 'title' => '接口密钥', 'value' => ''],
            ['type' => 'input', 'field' => 'api_base_url', 'title' => '接口网关', 'value' => ''],
        ];
    }

    /**
     * 获取进件资料表单结构。
     *
     * @return array<int, array<string, mixed>>
     */
    public function getOnboardingFormSchema(): array
    {
        // 字段命名保持与拉卡拉统一，后续接星驿付正式接口时只在插件内转换上游字段。
        return [
            ['type' => 'input', 'field' => 'merchant_name', 'title' => '商户主体名称', 'value' => '', 'validate' => [['required' => true, 'message' => '商户主体名称不能为空']]],
            ['type' => 'input', 'field' => 'merchant_short_name', 'title' => '商户简称', 'value' => ''],
            ['type' => 'input', 'field' => 'contact_name', 'title' => '联系人姓名', 'value' => ''],
            ['type' => 'input', 'field' => 'contact_mobile', 'title' => '联系人手机号', 'value' => ''],
            ['type' => 'input', 'field' => 'license_no', 'title' => '营业执照号', 'value' => ''],
            ['type' => 'upload', 'field' => 'license_photo', 'title' => '营业执照照片', 'value' => '', 'props' => $this->imageUploadProps(), 'ocr_type' => 'business_license'],
            ['type' => 'input', 'field' => 'legal_name', 'title' => '法人/负责人姓名', 'value' => ''],
            ['type' => 'input', 'field' => 'legal_id_no', 'title' => '法人/负责人身份证号', 'value' => ''],
            ['type' => 'upload', 'field' => 'legal_id_front', 'title' => '身份证人像面', 'value' => '', 'props' => $this->imageUploadProps(), 'ocr_type' => 'id_card_front'],
            ['type' => 'upload', 'field' => 'legal_id_back', 'title' => '身份证国徽面', 'value' => '', 'props' => $this->imageUploadProps(), 'ocr_type' => 'id_card_back'],
            ['type' => 'input', 'field' => 'settlement_account_name', 'title' => '结算账户名', 'value' => ''],
            ['type' => 'input', 'field' => 'settlement_account_no', 'title' => '结算账号', 'value' => ''],
            ['type' => 'input', 'field' => 'settlement_bank_name', 'title' => '开户银行', 'value' => ''],
        ];
    }

    /**
     * 发起支付。
     *
     * @throws PaymentException 当前插件未启用真实支付能力
     */
    public function pay(array $order): array
    {
        throw new PaymentException('星驿付 API 支付能力尚未启用', 40200);
    }

    /**
     * 查询支付订单。
     */
    public function query(array $order): array
    {
        return ['success' => false, 'status' => PaymentPluginStatusConstant::PENDING, 'msg' => '星驿付 API 查单能力尚未启用'];
    }

    /**
     * 关闭支付订单。
     */
    public function close(array $order): array
    {
        return ['success' => false, 'msg' => '星驿付 API 关单能力尚未启用'];
    }

    /**
     * 申请退款。
     */
    public function refund(array $order): array
    {
        return ['success' => false, 'msg' => '星驿付 API 退款能力尚未启用'];
    }

    /**
     * 解析支付回调。
     *
     * @throws PaymentException 当前插件未启用真实支付回调能力
     */
    public function notify(Request $request): array
    {
        throw new PaymentException('星驿付 API 支付回调能力尚未启用', 40200);
    }

    /**
     * 支付回调成功应答占位。
     */
    public function notifySuccess(): string|Response
    {
        return 'success';
    }

    /**
     * 支付回调失败应答占位。
     */
    public function notifyFail(): string|Response
    {
        return 'fail';
    }

    /**
     * 提交进件占位。
     *
     * @param array<string, mixed> $payload 标准进件上下文
     * @return array<string, mixed>
     */
    public function submitOnboarding(array $payload): array
    {
        // 上游进件尚未接入，返回 pending 可保护主流程不误判签约成功。
        return [
            'success' => false,
            'status' => 'pending',
            'upstream_status' => 'reserved',
            'message' => '星驿付 API 进件接口待按正式文档接入',
        ];
    }

    /**
     * 查询进件占位。
     *
     * @param array<string, mixed> $payload 标准进件上下文
     * @return array<string, mixed>
     */
    public function queryOnboarding(array $payload): array
    {
        return [
            'success' => false,
            'status' => 'pending',
            'upstream_status' => 'reserved',
            'message' => '星驿付 API 进件查询接口待按正式文档接入',
        ];
    }

    /**
     * 取消进件占位。
     *
     * @param array<string, mixed> $payload 标准进件上下文
     * @return array<string, mixed>
     */
    public function cancelOnboarding(array $payload): array
    {
        return [
            'success' => true,
            'status' => 'cancelled',
            'message' => '星驿付 API 进件取消已在本地处理',
        ];
    }

    /**
     * 解析进件回调占位。
     *
     * @throws PaymentException 当前插件未启用真实进件回调能力
     */
    public function notifyOnboarding(Request $request): array
    {
        throw new PaymentException('星驿付 API 进件回调能力尚未启用', 40200);
    }

    /**
     * 构造公开图片上传字段配置。
     *
     * @return array<string, mixed>
     */
    private function imageUploadProps(): array
    {
        return [
            'fileUpload' => [
                'selectorType' => 'image',
                'scene' => FileConstant::SCENE_IMAGE,
                'visibility' => FileConstant::VISIBILITY_PUBLIC,
                'storageEngine' => FileConstant::STORAGE_LOCAL,
                'getKey' => 'url',
                'accept' => '.jpg,.jpeg,.png,.gif,.webp,.bmp',
                'listType' => 'picture-card',
                'showFileList' => true,
                'imagePreview' => true,
                'limit' => 1,
                'multiple' => false,
            ],
            'tip' => 'OCR 识别入口预留，一期暂不启用。',
        ];
    }
}
