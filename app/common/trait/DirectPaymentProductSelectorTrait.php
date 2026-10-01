<?php

declare(strict_types=1);

namespace app\common\trait;

use app\common\constant\EpayProtocolConstant;
use app\common\constant\PaymentMethodConstant;
use app\exception\PaymentDefinitiveException;
use app\exception\PaymentException;
use app\exception\PaymentProductUnavailableException;
use app\exception\PaymentUncertainException;
use Throwable;

/**
 * 直连聚合插件支付产品候选选择。
 *
 * 插件通过 `PRODUCT_*`、`enabled_products` 和处理器声明可用产品。
 */
trait DirectPaymentProductSelectorTrait
{
    /**
     * 构建直连插件已开通产品配置字段。
     *
     * @param array<string, string> $options 产品值 => 展示名称
     * @return array<string, mixed>
     */
    private function directPaymentEnabledProductsField(array $options): array
    {
        $values = array_map(static fn (int|string $value): string => (string) $value, array_keys($options));
        $labels = array_values($options);

        return [
            'type' => 'checkbox',
            'field' => 'enabled_products',
            'title' => '已开通产品',
            'value' => [],
            'options' => array_map(
                static fn (string $value, string $label): array => ['label' => $label, 'value' => $value],
                $values,
                $labels
            ),
            'validate' => [
                ['required' => true, 'message' => '已开通产品不能为空'],
            ],
        ];
    }

    /**
     * 按当前支付环境执行最合适的支付产品。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param array<string, callable|array<string, mixed>> $handlers 产品处理器，key 使用标准支付调用方式
     * @param string $gatewayName 插件展示名
     * @return array<string, mixed>
     */
    private function executeDirectPaymentProduct(array $order, array $handlers, string $gatewayName): array
    {
        $declaredHandlers = $handlers;
        $selection = $this->directPaymentSelection($order, $handlers);
        $handlers = $selection['handlers'];
        $selectedProduct = $this->directPaymentPinnedProduct($order);
        $candidates = $selection['candidates'];
        $attempts = [];

        foreach ($candidates as $index => $product) {
            $actualProduct = $this->directPaymentSelectedProduct($order, $declaredHandlers[$product], $product);
            $this->preparePayProduct($actualProduct);
            try {
                $result = $handlers[$product]();
                $returnedProduct = trim((string) ($result['pay_product'] ?? ''));
                if ($selectedProduct !== '' || $this->hasPayProductPreflight()) {
                    if ($returnedProduct === '' || !hash_equals($actualProduct, $returnedProduct)) {
                        throw new PaymentUncertainException('支付插件返回产品与外呼前保存的产品不一致', 40200, [
                            'selected_product' => $actualProduct,
                            'actual_product' => $returnedProduct,
                        ]);
                    }
                }

                return $result;
            } catch (PaymentException $e) {
                $attempts[] = [
                    'product' => $actualProduct,
                    'message' => $e->getMessage(),
                    'code' => (string) $e->getCode(),
                ];

                // 外呼前产品已经提交；不能证明上一 handler 没有送出请求时，绝不切换产品。
                if ($this->hasPayProductPreflight() || $index === count($candidates) - 1 || !$this->directPaymentCanFallback($e)) {
                    throw $this->withDirectPaymentAttempts(
                        $e,
                        $order,
                        $candidates,
                        $attempts,
                        $actualProduct
                    );
                }
            } catch (Throwable $e) {
                // handler 已执行；未知异常按不确定处理，并保存映射产品而不是能力键。
                throw $this->withDirectPaymentAttempts(
                    new PaymentUncertainException($e->getMessage(), 40200, [
                        'channel_error_code' => 'PLUGIN_CREATE_ORDER_ERROR',
                        'exception_class' => $e::class,
                    ]),
                    $order,
                    $candidates,
                    array_merge($attempts, [[
                        'product' => $actualProduct,
                        'message' => $e->getMessage(),
                        'code' => '40200',
                    ]]),
                    $actualProduct
                );
            }
        }

        throw new PaymentProductUnavailableException($gatewayName . '没有适合当前环境的已开通支付产品', 40200, [
            'env' => $this->directPaymentEnv($order),
            'pay_type' => (string) ($order['pay_type_code'] ?? ''),
            'candidate_products' => $candidates,
            'available_products' => array_keys($handlers),
            'enabled_products' => $this->directPaymentEnabledProducts(),
        ]);
    }

