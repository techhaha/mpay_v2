<?php

namespace app\command;

use app\common\constant\AuthConstant;
use app\common\constant\CommonConstant;
use app\common\util\FormatHelper;
use app\exception\CommandException;
use app\model\merchant\Merchant;
use app\repository\merchant\base\MerchantRepository;
use app\repository\merchant\credential\MerchantApiCredentialRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * ePay V2 开发联调初始化命令。
 *
 * 负责生成平台 RSA 密钥和测试商户 RSA 密钥，并把商户公钥写回数据库。
 */
#[AsCommand('epay:v2-bootstrap', '初始化 ePay V2 开发联调密钥')]
class EpayV2Bootstrap extends Command
{
    /**
     * 配置命令参数。
     *
     * @return void
     */
    protected function configure(): void
    {
        $this
            ->setDescription('为开发环境生成平台 RSA 密钥、测试商户 RSA 密钥，并写回商户公钥。')
            ->addOption('merchant-id', null, InputOption::VALUE_OPTIONAL, '指定商户 ID')
            ->addOption('merchant-no', null, InputOption::VALUE_OPTIONAL, '指定商户号')
            ->addOption('force-platform', null, InputOption::VALUE_NONE, '强制覆盖已存在的平台密钥文件')
            ->addOption('force-merchant', null, InputOption::VALUE_NONE, '强制覆盖已存在的商户密钥文件和商户 RSA 公钥');
    }

    /**
     * 执行初始化。
     *
     * @param InputInterface $input 命令输入
     * @param OutputInterface $output 输出对象
     * @return int 命令退出码
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $merchant = $this->pickMerchant(
                $this->optionInt($input, 'merchant-id', 0),
                trim($this->optionString($input, 'merchant-no', ''))
            );
            $directory = base_path(false) . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'epay';
            $forcePlatform = $this->optionBool($input, 'force-platform', false);
            $forceMerchant = $this->optionBool($input, 'force-merchant', false);

            if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
                throw new CommandException('创建密钥目录失败: ' . $directory);
            }

            $platformPaths = $this->bootstrapPlatformKeys($directory, $forcePlatform);
            $merchantPaths = $this->bootstrapMerchantKeys($merchant, $directory, $forceMerchant);

            $output->writeln('<info>[完成]</info> ePay V2 开发联调密钥已就绪');
            $output->writeln(sprintf(
                '商户: id=%d no=%s name=%s',
                (int) $merchant->id,
                (string) $merchant->merchant_no,
                (string) $merchant->merchant_name
            ));
            $output->writeln('平台私钥: ' . $platformPaths['private']);
            $output->writeln('平台公钥: ' . $platformPaths['public']);
            $output->writeln('商户私钥: ' . $merchantPaths['private']);
            $output->writeln('商户公钥: ' . $merchantPaths['public']);
            $output->writeln('商户公钥预览: ' . FormatHelper::maskCredentialValue($merchantPaths['public_key'], false));
            $output->writeln('下一步可直接运行: php webman epay:v2-api --live --merchant-id=' . (int) $merchant->id);

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $output->writeln('<error>[失败]</error> ' . $this->formatThrowable($e));

            return self::FAILURE;
        }
    }

    /**
     * 生成或复用平台 RSA 密钥。
     *
     * @param string $directory 密钥目录
     * @param bool $force 是否强制覆盖
     * @return array{private: string, public: string}
     * @throws CommandException
     */
    private function bootstrapPlatformKeys(string $directory, bool $force): array
    {
        $privatePath = $directory . DIRECTORY_SEPARATOR . 'platform-private.pem';
        $publicPath = $directory . DIRECTORY_SEPARATOR . 'platform-public.pem';

        if (!$force && is_file($privatePath) && is_file($publicPath)) {
            return ['private' => $privatePath, 'public' => $publicPath];
        }

        $pair = $this->generateKeyPair();
        $this->writePemFile($privatePath, $pair['private_key']);
        $this->writePemFile($publicPath, $pair['public_key']);

        return ['private' => $privatePath, 'public' => $publicPath];
    }

