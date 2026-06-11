<?php

namespace app\service\payment\onboarding;

use app\common\base\BaseService;
use app\common\constant\OnboardingConstant;
use app\exception\PaymentException;
use app\model\payment\MerchantChannelOnboarding;
use app\model\payment\PaymentPluginOnboardingConf;
use app\repository\merchant\base\MerchantRepository;
use app\repository\payment\config\MerchantChannelOnboardingLogRepository;
use app\repository\payment\config\MerchantChannelOnboardingRepository;
use app\repository\payment\config\PaymentPluginOnboardingConfRepository;
use Throwable;

/**
 * 商户支付渠道进件申请服务。
 */
class MerchantChannelOnboardingService extends BaseService
{
    public function __construct(
        protected MerchantChannelOnboardingRepository $onboardingRepository,
        protected MerchantChannelOnboardingLogRepository $logRepository,
        protected PaymentPluginOnboardingConfRepository $configRepository,
        protected PaymentOnboardingConfigService $configService,
        protected OnboardingPluginManager $pluginManager,
        protected MerchantRepository $merchantRepository
    ) {
    }

    /**
     * 管理后台分页查询进件申请。
     *
     * @param array<string, mixed> $filters 查询条件
     * @param int $page 页码
     * @param int $pageSize 每页条数
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function adminPaginate(array $filters = [], int $page = 1, int $pageSize = 10)
    {
        $paginator = $this->baseListQuery($filters)
            ->orderByDesc('o.id')
            ->paginate(max(1, $pageSize), ['*'], 'page', max(1, $page));

        $paginator->getCollection()->transform(fn ($row) => $this->decorateListRow($row));

        return $paginator;
    }

    /**
     * 商户端分页查询当前商户自己的进件申请。
     *
     * @param int $merchantId 当前商户ID
     * @param array<string, mixed> $filters 查询条件
     * @param int $page 页码
     * @param int $pageSize 每页条数
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function merchantPaginate(int $merchantId, array $filters = [], int $page = 1, int $pageSize = 10)
    {
        // 商户端查询必须强制写入 merchant_id，避免前端传参越权读取其他商户申请。
        $filters['merchant_id'] = $merchantId;

        $paginator = $this->baseListQuery($filters)
            ->orderByDesc('o.id')
            ->paginate(max(1, $pageSize), ['*'], 'page', max(1, $page));

        $paginator->getCollection()->transform(fn ($row) => $this->decorateListRow($row));

        return $paginator;
    }

    /**
     * 管理后台读取进件申请详情。
     *
     * @param int $id 申请ID
     * @return array<string, mixed>|null
     */
    public function findForAdmin(int $id): ?array
    {
        $row = $this->findDetailRow($id);

        return $row ? $this->decorateDetailRow($row) : null;
    }

    /**
     * 商户端读取当前商户自己的进件申请详情。
     *
     * @param int $merchantId 当前商户ID
     * @param int $id 申请ID
     * @return array<string, mixed>|null
     */
    public function findForMerchant(int $merchantId, int $id): ?array
    {
        $row = $this->findDetailRow($id, $merchantId);

        return $row ? $this->decorateDetailRow($row) : null;
    }

    /**
     * 商户端创建或更新进件申请。
     *
     * 同一商户同一进件配置只允许存在一条非终态申请；草稿、平台驳回、
     * 上游退回允许复用原申请补件重提。
     *
     * @param int $merchantId 当前商户ID
     * @param string $merchantNo 当前商户编号
     * @param array<string, mixed> $data 表单数据
     * @return MerchantChannelOnboarding
     * @throws PaymentException
     */
    public function createForMerchant(int $merchantId, string $merchantNo, array $data): MerchantChannelOnboarding
    {
        $configId = (int) ($data['onboarding_config_id'] ?? 0);
        $config = $this->configRepository->findMerchantVisible($configId);
        if (!$config) {
            throw new PaymentException('进件渠道不可用', 40290);
        }

        $active = $this->onboardingRepository->findActiveByMerchantConfig($merchantId, $configId);
        if ($active && !in_array((int) $active->status, [
            OnboardingConstant::STATUS_DRAFT,
            OnboardingConstant::STATUS_PLATFORM_REJECTED,
            OnboardingConstant::STATUS_UPSTREAM_REJECTED,
        ], true)) {
            throw new PaymentException('该进件渠道已有在途申请，请勿重复提交', 40291);
        }

        $payload = $this->normalizeApplicationPayload($config, $data, $merchantId, $merchantNo);
        if (!empty($data['submit'])) {
            // 商户保存草稿可不完整，提交平台审核前必须满足插件声明的必填资料。
            $this->assertRequiredFormDataComplete($config, (array) $payload['form_data']);
        }

        // 商户点击“提交审核”时进入平台待审；只保存时保留草稿状态。
        $payload['status'] = !empty($data['submit'])
            ? OnboardingConstant::STATUS_PLATFORM_PENDING
            : OnboardingConstant::STATUS_DRAFT;
        if ((int) $payload['status'] === OnboardingConstant::STATUS_PLATFORM_PENDING) {
            $payload['submitted_at'] = $this->now();
        }

        return $this->transaction(function () use ($active, $payload): MerchantChannelOnboarding {
            if ($active) {
                // 补件重提复用原申请编号，保留日志链路和上游关联排查上下文。
                $active->fill($payload);
                $active->save();
                $model = $active->refresh();
                $this->writeLog($model, 'merchant_update', OnboardingConstant::OPERATOR_MERCHANT, (int) $model->merchant_id, '商户', 'success', '商户更新进件资料');

                return $model;
            }

            $payload['onboarding_no'] = $this->generateNo('ONB');
            $model = $this->onboardingRepository->create($payload);
            /** @var MerchantChannelOnboarding $model */
            $this->writeLog($model, 'merchant_create', OnboardingConstant::OPERATOR_MERCHANT, (int) $model->merchant_id, '商户', 'success', '商户创建进件申请');

            return $model;
        });
    }

