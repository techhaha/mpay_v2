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
 * 中国工商银行客户服务动账提醒的银行专属解析。
 *
 * 微信消息方向只代表公众号发来消息，银行资金方向仍须由“交易金额”的
 * 入账/出账字段确定；仅银联入账可作为收款候选。
 */
class IcbcReceiptPayment extends BasePayment implements PaymentInterface, PayPluginInterface, ChannelNotifyInterface
{
    use BankReceiptPaymentTrait;

    /** @var array<string, mixed> */
    protected array $paymentInfo = [
        'code' => 'icbc_receipt',
        'name' => '工商银行银行卡收款',
        'plugin_type' => PaymentPluginTypeConstant::TYPE_HANGUP,
        'author' => 'MPAY',
        'version' => '1.0.0',
        'pay_types' => ['bank'],
        'transfer_types' => [],
    ];

    protected function bankName(): string
    {
        return '中国工商银行';
    }

    protected function officialAccountId(): string
    {
        return 'gh_6be1b2174d18';
    }

    protected function officialAccountName(): string
    {
        return '中国工商银行客户服务';
    }

    /**
     * 从工行“动账交易提醒”中只接受借记卡银联入账。
     *
     * 通知没有交易时间字段时使用微信消息创建时间。
     *
     * @return array{amount:int,card_tail:string,event_at:int}|null
     */
    protected function parseBankDescription(string $title, string $description, int $messageTime): ?array
    {
        if ($title !== '动账交易提醒') {
            return null;
        }
        $accountType = $this->bankField($description, '账号类型');
        if (preg_match('/^尾号(\d{4})的借记卡$/u', $accountType, $card) !== 1) {
            throw new PaymentException('工商银行入账账号类型未识别', 40200);
        }
        $amountText = $this->bankField($description, '交易金额');
        if (preg_match('/^出账\s+.+\s+人民币元$/u', $amountText) === 1) {
            return null;
        }
        if ($this->bankField($description, '交易类型') !== '银联入账'
            || preg_match('/^入账\s+(.+)\s+人民币元$/u', $amountText, $money) !== 1) {
            throw new PaymentException('工商银行交易尚未验证为人民币入账', 40200);
        }

        $timeText = $this->optionalBankField($description, '交易时间');
        $eventAt = $timeText === null ? $messageTime : $this->transactionTime($timeText);
        if ($timeText !== null && ($messageTime - $eventAt < 0 || $messageTime - $eventAt > 300)) {
            throw new PaymentException('工商银行交易时间与公众号消息时间不一致', 40200);
        }

        return [
            'amount' => $this->bankAmount($money[1]),
            'card_tail' => $card[1],
            'event_at' => $eventAt,
        ];
    }

    /** 工行交易时间包含年份，但通知只精确到分钟。 */
    private function transactionTime(string $value): int
    {
        if (preg_match('/^(\d{4})年(\d{1,2})月(\d{1,2})日\s*(\d{1,2}):(\d{2})$/u', trim($value), $m) !== 1) {
            throw new PaymentException('工商银行交易时间格式不合法', 40200);
        }
        return $this->bankTimestamp((int) $m[1], (int) $m[2], (int) $m[3], (int) $m[4], (int) $m[5], 0);
    }
}
