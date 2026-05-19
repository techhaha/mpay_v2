<?php

/**
 * 系统配置默认值 Seeder。
 */
return new class {
    public string $name = 'system_config_seeder';

    private const FIXED_DEFAULTS = [
        'platform' => [
            'site_logo' => '/assets/brand/mpay-logo.svg',
            'site_logo_compact' => '/assets/brand/mpay-mark.svg',
        ],
        'cashier' => [
            'cashier_logo' => '/assets/brand/mpay-logo.svg',
        ],
    ];

    /**
     * 写入系统配置默认值。
     *
     * @param \PDO $pdo 数据库连接
     * @param array<string, mixed> $context 安装上下文
     * @return array<string, int> 执行摘要
     */
    public function run(\PDO $pdo, array $context = []): array
    {
        $tabs = require base_path(false) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'system_config.php';
        $statement = $pdo->prepare(
            'INSERT INTO `ma_system_config` (`config_key`, `config_value`, `group_code`, `created_at`, `updated_at`) VALUES (?, ?, ?, NOW(), NOW()) ' .
            'ON DUPLICATE KEY UPDATE `group_code` = VALUES(`group_code`), `updated_at` = NOW()'
        );
        $written = 0;

        foreach ($tabs as $groupCode => $tab) {
            foreach ($this->defaults((array) ($tab['rules'] ?? [])) as $key => $value) {
                $statement->execute([$key, $this->stringValue($value), (string) $groupCode]);
                $written++;
            }
        }

        foreach (self::FIXED_DEFAULTS as $groupCode => $items) {
            foreach ($items as $key => $value) {
                $statement->execute([$key, $this->stringValue($value), (string) $groupCode]);
                $written++;
            }
        }

        return ['count' => $written];
    }

    /**
     * 从系统配置页面规则中提取默认值。
     *
     * @param array<int, array<string, mixed>> $rules 配置规则
     * @return array<string, mixed> 默认值映射
     */
    private function defaults(array $rules): array
    {
        $values = [];
        foreach ($rules as $rule) {
            $field = (string) ($rule['field'] ?? '');
            if ($field !== '' && !str_starts_with($field, '__')) {
                $values[$field] = $rule['value'] ?? '';
            }

        }

        return $values;
    }

    /**
     * 转换配置入库文本。
     *
     * @param mixed $value 原始值
     * @return string 入库文本
     */
    private function stringValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE) ?: '';
        }
        if ($value === null) {
            return '';
        }

        return (string) $value;
    }
};
