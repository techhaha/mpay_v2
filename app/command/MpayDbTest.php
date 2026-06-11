<?php

namespace app\command;

use app\common\constant\CommonConstant;
use app\model\admin\ChannelDailyStat;
use app\model\admin\ChannelNotifyLog;
use app\model\admin\PayCallbackLog;
use app\model\admin\PayOrderOperationLog;
use app\model\merchant\Merchant;
use app\model\merchant\MerchantAccount;
use app\model\merchant\MerchantAccountLedger;
use app\model\merchant\MerchantApiCredential;
use app\model\merchant\MerchantFundFreeze;
use app\model\merchant\MerchantGroup;
use app\model\payment\BizOrder;
use app\model\payment\NotifyTask;
use app\model\payment\PaymentChannel;
use app\model\payment\PaymentPlugin;
use app\model\payment\PaymentPluginConf;
use app\model\payment\PaymentPollGroup;
use app\model\payment\PaymentPollGroupBind;
use app\model\payment\PaymentPollGroupChannel;
use app\model\payment\PaymentType;
use app\model\payment\PayOrder;
use app\model\payment\RefundOrder;
use app\model\payment\SettlementItem;
use app\model\payment\SettlementOrder;
use app\repository\account\balance\MerchantAccountRepository;
use app\repository\account\freeze\MerchantFundFreezeRepository;
use app\repository\account\ledger\MerchantAccountLedgerRepository;
use app\repository\merchant\base\MerchantRepository;
use app\repository\payment\config\PaymentChannelRepository;
use app\repository\payment\config\PaymentPluginConfRepository;
use app\repository\payment\config\PaymentPluginRepository;
use app\repository\payment\config\PaymentTypeRepository;
use app\repository\payment\notify\NotifyTaskRepository;
use app\repository\payment\settlement\SettlementOrderRepository;
use app\repository\payment\trade\PayOrderRepository;
use app\repository\payment\trade\RefundOrderRepository;
use app\service\payment\runtime\PaymentRouteService;
use app\service\payment\trace\TradeTraceService;
use support\Db;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * MPAY 数据库集成检查命令。
 *
 * 默认只读运行，覆盖主链路模型表结构、仓库查询、核心配置关联和排障查询入口。
 */
#[AsCommand('mpay:db-test', '运行 MPAY 数据库集成检查')]
class MpayDbTest extends Command
{
    /**
     * @var array<int, string>
     */
    private array $failures = [];

    /**
     * @var array<int, string>
     */
    private array $skips = [];

    /**
     * @var array<int, string>
     */
    private array $warnings = [];

    private bool $showBroken = false;

    private bool $fixDisableBrokenChannels = false;

    /**
     * 配置命令参数。
     *
     * @return void
     */
    protected function configure(): void
    {
        $this
            ->setDescription('运行 MPAY 数据库集成检查，默认只读。')
            ->addOption('show-broken', null, InputOption::VALUE_NONE, '输出配置断链明细')
            ->addOption('fix-disable-broken-channels', null, InputOption::VALUE_NONE, '将断链支付通道置为禁用状态');
    }

