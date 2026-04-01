<?php

namespace app\http\admin\controller;

use app\common\base\BaseController;
use app\repositories\MerchantRepository;
use app\services\SystemConfigService;
use support\Db;
use support\Request;

class MerchantController extends BaseController
{
    public function __construct(
        protected MerchantRepository $merchantRepository,
        protected SystemConfigService $systemConfigService,
    ) {
    }

    public function list(Request $request)
    {
        $page = (int)$request->get('page', 1);
        $pageSize = (int)$request->get('page_size', 10);

        $filters = [
            'status' => $request->get('status', ''),
            'merchant_no' => trim((string)$request->get('merchant_no', '')),
            'merchant_name' => trim((string)$request->get('merchant_name', '')),
            'email' => trim((string)$request->get('email', '')),
            'balance' => trim((string)$request->get('balance', '')),
        ];

        $paginator = $this->merchantRepository->searchPaginate($filters, $page, $pageSize);
        $items = [];
        foreach ($paginator->items() as $row) {
            $item = method_exists($row, 'toArray') ? $row->toArray() : (array)$row;
            $items[] = $this->normalizeMerchantRow($item);
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
            return $this->fail('merchant id is required', 400);
        }

        $row = $this->merchantRepository->find($id);
        if (!$row) {
            return $this->fail('merchant not found', 404);
        }

        $merchant = method_exists($row, 'toArray') ? $row->toArray() : (array)$row;
        return $this->success($this->normalizeMerchantRow($merchant));
    }

    public function profileDetail(Request $request)
    {
        $id = (int)$request->get('id', 0);
        if ($id <= 0) {
            return $this->fail('merchant id is required', 400);
        }

        $merchant = $this->merchantRepository->find($id);
        if (!$merchant) {
            return $this->fail('merchant not found', 404);
        }

        $merchantRow = method_exists($merchant, 'toArray') ? $merchant->toArray() : (array)$merchant;
        return $this->success([
            'merchant' => $this->normalizeMerchantRow($merchantRow),
            'profile' => $this->buildMerchantProfile($merchantRow),
        ]);
    }

