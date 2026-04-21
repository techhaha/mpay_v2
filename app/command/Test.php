<?php

namespace app\command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * 基础测试命令。
 *
 * 用于快速验证命令注册和控制台输出是否正常。
 */
#[AsCommand('test', 'test')]
class Test extends Command
{
    /**
     * 配置命令说明。
     *
     * @return void
     */
    protected function configure(): void
    {
    }

    /**
     * 输出命令名以验证命令注册正常。
     *
     * @param InputInterface $input 命令输入
     * @param OutputInterface $output 命令输出
     * @return int 命令退出码
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<info>Hello</info> <comment>' . $this->getName() . '</comment>');
        return self::SUCCESS;
    }
}


