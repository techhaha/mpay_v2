<?php

namespace app\service\payment\transfer;

use app\common\base\BaseService;
use app\common\constant\TransferConstant;
use app\exception\ConflictException;
use app\exception\ResourceNotFoundException;
use app\exception\ValidationException;
use app\model\merchant\Merchant;
use app\model\payment\TransferOrder;
use app\repository\account\balance\MerchantAccountRepository;
use app\repository\payment\trade\TransferOrderRepository;

/**
 * 转账服务。
 */
class TransferService extends BaseService
{
    public function __construct(
        protected MerchantAccountRepository $merchantAccountRepository,
        protected TransferOrderRepository $transferOrderRepository
    ) {
    }

    /**
     * 创建转账单。
     *
     * @param Merchant $merchant 商户
     * @param array $input 请求参数
     * @return array<string, mixed>
     */
    public function submit(Merchant $merchant, array $input): array
    {
        $type = trim((string) ($input['type'] ?? ''));
        $account = trim((string) ($input['account'] ?? ''));
        $name = trim((string) ($input['name'] ?? ''));
        $money = trim((string) ($input['money'] ?? ''));
        $amount = $this->parseMoneyToAmount($money);

        if ($type === '') {
            throw new ValidationException('type 不能为空');
        }
        if ($account === '' || $name === '') {
            throw new ValidationException('account/name 不能为空');
        }
        if ($amount <= 0) {
            throw new ValidationException('money 参数不合法');
        }

        $merchantId = (int) $merchant->id;
        $outBizNo = trim((string) ($input['out_biz_no'] ?? ''));
        if ($outBizNo !== '') {
            $existing = $this->transferOrderRepository->findByOutBizNo($merchantId, $outBizNo);
            if ($existing) {
                if ((int) $existing->amount !== $amount) {
                    throw new ConflictException('幂等冲突', [
                        'biz_no' => (string) $existing->biz_no,
                        'out_biz_no' => $outBizNo,
                    ]);
                }

                return $this->formatTransferOrder($existing);
            }
        }

        $transferRate = $this->resolveTransferRate();
        $costAmount = (int) floor($amount * $transferRate);
        $bizNo = $this->generateNo('TRF');
        $traceNo = $this->generateNo('TRC');

        /** @var TransferOrder $transferOrder */
        $transferOrder = $this->transferOrderRepository->create([
            'biz_no' => $bizNo,
            'trace_no' => $traceNo,
            'merchant_id' => $merchantId,
            'merchant_group_id' => (int) ($merchant->group_id ?? 0),
            'out_biz_no' => $outBizNo !== '' ? $outBizNo : $this->generateNo('OBN'),
            'type' => $type,
            'account' => $account,
            'name' => $name,
            'amount' => $amount,
            'cost_amount' => $costAmount,
            'remark' => (string) ($input['remark'] ?? ''),
            'bookid' => (string) ($input['bookid'] ?? ''),
            'channel_id' => (int) ($input['channel_id'] ?? 0),
            'channel_request_no' => $this->generateNo('TRQ'),
            'status' => TransferConstant::TRANSFER_STATUS_PENDING,
            'request_at' => $this->now(),
            'ext_json' => (array) ($input['ext_json'] ?? []),
        ]);

        return $this->formatTransferOrder($transferOrder);
    }

    /**
     * 查询转账单。
     *
     * @param Merchant $merchant 商户
     * @param array $input 请求参数
     * @return array<string, mixed>
     */
    public function query(Merchant $merchant, array $input): array
    {
        $order = $this->resolveTransferOrder($merchant, $input);
        return $this->formatTransferOrder($order);
    }

    /**
     * 查询转账余额。
     *
     * @param Merchant $merchant 商户
     * @return array<string, mixed>
     */
    public function balance(Merchant $merchant): array
    {
        $account = $this->merchantAccountRepository->findByMerchantId((int) $merchant->id);
        return [
            'available_money' => $this->formatAmount((int) ($account->available_balance ?? 0)),
            'transfer_rate' => number_format($this->resolveTransferRate(), 2, '.', ''),
        ];
    }

    /**
     * 解析转账单。
     *
     * @param Merchant $merchant 商户
     * @param array $input 请求参数
     * @return TransferOrder
     */
    private function resolveTransferOrder(Merchant $merchant, array $input): TransferOrder
    {
        $merchantId = (int) $merchant->id;
        $bizNo = trim((string) ($input['biz_no'] ?? ''));
        $outBizNo = trim((string) ($input['out_biz_no'] ?? ''));

        if ($bizNo !== '') {
            $order = $this->transferOrderRepository->findByBizNo($bizNo);
            if (!$order || (int) $order->merchant_id !== $merchantId) {
                throw new ResourceNotFoundException('转账单不存在', ['biz_no' => $bizNo]);
            }

            return $order;
        }

        if ($outBizNo !== '') {
            $order = $this->transferOrderRepository->findByOutBizNo($merchantId, $outBizNo);
            if (!$order) {
                throw new ResourceNotFoundException('转账单不存在', ['out_biz_no' => $outBizNo]);
            }

            return $order;
        }

        throw new ValidationException('biz_no/out_biz_no 不能为空');
    }

    /**
     * 格式化转账单。
     *
     * @param TransferOrder $order 转账单
     * @return array<string, mixed>
     */
    private function formatTransferOrder(TransferOrder $order): array
    {
        return [
            'status' => (int) $order->status,
            'errmsg' => (string) ($order->channel_error_msg ?? ''),
            'biz_no' => (string) $order->biz_no,
            'out_biz_no' => (string) $order->out_biz_no,
            'orderid' => (string) $order->biz_no,
            'paydate' => $this->formatDateTime($order->succeeded_at ?? null, ''),
            'amount' => $this->formatAmount((int) $order->amount),
            'cost_money' => $this->formatAmount((int) $order->cost_amount),
            'remark' => (string) $order->remark,
        ];
    }

    /**
     * 解析转账费率。
     *
     * @return float
     */
    private function resolveTransferRate(): float
    {
        $rate = (string) config('epay.v2.transfer_rate', '0.01');
        $rate = trim($rate);
        if ($rate === '' || !is_numeric($rate)) {
            return 0.01;
        }

        $floatRate = (float) $rate;
        return $floatRate > 0 ? $floatRate : 0.01;
    }

    /**
     * 金额字符串转分。
     *
     * @param string $money 金额字符串
     * @return int
     */
    private function parseMoneyToAmount(string $money): int
    {
        $money = trim($money);
        if ($money === '' || !preg_match('/^\d+(?:\.\d{1,2})?$/', $money)) {
            return 0;
        }

        [$integer, $fraction] = array_pad(explode('.', $money, 2), 2, '');
        $fraction = str_pad($fraction, 2, '0');

        return ((int) $integer) * 100 + (int) substr($fraction, 0, 2);
    }
}