    public function save(Request $request)
    {
        $data = $request->post();
        $id = (int)($data['id'] ?? 0);

        $merchantNo = trim((string)($data['merchant_no'] ?? ''));
        $merchantName = trim((string)($data['merchant_name'] ?? ''));
        $balance = max(0, (float)($data['balance'] ?? 0));
        $email = trim((string)($data['email'] ?? $data['notify_email'] ?? ''));
        $status = (int)($data['status'] ?? 1);
        $remark = trim((string)($data['remark'] ?? ''));

        if ($merchantNo === '' || $merchantName === '') {
            return $this->fail('merchant_no and merchant_name are required', 400);
        }

        if ($id > 0) {
            $this->merchantRepository->updateById($id, [
                'merchant_no' => $merchantNo,
                'merchant_name' => $merchantName,
                'balance' => $balance,
                'email' => $email,
                'status' => $status,
                'remark' => $remark,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        } else {
            $exists = $this->merchantRepository->findByMerchantNo($merchantNo);
            if ($exists) {
                return $this->fail('merchant_no already exists', 400);
            }

            $this->merchantRepository->create([
                'merchant_no' => $merchantNo,
                'merchant_name' => $merchantName,
                'balance' => $balance,
                'email' => $email,
                'status' => $status,
                'remark' => $remark,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }

        return $this->success(null, 'saved');
    }

    public function toggle(Request $request)
    {
        $id = (int)$request->post('id', 0);
        $status = $request->post('status', null);

        if ($id <= 0 || $status === null) {
            return $this->fail('invalid params', 400);
        }

        $ok = $this->merchantRepository->updateById($id, ['status' => (int)$status]);
        return $ok ? $this->success(null, 'updated') : $this->fail('update failed', 500);
    }

    public function profileSave(Request $request)
    {
        $merchantId = (int)$request->post('merchant_id', 0);
        if ($merchantId <= 0) {
            return $this->fail('merchant_id is required', 400);
        }

        $merchant = $this->merchantRepository->find($merchantId);
        if (!$merchant) {
            return $this->fail('merchant not found', 404);
        }

        $merchantRow = method_exists($merchant, 'toArray') ? $merchant->toArray() : (array)$merchant;

        $profile = [
            'email' => trim((string)$request->post('email', $request->post('notify_email', ''))),
            'remark' => trim((string)$request->post('remark', '')),
            'balance' => max(0, (float)$request->post('balance', $merchantRow['balance'] ?? 0)),
        ];

        $updateData = $profile;
        $updateData['updated_at'] = date('Y-m-d H:i:s');
        $this->merchantRepository->updateById($merchantId, $updateData);

        return $this->success(null, 'saved');
    }

    public function statistics(Request $request)
    {
        $page = (int)$request->get('page', 1);
        $pageSize = (int)$request->get('page_size', 10);
        $filters = $this->buildOpFilters($request);

        $summaryQuery = Db::table('ma_mer as m')
            ->leftJoin('ma_pay_app as ma', 'ma.mer_id', '=', 'm.id')
            ->leftJoin('ma_pay_channel as pc', 'pc.mer_id', '=', 'm.id')
            ->leftJoin('ma_pay_order as o', function ($join) use ($filters) {
                $join->on('o.merchant_id', '=', 'm.id');
                if (!empty($filters['created_from'])) {
                    $join->where('o.created_at', '>=', $filters['created_from']);
                }
                if (!empty($filters['created_to'])) {
                    $join->where('o.created_at', '<=', $filters['created_to']);
                }
            });
        $this->applyMerchantFilters($summaryQuery, $filters);

        $summaryRow = $summaryQuery
            ->selectRaw(
                'COUNT(DISTINCT m.id) AS merchant_count,
                COUNT(DISTINCT CASE WHEN m.status = 1 THEN m.id END) AS active_merchant_count,
                COUNT(DISTINCT ma.id) AS app_count,
                COUNT(DISTINCT pc.id) AS channel_count,
                COUNT(DISTINCT o.id) AS order_count,
                COUNT(DISTINCT CASE WHEN o.status = 1 THEN o.id END) AS success_order_count,
                COALESCE(SUM(CASE WHEN o.status = 1 THEN o.real_amount ELSE 0 END), 0) AS success_amount,
                COALESCE(SUM(CASE WHEN o.status = 1 THEN o.fee ELSE 0 END), 0) AS fee_amount'
            )
            ->first();

        $listQuery = Db::table('ma_mer as m')
            ->leftJoin('ma_pay_app as ma', 'ma.mer_id', '=', 'm.id')
            ->leftJoin('ma_pay_channel as pc', 'pc.mer_id', '=', 'm.id')
            ->leftJoin('ma_pay_order as o', function ($join) use ($filters) {
                $join->on('o.merchant_id', '=', 'm.id');
                if (!empty($filters['created_from'])) {
                    $join->where('o.created_at', '>=', $filters['created_from']);
                }
                if (!empty($filters['created_to'])) {
                    $join->where('o.created_at', '<=', $filters['created_to']);
                }
            });
        $this->applyMerchantFilters($listQuery, $filters);

        $paginator = $listQuery
            ->selectRaw(
                'm.id, m.merchant_no, m.merchant_name, m.balance, m.email, m.status, m.remark, m.created_at,
                COUNT(DISTINCT ma.id) AS app_count,
                COUNT(DISTINCT CASE WHEN ma.status = 1 THEN ma.id END) AS active_app_count,
                COUNT(DISTINCT pc.id) AS channel_count,
                COUNT(DISTINCT o.id) AS order_count,
                COUNT(DISTINCT CASE WHEN o.status = 1 THEN o.id END) AS success_order_count,
                COUNT(DISTINCT CASE WHEN o.status = 0 THEN o.id END) AS pending_order_count,
                COALESCE(SUM(CASE WHEN o.status = 1 THEN o.real_amount ELSE 0 END), 0) AS success_amount,
                COALESCE(SUM(CASE WHEN o.status = 1 THEN o.fee ELSE 0 END), 0) AS fee_amount,
                MAX(o.created_at) AS last_order_at'
            )
            ->groupBy('m.id', 'm.merchant_no', 'm.merchant_name', 'm.balance', 'm.email', 'm.status', 'm.remark', 'm.created_at')
            ->orderByDesc('m.id')
            ->paginate($pageSize, ['*'], 'page', $page);

        return $this->success([
            'summary' => [
                'merchant_count' => (int)($summaryRow->merchant_count ?? 0),
                'active_merchant_count' => (int)($summaryRow->active_merchant_count ?? 0),
                'app_count' => (int)($summaryRow->app_count ?? 0),
                'channel_count' => (int)($summaryRow->channel_count ?? 0),
                'order_count' => (int)($summaryRow->order_count ?? 0),
                'success_order_count' => (int)($summaryRow->success_order_count ?? 0),
                'success_amount' => (string)($summaryRow->success_amount ?? '0.00'),
                'fee_amount' => (string)($summaryRow->fee_amount ?? '0.00'),
            ],
            'list' => array_map(fn ($row) => (array)$row, $paginator->items()),
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'size' => $paginator->perPage(),
        ]);
    }

    public function funds(Request $request)
    {
        $page = (int)$request->get('page', 1);
        $pageSize = (int)$request->get('page_size', 10);
        $filters = $this->buildOpFilters($request);

        $summaryQuery = Db::table('ma_mer as m')
            ->leftJoin('ma_pay_order as o', function ($join) use ($filters) {
                $join->on('o.merchant_id', '=', 'm.id');
                if (!empty($filters['created_from'])) {
                    $join->where('o.created_at', '>=', $filters['created_from']);
                }
                if (!empty($filters['created_to'])) {
                    $join->where('o.created_at', '<=', $filters['created_to']);
                }
            });
        $this->applyMerchantFilters($summaryQuery, $filters);

        $summaryRow = $summaryQuery
            ->selectRaw(
                'COUNT(DISTINCT m.id) AS merchant_count,
                COALESCE(SUM(CASE WHEN o.status = 1 THEN o.real_amount ELSE 0 END), 0) AS settled_amount,
                COALESCE(SUM(CASE WHEN o.status = 0 THEN o.amount ELSE 0 END), 0) AS pending_amount,
                COALESCE(SUM(CASE WHEN o.status = 1 THEN o.fee ELSE 0 END), 0) AS fee_amount,
                COALESCE(SUM(CASE WHEN o.status = 1 THEN o.real_amount - o.fee ELSE 0 END), 0) AS net_amount,
                COUNT(DISTINCT CASE WHEN o.notify_stat = 0 THEN o.id END) AS notify_pending_orders'
            )
            ->first();

        $listQuery = Db::table('ma_mer as m')
            ->leftJoin('ma_pay_order as o', function ($join) use ($filters) {
                $join->on('o.merchant_id', '=', 'm.id');
                if (!empty($filters['created_from'])) {
                    $join->where('o.created_at', '>=', $filters['created_from']);
                }
                if (!empty($filters['created_to'])) {
                    $join->where('o.created_at', '<=', $filters['created_to']);
                }
            });
        $this->applyMerchantFilters($listQuery, $filters);

        $paginator = $listQuery
            ->selectRaw(
                'm.id, m.merchant_no, m.merchant_name, m.balance, m.email, m.status, m.remark, m.created_at,
                COUNT(DISTINCT CASE WHEN o.status = 1 THEN o.id END) AS success_order_count,
                COUNT(DISTINCT CASE WHEN o.status = 0 THEN o.id END) AS pending_order_count,
                COUNT(DISTINCT CASE WHEN o.notify_stat = 0 THEN o.id END) AS notify_pending_orders,
                COALESCE(SUM(CASE WHEN o.status = 1 THEN o.real_amount ELSE 0 END), 0) AS settled_amount,
                COALESCE(SUM(CASE WHEN o.status = 0 THEN o.amount ELSE 0 END), 0) AS pending_amount,
                COALESCE(SUM(CASE WHEN o.status = 1 THEN o.fee ELSE 0 END), 0) AS fee_amount,
                COALESCE(SUM(CASE WHEN o.status = 1 THEN o.real_amount - o.fee ELSE 0 END), 0) AS net_amount,
                MAX(o.pay_at) AS last_pay_at'
            )
            ->groupBy('m.id', 'm.merchant_no', 'm.merchant_name', 'm.balance', 'm.email', 'm.status', 'm.remark', 'm.created_at')
            ->orderByRaw('COALESCE(SUM(CASE WHEN o.status = 1 THEN o.real_amount - o.fee ELSE 0 END), 0) DESC')
            ->paginate($pageSize, ['*'], 'page', $page);

        return $this->success([
            'summary' => [
                'merchant_count' => (int)($summaryRow->merchant_count ?? 0),
                'settled_amount' => (string)($summaryRow->settled_amount ?? '0.00'),
                'pending_amount' => (string)($summaryRow->pending_amount ?? '0.00'),
                'fee_amount' => (string)($summaryRow->fee_amount ?? '0.00'),
                'net_amount' => (string)($summaryRow->net_amount ?? '0.00'),
                'notify_pending_orders' => (int)($summaryRow->notify_pending_orders ?? 0),
            ],
            'list' => array_map(fn ($row) => (array)$row, $paginator->items()),
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'size' => $paginator->perPage(),
        ]);
    }

    public function audit(Request $request)
    {
        $page = (int)$request->get('page', 1);
        $pageSize = (int)$request->get('page_size', 10);
        $auditStatus = trim((string)$request->get('audit_status', ''));
        $keyword = trim((string)$request->get('keyword', ''));

        $summaryQuery = Db::table('ma_mer as m');
        if ($keyword !== '') {
            $summaryQuery->where(function ($query) use ($keyword) {
                $query->where('m.merchant_no', 'like', '%' . $keyword . '%')
                    ->orWhere('m.merchant_name', 'like', '%' . $keyword . '%');
            });
        }
        if ($auditStatus === 'pending') {
            $summaryQuery->where('m.status', 0);
        } elseif ($auditStatus === 'approved') {
            $summaryQuery->where('m.status', 1);
        }

        $summaryRow = $summaryQuery
            ->selectRaw(
                'COUNT(DISTINCT m.id) AS merchant_count,
                COUNT(DISTINCT CASE WHEN m.status = 0 THEN m.id END) AS pending_count,
                COUNT(DISTINCT CASE WHEN m.status = 1 THEN m.id END) AS approved_count'
            )
            ->first();

        $listQuery = Db::table('ma_mer as m')
            ->leftJoin('ma_pay_app as ma', 'ma.mer_id', '=', 'm.id');
        if ($keyword !== '') {
            $listQuery->where(function ($query) use ($keyword) {
                $query->where('m.merchant_no', 'like', '%' . $keyword . '%')
                    ->orWhere('m.merchant_name', 'like', '%' . $keyword . '%');
            });
        }
        if ($auditStatus === 'pending') {
            $listQuery->where('m.status', 0);
        } elseif ($auditStatus === 'approved') {
            $listQuery->where('m.status', 1);
        }

        $paginator = $listQuery
            ->selectRaw(
                'm.id, m.merchant_no, m.merchant_name, m.balance, m.email, m.status, m.remark, m.created_at, m.updated_at,
                COUNT(DISTINCT ma.id) AS app_count,
                COUNT(DISTINCT CASE WHEN ma.status = 1 THEN ma.id END) AS active_app_count,
                COUNT(DISTINCT CASE WHEN ma.status = 0 THEN ma.id END) AS disabled_app_count'
            )
            ->groupBy('m.id', 'm.merchant_no', 'm.merchant_name', 'm.balance', 'm.email', 'm.status', 'm.remark', 'm.created_at', 'm.updated_at')
            ->orderBy('m.status', 'asc')
            ->orderByDesc('m.id')
            ->paginate($pageSize, ['*'], 'page', $page);

        $items = [];
        foreach ($paginator->items() as $row) {
            $item = (array)$row;
            $item['audit_status'] = (int)($item['status'] ?? 0) === 1 ? 'approved' : 'pending';
            $item['audit_status_text'] = $item['audit_status'] === 'approved' ? 'approved' : 'pending';
            $items[] = $item;
        }

        return $this->success([
            'summary' => [
                'merchant_count' => (int)($summaryRow->merchant_count ?? 0),
                'pending_count' => (int)($summaryRow->pending_count ?? 0),
                'approved_count' => (int)($summaryRow->approved_count ?? 0),
            ],
            'list' => $items,
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'size' => $paginator->perPage(),
        ]);
    }

    public function auditAction(Request $request)
    {
        $id = (int)$request->post('id', 0);
        $action = trim((string)$request->post('action', ''));

        if ($id <= 0 || !in_array($action, ['approve', 'suspend'], true)) {
            return $this->fail('invalid params', 400);
        }

        $status = $action === 'approve' ? 1 : 0;
        Db::connection()->transaction(function () use ($id, $status) {
            Db::table('ma_mer')->where('id', $id)->update([
                'status' => $status,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            Db::table('ma_pay_app')->where('mer_id', $id)->update([
                'status' => $status,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        });

        return $this->success(null, $action === 'approve' ? 'approved' : 'suspended');
    }

    public function groupList(Request $request)
    {
        $page = max(1, (int)$request->get('page', 1));
        $pageSize = max(1, (int)$request->get('page_size', 10));
        $keyword = trim((string)$request->get('keyword', ''));
        $status = $request->get('status', '');

        $items = array_map([$this, 'normalizeGroupItem'], $this->getConfigEntries('merchant_groups'));
        $items = array_values(array_filter($items, function (array $item) use ($keyword, $status) {
            if ($keyword !== '') {
                $haystacks = [
                    strtolower((string)($item['group_code'] ?? '')),
                    strtolower((string)($item['group_name'] ?? '')),
                    strtolower((string)($item['remark'] ?? '')),
                ];
                $needle = strtolower($keyword);
                $matched = false;
                foreach ($haystacks as $haystack) {
                    if ($haystack !== '' && str_contains($haystack, $needle)) {
                        $matched = true;
                        break;
                    }
                }
                if (!$matched) {
                    return false;
                }
            }

            if ($status !== '' && (int)$item['status'] !== (int)$status) {
                return false;
            }

            return true;
        }));

        usort($items, function (array $a, array $b) {
            $sortCompare = (int)($a['sort'] ?? 0) <=> (int)($b['sort'] ?? 0);
            if ($sortCompare !== 0) {
                return $sortCompare;
            }
            return strcmp((string)($b['updated_at'] ?? ''), (string)($a['updated_at'] ?? ''));
        });

        return $this->success($this->buildConfigPagePayload(
            $items,
            $page,
            $pageSize,
            [
                'group_count' => count($items),
                'active_count' => count(array_filter($items, fn (array $item) => (int)$item['status'] === 1)),
                'disabled_count' => count(array_filter($items, fn (array $item) => (int)$item['status'] !== 1)),
            ]
        ));
    }

    public function groupSave(Request $request)
    {
        $data = $request->post();
        $id = trim((string)($data['id'] ?? ''));
        $groupCode = trim((string)($data['group_code'] ?? ''));
        $groupName = trim((string)($data['group_name'] ?? ''));

        if ($groupCode === '' || $groupName === '') {
            return $this->fail('group_code and group_name are required', 400);
        }

        $items = array_map([$this, 'normalizeGroupItem'], $this->getConfigEntries('merchant_groups'));
        foreach ($items as $item) {
            if (($item['group_code'] ?? '') === $groupCode && ($item['id'] ?? '') !== $id) {
                return $this->fail('group_code already exists', 400);
            }
        }

        $now = date('Y-m-d H:i:s');
        $saved = false;
        foreach ($items as &$item) {
            if (($item['id'] ?? '') !== $id || $id === '') {
                continue;
            }

            $item = array_merge($item, [
                'group_code' => $groupCode,
                'group_name' => $groupName,
                'sort' => (int)($data['sort'] ?? 0),
                'status' => (int)($data['status'] ?? 1),
                'remark' => trim((string)($data['remark'] ?? '')),
                'updated_at' => $now,
            ]);
            $saved = true;
            break;
        }
        unset($item);

        if (!$saved) {
            $items[] = [
                'id' => $id !== '' ? $id : uniqid('grp_', true),
                'group_code' => $groupCode,
                'group_name' => $groupName,
                'sort' => (int)($data['sort'] ?? 0),
                'status' => (int)($data['status'] ?? 1),
                'remark' => trim((string)($data['remark'] ?? '')),
                'merchant_count' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $this->setConfigEntries('merchant_groups', $items);
        return $this->success(null, 'saved');
    }

    public function groupDelete(Request $request)
    {
        $id = trim((string)$request->post('id', ''));
        if ($id === '') {
            return $this->fail('id is required', 400);
        }

        $items = array_map([$this, 'normalizeGroupItem'], $this->getConfigEntries('merchant_groups'));
        $filtered = array_values(array_filter($items, fn (array $item) => ($item['id'] ?? '') !== $id));
        if (count($filtered) === count($items)) {
            return $this->fail('group not found', 404);
        }

        $this->setConfigEntries('merchant_groups', $filtered);
        return $this->success(null, 'deleted');
    }

    public function packageList(Request $request)
    {
        $page = max(1, (int)$request->get('page', 1));
        $pageSize = max(1, (int)$request->get('page_size', 10));
        $keyword = trim((string)$request->get('keyword', ''));
        $status = $request->get('status', '');
        $apiType = trim((string)$request->get('api_type', ''));

        $items = array_map([$this, 'normalizePackageItem'], $this->getConfigEntries('merchant_packages'));
        $items = array_values(array_filter($items, function (array $item) use ($keyword, $status, $apiType) {
            if ($keyword !== '') {
                $haystacks = [
                    strtolower((string)($item['package_code'] ?? '')),
                    strtolower((string)($item['package_name'] ?? '')),
                    strtolower((string)($item['fee_desc'] ?? '')),
                    strtolower((string)($item['remark'] ?? '')),
                ];
                $needle = strtolower($keyword);
                $matched = false;
                foreach ($haystacks as $haystack) {
                    if ($haystack !== '' && str_contains($haystack, $needle)) {
                        $matched = true;
                        break;
                    }
                }
                if (!$matched) {
                    return false;
                }
            }

            if ($status !== '' && (int)$item['status'] !== (int)$status) {
                return false;
            }
            if ($apiType !== '' && (string)$item['api_type'] !== $apiType) {
                return false;
            }

            return true;
        }));

        usort($items, function (array $a, array $b) {
            $sortCompare = (int)($a['sort'] ?? 0) <=> (int)($b['sort'] ?? 0);
            if ($sortCompare !== 0) {
                return $sortCompare;
            }
            return strcmp((string)($b['updated_at'] ?? ''), (string)($a['updated_at'] ?? ''));
        });

        $apiTypeCount = [];
        foreach ($items as $item) {
            $type = (string)($item['api_type'] ?? 'custom');
            $apiTypeCount[$type] = ($apiTypeCount[$type] ?? 0) + 1;
        }

        return $this->success($this->buildConfigPagePayload(
            $items,
            $page,
            $pageSize,
            [
                'package_count' => count($items),
                'active_count' => count(array_filter($items, fn (array $item) => (int)$item['status'] === 1)),
                'disabled_count' => count(array_filter($items, fn (array $item) => (int)$item['status'] !== 1)),
                'api_type_count' => $apiTypeCount,
            ]
        ));
    }

    public function packageSave(Request $request)
    {
        $data = $request->post();
        $id = trim((string)($data['id'] ?? ''));
        $packageCode = trim((string)($data['package_code'] ?? ''));
        $packageName = trim((string)($data['package_name'] ?? ''));
        $apiType = trim((string)($data['api_type'] ?? 'epay'));

        if ($packageCode === '' || $packageName === '') {
            return $this->fail('package_code and package_name are required', 400);
        }
        if (!in_array($apiType, ['epay', 'openapi', 'custom'], true)) {
            return $this->fail('invalid api_type', 400);
        }

        $items = array_map([$this, 'normalizePackageItem'], $this->getConfigEntries('merchant_packages'));
        foreach ($items as $item) {
            if (($item['package_code'] ?? '') === $packageCode && ($item['id'] ?? '') !== $id) {
                return $this->fail('package_code already exists', 400);
            }
        }

        $now = date('Y-m-d H:i:s');
        $saved = false;
        foreach ($items as &$item) {
            if (($item['id'] ?? '') !== $id || $id === '') {
                continue;
            }

            $item = array_merge($item, [
                'package_code' => $packageCode,
                'package_name' => $packageName,
                'api_type' => $apiType,
                'sort' => (int)($data['sort'] ?? 0),
                'status' => (int)($data['status'] ?? 1),
                'channel_limit' => max(0, (int)($data['channel_limit'] ?? 0)),
                'daily_limit' => trim((string)($data['daily_limit'] ?? '')),
                'fee_desc' => trim((string)($data['fee_desc'] ?? '')),
                'callback_policy' => trim((string)($data['callback_policy'] ?? '')),
                'remark' => trim((string)($data['remark'] ?? '')),
                'updated_at' => $now,
            ]);
            $saved = true;
            break;
        }
        unset($item);

        if (!$saved) {
            $items[] = [
                'id' => $id !== '' ? $id : uniqid('pkg_', true),
                'package_code' => $packageCode,
                'package_name' => $packageName,
                'api_type' => $apiType,
                'sort' => (int)($data['sort'] ?? 0),
                'status' => (int)($data['status'] ?? 1),
                'channel_limit' => max(0, (int)($data['channel_limit'] ?? 0)),
                'daily_limit' => trim((string)($data['daily_limit'] ?? '')),
                'fee_desc' => trim((string)($data['fee_desc'] ?? '')),
                'callback_policy' => trim((string)($data['callback_policy'] ?? '')),
                'remark' => trim((string)($data['remark'] ?? '')),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $this->setConfigEntries('merchant_packages', $items);
        return $this->success(null, 'saved');
    }

    public function packageDelete(Request $request)
    {
        $id = trim((string)$request->post('id', ''));
        if ($id === '') {
            return $this->fail('id is required', 400);
        }

        $items = array_map([$this, 'normalizePackageItem'], $this->getConfigEntries('merchant_packages'));
        $filtered = array_values(array_filter($items, fn (array $item) => ($item['id'] ?? '') !== $id));
        if (count($filtered) === count($items)) {
            return $this->fail('package not found', 404);
        }

        $this->setConfigEntries('merchant_packages', $filtered);
        return $this->success(null, 'deleted');
    }

    private function buildOpFilters(Request $request): array
    {
        return [
            'merchant_id' => (int)$request->get('merchant_id', 0),
            'status' => (string)$request->get('status', ''),
            'keyword' => trim((string)$request->get('keyword', '')),
            'email' => trim((string)$request->get('email', '')),
            'balance' => trim((string)$request->get('balance', '')),
            'created_from' => trim((string)$request->get('created_from', '')),
            'created_to' => trim((string)$request->get('created_to', '')),
        ];
    }

    private function applyMerchantFilters($query, array $filters): void
    {
        if (($filters['status'] ?? '') !== '') {
            $query->where('m.status', (int)$filters['status']);
        }
        if (!empty($filters['merchant_id'])) {
            $query->where('m.id', (int)$filters['merchant_id']);
        }
        if (!empty($filters['keyword'])) {
            $query->where(function ($builder) use ($filters) {
                $builder->where('m.merchant_no', 'like', '%' . $filters['keyword'] . '%')
                    ->orWhere('m.merchant_name', 'like', '%' . $filters['keyword'] . '%')
                    ->orWhere('m.email', 'like', '%' . $filters['keyword'] . '%');
            });
        }
        if (!empty($filters['email'])) {
            $query->where('m.email', 'like', '%' . $filters['email'] . '%');
        }
        if (isset($filters['balance']) && $filters['balance'] !== '') {
            $query->where('m.balance', (string)$filters['balance']);
        }
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

    private function setConfigEntries(string $configKey, array $items): void
    {
        $this->systemConfigService->setValue($configKey, array_values($items));
    }

    private function buildConfigPagePayload(array $items, int $page, int $pageSize, array $summary): array
    {
        $offset = ($page - 1) * $pageSize;
        return [
            'summary' => $summary,
            'list' => array_values(array_slice($items, $offset, $pageSize)),
            'total' => count($items),
            'page' => $page,
            'size' => $pageSize,
        ];
    }

    private function normalizeGroupItem(array $item): array
    {
        return [
            'id' => (string)($item['id'] ?? ''),
            'group_code' => trim((string)($item['group_code'] ?? '')),
            'group_name' => trim((string)($item['group_name'] ?? '')),
            'sort' => (int)($item['sort'] ?? 0),
            'status' => (int)($item['status'] ?? 1),
            'remark' => trim((string)($item['remark'] ?? '')),
            'merchant_count' => max(0, (int)($item['merchant_count'] ?? 0)),
            'created_at' => (string)($item['created_at'] ?? ''),
            'updated_at' => (string)($item['updated_at'] ?? ''),
        ];
    }

    private function normalizePackageItem(array $item): array
    {
        $apiType = trim((string)($item['api_type'] ?? 'epay'));
        if (!in_array($apiType, ['epay', 'openapi', 'custom'], true)) {
            $apiType = 'custom';
        }

        return [
            'id' => (string)($item['id'] ?? ''),
            'package_code' => trim((string)($item['package_code'] ?? '')),
            'package_name' => trim((string)($item['package_name'] ?? '')),
            'api_type' => $apiType,
            'sort' => (int)($item['sort'] ?? 0),
            'status' => (int)($item['status'] ?? 1),
            'channel_limit' => max(0, (int)($item['channel_limit'] ?? 0)),
            'daily_limit' => trim((string)($item['daily_limit'] ?? '')),
            'fee_desc' => trim((string)($item['fee_desc'] ?? '')),
            'callback_policy' => trim((string)($item['callback_policy'] ?? '')),
            'remark' => trim((string)($item['remark'] ?? '')),
            'created_at' => (string)($item['created_at'] ?? ''),
            'updated_at' => (string)($item['updated_at'] ?? ''),
        ];
    }

    private function merchantProfileKey(int $merchantId): string
    {
        return 'merchant_profile_' . $merchantId;
    }

    private function defaultMerchantProfile(): array
    {
        return [
            'group_code' => '',
            'contact_name' => '',
            'contact_phone' => '',
            'notify_email' => '',
            'callback_domain' => '',
            'callback_ip_whitelist' => '',
            'risk_level' => 'standard',
            'single_limit' => 0,
            'daily_limit' => 0,
            'settlement_cycle' => 't1',
            'tech_support' => '',
            'remark' => '',
            'updated_at' => '',
        ];
    }

    private function buildGroupMap(): array
    {
        $map = [];
        foreach ($this->getConfigEntries('merchant_groups') as $group) {
            $groupCode = trim((string)($group['group_code'] ?? ''));
            if ($groupCode === '') {
                continue;
            }
            $map[$groupCode] = trim((string)($group['group_name'] ?? $groupCode));
        }

        return $map;
    }

    private function normalizeMerchantRow(array $merchant): array
    {
        $merchant['merchant_no'] = trim((string)($merchant['merchant_no'] ?? ''));
        $merchant['merchant_name'] = trim((string)($merchant['merchant_name'] ?? ''));
        $merchant['balance'] = (string)($merchant['balance'] ?? '0.00');
        $merchant['email'] = trim((string)($merchant['email'] ?? ''));
        $merchant['remark'] = trim((string)($merchant['remark'] ?? ''));
        $merchant['status'] = (int)($merchant['status'] ?? 1);
        $merchant['created_at'] = (string)($merchant['created_at'] ?? '');
        $merchant['updated_at'] = (string)($merchant['updated_at'] ?? '');
        return $merchant;
    }

    private function buildMerchantProfile(array $merchant): array
    {
        return [
            'merchant_no' => trim((string)($merchant['merchant_no'] ?? '')),
            'merchant_name' => trim((string)($merchant['merchant_name'] ?? '')),
            'balance' => (string)($merchant['balance'] ?? '0.00'),
            'email' => trim((string)($merchant['email'] ?? '')),
            'status' => (int)($merchant['status'] ?? 1),
            'remark' => trim((string)($merchant['remark'] ?? '')),
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
}
