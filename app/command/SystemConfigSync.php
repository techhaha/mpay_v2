<?php

namespace app\command;

use app\repository\system\config\SystemConfigRepository;
use app\service\system\config\SystemConfigDefinitionService;
use app\service\system\config\SystemConfigRuntimeService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * 系统配置同步命令。
 *
 * 将系统配置定义中的默认值同步到数据库，并刷新运行时配置缓存。
 */
#[AsCommand('system:config-sync', '同步系统配置默认值到数据库')]
class SystemConfigSync extends Command
{
    /**
     * 配置命令说明。
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->setDescription('同步 config/system_config.php 中定义的系统配置默认值到数据库。');
    }

    /**
     * 将系统配置定义同步到数据库并刷新运行时缓存。
     *
     * @param InputInterface $input 命令输入
     * @param OutputInterface $output 命令输出
     * @return int 命令退出码
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            /** @var SystemConfigDefinitionService $definitionService */
            $definitionService = container_make(SystemConfigDefinitionService::class, []);
            /** @var SystemConfigRepository $repository */
            $repository = container_make(SystemConfigRepository::class, []);
            /** @var SystemConfigRuntimeService $runtimeService */
            $runtimeService = container_make(SystemConfigRuntimeService::class, []);

            $tabs = $definitionService->tabs();
            $written = 0;

            foreach ($tabs as $tab) {
                $groupCode = (string) ($tab['key'] ?? '');
                foreach ((array) ($tab['rules'] ?? []) as $rule) {
                    if (!is_array($rule)) {
                        continue;
                    }

                    $configKey = strtolower(trim((string) ($rule['field'] ?? '')));
                    if ($configKey === '' || str_starts_with($configKey, '__')) {
                        continue;
                    }

                    $repository->updateOrCreate(
                        ['config_key' => $configKey],
                        [
                            'group_code' => $groupCode,
                            'config_value' => (string) ($rule['value'] ?? ''),
                        ]
                    );

                    $written++;
                }
            }

            $runtimeService->refresh();

            $output->writeln(sprintf('<info>系统配置同步完成</info>，写入 %d 项。', $written));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $output->writeln('<error>系统配置同步失败：' . $e->getMessage() . '</error>');

            return self::FAILURE;
        }
    }
}