    /**
     * 管理后台手动创建进件申请。
     *
     * @param array<string, mixed> $data 表单数据
     * @param int $adminId 操作管理员ID
     * @return MerchantChannelOnboarding
     * @throws PaymentException
     */
    public function createForAdmin(array $data, int $adminId = 0): MerchantChannelOnboarding
    {
        $merchantId = (int) ($data['merchant_id'] ?? 0);
        $merchant = $this->merchantRepository->find($merchantId);
        if (!$merchant) {
            throw new PaymentException('商户不存在', 40292);
        }

        $config = $this->configRepository->findEnabled((int) ($data['onboarding_config_id'] ?? 0));
        if (!$config) {
            throw new PaymentException('进件配置不可用', 40293);
        }

        $active = $this->onboardingRepository->findActiveByMerchantConfig($merchantId, (int) $config->id);
        if ($active) {
            throw new PaymentException('该商户在此进件配置下已有非终态申请，请先处理原申请', 40291);
        }

        $payload = $this->normalizeApplicationPayload($config, $data, $merchantId, (string) $merchant->merchant_no);
        if (!empty($data['submit'])) {
            // 后台选择“通过预审”时同样要先拦截缺失资料，避免后续上游提交才失败。
            $this->assertRequiredFormDataComplete($config, (array) $payload['form_data']);
        }

        // 后台手动进件可选择直接通过平台预审，后续由管理员提交上游。
        $payload['status'] = !empty($data['submit'])
            ? OnboardingConstant::STATUS_PLATFORM_APPROVED
            : OnboardingConstant::STATUS_DRAFT;

        /** @var MerchantChannelOnboarding $model */
        $model = $this->onboardingRepository->create(array_merge($payload, [
            'onboarding_no' => $this->generateNo('ONB'),
            'reviewed_at' => !empty($data['submit']) ? $this->now() : null,
        ]));
        $this->writeLog($model, 'admin_create', OnboardingConstant::OPERATOR_ADMIN, $adminId, '管理员', 'success', '后台创建进件申请');

        return $model;
    }

    /**
     * 商户提交平台预审。
     *
     * @param int $merchantId 当前商户ID
     * @param int $id 申请ID
     * @return MerchantChannelOnboarding|null
     * @throws PaymentException
     */
    public function submitForMerchant(int $merchantId, int $id): ?MerchantChannelOnboarding
    {
        $model = $this->findOwnedModel($id, $merchantId);
        if (!$model) {
            return null;
        }

        if (!in_array((int) $model->status, OnboardingConstant::merchantSubmittableStatuses(), true)) {
            throw new PaymentException('当前状态不允许提交平台审核', 40294);
        }

        $config = $this->requireConfig((int) $model->onboarding_config_id);
        $this->assertRequiredFormDataComplete($config, is_array($model->form_data) ? $model->form_data : []);

        $model->status = OnboardingConstant::STATUS_PLATFORM_PENDING;
        $model->submitted_at = $this->now();
        $model->platform_audit_msg = '';
        $model->save();
        $this->writeLog($model, 'merchant_submit', OnboardingConstant::OPERATOR_MERCHANT, $merchantId, '商户', 'success', '商户提交平台审核');

        return $model->refresh();
    }