    /**
     * 生成或复用商户 RSA 密钥，并写回商户公钥。
     *
     * @param Merchant $merchant 商户
     * @param string $directory 密钥目录
     * @param bool $force 是否强制覆盖
     * @return array{private: string, public: string, public_key: string}
     * @throws CommandException
     */
    private function bootstrapMerchantKeys(Merchant $merchant, string $directory, bool $force): array
    {
        /** @var MerchantApiCredentialRepository $credentialRepository */
        $credentialRepository = $this->resolve(MerchantApiCredentialRepository::class);
        $credential = $credentialRepository->findByMerchantId((int) $merchant->id);
        $privatePath = $directory . DIRECTORY_SEPARATOR . sprintf('merchant-%d-private.pem', (int) $merchant->id);
        $publicPath = $directory . DIRECTORY_SEPARATOR . sprintf('merchant-%d-public.pem', (int) $merchant->id);

        if (!$force && is_file($privatePath) && is_file($publicPath) && trim((string) ($credential?->merchant_public_key ?? '')) !== '') {
            return [
                'private' => $privatePath,
                'public' => $publicPath,
                'public_key' => trim((string) ($credential?->merchant_public_key ?? '')),
            ];
        }

        $pair = $this->generateKeyPair();
        $this->writePemFile($privatePath, $pair['private_key']);
        $this->writePemFile($publicPath, $pair['public_key']);

        $credentialRepository->updateOrCreate(
            ['merchant_id' => (int) $merchant->id],
            [
                'merchant_id' => (int) $merchant->id,
                'status' => AuthConstant::CREDENTIAL_STATUS_ENABLED,
                'api_key' => (string) ($credential?->api_key ?: bin2hex(random_bytes(16))),
                'merchant_public_key' => $pair['public_key'],
            ]
        );

        return [
            'private' => $privatePath,
            'public' => $publicPath,
            'public_key' => $pair['public_key'],
        ];
    }

    /**
     * 选择目标商户。
     *
     * @param int $merchantId 商户ID
     * @param string $merchantNo 商户号
     * @return Merchant 商户
     * @throws CommandException
     */
    private function pickMerchant(int $merchantId, string $merchantNo): Merchant
    {
        /** @var MerchantRepository $merchantRepository */
        $merchantRepository = $this->resolve(MerchantRepository::class);

        if ($merchantId > 0) {
            $merchant = $merchantRepository->find($merchantId);
            if (!$merchant || (int) $merchant->status !== CommonConstant::STATUS_ENABLED) {
                throw new CommandException('指定商户不存在或未启用: ' . $merchantId);
            }

            return $merchant;
        }

        if ($merchantNo !== '') {
            $merchant = $merchantRepository->findByMerchantNo($merchantNo);
            if (!$merchant || (int) $merchant->status !== CommonConstant::STATUS_ENABLED) {
                throw new CommandException('指定商户不存在或未启用: ' . $merchantNo);
            }

            return $merchant;
        }

        $merchant = $merchantRepository->enabledList(['id', 'merchant_no', 'merchant_name', 'status'])->first();
        if (!$merchant) {
            throw new CommandException('未找到启用中的商户。');
        }

        return $merchant;
    }

    /**
     * 生成 RSA 密钥对。
     *
     * @return array{private_key: string, public_key: string}
     * @throws CommandException
     */
    private function generateKeyPair(): array
    {
        $this->ensureOpenSslConfig();
        $pair = $this->generateKeyPairWithOpenSsl();
        if ($pair !== null) {
            return $pair;
        }

        $pair = $this->generateKeyPairWithSshKeygen();
        if ($pair !== null) {
            return $pair;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $pair = $this->generateKeyPairWithPowerShell();
            if ($pair !== null) {
                return $pair;
            }
        }

        throw new CommandException('生成 RSA 密钥对失败');
    }

