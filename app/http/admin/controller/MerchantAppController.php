<?php

namespace app\http\admin\controller;

use app\common\base\BaseController;
use app\repositories\MerchantAppRepository;
use app\repositories\MerchantRepository;
use app\services\SystemConfigService;
use support\Request;

class MerchantAppController extends BaseController
{
    public function __construct(
        protected MerchantAppRepository $merchantAppRepository,
        protected MerchantRepository $merchantRepository,
        protected SystemConfigService $systemConfigService,
    ) {
    }

    public function list(Request $request)
    {
        $page = (int)$request->get('page', 1);
        $pageSize = (int)$request->get('page_size', 10);

        $filters = [
            'merchant_id' => (int)$request->get('merchant_id', 0),
            'status' => $request->get('status', ''),
            'app_id' => trim((string)$request->get('app_id', '')),
            'app_name' => trim((string)$request->get('app_name', '')),
            'api_type' => trim((string)$request->get('api_type', '')),
        ];

        $paginator = $this->merchantAppRepository->searchPaginate($filters, $page, $pageSize);
        $packageMap = $this->buildPackageMap();
        $items = [];
        foreach ($paginator->items() as $row) {
            $item = (array)$row;
            $config = $this->getConfigObject($this->appConfigKey((int)($item['id'] ?? 0)));
            $packageCode = trim((string)($config['package_code'] ?? ''));
            $item['package_code'] = $packageCode;
            $item['package_name'] = $packageCode !== '' ? ($packageMap[$packageCode] ?? $packageCode) : '';
            $items[] = $item;
        }

        return $this->success([
            'list' => $items,
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'size' => $paginator->perPage(),
        ]);
    }

    public function detail(Request $request)
    {
        $id = (int)$request->get('id', 0);
        if ($id <= 0) {
            return $this->fail('app id is required', 400);
        }

        $row = $this->merchantAppRepository->find($id);
        if (!$row) {
            return $this->fail('app not found', 404);
        }

        return $this->success($row);
    }

    public function configDetail(Request $request)
    {
        $id = (int)$request->get('id', 0);
        if ($id <= 0) {
            return $this->fail('app id is required', 400);
        }

        $app = $this->merchantAppRepository->find($id);
        if (!$app) {
            return $this->fail('app not found', 404);
        }

        $config = array_merge($this->defaultAppConfig(), $this->getConfigObject($this->appConfigKey($id)));
        return $this->success([
            'app' => $app,
            'config' => $config,
        ]);
    }

    public function save(Request $request)
    {
        $data = $request->post();
        $id = (int)($data['id'] ?? 0);

        $merchantId = (int)($data['merchant_id'] ?? 0);
        $apiType = trim((string)($data['api_type'] ?? 'epay'));
        $appId = trim((string)($data['app_id'] ?? ''));
        $appName = trim((string)($data['app_name'] ?? ''));
        $status = (int)($data['status'] ?? 1);

        if ($merchantId <= 0 || $appId === '' || $appName === '') {
            return $this->fail('merchant_id, app_id and app_name are required', 400);
        }

        $merchant = $this->merchantRepository->find($merchantId);
        if (!$merchant) {
            return $this->fail('merchant not found', 404);
        }

        if (!in_array($apiType, ['openapi', 'epay', 'custom', 'default'], true)) {
            return $this->fail('invalid api_type', 400);
        }

        if ($id > 0) {
            $row = $this->merchantAppRepository->find($id);
            if (!$row) {
                return $this->fail('app not found', 404);
            }

            if ($row->app_id !== $appId) {
                $exists = $this->merchantAppRepository->findAnyByAppId($appId);
                if ($exists) {
                    return $this->fail('app_id already exists', 400);
                }
            }

            $update = [
                'merchant_id' => $merchantId,
                'api_type' => $apiType,
                'app_id' => $appId,
                'app_name' => $appName,
                'status' => $status,
            ];

            if (!empty($data['app_secret'])) {
                $update['app_secret'] = (string)$data['app_secret'];
            }

            $this->merchantAppRepository->updateById($id, $update);
        } else {
            $exists = $this->merchantAppRepository->findAnyByAppId($appId);
            if ($exists) {
                return $this->fail('app_id already exists', 400);
            }

            $secret = !empty($data['app_secret']) ? (string)$data['app_secret'] : $this->generateSecret();
            $this->merchantAppRepository->create([
                'merchant_id' => $merchantId,
                'api_type' => $apiType,
                'app_id' => $appId,
                'app_secret' => $secret,
                'app_name' => $appName,
                'status' => $status,
            ]);
        }

        return $this->success(null, 'saved');
    }