    /**
     * 执行数据库集成检查。
     *
     * @param InputInterface $input 命令输入
     * @param OutputInterface $output 输出对象
     * @return int 命令退出码
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->showBroken = (bool) $input->getOption('show-broken');
        $this->fixDisableBrokenChannels = (bool) $input->getOption('fix-disable-broken-channels');

        $cases = [
            'db.connection' => fn () => $this->checkConnection(),
            'schema.core_tables' => fn () => $this->checkCoreTables(),
            'schema.model_casts' => fn () => $this->checkModelCasts(),
            'repository.core_queries' => fn () => $this->checkRepositoryQueries(),
            'config.plugin_channel_relations' => fn () => $this->checkPluginChannelRelations(),
            'route.config_relations' => fn () => $this->checkRouteConfigRelations(),
            'trade.data_relations' => fn () => $this->checkTradeDataRelations(),
            'finance.reconciliation_samples' => fn () => $this->checkFinanceSamples(),
            'ops.trace_queries' => fn () => $this->checkTraceQueries(),
        ];

        $passed = 0;
        foreach ($cases as $name => $case) {
            try {
                $message = (string) $case();
                $passed++;
                $output->writeln(sprintf('<info>[通过]</info> %s%s', $name, $message !== '' ? ' - ' . $message : ''));
            } catch (SkipCaseException $e) {
                $this->skips[] = sprintf('%s: %s', $name, $e->getMessage());
                $output->writeln(sprintf('<comment>[跳过]</comment> %s - %s', $name, $e->getMessage()));
            } catch (Throwable $e) {
                $this->failures[] = sprintf('%s: %s', $name, $e->getMessage());
                $output->writeln(sprintf('<error>[失败]</error> %s - %s', $name, $e->getMessage()));
            }
        }

        $output->writeln(sprintf(
            '汇总: %d 通过, %d 跳过, %d 告警, %d 失败',
            $passed,
            count($this->skips),
            count($this->warnings),
            count($this->failures)
        ));

        foreach ($this->warnings as $warning) {
            $output->writeln('<comment>- ' . $warning . '</comment>');
        }

        foreach ($this->failures as $failure) {
            $output->writeln('<error>- ' . $failure . '</error>');
        }

        return $this->failures === [] ? self::SUCCESS : self::FAILURE;
    }

    private function checkConnection(): string
    {
        Db::connection()->select('select 1 as ok');

        return '数据库连接正常';
    }

    private function checkCoreTables(): string
    {
        $expectations = [
            Merchant::class => ['id', 'merchant_no', 'group_id', 'pay_status', 'settle_status', 'status'],
            MerchantGroup::class => ['id', 'group_name', 'status'],
            MerchantApiCredential::class => ['id', 'merchant_id', 'api_key', 'merchant_public_key', 'status'],
            MerchantAccount::class => ['id', 'merchant_id', 'available_balance', 'frozen_balance'],
            MerchantAccountLedger::class => ['id', 'ledger_no', 'merchant_id', 'biz_type', 'biz_no', 'amount', 'idempotency_key'],
            MerchantFundFreeze::class => ['id', 'freeze_no', 'merchant_id', 'freeze_amount', 'remaining_amount', 'status'],
            PaymentType::class => ['id', 'code', 'name', 'status'],
            PaymentPlugin::class => ['code', 'class_name', 'config_schema', 'pay_types', 'allow_merchant', 'status'],
            PaymentPluginConf::class => ['id', 'merchant_id', 'plugin_code', 'config', 'settlement_cycle_type'],
            PaymentChannel::class => ['id', 'merchant_id', 'pay_type_id', 'plugin_code', 'api_config_id', 'channel_mode', 'status'],
            PaymentPollGroup::class => ['id', 'group_name', 'route_mode', 'status'],
            PaymentPollGroupChannel::class => ['id', 'poll_group_id', 'channel_id', 'weight', 'status'],
            PaymentPollGroupBind::class => ['id', 'merchant_group_id', 'pay_type_id', 'poll_group_id', 'status'],
            BizOrder::class => ['id', 'biz_no', 'trace_no', 'merchant_id', 'merchant_order_no', 'order_amount', 'status'],
            PayOrder::class => ['id', 'pay_no', 'biz_no', 'trace_no', 'merchant_id', 'channel_id', 'pay_type_id', 'pay_amount', 'status'],
            RefundOrder::class => ['id', 'refund_no', 'pay_no', 'merchant_id', 'refund_amount', 'status'],
            SettlementOrder::class => ['id', 'settle_no', 'merchant_id', 'gross_amount', 'net_amount', 'status'],
            SettlementItem::class => ['id', 'settle_no', 'merchant_id', 'pay_no', 'net_amount', 'item_status'],
            NotifyTask::class => ['id', 'notify_no', 'event_type', 'ref_no', 'merchant_id', 'notify_url', 'status', 'retry_count'],
            PayCallbackLog::class => ['id', 'pay_no', 'request_data', 'request_hash', 'verify_status', 'process_status'],
            ChannelNotifyLog::class => ['id', 'notify_no', 'channel_id', 'raw_payload', 'verify_status', 'process_status'],
            ChannelDailyStat::class => ['id', 'channel_id', 'stat_date', 'pay_success_count', 'pay_amount', 'health_score'],
            PayOrderOperationLog::class => ['id', 'pay_no', 'action', 'admin_id', 'result_status', 'created_at'],
        ];

        $schema = Db::connection()->getSchemaBuilder();
        foreach ($expectations as $modelClass => $columns) {
            $table = $this->table($modelClass);
            if (!$schema->hasTable($table)) {
                throw new \RuntimeException('缺少数据表 ' . $table);
            }

            $exists = $schema->getColumnListing($table);
            $missing = array_values(array_diff($columns, $exists));
            if ($missing !== []) {
                throw new \RuntimeException(sprintf('%s 缺少字段: %s', $table, implode(', ', $missing)));
            }
        }

        return sprintf('已检查 %d 张核心表', count($expectations));
    }

    private function checkModelCasts(): string
    {
        $samples = [
            [new PaymentPlugin(['config_schema' => [['field' => 'app_id']], 'pay_types' => ['alipay']]), 'config_schema', 'array'],
            [new PaymentPluginConf(['config' => ['secret' => 'value']]), 'config', 'array'],
            [new PayOrder(['pay_amount' => '100', 'ext_json' => ['a' => 1]]), 'pay_amount', 'integer'],
            [new PayOrder(['pay_amount' => '100', 'ext_json' => ['a' => 1]]), 'ext_json', 'array'],
            [new NotifyTask(['notify_data' => ['pay_no' => 'P1'], 'retry_count' => '2']), 'notify_data', 'array'],
            [new NotifyTask(['notify_data' => ['pay_no' => 'P1'], 'retry_count' => '2']), 'retry_count', 'integer'],
            [new MerchantAccount(['available_balance' => '100']), 'available_balance', 'integer'],
            [new MerchantFundFreeze(['remaining_amount' => '50']), 'remaining_amount', 'integer'],
        ];

        foreach ($samples as [$model, $field, $type]) {
            $value = $model->{$field};
            $ok = match ($type) {
                'array' => is_array($value),
                'integer' => is_int($value),
                default => true,
            };
            if (!$ok) {
                throw new \RuntimeException(sprintf('%s::%s cast 不是 %s', $model::class, $field, $type));
            }
        }

        return '模型 casts 正常';
    }

    private function checkRepositoryQueries(): string
    {
        $repositories = [
            MerchantRepository::class,
            MerchantAccountRepository::class,
            MerchantAccountLedgerRepository::class,
            MerchantFundFreezeRepository::class,
            PaymentTypeRepository::class,
            PaymentPluginRepository::class,
            PaymentPluginConfRepository::class,
            PaymentChannelRepository::class,
            PayOrderRepository::class,
            RefundOrderRepository::class,
            SettlementOrderRepository::class,
            NotifyTaskRepository::class,
        ];

        foreach ($repositories as $repositoryClass) {
            $repository = container_get($repositoryClass);
            $repository->query()->limit(1)->get();
        }

        return sprintf('已检查 %d 个核心仓库', count($repositories));
    }

    private function checkPluginChannelRelations(): string
    {
        $pluginCount = PaymentPlugin::query()->count();
        $payTypeCount = PaymentType::query()->count();
        if ($pluginCount <= 0 || $payTypeCount <= 0) {
            throw new SkipCaseException('支付插件或支付方式为空，安装初始化后再检查配置关联');
        }

        $brokenConfigQuery = PaymentPluginConf::query()
            ->from('ma_payment_plugin_conf as pc')
            ->leftJoin((new PaymentPlugin())->getTable() . ' as p', 'pc.plugin_code', '=', 'p.code')
            ->whereNull('p.code');
        $brokenConfig = (clone $brokenConfigQuery)
            ->count();
        if ($brokenConfig > 0) {
            $this->warn('存在未绑定有效插件的插件配置: ' . $brokenConfig);
            $this->appendBrokenRows(
                '无效插件配置',
                (clone $brokenConfigQuery)->orderBy('pc.id')->limit(20)->get(['pc.id', 'pc.merchant_id', 'pc.plugin_code'])
            );
        }

        $brokenChannelPluginQuery = PaymentChannel::query()
            ->from('ma_payment_channel as c')
            ->leftJoin((new PaymentPlugin())->getTable() . ' as p', 'c.plugin_code', '=', 'p.code')
            ->whereNull('p.code');
        $brokenChannelPlugin = (clone $brokenChannelPluginQuery)
            ->count();
        if ($brokenChannelPlugin > 0) {
            $this->warn('存在未绑定有效插件的支付通道: ' . $brokenChannelPlugin);
            $this->appendBrokenRows(
                '无效插件通道',
                (clone $brokenChannelPluginQuery)->orderBy('c.id')->limit(20)->get(['c.id', 'c.name', 'c.merchant_id', 'c.plugin_code', 'c.api_config_id', 'c.status'])
            );
        }

        $brokenChannelPayTypeQuery = PaymentChannel::query()
            ->from('ma_payment_channel as c')
            ->leftJoin((new PaymentType())->getTable() . ' as t', 'c.pay_type_id', '=', 't.id')
            ->whereNull('t.id');
        $brokenChannelPayType = (clone $brokenChannelPayTypeQuery)
            ->count();
        if ($brokenChannelPayType > 0) {
            $this->warn('存在未绑定有效支付方式的支付通道: ' . $brokenChannelPayType);
            $this->appendBrokenRows(
                '无效支付方式通道',
                (clone $brokenChannelPayTypeQuery)->orderBy('c.id')->limit(20)->get(['c.id', 'c.name', 'c.merchant_id', 'c.pay_type_id', 'c.plugin_code', 'c.api_config_id', 'c.status'])
            );
        }

        $brokenChannelConfigQuery = PaymentChannel::query()
            ->from('ma_payment_channel as c')
            ->leftJoin((new PaymentPluginConf())->getTable() . ' as pc', 'c.api_config_id', '=', 'pc.id')
            ->whereNull('pc.id');
        $brokenChannelConfig = (clone $brokenChannelConfigQuery)
            ->count();
        if ($brokenChannelConfig > 0) {
            $this->warn('存在未绑定有效插件配置的支付通道: ' . $brokenChannelConfig);
            $this->appendBrokenRows(
                '无效插件配置通道',
                (clone $brokenChannelConfigQuery)->orderBy('c.id')->limit(20)->get(['c.id', 'c.name', 'c.merchant_id', 'c.plugin_code', 'c.api_config_id', 'c.status'])
            );
        }

        if ($this->fixDisableBrokenChannels) {
            $fixed = $this->disableBrokenChannels();
            if ($fixed > 0) {
                $this->warn('已禁用断链支付通道: ' . $fixed);
            }
        }

        return sprintf('插件=%d 支付方式=%d 通道配置关联正常', $pluginCount, $payTypeCount);
    }

    private function disableBrokenChannels(): int
    {
        $brokenIds = PaymentChannel::query()
            ->from('ma_payment_channel as c')
            ->leftJoin((new PaymentPlugin())->getTable() . ' as p', 'c.plugin_code', '=', 'p.code')
            ->leftJoin((new PaymentType())->getTable() . ' as t', 'c.pay_type_id', '=', 't.id')
            ->leftJoin((new PaymentPluginConf())->getTable() . ' as pc', 'c.api_config_id', '=', 'pc.id')
            ->where(function ($query) {
                $query->whereNull('p.code')
                    ->orWhereNull('t.id')
                    ->orWhereNull('pc.id');
            })
            ->pluck('c.id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if ($brokenIds === []) {
            return 0;
        }

        return (int) PaymentChannel::query()
            ->whereIn('id', $brokenIds)
            ->where('status', '<>', CommonConstant::STATUS_DISABLED)
            ->update(['status' => CommonConstant::STATUS_DISABLED]);
    }

    private function appendBrokenRows(string $title, iterable $rows): void
    {
        if (!$this->showBroken) {
            return;
        }

        foreach ($rows as $row) {
            $this->warn($title . ': ' . json_encode($row->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
    }

    private function checkRouteConfigRelations(): string
    {
        $bindCount = PaymentPollGroupBind::query()->count();
        if ($bindCount <= 0) {
            throw new SkipCaseException('轮询组绑定为空，暂无路由配置可检查');
        }

        $brokenGroup = PaymentPollGroupBind::query()
            ->leftJoin((new MerchantGroup())->getTable() . ' as g', 'ma_payment_poll_group_bind.merchant_group_id', '=', 'g.id')
            ->whereNull('g.id')
            ->count();
        $brokenPayType = PaymentPollGroupBind::query()
            ->leftJoin((new PaymentType())->getTable() . ' as t', 'ma_payment_poll_group_bind.pay_type_id', '=', 't.id')
            ->whereNull('t.id')
            ->count();
        $brokenPollGroup = PaymentPollGroupBind::query()
            ->leftJoin((new PaymentPollGroup())->getTable() . ' as pg', 'ma_payment_poll_group_bind.poll_group_id', '=', 'pg.id')
            ->whereNull('pg.id')
            ->count();
        $brokenGroupChannel = PaymentPollGroupChannel::query()
            ->leftJoin((new PaymentPollGroup())->getTable() . ' as pg', 'ma_payment_poll_group_channel.poll_group_id', '=', 'pg.id')
            ->whereNull('pg.id')
            ->count();
        $brokenChannel = PaymentPollGroupChannel::query()
            ->leftJoin((new PaymentChannel())->getTable() . ' as c', 'ma_payment_poll_group_channel.channel_id', '=', 'c.id')
            ->whereNull('c.id')
            ->count();

        $broken = $brokenGroup + $brokenPayType + $brokenPollGroup + $brokenGroupChannel + $brokenChannel;
        if ($broken > 0) {
            throw new \RuntimeException(sprintf(
                '路由配置存在断链 group=%d pay_type=%d poll_group=%d group_channel=%d channel=%d',
                $brokenGroup,
                $brokenPayType,
                $brokenPollGroup,
                $brokenGroupChannel,
                $brokenChannel
            ));
        }

        $service = container_get(PaymentRouteService::class);
        $this->ensureMethod($service, 'resolveByMerchantGroup');
        $this->ensureMethod($service, 'previewAvailablePayTypes');

        return '路由配置关联正常';
    }

    private function checkTradeDataRelations(): string
    {
        $payOrderCount = PayOrder::query()->count();
        if ($payOrderCount <= 0) {
            throw new SkipCaseException('支付订单为空，暂无交易样本可检查');
        }

        $brokenMerchant = PayOrder::query()
            ->leftJoin((new Merchant())->getTable() . ' as m', 'ma_pay_order.merchant_id', '=', 'm.id')
            ->whereNull('m.id')
            ->count();
        $brokenPayType = PayOrder::query()
            ->leftJoin((new PaymentType())->getTable() . ' as t', 'ma_pay_order.pay_type_id', '=', 't.id')
            ->whereNull('t.id')
            ->count();
        $brokenChannel = PayOrder::query()
            ->leftJoin((new PaymentChannel())->getTable() . ' as c', 'ma_pay_order.channel_id', '=', 'c.id')
            ->where('ma_pay_order.channel_id', '>', 0)
            ->whereNull('c.id')
            ->count();

        if ($brokenMerchant + $brokenPayType + $brokenChannel > 0) {
            throw new \RuntimeException(sprintf('支付订单存在断链 merchant=%d pay_type=%d channel=%d', $brokenMerchant, $brokenPayType, $brokenChannel));
        }

        $brokenRefundPay = RefundOrder::query()
            ->leftJoin((new PayOrder())->getTable() . ' as p', 'ma_refund_order.pay_no', '=', 'p.pay_no')
            ->whereNull('p.pay_no')
            ->count();
        if ($brokenRefundPay > 0) {
            throw new \RuntimeException('退款单存在无效支付单关联: ' . $brokenRefundPay);
        }

        return '交易样本关联正常';
    }

    private function checkFinanceSamples(): string
    {
        $accountCount = MerchantAccount::query()->count();
        if ($accountCount <= 0) {
            throw new SkipCaseException('商户资金账户为空，暂无资金样本可检查');
        }

        $negativeAccounts = MerchantAccount::query()
            ->where(function ($query) {
                $query->where('available_balance', '<', 0)
                    ->orWhere('frozen_balance', '<', 0);
            })
            ->count();
        if ($negativeAccounts > 0) {
            throw new \RuntimeException('存在负数资金账户: ' . $negativeAccounts);
        }

        $brokenLedgerMerchant = MerchantAccountLedger::query()
            ->leftJoin((new Merchant())->getTable() . ' as m', 'ma_merchant_account_ledger.merchant_id', '=', 'm.id')
            ->whereNull('m.id')
            ->count();
        if ($brokenLedgerMerchant > 0) {
            throw new \RuntimeException('资金流水存在无效商户关联: ' . $brokenLedgerMerchant);
        }

        $brokenFreezeMerchant = MerchantFundFreeze::query()
            ->leftJoin((new Merchant())->getTable() . ' as m', 'ma_merchant_fund_freeze.merchant_id', '=', 'm.id')
            ->whereNull('m.id')
            ->count();
        if ($brokenFreezeMerchant > 0) {
            throw new \RuntimeException('冻结明细存在无效商户关联: ' . $brokenFreezeMerchant);
        }

        $negativeFreezes = MerchantFundFreeze::query()
            ->where(function ($query) {
                $query->where('freeze_amount', '<', 0)
                    ->orWhere('remaining_amount', '<', 0);
            })
            ->count();
        if ($negativeFreezes > 0) {
            throw new \RuntimeException('存在负数冻结明细: ' . $negativeFreezes);
        }

        return '资金样本基础一致性正常';
    }

    private function checkTraceQueries(): string
    {
        $traceNo = (string) (PayOrder::query()
            ->whereNotNull('trace_no')
            ->where('trace_no', '<>', '')
            ->orderByDesc('id')
            ->value('trace_no') ?? '');
        if ($traceNo === '') {
            throw new SkipCaseException('暂无 trace_no 样本，跳过追踪聚合查询');
        }

        $service = container_get(TradeTraceService::class);
        $result = $service->queryByTraceNo($traceNo);
        if (!is_array($result) || (string) ($result['resolved_trace_no'] ?? '') === '') {
            throw new \RuntimeException('追踪聚合查询未返回有效结果');
        }

        return '追踪聚合查询正常 trace_no=' . $traceNo;
    }

    /**
     * 获取模型表名。
     *
     * @param class-string $modelClass 模型类
     * @return string 表名
     */
    private function table(string $modelClass): string
    {
        return (new $modelClass())->getTable();
    }

    private function ensureMethod(object $instance, string $method): void
    {
        if (!method_exists($instance, $method)) {
            throw new \RuntimeException(sprintf('未找到方法 %s::%s', $instance::class, $method));
        }
    }

    private function warn(string $message): void
    {
        $this->warnings[] = $message;
    }
}

class SkipCaseException extends \RuntimeException
{
}
