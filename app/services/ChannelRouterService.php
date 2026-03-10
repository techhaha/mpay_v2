<?php

namespace app\services;

use app\common\base\BaseService;
use app\exceptions\NotFoundException;
use app\models\PaymentChannel;
use app\repositories\PaymentChannelRepository;

/**
 * 通道路由服务
 *
 * 负责根据商户、应用、支付方式选择合适的通道
 */
class ChannelRouterService extends BaseService
{
    public function __construct(
        protected PaymentChannelRepository $channelRepository
    ) {
    }

    /**
     * 选择通道
     *
     * @param int $merchantId 商户ID
     * @param int $merchantAppId 商户应用ID
     * @param int $methodId 支付方式ID
     * @return PaymentChannel
     * @throws NotFoundException
     */
    public function chooseChannel(int $merchantId, int $merchantAppId, int $methodId): PaymentChannel
    {
        $channel = $this->channelRepository->findAvailableChannel($merchantId, $merchantAppId, $methodId);

        if (!$channel) {
            throw new NotFoundException("未找到可用的支付通道：商户ID={$merchantId}, 应用ID={$merchantAppId}, 支付方式ID={$methodId}");
        }

        return $channel;
    }
}