    /**
     * 预选当前订单的产品，不执行处理器或请求上游。
     *
     * 身份预检与 pay() 共用该结果，避免授权产品与实际下单产品不同。
     * 固定产品仍须开通且满足原环境/显式 method；不能重新选择其他产品。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param array<string, callable|array<string, mixed>> $declarations 插件产品声明
     * @return array{handlers:array<string, callable>,candidates:list<string>,product:string}
     */
    private function directPaymentSelection(array $order, array $declarations): array
    {
        $handlers = $this->directPaymentUsableHandlers($order, $declarations);
        $candidates = $this->directPaymentProductCandidates($order, array_keys($handlers));
        $selectedProduct = $this->directPaymentPinnedProduct($order);
        if ($selectedProduct !== '') {
            // 同一产品可同时绑定 h5/jump，固定时只从本次合理候选里找处理器。
            $candidates = [$this->directPaymentHandlerForProduct(
                $order,
                $declarations,
                array_intersect_key($handlers, array_flip($candidates)),
                $selectedProduct
            )];
        }

        return [
            'handlers' => $handlers,
            'candidates' => $candidates,
            'product' => $this->directPaymentSelectedProduct($order, $declarations[$candidates[0]], $candidates[0]),
        ];
    }

    /**
     * 过滤当前订单真正可用的产品处理器。
     *
     * handler 可以直接传闭包，也可以传：
     *
     * [
     *     'products' => ['wxpay' => 'wxpay_scan', 'alipay' => 'alipay_scan'],
     *     'handler' => fn (): array => $this->scanPayByType(...),
     * ]
     *
     * `products` 固定使用 pay_type_code => product_code 映射，表示该 handler 在当前支付方式下依赖哪个插件产品。
     * product_code 应来自插件类内 `PRODUCT_*` 常量；第三方接口的交易类型编码不要写成 handler key。
     * 如果通道配置了 `enabled_products`，每个 handler 都必须声明 `products`，这里会提前过滤未勾选的产品；
     * 如果没有本地产品开关，处理器是否存在就是插件代码能力声明。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param array<string, callable|array<string, mixed>> $handlers 原始处理器
     * @return array<string, callable> 已按本地开通产品过滤后的处理器
     */
    private function directPaymentUsableHandlers(array $order, array $handlers): array
    {
        $payType = (string) ($order['pay_type_code'] ?? '');
        $usable = [];

        foreach ($handlers as $key => $definition) {
            $handler = null;
            $products = null;

            if (is_array($definition) && array_key_exists('handler', $definition)) {
                $handler = $definition['handler'];
                $products = $definition['products'] ?? null;
            } elseif (is_callable($definition)) {
                $handler = $definition;
            }

            if (!is_callable($handler)) {
                continue;
            }

            if ($products === null && $this->directPaymentEnabledProducts() !== []) {
                continue;
            }

            $matched = true;
            $requiredProducts = $this->directPaymentRequiredProducts($products, $payType, $matched);
            if (!$matched || !$this->directPaymentProductsEnabled($requiredProducts)) {
                continue;
            }

            $usable[(string) $key] = $handler;
        }

        return $usable;
    }

    /**
     * 解析某个 handler 在当前支付方式下依赖的插件产品。
     *
     * @param mixed $products handler 声明的产品依赖
     * @param string $payType 当前支付方式
     * @param bool $matched 是否匹配当前支付方式
     * @return array<int, string>
     */
    private function directPaymentRequiredProducts(mixed $products, string $payType, bool &$matched): array
    {
        $matched = true;
        if ($products === null) {
            return [];
        }

        if (!is_array($products) || array_is_list($products) || !array_key_exists($payType, $products)) {
            $matched = false;
            return [];
        }

        $product = trim((string) $products[$payType]);
        if ($product === '') {
            $matched = false;
            return [];
        }

        return [$product];
    }

    /**
     * 解析已选处理器对应的真实插件产品编码。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param mixed $definition 处理器声明
     * @param string $handlerKey 处理器键
     * @return string 产品编码
     */
    private function directPaymentSelectedProduct(array $order, mixed $definition, string $handlerKey): string
    {
        if (is_array($definition) && array_key_exists('products', $definition)) {
            $matched = true;
            $products = $this->directPaymentRequiredProducts(
                $definition['products'],
                (string) ($order['pay_type_code'] ?? ''),
                $matched
            );
            if ($matched && $products !== []) {
                return (string) $products[0];
            }
        }

        return $handlerKey;
    }

    /**
     * 读取身份流程固定的插件产品。
     */
    private function directPaymentPinnedProduct(array $order): string
    {
        $payment = $this->directPaymentPayload($order);

        return trim((string) ($payment['selected_product'] ?? ''));
    }