    /**
     * 尝试通过 OpenSSL 扩展生成密钥对。
     *
     * @return array{private_key: string, public_key: string}|null
     */
    private function generateKeyPairWithOpenSsl(): ?array
    {
        while (openssl_error_string()) {
        }

        $resource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        if ($resource === false) {
            return null;
        }

        $privateKey = '';
        if (!openssl_pkey_export($resource, $privateKey) || trim($privateKey) === '') {
            return null;
        }

        $details = openssl_pkey_get_details($resource);
        $publicKey = trim((string) ($details['key'] ?? ''));
        if ($publicKey === '') {
            return null;
        }

        return [
            'private_key' => trim($privateKey),
            'public_key' => $publicKey,
        ];
    }

    /**
     * 通过系统自带 ssh-keygen 生成 PEM 私钥和 PKCS8 公钥。
     *
     * @return array{private_key: string, public_key: string}|null
     */
    private function generateKeyPairWithSshKeygen(): ?array
    {
        $runtimeDir = base_path(false) . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'epay';
        if (!is_dir($runtimeDir) && !mkdir($runtimeDir, 0777, true) && !is_dir($runtimeDir)) {
            return null;
        }

        $basePath = $runtimeDir . DIRECTORY_SEPARATOR . 'sshkeygen-' . bin2hex(random_bytes(6));
        $privatePath = str_replace('\\', '/', $basePath);
        $publicPath = $privatePath . '.pub';

        try {
            $generateCommand = sprintf(
                'ssh-keygen -q -t rsa -b 2048 -m PEM -N "" -f %s 2>&1',
                escapeshellarg($privatePath)
            );
            shell_exec($generateCommand);

            if (!is_file($basePath) || !is_file($basePath . '.pub')) {
                return null;
            }

            $privateKey = trim((string) file_get_contents($basePath));
            $exportCommand = sprintf(
                'ssh-keygen -e -m PKCS8 -f %s 2>&1',
                escapeshellarg($publicPath)
            );
            $publicKey = trim((string) shell_exec($exportCommand));
            if ($privateKey === '' || $publicKey === '' || !str_contains($publicKey, 'BEGIN PUBLIC KEY')) {
                return null;
            }

            return [
                'private_key' => $privateKey,
                'public_key' => $publicKey,
            ];
        } finally {
            @unlink($basePath);
            @unlink($basePath . '.pub');
        }
    }

    /**
     * 在 Windows 下通过 PowerShell/.NET 生成密钥对。
     *
     * @return array{private_key: string, public_key: string}|null
     */
    private function generateKeyPairWithPowerShell(): ?array
    {
        $script = <<<'POWERSHELL'
$rsa = [System.Security.Cryptography.RSA]::Create(2048)
function Convert-ToPem([string]$Label, [byte[]]$Bytes) {
    $base64 = [System.Convert]::ToBase64String($Bytes)
    $lines = [System.Text.RegularExpressions.Regex]::Matches($base64, '.{1,64}') | ForEach-Object { $_.Value }
    return "-----BEGIN $Label-----`n$($lines -join "`n")`n-----END $Label-----"
}
$result = [ordered]@{
    private_key = Convert-ToPem 'PRIVATE KEY' ($rsa.ExportPkcs8PrivateKey())
    public_key = Convert-ToPem 'PUBLIC KEY' ($rsa.ExportSubjectPublicKeyInfo())
}
$result | ConvertTo-Json -Compress
POWERSHELL;

        $encodedScript = iconv('UTF-8', 'UTF-16LE', $script);
        if ($encodedScript === false) {
            return null;
        }

        $command = 'powershell -NoProfile -NonInteractive -ExecutionPolicy Bypass -EncodedCommand ' . base64_encode($encodedScript) . ' 2>&1';
        $output = shell_exec($command);
        if (!is_string($output) || trim($output) === '') {
            return null;
        }

        $decoded = json_decode(trim($output), true);
        if (!is_array($decoded)) {
            return null;
        }

        $privateKey = trim((string) ($decoded['private_key'] ?? ''));
        $publicKey = trim((string) ($decoded['public_key'] ?? ''));
        if ($privateKey === '' || $publicKey === '') {
            return null;
        }

        return [
            'private_key' => $privateKey,
            'public_key' => $publicKey,
        ];
    }

