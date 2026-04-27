<?php

namespace app\command;

use app\service\payment\runtime\MerchantNotifyDispatcherService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * 商户通知重试命令。
 *
 * 用于手动或定时重试已经到达下次重试时间的商户通知任务。
 */
#[AsCommand('payment:notify-retry', '重试到期的商户通知任务')]
class NotifyRetry extends Command
{
    public function __construct(
        protected MerchantNotifyDispatcherService $merchantNotifyDispatcherService
    ) {
        parent::__construct();
    }

    /**
     * 配置命令参数。
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_OPTIONAL, '单次最多处理多少条任务', 100);
    }

    /**
     * 执行重试。
     *
     * @param InputInterface $input 输入
     * @param OutputInterface $output 输出
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = max(1, (int) $input->getOption('limit'));
        $count = $this->merchantNotifyDispatcherService->dispatchRetryableTasks($limit);

        $output->writeln(sprintf('<info>已处理 %d 条商户通知任务</info>', $count));

        return self::SUCCESS;
    }
}