    public function resetSecret(Request $request)
    {
        $id = (int)$request->post('id', 0);
        if ($id <= 0) {
            return $this->fail('app id is required', 400);
        }

        $row = $this->merchantAppRepository->find($id);
        if (!$row) {
            return $this->fail('app not found', 404);
        }

        $secret = $this->generateSecret();
        $this->merchantAppRepository->updateById($id, ['app_secret' => $secret]);

        return $this->success(['app_secret' => $secret], 'reset success');
    }

    public function toggle(Request $request)
    {
        $id = (int)$request->post('id', 0);
        $status = $request->post('status', null);

        if ($id <= 0 || $status === null) {
            return $this->fail('invalid params', 400);
        }

        $ok = $this->merchantAppRepository->updateById($id, ['status' => (int)$status]);
        return $ok ? $this->success(null, 'updated') : $this->fail('update failed', 500);
    }

    public function configSave(Request $request)
    {
        $id = (int)$request->post('id', 0);
        if ($id <= 0) {
            return $this->fail('app id is required', 400);
        }

        $app = $this->merchantAppRepository->find($id);
        if (!$app) {
            return $this->fail('app not found', 404);
        }

        $signType = trim((string)$request->post('sign_type', 'md5'));
        $callbackMode = trim((string)$request->post('callback_mode', 'server'));
        if (!in_array($signType, ['md5', 'sha256', 'hmac-sha256'], true)) {
            return $this->fail('invalid sign_type', 400);
        }
        if (!in_array($callbackMode, ['server', 'server+page', 'manual'], true)) {
            return $this->fail('invalid callback_mode', 400);
        }

        $config = [
            'package_code' => trim((string)$request->post('package_code', '')),
            'notify_url' => trim((string)$request->post('notify_url', '')),
            'return_url' => trim((string)$request->post('return_url', '')),
            'callback_mode' => $callbackMode,
            'sign_type' => $signType,
            'order_expire_minutes' => max(0, (int)$request->post('order_expire_minutes', 30)),
            'callback_retry_limit' => max(0, (int)$request->post('callback_retry_limit', 6)),
            'ip_whitelist' => trim((string)$request->post('ip_whitelist', '')),
            'amount_min' => max(0, (float)$request->post('amount_min', 0)),
            'amount_max' => max(0, (float)$request->post('amount_max', 0)),
            'daily_limit' => max(0, (float)$request->post('daily_limit', 0)),
            'notify_enabled' => (int)$request->post('notify_enabled', 1) === 1 ? 1 : 0,
            'remark' => trim((string)$request->post('remark', '')),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        if ($config['package_code'] !== '') {
            $packageExists = false;
            foreach ($this->getConfigEntries('merchant_packages') as $package) {
                if (($package['package_code'] ?? '') === $config['package_code']) {
                    $packageExists = true;
                    break;
                }
            }
            if (!$packageExists) {
                return $this->fail('package_code not found', 400);
            }
        }

        $stored = array_merge($this->defaultAppConfig(), $this->getConfigObject($this->appConfigKey($id)), $config);
        $this->systemConfigService->setValue($this->appConfigKey($id), $stored);

        return $this->success(null, 'saved');
    }

    private function generateSecret(): string
    {
        $raw = random_bytes(24);
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private function appConfigKey(int $appId): string
    {
        return 'merchant_app_config_' . $appId;
    }

    private function defaultAppConfig(): array
    {
        return [
            'package_code' => '',
            'notify_url' => '',
            'return_url' => '',
            'callback_mode' => 'server',
            'sign_type' => 'md5',
            'order_expire_minutes' => 30,
            'callback_retry_limit' => 6,
            'ip_whitelist' => '',
            'amount_min' => 0,
            'amount_max' => 0,
            'daily_limit' => 0,
            'notify_enabled' => 1,
            'remark' => '',
            'updated_at' => '',
        ];
    }

    private function getConfigObject(string $configKey): array
    {
        $raw = $this->systemConfigService->getValue($configKey, '{}');
        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function getConfigEntries(string $configKey): array
    {
        $raw = $this->systemConfigService->getValue($configKey, '[]');
        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_filter($decoded, 'is_array'));
    }

    private function buildPackageMap(): array
    {
        $map = [];
        foreach ($this->getConfigEntries('merchant_packages') as $package) {
            $packageCode = trim((string)($package['package_code'] ?? ''));
            if ($packageCode === '') {
                continue;
            }
            $map[$packageCode] = trim((string)($package['package_name'] ?? $packageCode));
        }

        return $map;
    }
}