    /**
     * 管理后台平台预审。
     *
     * @param int $id 申请ID
     * @param array<string, mixed> $data 审核参数
     * @param int $adminId 操作管理员ID
     * @return MerchantChannelOnboarding|null
     * @throws PaymentException
     */
    public function review(int $id, array $data, int $adminId = 0): ?MerchantChannelOnboarding
    {
        $model = $this->onboardingRepository->find($id);
        if (!$model) {
            return null;
        }

        if (!in_array((int) $model->status, [
            OnboardingConstant::STATUS_PLATFORM_PENDING,
            OnboardingConstant::STATUS_DRAFT,
        ], true)) {
            throw new PaymentException('当前状态不允许平台审核', 40295);
        }

        $approved = (int) ($data['approved'] ?? 0) === 1;
        if ($approved) {
            $config = $this->requireConfig((int) $model->onboarding_config_id);
            $this->assertRequiredFormDataComplete($config, is_array($model->form_data) ? $model->form_data : []);
        }

        // 平台审核只推进本地状态；真实上游提交必须由管理员显式触发。
        $model->status = $approved ? OnboardingConstant::STATUS_PLATFORM_APPROVED : OnboardingConstant::STATUS_PLATFORM_REJECTED;
        $model->platform_audit_msg = trim((string) ($data['message'] ?? ''));
        $model->reviewed_at = $this->now();
        $model->save();
        $this->writeLog(
            $model,
            $approved ? 'admin_approve' : 'admin_reject',
            OnboardingConstant::OPERATOR_ADMIN,
            $adminId,
            '管理员',
            'success',
            $approved ? '平台审核通过' : '平台审核驳回'
        );

        return $model->refresh();
    }

    /**
     * 管理后台提交进件资料到上游。
     *
     * @param int $id 申请ID
     * @param int $adminId 操作管理员ID
     * @return MerchantChannelOnboarding|null
     * @throws Throwable
     */
    public function submitUpstream(int $id, int $adminId = 0): ?MerchantChannelOnboarding
    {
        $model = $this->onboardingRepository->find($id);
        if (!$model) {
            return null;
        }

        if (!in_array((int) $model->status, [
            OnboardingConstant::STATUS_PLATFORM_APPROVED,
            OnboardingConstant::STATUS_UPSTREAM_REJECTED,
            OnboardingConstant::STATUS_FAILED,
        ], true)) {
            throw new PaymentException('当前状态不允许提交上游', 40296);
        }

        $config = $this->requireConfig((int) $model->onboarding_config_id);
        $this->assertRequiredFormDataComplete($config, is_array($model->form_data) ? $model->form_data : []);
        $plugin = $this->pluginManager->createByConfig($config);

        try {
            // 插件只负责把标准 payload 转成上游报文，申请生命周期由本服务统一推进。
            $result = $plugin->submitOnboarding($this->pluginPayload($model, $config));
            $this->applyUpstreamResult($model, $result, OnboardingConstant::STATUS_UPSTREAM_PENDING);
            $model->upstream_submitted_at = $this->now();
            $model->save();
            $this->writeLog($model, 'submit_upstream', OnboardingConstant::OPERATOR_ADMIN, $adminId, '管理员', 'success', (string) ($result['message'] ?? '已提交上游'), $result);
        } catch (Throwable $e) {
            // 上游提交阶段失败属于本地处理失败；摘要日志保留错误消息但不落完整请求报文。
            $model->status = OnboardingConstant::STATUS_FAILED;
            $model->upstream_message = $e->getMessage();
            $model->save();
            $this->writeLog($model, 'submit_upstream', OnboardingConstant::OPERATOR_ADMIN, $adminId, '管理员', 'failed', $e->getMessage());
            throw $e;
        }

        return $model->refresh();
    }

    /**
     * 查询上游进件状态并同步本地记录。
     *
     * @param int $id 申请ID
     * @param string $operatorType 操作人类型
     * @param int $operatorId 操作人ID
     * @return MerchantChannelOnboarding|null
     * @throws PaymentException
     */
    public function queryUpstream(int $id, string $operatorType = OnboardingConstant::OPERATOR_ADMIN, int $operatorId = 0): ?MerchantChannelOnboarding
    {
        $model = $this->onboardingRepository->find($id);
        if (!$model) {
            return null;
        }

        if ((string) $model->upstream_apply_id === '' && (string) $model->upstream_contract_id === '') {
            throw new PaymentException('当前申请还没有上游申请单号或合同号', 40297);
        }

        $config = $this->requireConfig((int) $model->onboarding_config_id);
        $plugin = $this->pluginManager->createByConfig($config);
        // 查询结果只更新进件记录，不自动生成支付通道或插件配置。
        $result = $plugin->queryOnboarding($this->pluginPayload($model, $config));
        $this->applyUpstreamResult($model, $result, (int) $model->status);
        $model->save();
        $this->writeLog($model, 'query_upstream', $operatorType, $operatorId, $operatorType === OnboardingConstant::OPERATOR_MERCHANT ? '商户' : '管理员', 'success', (string) ($result['message'] ?? '查询成功'), $result);

        return $model->refresh();
    }