    /**
     * 找到固定插件产品对应的处理器。
     *
     * @param array<string, callable|array<string, mixed>> $declaredHandlers
     * @param array<string, callable> $usableHandlers
     */
    private function directPaymentHandlerForProduct(
        array $order,
        array $declaredHandlers,
        array $usableHandlers,
        string $selectedProduct
    ): string {
        $payType = (string) ($order['pay_type_code'] ?? '');

        foreach ($declaredHandlers as $key => $definition) {
            if (!is_array($definition) || !array_key_exists('products', $definition)) {
                continue;
            }

            $matched = true;
            $products = $this->directPaymentRequiredProducts($definition['products'], $payType, $matched);
            if ($matched && in_array($selectedProduct, $products, true) && isset($usableHandlers[$key])) {
                return (string) $key;
            }
        }

        throw new PaymentProductUnavailableException('身份授权产品未在当前通道开通或插件未实现', 40200, [
            'selected_product' => $selectedProduct,
            'pay_type' => $payType,
            'enabled_products' => $this->directPaymentEnabledProducts(),
        ]);
    }

    /**
     * 判断 handler 依赖的插件产品是否已经在当前通道开通。
     *
     * @param array<int, string> $requiredProducts handler 依赖的插件产品
     */
    private function directPaymentProductsEnabled(array $requiredProducts): bool
    {
        if ($requiredProducts === []) {
            return true;
        }

        $enabledProducts = $this->directPaymentEnabledProducts();
        if ($enabledProducts === []) {
            return false;
        }

        foreach ($requiredProducts as $product) {
            if (!in_array($product, $enabledProducts, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * 读取插件本地已开通产品配置。
     *
     * @return array<int, string>
     */
    private function directPaymentEnabledProducts(): array
    {
        $products = method_exists($this, 'enabledProducts')
            ? $this->enabledProducts()
            : $this->getConfig('enabled_products', []);

        return is_array($products)
            ? array_values(array_map(static fn (mixed $product): string => (string) $product, $products))
            : [];
    }

    /**
     * 按支付方式、环境和显式 method 生成候选产品。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param array<int, string> $availableProducts 当前插件已实现的产品
     * @return array<int, string>
     */
    private function directPaymentProductCandidates(array $order, array $availableProducts): array
    {
        $available = array_values(array_unique($availableProducts));
        $payType = (string) ($order['pay_type_code'] ?? '');
        $env = $this->directPaymentEnv($order);
        $payment = $this->directPaymentPayload($order);

        // 付款码支付是独立业务形态，不自动降级到普通扫码，避免用户拿付款码时生成二维码。
        if ($this->directPaymentHasAuthCode($payment)) {
            if (!in_array(PaymentMethodConstant::AUTH_CODE, $available, true)) {
                throw new PaymentProductUnavailableException('当前通道未开通或插件未实现付款码支付', 40200, [
                    'method' => PaymentMethodConstant::AUTH_CODE,
                    'available_methods' => $available,
                ]);
            }

            return [PaymentMethodConstant::AUTH_CODE];
        }

        // API 显式 method 是严格约束，不能在插件未实现或环境不匹配时静默降级。
        $methodProduct = $this->directPaymentMethodProduct((string) ($payment['method'] ?? ''));
        if ($methodProduct !== '') {
            if ($methodProduct === PaymentMethodConstant::AUTH_CODE) {
                throw new PaymentDefinitiveException('付款码支付必须提供 auth_code', 40200);
            }
            if (!in_array($methodProduct, $available, true)) {
                throw new PaymentProductUnavailableException('当前通道未开通或插件未实现指定支付方式', 40200, [
                    'method' => $methodProduct,
                    'available_methods' => $available,
                ]);
            }
            if (!$this->directPaymentProductAllowed($methodProduct, $payType, $env)) {
                throw new PaymentDefinitiveException('指定支付方式与当前支付环境不匹配', 40200, [
                    'method' => $methodProduct,
                    'env' => $env,
                    'pay_type' => $payType,
                ]);
            }

            return [$methodProduct];
        }

        $preferred = $this->directPaymentPreferredProducts($payType, $env);

        $candidates = array_values(array_filter(
            array_unique($preferred),
            static fn (string $product): bool => in_array($product, $available, true)
        ));

        if ($candidates === []) {
            throw new PaymentProductUnavailableException('当前通道没有适合支付环境的已开通产品', 40200, [
                'env' => $env,
                'pay_type' => $payType,
                'preferred_methods' => $preferred,
                'available_methods' => $available,
            ]);
        }

        return $candidates;
    }

    /**
     * 根据环境给出通用候选顺序。
     *
     * @return array<int, string>
     */
    private function directPaymentPreferredProducts(string $payType, string $env): array
    {
        if ($env === EpayProtocolConstant::DEVICE_JUMP) {
            return ['jump', 'h5', 'web', 'urlscheme'];
        }

        if ($env === EpayProtocolConstant::DEVICE_WECHAT) {
            return $payType === 'wxpay'
                ? ['jsapi', 'mini', 'urlscheme', 'qrcode', 'jump']
                : ['h5', 'jump', 'urlscheme', 'qrcode', 'web'];
        }

        if ($env === EpayProtocolConstant::DEVICE_ALIPAY) {
            return $payType === 'alipay'
                ? ['jsapi', 'mini', 'h5', 'jump', 'web', 'qrcode']
                : ['h5', 'jump', 'urlscheme', 'qrcode', 'web'];
        }

        if (in_array($env, [EpayProtocolConstant::DEVICE_MOBILE, EpayProtocolConstant::DEVICE_QQ], true)) {
            return ['h5', 'jump', 'urlscheme', 'mini', 'qrcode', 'web'];
        }

        return $payType === 'alipay'
            ? ['web', 'qrcode', 'jump', 'h5']
            : ['qrcode', 'web', 'jump', 'h5'];
    }

    /**
     * 解析支付环境。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     */
    private function directPaymentEnv(array $order): string
    {
        $env = strtolower(trim((string) ($order['_env'] ?? EpayProtocolConstant::DEVICE_PC)));

        return in_array($env, EpayProtocolConstant::v1Devices(), true) ? $env : EpayProtocolConstant::DEVICE_PC;
    }

    /**
     * 读取支付扩展载荷。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed>
     */
    private function directPaymentPayload(array $order): array
    {
        $extra = (array) ($order['extra'] ?? []);
        $payment = $extra['payment'] ?? [];

        return is_array($payment) ? $payment : [];
    }

    /**
     * 判断是否有付款码。
     *
     * @param array<string, mixed> $payment 支付扩展载荷
     */
    private function directPaymentHasAuthCode(array $payment): bool
    {
        return trim((string) ($payment['auth_code'] ?? '')) !== '';
    }

    /**
     * 读取标准支付产品偏好。
     */
    private function directPaymentMethodProduct(string $method): string
    {
        $method = strtolower(trim($method));
        if ($method === '') {
            return '';
        }

        if (!in_array($method, PaymentMethodConstant::values(), true)) {
            throw new PaymentDefinitiveException('不支持的支付调用方式', 40200, ['method' => $method]);
        }

        return $method;
    }

    /**
     * 判断显式 method 对当前环境是否合理。
     */
    private function directPaymentProductAllowed(string $product, string $payType, string $env): bool
    {
        if ($product === 'auth_code') {
            return true;
        }
        if ($env === EpayProtocolConstant::DEVICE_JUMP && in_array($product, ['qrcode', 'jsapi'], true)) {
            return false;
        }
        if ($product === 'jsapi') {
            return ($payType === 'wxpay' && $env === EpayProtocolConstant::DEVICE_WECHAT)
                || ($payType === 'alipay' && $env === EpayProtocolConstant::DEVICE_ALIPAY);
        }
        if (in_array($product, ['h5', 'jump', 'urlscheme'], true)) {
            return $env !== EpayProtocolConstant::DEVICE_PC || $product === 'jump';
        }
        if ($product === 'web') {
            return $env !== EpayProtocolConstant::DEVICE_WECHAT || $payType !== 'wxpay';
        }

        return true;
    }

    /**
     * 判断渠道错误是否允许继续尝试下一个产品。
     */
    private function directPaymentCanFallback(PaymentException $e): bool
    {
        return $e instanceof PaymentProductUnavailableException;
    }

    /**
     * 附加产品尝试信息，方便后台排障。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param array<int, string> $candidates 候选产品
     * @param array<int, array<string, string>> $attempts 已尝试产品
     */
    private function withDirectPaymentAttempts(
        PaymentException $e,
        array $order,
        array $candidates,
        array $attempts,
        string $payProduct = ''
    ): PaymentException {
        $data = method_exists($e, 'getData') ? $e->getData() : [];
        $data['env'] = $this->directPaymentEnv($order);
        $data['pay_type'] = (string) ($order['pay_type_code'] ?? '');
        $data['candidate_products'] = $candidates;
        $data['product_attempts'] = $attempts;
        if ($payProduct !== '') {
            $data['pay_product'] = $payProduct;
        }

        $exception = $e::class;

        return new $exception($e->getMessage(), (int) ($e->getCode() ?: 40200), $data);
    }
}
