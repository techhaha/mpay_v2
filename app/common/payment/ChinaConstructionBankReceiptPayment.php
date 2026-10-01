<?php

declare(strict_types=1);

namespace app\common\payment;

use app\common\base\BasePayment;
use app\common\constant\PaymentPluginTypeConstant;
use app\common\interface\ChannelNotifyInterface;
use app\common\interface\PaymentInterface;
use app\common\interface\PayPluginInterface;
use app\common\trait\BankReceiptPaymentTrait;
use app\exception\PaymentException;

/**
 * 中国建设银行交易提醒的银行专属解析。
 *
 * 只接受人民币储蓄账户“收入”；“支出”由本插件解析为非入账消息。
 */
class ChinaConstructionBankReceiptPayment extends BasePayment implements PaymentInterface, PayPluginInterface, ChannelNotifyInterface
{
    use BankReceiptPaymentTrait;

    /** @var array<string, mixed> */
    protected array $paymentInfo = [
        'code' => 'china_construction_bank_receipt',
        'name' => '建设银行银行卡收款',
        'plugin_type' => PaymentPluginTypeConstant::TYPE_HANGUP,
        'author' => 'MPAY',
        'version' => '1.0.0',
        'pay_types' => ['bank'],
        'transfer_types' => [],
    ];

    protected function bankName(): string
    {
        return '中国建设银行';
    }

    protected function officialAccountId(): string
    {
        return 'gh_a19f4d5e89e5';
    }

    protected function officialAccountName(): string
    {
        return '中国建设银行';
    }

    /**
     * 从建行“交易提醒”中只接受收入和人民币。
     *
     * 通知没有交易时间字段时使用微信消息创建时间。
     *
     * @return array{amount:int,card_tail:string,event_at:int}|null
     */
    protected function parseBankDescription(string $title, string $description, int $messageTime): ?array
    {
        if ($title !== '交易提醒') {
            return null;
        }
        $type = $this->bankField($description, '交易类型');
        if (preg_match('/^(收入|支出)（尾号(\d{4})储蓄账户）$/u', $type, $match) !== 1) {
            throw new PaymentException('建设银行交易类型格式不合法', 40200);
        }
        if ($match[1] === '支出') {
            return null;
        }
        if ($this->bankField($description, '交易币种') !== '人民币') {
            throw new PaymentException('建设银行入账币种不是人民币', 40200);
        }

        $timeText = $this->optionalBankField($description, '交易时间');
        $eventAt = $timeText === null ? $messageTime : $this->transactionTime($timeText);
        if ($timeText !== null && ($messageTime - $eventAt < 0 || $messageTime - $eventAt > 300)) {
            throw new PaymentException('建设银行交易时间与公众号消息时间不一致', 40200);
        }

        return [
            'amount' => $this->bankAmount($this->bankField($description, '交易金额')),
            'card_tail' => $match[2],
            'event_at' => $eventAt,
        ];
    }

    /** 建行交易时间为包含年份和秒的固定格式。 */
    private function transactionTime(string $value): int
    {
        if (preg_match('/^(\d{4})年(\d{1,2})月(\d{1,2})日\s*(\d{1,2}):(\d{2}):(\d{2})$/u', trim($value), $m) !== 1) {
            throw new PaymentException('建设银行交易时间格式不合法', 40200);
        }
        return $this->bankTimestamp((int) $m[1], (int) $m[2], (int) $m[3], (int) $m[4], (int) $m[5], (int) $m[6]);
    }
}
