<?php

namespace app\http\mer\controller\account;

use app\common\base\BaseController;
use app\http\mer\validation\BalanceValidator;
use app\service\account\funds\MerchantAccountService;
use app\service\merchant\MerchantService;
use support\Request;
use support\Response;

/**
 * 商户账户控制器。
 *
 * 负责商户余额查询等账户类接口。
 *
 * @property MerchantService $merchantService 商户服务
 * @property MerchantAccountService $merchantAccountService 商户账户服务
 */
class AccountController extends BaseController
{
    /**
     * 构造方法。
     *
     * @param MerchantService $merchantService 商户服务
     * @param MerchantAccountService $merchantAccountService 商户账户服务
     * @return void
     */
    public function __construct(
        protected MerchantService $merchantService,
        protected MerchantAccountService $merchantAccountService
    ) {
    }

    /**
     * 查询商户余额。
     *
     * @param Request $request 请求对象
     * @param string $merchantNo 商户号
     * @return Response 响应对象
     */
    public function balance(Request $request, string $merchantNo): Response
    {
        $data = $this->validated(['merchant_no' => $merchantNo], BalanceValidator::class, 'show');

        $currentMerchantNo = $this->currentMerchantNo($request);
        if ($currentMerchantNo !== '' && $currentMerchantNo !== (string) $data['merchant_no']) {
            return $this->fail('无权查看该商户余额', 403);
        }

        $merchant = $this->merchantService->findEnabledMerchantByNo((string) $data['merchant_no']);

        return $this->success($this->merchantAccountService->getBalanceSnapshot((int) $merchant->id));
    }
}