    /**
     * 管理后台提交上游进件复议。
     *
     * 复议只在上游退回后允许调用，成功后申请回到上游处理中。
     *
     * @param int $id 申请ID
     * @param int $adminId 操作管理员ID
     * @return MerchantChannelOnboarding|null
     * @throws Throwable
     */
    public function reconsiderUpstream(int $id, int $adminId = 0): ?MerchantChannelOnboarding
    {
        $model = $this->onboardingRepository->find($id);
        if (!$model) {
            return null;
        }
        if ((int) $model->status !== OnboardingConstant::STATUS_UPSTREAM_REJECTED) {
            throw new PaymentException('只有上游退回的申请才能提交复议', 40308);
        }

        $config = $this->requireConfig((int) $model->onboarding_config_id);
        $this->assertRequiredFormDataComplete($config, is_array($model->form_data) ? $model->form_data : []);
        $plugin = $this->pluginManager->createByConfig($config);
        if (!method_exists($plugin, 'reconsiderOnboarding')) {
            throw new PaymentException('当前进件插件不支持复议提交', 40309);
        }

        try {
            $result = $plugin->reconsiderOnboarding($this->pluginPayload($model, $config));
            $this->applyUpstreamResult($model, $result, OnboardingConstant::STATUS_UPSTREAM_PENDING);
            $model->status = OnboardingConstant::STATUS_UPSTREAM_PENDING;
            $model->save();
            $this->writeLog($model, 'reconsider', OnboardingConstant::OPERATOR_ADMIN, $adminId, '管理员', 'success', (string) ($result['message'] ?? '复议已提交'), $result);
        } catch (Throwable $e) {
            $model->upstream_message = $e->getMessage();
            $model->save();
            $this->writeLog($model, 'reconsider', OnboardingConstant::OPERATOR_ADMIN, $adminId, '管理员', 'failed', $e->getMessage());
            throw $e;
        }

        return $model->refresh();
    }

    /**
     * 取消进件申请。
     *
     * @param int $id 申请ID
     * @param string $operatorType 操作人类型
     * @param int $operatorId 操作人ID
     * @return MerchantChannelOnboarding|null
     * @throws PaymentException
     */
    public function cancel(int $id, string $operatorType, int $operatorId = 0): ?MerchantChannelOnboarding
    {
        $model = $this->onboardingRepository->find($id);
        if (!$model) {
            return null;
        }

        if (OnboardingConstant::isTerminal((int) $model->status)) {
            throw new PaymentException('终态申请不能取消', 40298);
        }

        if ((string) $model->upstream_apply_id !== '' || (string) $model->upstream_contract_id !== '') {
            try {
                // 已提交上游的申请先尽量通知插件取消；上游取消失败也允许本地终止并记录失败摘要。
                $config = $this->requireConfig((int) $model->onboarding_config_id);
                $plugin = $this->pluginManager->createByConfig($config);
                $plugin->cancelOnboarding($this->pluginPayload($model, $config));
            } catch (Throwable $e) {
                $this->writeLog($model, 'cancel_upstream', $operatorType, $operatorId, '操作人', 'failed', $e->getMessage());
            }
        }

        $model->status = OnboardingConstant::STATUS_CANCELLED;
        $model->cancelled_at = $this->now();
        $model->save();
        $this->writeLog($model, 'cancel', $operatorType, $operatorId, '操作人', 'success', '进件申请已取消');

        return $model->refresh();
    }