    /**
     * 为 openssl_pkey_new() 补齐配置文件路径。
     *
     * 某些 Windows PHP 环境默认把 openssl.cnf 指向不存在的位置，这里优先回退到
     * 当前 PHP 目录自带的 extras/ssl/openssl.cnf。
     *
     * @return void
     */
    private function ensureOpenSslConfig(): void
    {
        $current = trim((string) getenv('OPENSSL_CONF'));
        if ($current !== '' && is_file($current)) {
            return;
        }

        $candidates = [];
        $loadedIni = php_ini_loaded_file();
        if (is_string($loadedIni) && $loadedIni !== '') {
            $candidates[] = dirname($loadedIni) . DIRECTORY_SEPARATOR . 'extras' . DIRECTORY_SEPARATOR . 'ssl' . DIRECTORY_SEPARATOR . 'openssl.cnf';
        }
        if (defined('PHP_BINARY')) {
            $candidates[] = dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . 'extras' . DIRECTORY_SEPARATOR . 'ssl' . DIRECTORY_SEPARATOR . 'openssl.cnf';
        }
        $locations = openssl_get_cert_locations();
        if (!empty($locations['default_cert_file'])) {
            $candidates[] = (string) $locations['default_cert_file'];
        }

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '' && is_file($candidate)) {
                putenv('OPENSSL_CONF=' . $candidate);
                $_ENV['OPENSSL_CONF'] = $candidate;
                return;
            }
        }
    }

    /**
     * 写入 PEM 文件。
     *
     * @param string $path 文件路径
     * @param string $content 文件内容
     * @return void
     * @throws CommandException
     */
    private function writePemFile(string $path, string $content): void
    {
        if (file_put_contents($path, $content . PHP_EOL) === false) {
            throw new CommandException('写入密钥文件失败: ' . $path);
        }
    }

    /**
     * 格式化异常信息。
     *
     * @param Throwable $e 异常
     * @return string 文本信息
     */
    private function formatThrowable(\Throwable $e): string
    {
        return $e::class . '：' . $e->getMessage();
    }

    /**
     * 读取字符串选项。
     *
     * @param InputInterface $input 命令输入
     * @param string $name 选项名称
     * @param string $default 默认值
     * @return string 选项值
     */
    private function optionString(InputInterface $input, string $name, string $default = ''): string
    {
        $value = $input->getOption($name);
        return $value === null || $value === false ? $default : (is_string($value) ? $value : (string) $value);
    }

    /**
     * 读取整数选项。
     *
     * @param InputInterface $input 命令输入
     * @param string $name 选项名称
     * @param int $default 默认值
     * @return int 选项值
     */
    private function optionInt(InputInterface $input, string $name, int $default = 0): int
    {
        $value = $input->getOption($name);
        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * 读取布尔选项。
     *
     * @param InputInterface $input 命令输入
     * @param string $name 选项名称
     * @param bool $default 默认值
     * @return bool 布尔值
     */
    private function optionBool(InputInterface $input, string $name, bool $default = false): bool
    {
        $value = $input->getOption($name);
        if ($value === null || $value === '') {
            return $default;
        }

        $filtered = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

        return $filtered === null ? $default : $filtered;
    }

    /**
     * 从容器解析实例。
     *
     * @param string $class 类名
     * @return object 实例
     * @throws CommandException
     */
    private function resolve(string $class): object
    {
        try {
            $instance = container_get($class);
        } catch (\Throwable $e) {
            throw new CommandException("无法解析 {$class}。", 0, $e);
        }

        if (!is_object($instance)) {
            throw new CommandException("解析后的 {$class} 不是对象。");
        }

        return $instance;
    }
}
