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
use DateTimeImmutable;
use DateTimeZone;

/**
 * 中国银行微银行动账消息的严格方向与金额适配。
 *
 * 支付、验签、订单匹配及业务金额恢复由 BankReceiptPaymentTrait 负责。
 * 只识别已从完整消息快照验证的“银联入账”；消费及未知交易类型不确认支付。
 */
class BankOfChinaReceiptPayment extends BasePayment implements PaymentInterface, PayPluginInterface, ChannelNotifyInterface
{
    use BankReceiptPaymentTrait;

    /** @var array<string, mixed> */
    protected array $paymentInfo = [
        'code' => 'bank_of_china_receipt',
        'name' => '中国银行银行卡收款',
        'plugin_type' => PaymentPluginTypeConstant::TYPE_HANGUP,
        'author' => 'MPAY',
        'version' => '1.0.0',
        'pay_types' => ['bank'],
        'transfer_types' => [],
    ];

    protected function bankName(): string
    {
        return '中国银行';
    }

    protected function officialAccountId(): string
    {
        return 'gh_19b52c2473d6';
    }

    protected function officialAccountName(): string
    {
        return '中国银行微银行';
    }

    /**
     * 从“交易成功提醒”的描述中只接受银联入账。
     *
     * 中行交易时间没有年份，借助同条微信消息时间选择最接近的年份；
     * 通知没有交易时间字段时使用微信消息创建时间。
     *
     * @return array{amount:int,card_tail:string,event_at:int}|null
     */
    protected function parseBankDescription(string $title, string $description, int $messageTime): ?array
    {
        if ($title !== '交易成功提醒') {
            return null;
        }
        $type = $this->bankField($description, '交易类型');
        if (preg_match('/^二维码消费（尾号\d{4}）$/u', $type) === 1) {
            return null;
        }
        if (preg_match('/^银联入账（尾号(\d{4})）$/u', $type, $match) !== 1) {
            throw new PaymentException('中国银行交易类型尚未验证为入账', 40200);
        }
        $amount = $this->bankField($description, '交易金额');
        if (preg_match('/^人民币\s*(.+)$/u', $amount, $money) !== 1) {
            throw new PaymentException('中国银行入账币种或金额格式不合法', 40200);
        }

        $timeText = $this->optionalBankField($description, '交易时间');
        $eventAt = $timeText === null ? $messageTime : $this->bankYearlessTime($timeText, $messageTime);
        if ($timeText !== null && ($messageTime - $eventAt < 0 || $messageTime - $eventAt > 300)) {
            throw new PaymentException('中国银行交易时间与公众号消息时间不一致', 40200);
        }

        return [
            'amount' => $this->bankAmount($money[1]),
            'card_tail' => $match[1],
            'event_at' => $eventAt,
        ];
    }

    /** 中行交易时间不带年份，选择最接近本条微信消息的相邻年份。 */
    private function bankYearlessTime(string $value, int $messageTime): int
    {
        if (preg_match('/^(\d{1,2})月(\d{1,2})日\s*(\d{1,2}):(\d{2})$/u', trim($value), $m) !== 1) {
            throw new PaymentException('中国银行交易时间格式不合法', 40200);
        }
        $year = (int) (new DateTimeImmutable('@' . $messageTime))
            ->setTimezone(new DateTimeZone('Asia/Shanghai'))->format('Y');
        $candidates = [];
        foreach ([$year - 1, $year, $year + 1] as $candidateYear) {
            if (checkdate((int) $m[1], (int) $m[2], $candidateYear)) {
                $candidates[] = $this->bankTimestamp(
                    $candidateYear, (int) $m[1], (int) $m[2], (int) $m[3], (int) $m[4], 0
                );
            }
        }
        if ($candidates === []) {
            throw new PaymentException('中国银行交易日期无有效年份', 40200);
        }
        usort($candidates, static fn (int $a, int $b): int =>
            abs($messageTime - $a) <=> abs($messageTime - $b));
        return $candidates[0];
    }
}