    /**
     * 管理后台手动绑定上游签约结果。
     *
     * 手动绑定只补全进件结果，不创建 `ma_payment_channel` 或 `ma_payment_plugin_conf`。
     *
     * @param int $id 申请ID
     * @param array<string, mixed> $data 绑定参数
     * @param int $adminId 操作管理员ID
     * @return MerchantChannelOnboarding|null
     */
    public function manualBind(int $id, array $data, int $adminId = 0): ?MerchantChannelOnboarding
    {
        $model = $this->onboardingRepository->find($id);
        if (!$model) {
            return null;
        }

        if (trim((string) ($data['upstream_merchant_no'] ?? $model->upstream_merchant_no)) === '') {
            throw new PaymentException('请填写上游商户号', 40307);
        }

        $model->upstream_apply_id = trim((string) ($data['upstream_apply_id'] ?? $model->upstream_apply_id));
        $model->upstream_contract_id = trim((string) ($data['upstream_contract_id'] ?? $model->upstream_contract_id));
        $model->upstream_merchant_no = trim((string) ($data['upstream_merchant_no'] ?? $model->upstream_merchant_no));
        $model->upstream_terminal_no = trim((string) ($data['upstream_terminal_no'] ?? $model->upstream_terminal_no));
        $model->upstream_status = trim((string) ($data['upstream_status'] ?? 'manual_signed'));
        $model->upstream_message = trim((string) ($data['message'] ?? '后台手动绑定签约结果'));
        $model->status = OnboardingConstant::STATUS_SIGNED;
        $model->signed_at = $this->now();
        $model->save();
        $this->writeLog($model, 'manual_bind', OnboardingConstant::OPERATOR_ADMIN, $adminId, '管理员', 'success', '后台手动绑定签约结果');

        return $model->refresh();
    }

    /**
     * 处理公开上游进件回调。
     *
     * @param string $pluginCode 路由中的插件编码
     * @param int $configId 进件配置ID
     * @param \support\Request $request 回调请求
     * @return string 返回给上游的响应内容
     * @throws PaymentException
     */
    public function handleNotify(string $pluginCode, int $configId, \support\Request $request): string
    {
        $config = $this->requireConfig($configId);
        if ((string) $config->plugin_code !== $pluginCode) {
            throw new PaymentException('进件回调配置与插件不匹配', 40299);
        }

        $plugin = $this->pluginManager->createByConfig($config);
        try {
            $result = $plugin->notifyOnboarding($request);
            // 优先用平台申请单号匹配，兼容上游仅回传合同号的场景。
            $model = $this->findByUpstreamResult($result);
            if (!$model) {
                throw new PaymentException('未匹配到进件申请单', 40300, $result);
            }

            $this->applyUpstreamResult($model, $result, (int) $model->status);
            $model->save();
            $this->writeLog($model, 'upstream_notify', OnboardingConstant::OPERATOR_UPSTREAM, 0, '上游', 'success', (string) ($result['message'] ?? '上游通知'), $result);

            if (method_exists($plugin, 'notifyOnboardingSuccess')) {
                return (string) $plugin->notifyOnboardingSuccess();
            }
        } catch (Throwable $e) {
            if (method_exists($plugin, 'notifyOnboardingFail')) {
                return (string) $plugin->notifyOnboardingFail();
            }
            if (method_exists($plugin, 'notifyFail')) {
                return (string) $plugin->notifyFail();
            }
            throw $e;
        }

        return method_exists($plugin, 'notifySuccess') ? (string) $plugin->notifySuccess() : 'success';
    }

    /**
     * 构造进件列表基础查询。
     *
     * @param array<string, mixed> $filters 查询条件
     */
    private function baseListQuery(array $filters)
    {
        $query = $this->onboardingRepository->query()
            ->from('ma_merchant_channel_onboarding as o')
            ->leftJoin('ma_merchant as m', 'o.merchant_id', '=', 'm.id')
            ->leftJoin('ma_payment_plugin_onboarding_conf as c', 'o.onboarding_config_id', '=', 'c.id')
            ->leftJoin('ma_payment_plugin as p', 'o.plugin_code', '=', 'p.code')
            ->select([
                'o.*',
                'm.merchant_name',
                'c.name as onboarding_config_name',
                'p.name as plugin_name',
            ]);

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->where(function ($builder) use ($keyword) {
                $builder->where('o.onboarding_no', 'like', '%' . $keyword . '%')
                    ->orWhere('o.merchant_no', 'like', '%' . $keyword . '%')
                    ->orWhere('m.merchant_name', 'like', '%' . $keyword . '%')
                    ->orWhere('o.upstream_apply_id', 'like', '%' . $keyword . '%')
                    ->orWhere('o.upstream_merchant_no', 'like', '%' . $keyword . '%');
            });
        }

        foreach (['merchant_id', 'onboarding_config_id', 'status'] as $field) {
            if (array_key_exists($field, $filters) && $filters[$field] !== '') {
                $query->where('o.' . $field, (int) $filters[$field]);
            }
        }

        $pluginCode = trim((string) ($filters['plugin_code'] ?? ''));
        if ($pluginCode !== '') {
            $query->where('o.plugin_code', $pluginCode);
        }

