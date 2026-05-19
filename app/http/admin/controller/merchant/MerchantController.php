<?php

namespace app\http\admin\controller\merchant;

use app\common\base\BaseController;
use app\http\admin\validation\MerchantApiCredentialValidator;
use app\http\admin\validation\MerchantValidator;
use app\service\merchant\auth\MerchantAuthService;
use app\service\merchant\MerchantService;
use support\Request;
use support\Response;

/**
 * 商户管理控制器。
 *
 * 当前先提供商户列表查询，后续可继续扩展商户详情、新增、编辑等能力。
 *
 * @property MerchantService $merchantService 商户服务
 */
class MerchantController extends BaseController
{
    /**
     * 构造方法。
     *
     * @param MerchantService $merchantService 商户服务
     * @return void
     */
    public function __construct(
        protected MerchantService $merchantService,
        protected MerchantAuthService $merchantAuthService
    ) {
    }

    /**
     * 查询商户列表。
     *
     * 返回值里额外携带启用中的商户分组选项，方便前端一次性渲染筛选条件。
     * 
     * @param Request $request 请求对象
     * @return Response 响应对象
     */
    public function index(Request $request): Response
    {
        $data = $this->validated($request->all(), MerchantValidator::class, 'index');
        $page = max(1, (int) ($data['page'] ?? 1));
        $pageSize = max(1, (int) ($data['page_size'] ?? 10));

        return $this->success($this->merchantService->paginateWithGroupOptions($data, $page, $pageSize));
    }

    /**
     * 查询商户详情。
     *
     * @param Request $request 请求对象
     * @param string $id 商户ID
     * @return Response 响应对象
     */
    public function show(Request $request, string $id): Response
    {
        $data = $this->validated(['id' => (int) $id], MerchantValidator::class, 'show');
        $merchant = $this->merchantService->findById((int) $data['id']);

        if (!$merchant) {
            return $this->fail('商户不存在', 404);
        }

        return $this->success($merchant);
    }

    /**
     * 新增商户。
     *
     * @param Request $request 请求对象
     * @return Response 响应对象
     */
    public function store(Request $request): Response
    {
        $data = $this->validated($request->all(), MerchantValidator::class, 'store');
        return $this->success($this->merchantService->createWithDetail($data));
    }

    /**
     * 更新商户。
     *
     * @param Request $request 请求对象
     * @param string $id 商户ID
     * @return Response 响应对象
     */
    public function update(Request $request, string $id): Response
    {
        $payload = array_merge($request->all(), ['id' => (int) $id]);
        $scene = count(array_diff(array_keys($request->all()), ['status'])) === 0 ? 'updateStatus' : 'update';
        $data = $this->validated($payload, MerchantValidator::class, $scene);
        $merchant = $this->merchantService->updateWithDetail((int) $data['id'], $data);

        if (!$merchant) {
            return $this->fail('商户不存在', 404);
        }

        return $this->success($merchant);
    }

    /**
     * 删除商户。
     *
     * @param Request $request 请求对象
     * @param string $id 商户ID
     * @return Response 响应对象
     */
    public function destroy(Request $request, string $id): Response
    {
        $data = $this->validated(['id' => (int) $id], MerchantValidator::class, 'destroy');

        return $this->success($this->merchantService->delete((int) $data['id']));
    }

    /**
     * 重置商户登录密码。
     *
     * @param Request $request 请求对象
     * @param string $id 商户ID
     * @return Response 响应对象
     */
    public function resetPassword(Request $request, string $id): Response
    {
        $payload = array_merge($request->all(), ['id' => (int) $id]);
        $data = $this->validated($payload, MerchantValidator::class, 'resetPassword');

        return $this->success($this->merchantService->resetPassword((int) $data['id'], (string) $data['password']));
    }

    /**
     * 签发商户后台临时登录令牌。
     *
     * 该入口只在管理后台登录态下可用，用于从商户工作台直接进入当前商户后台。
     *
     * @param Request $request 请求对象
     * @param string $id 商户ID
     * @return Response 响应对象
     */
    public function loginToken(Request $request, string $id): Response
    {
        $data = $this->validated(['id' => (int) $id], MerchantValidator::class, 'loginToken');
        $merchant = $this->merchantService->ensureMerchantEnabled((int) $data['id']);
        $issued = $this->merchantAuthService->issueToken(
            (int) $merchant->id,
            3600,
            (string) $request->getRealIp(),
            (string) $request->header('user-agent', '')
        );

        return $this->success([
            'token' => (string) $issued['token'],
            'expires_in' => (int) $issued['expires_in'],
            'merchant_id' => (int) $merchant->id,
            'merchant_no' => (string) $merchant->merchant_no,
        ]);
    }

    /**
     * 生成或重置商户 API 凭证。
     *
     * @param Request $request 请求对象
     * @param string $id 商户ID
     * @return Response 响应对象
     */
    public function issueCredential(Request $request, string $id): Response
    {
        $merchantId = (int) $id;
        $data = $this->validated($request->all(), MerchantApiCredentialValidator::class, 'issueCredential');

        return $this->success($this->merchantService->issueCredential($merchantId, $data));
    }

    /**
     * 查询商户总览。
     *
     * @param Request $request 请求对象
     * @param string $id 商户ID
     * @return Response 响应对象
     */
    public function overview(Request $request, string $id): Response
    {
        $data = $this->validated(['id' => (int) $id], MerchantValidator::class, 'overview');

        return $this->success($this->merchantService->overview((int) $data['id']));
    }

    /**
     * 查询商户下拉选项。
     *
     * @param Request $request 请求对象
     * @return Response 响应对象
     */
    public function options(Request $request): Response
    {
        return $this->success($this->merchantService->enabledOptions());
    }

    /**
     * 远程查询商户选择项。
     *
     * @param Request $request 请求对象
     * @return Response 响应对象
     */
    public function selectOptions(Request $request): Response
    {
        $page = max(1, (int) $request->input('page', 1));
        $pageSize = min(50, max(1, (int) $request->input('page_size', 20)));

        return $this->success($this->merchantService->searchOptions($request->all(), $page, $pageSize));
    }
}