        return $query;
    }

    /**
     * 查询详情行；商户端会额外限定 merchant_id。
     *
     * @param int $id 申请ID
     * @param int $merchantId 商户ID，0 表示后台查询
     * @return array<string, mixed>|null
     */
    private function findDetailRow(int $id, int $merchantId = 0): ?array
    {
        $query = $this->baseListQuery([])->where('o.id', $id);
        if ($merchantId > 0) {
            $query->where('o.merchant_id', $merchantId);
        }
        $row = $query->first();
        if (!$row) {
            return null;
        }

        return (array) $row->toArray();
    }

    /**
     * 装饰列表行。
     */
    private function decorateListRow($row)
    {
        $row->status_text = OnboardingConstant::statusMap()[(int) $row->status] ?? '未知';
        $row->subject_type_text = OnboardingConstant::subjectTypeMap()[(string) $row->subject_type] ?? (string) $row->subject_type;
        // 列表只展示脱敏摘要，完整资料留到本人或管理员详情页查看。
        $row->form_data = $this->maskSensitiveData((array) ($row->form_data ?? []));
        $row->file_assets = [];
        $row->created_at_text = $this->formatDateTime($row->created_at, '—');
        $row->updated_at_text = $this->formatDateTime($row->updated_at, '—');

        return $row;
    }

    /**
     * 装饰详情行并附带最近处理日志。
     *
     * @param array<string, mixed> $row 详情数据
     * @return array<string, mixed>
     */
    private function decorateDetailRow(array $row): array
    {
        $row['status_text'] = OnboardingConstant::statusMap()[(int) ($row['status'] ?? 0)] ?? '未知';
        $row['subject_type_text'] = OnboardingConstant::subjectTypeMap()[(string) ($row['subject_type'] ?? '')] ?? (string) ($row['subject_type'] ?? '');
        $row['logs'] = $this->logRepository->query()
            ->where('onboarding_id', (int) $row['id'])
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->values()
            ->all();

        return $row;
    }

    /**
     * 归一化申请入库数据。
     *
     * @param PaymentPluginOnboardingConf $config 进件配置
     * @param array<string, mixed> $data 请求数据
     * @param int $merchantId 商户ID
     * @param string $merchantNo 商户编号
     * @return array<string, mixed>
     * @throws PaymentException
     */
    private function normalizeApplicationPayload(PaymentPluginOnboardingConf $config, array $data, int $merchantId, string $merchantNo): array
    {
        $subjectType = trim((string) ($data['subject_type'] ?? ''));
        $this->assertSubjectTypeAllowed($config, $subjectType);
        $products = $this->stringList($data['apply_products'] ?? []);
        $this->assertProductsAllowed($config, $products);

        return [
            'merchant_id' => $merchantId,
            'merchant_no' => $merchantNo,
            'onboarding_config_id' => (int) $config->id,
            'plugin_code' => (string) $config->plugin_code,
            'subject_type' => $subjectType,
            'apply_products' => $products,
            'form_data' => is_array($data['form_data'] ?? null) ? $data['form_data'] : [],
            'file_assets' => is_array($data['file_assets'] ?? null) ? $data['file_assets'] : [],
            'rate_config' => is_array($config->rate_config) ? $config->rate_config : [],
            'remark' => trim((string) ($data['remark'] ?? '')),
        ];
    }

    /**
     * 校验申请主体是否在当前进件配置允许范围内。
     *
     * @throws PaymentException
     */
    private function assertSubjectTypeAllowed(PaymentPluginOnboardingConf $config, string $subjectType): void
    {
        if ($subjectType === '') {
            throw new PaymentException('请选择进件主体类型', 40301);
        }
        if (!in_array($subjectType, (array) $config->subject_types, true)) {
            throw new PaymentException('当前进件渠道不支持该主体类型', 40302);
        }
    }

    /**
     * 校验申请产品是否在当前进件配置允许范围内。
     *
     * @param array<int, string> $products 申请产品
     * @throws PaymentException
     */
    private function assertProductsAllowed(PaymentPluginOnboardingConf $config, array $products): void
    {
        if ($products === []) {
            throw new PaymentException('请选择申请产品', 40303);
        }
        foreach ($products as $product) {
            if (!in_array($product, (array) $config->apply_products, true)) {
                throw new PaymentException('当前进件渠道不支持该申请产品', 40304, ['product' => $product]);
            }
        }
    }

    /**
     * 校验进件资料满足插件声明的 required 字段。
     *
     * 只校验 schema 中明确标记为 required 的字段，条件字段和上游深度规则仍由插件适配层处理。
     *
     * @param PaymentPluginOnboardingConf $config 进件配置
     * @param array<string, mixed> $formData 商户填写的标准进件资料
     * @throws PaymentException
     */
    private function assertRequiredFormDataComplete(PaymentPluginOnboardingConf $config, array $formData): void
    {
        $plugin = $this->pluginManager->createByConfig($config);
        $schema = method_exists($plugin, 'getOnboardingFormSchema')
            ? (array) $plugin->getOnboardingFormSchema()
            : [];

        foreach ($this->flattenSchemaRules($schema) as $rule) {
            $field = trim((string) ($rule['field'] ?? ''));
            if ($field === '' || !is_array($rule['validate'] ?? null)) {
                continue;
            }

            $requiredRule = $this->requiredValidateRule((array) $rule['validate']);
            if ($requiredRule === null) {
                continue;
            }

            if ($this->isEmptyFormValue($formData[$field] ?? null)) {
                $message = trim((string) ($requiredRule['message'] ?? ''));
                if ($message === '') {
                    $message = '请填写' . (string) ($rule['title'] ?? $field);
                }

                throw new PaymentException($message, 40306, ['field' => $field]);
            }
        }
    }

    /**
     * 展开 form-create schema，便于统一处理嵌套子字段。
     *
     * @param array<int, array<string, mixed>> $rules 插件表单 schema
     * @return array<int, array<string, mixed>>
     */
    private function flattenSchemaRules(array $rules): array
    {
        $flat = [];
        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                continue;
            }

            $flat[] = $rule;
            if (is_array($rule['children'] ?? null)) {
                $flat = array_merge($flat, $this->flattenSchemaRules((array) $rule['children']));
            }
        }

        return $flat;
    }

    /**
     * 读取字段上的 required 校验规则。
     *
     * @param array<int, array<string, mixed>> $validateRules form-create 校验规则
     * @return array<string, mixed>|null
     */
    private function requiredValidateRule(array $validateRules): ?array
    {
        foreach ($validateRules as $rule) {
            if (is_array($rule) && ($rule['required'] ?? false) === true) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * 判断标准进件资料字段是否为空，兼容普通输入和上传组件返回值。
     */
    private function isEmptyFormValue(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }
        if (is_string($value)) {
            return trim($value) === '';
        }
        if (is_array($value)) {
            if ($value === []) {
                return true;
            }

            $keys = array_keys($value);
            $isList = $keys === range(0, count($value) - 1);
            if ($isList) {
                foreach ($value as $item) {
                    if (!$this->isEmptyFormValue($item)) {
                        return false;
                    }
                }

                return true;
            }

            foreach (['value', 'url', 'path', 'id', 'file_id', 'preview_url'] as $key) {
                if (array_key_exists($key, $value) && !$this->isEmptyFormValue($value[$key])) {
                    return false;
                }
            }

            return false;
        }

        return false;
    }

    /**
     * 获取启用中的进件配置。
     *
     * @throws PaymentException
     */
    private function requireConfig(int $configId): PaymentPluginOnboardingConf
    {
        $config = $this->configRepository->findEnabled($configId);
        if (!$config) {
            throw new PaymentException('进件配置不可用', 40305);
        }

        return $config;
    }

    /**
     * 构造插件标准 payload。
     *
     * @return array<string, mixed>
     */
    private function pluginPayload(MerchantChannelOnboarding $model, PaymentPluginOnboardingConf $config): array
    {
        // notify_url 使用进件配置 ID，而不是普通支付通道 ID，保证回调能定位到进件接口配置。
        return [
            'onboarding_no' => (string) $model->onboarding_no,
            'merchant_id' => (int) $model->merchant_id,
            'merchant_no' => (string) $model->merchant_no,
            'onboarding_config_id' => (int) $config->id,
            'subject_type' => (string) $model->subject_type,
            'apply_products' => is_array($model->apply_products) ? $model->apply_products : [],
            'form_data' => is_array($model->form_data) ? $model->form_data : [],
            'file_assets' => is_array($model->file_assets) ? $model->file_assets : [],
            'rate_config' => is_array($model->rate_config) ? $model->rate_config : [],
            'upstream_apply_id' => (string) $model->upstream_apply_id,
            'upstream_contract_id' => (string) $model->upstream_contract_id,
            'upstream_merchant_no' => (string) $model->upstream_merchant_no,
            'upstream_terminal_no' => (string) $model->upstream_terminal_no,
            'notify_url' => rtrim((string) sys_config('site_url', ''), '/') . '/api/payment-onboarding/' . rawurlencode((string) $model->plugin_code) . '/' . (int) $config->id . '/notify',
        ];
    }

    /**
     * 将插件标准结果写回进件申请。
     *
     * @param MerchantChannelOnboarding $model 进件申请
     * @param array<string, mixed> $result 插件返回结果
     * @param int $defaultStatus 未识别状态时保留的本地状态
     */
    private function applyUpstreamResult(MerchantChannelOnboarding $model, array $result, int $defaultStatus): void
    {
        // 仅接受插件返回的标准字段，避免不同上游原始字段污染核心记录。
        $status = $this->mapUpstreamStatus((string) ($result['status'] ?? ''), $defaultStatus);
        $model->status = $status;
        $model->upstream_apply_id = (string) ($result['upstream_apply_id'] ?? $result['apply_id'] ?? $model->upstream_apply_id);
        $model->upstream_contract_id = (string) ($result['upstream_contract_id'] ?? $result['contract_id'] ?? $model->upstream_contract_id);
        $model->upstream_merchant_no = (string) ($result['upstream_merchant_no'] ?? $result['merchant_no'] ?? $model->upstream_merchant_no);
        $model->upstream_terminal_no = (string) ($result['upstream_terminal_no'] ?? $result['terminal_no'] ?? $model->upstream_terminal_no);
        $model->upstream_status = (string) ($result['upstream_status'] ?? $result['channel_status'] ?? $result['status'] ?? $model->upstream_status);
        $model->upstream_message = (string) ($result['message'] ?? $model->upstream_message);
        if ($status === OnboardingConstant::STATUS_SIGNED && empty($model->signed_at)) {
            $model->signed_at = $this->now();
        }
    }

    /**
     * 将插件状态字符串映射为平台状态。
     */
    private function mapUpstreamStatus(string $status, int $defaultStatus): int
    {
        return match (strtolower(trim($status))) {
            'signed', 'success', 'approved', 'opened' => OnboardingConstant::STATUS_SIGNED,
            'rejected', 'returned', 'fail', 'failed' => OnboardingConstant::STATUS_UPSTREAM_REJECTED,
            'cancelled', 'canceled' => OnboardingConstant::STATUS_CANCELLED,
            'pending', 'processing', 'submitted' => OnboardingConstant::STATUS_UPSTREAM_PENDING,
            default => $defaultStatus,
        };
    }

    /**
     * 根据插件回调解析结果匹配本地申请。
     *
     * @param array<string, mixed> $result 插件标准通知结果
     */
    private function findByUpstreamResult(array $result): ?MerchantChannelOnboarding
    {
        $onboardingNo = trim((string) ($result['onboarding_no'] ?? ''));
        if ($onboardingNo !== '') {
            return $this->onboardingRepository->findByNo($onboardingNo);
        }

        $applyId = trim((string) ($result['upstream_apply_id'] ?? $result['apply_id'] ?? ''));
        if ($applyId !== '') {
            return $this->onboardingRepository->query()
                ->where('upstream_apply_id', $applyId)
                ->first();
        }

        $contractId = trim((string) ($result['upstream_contract_id'] ?? $result['contract_id'] ?? ''));
        if ($contractId !== '') {
            return $this->onboardingRepository->query()
                ->where('upstream_contract_id', $contractId)
                ->first();
        }

        return null;
    }

    /**
     * 查询商户自有申请模型。
     */
    private function findOwnedModel(int $id, int $merchantId): ?MerchantChannelOnboarding
    {
        return $this->onboardingRepository->query()
            ->whereKey($id)
            ->where('merchant_id', $merchantId)
            ->first();
    }

    /**
     * 写入进件处理日志。
     *
     * 日志只保存摘要和脱敏扩展信息，不保存完整上游报文。
     *
     * @param array<string, mixed> $summary 摘要扩展信息
     */
    private function writeLog(
        MerchantChannelOnboarding $model,
        string $action,
        string $operatorType,
        int $operatorId,
        string $operatorName,
        string $resultStatus,
        string $message,
        array $summary = []
    ): void {
        // 每条日志生成独立 request_no，便于排查一次操作链路，不作为上游请求报文保存。
        $this->logRepository->create([
            'onboarding_id' => (int) $model->id,
            'onboarding_no' => (string) $model->onboarding_no,
            'merchant_id' => (int) $model->merchant_id,
            'onboarding_config_id' => (int) $model->onboarding_config_id,
            'plugin_code' => (string) $model->plugin_code,
            'action' => $action,
            'operator_type' => $operatorType,
            'operator_id' => $operatorId,
            'operator_name' => $operatorName,
            'request_no' => $this->generateNo('ONR'),
            'upstream_apply_id' => (string) $model->upstream_apply_id,
            'upstream_status' => (string) $model->upstream_status,
            'result_status' => $resultStatus,
            'result_code' => '',
            'message' => mb_strcut($message, 0, 1000, 'UTF-8'),
            'summary' => $this->maskSensitiveData($summary),
        ]);
    }

    /**
     * 将逗号字符串或数组归一化为字符串数组。
     *
     * @return array<int, string>
     */
    private function stringList(mixed $value): array
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(static fn ($item): string => trim((string) $item), $value)));
    }
}
