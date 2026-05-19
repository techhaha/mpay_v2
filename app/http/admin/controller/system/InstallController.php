<?php

namespace app\http\admin\controller\system;

use app\common\base\BaseController;
use app\service\install\EnvironmentCheckService;
use app\service\install\InstallConfigService;
use app\service\install\InstallService;
use app\service\install\InstallStatusService;
use app\service\install\KeyGeneratorService;
use support\Request;
use support\Response;

/**
 * 管理后台安装向导公开接口。
 */
class InstallController extends BaseController
{
    /**
     * 构造方法。
     *
     * @param InstallStatusService $statusService 安装状态服务
     * @param EnvironmentCheckService $environmentCheckService 安装环境检测服务
     * @param InstallConfigService $configService 安装配置服务
     * @param InstallService $installService 安装编排服务
     * @param KeyGeneratorService $keyGeneratorService 密钥生成服务
     * @return void
     */
    public function __construct(
        protected InstallStatusService $statusService,
        protected EnvironmentCheckService $environmentCheckService,
        protected InstallConfigService $configService,
        protected InstallService $installService,
        protected KeyGeneratorService $keyGeneratorService
    ) {
    }

    /**
     * 获取安装状态。
     *
     * @param Request $request 请求对象
     * @return Response 响应对象
     */
    public function status(Request $request): Response
    {
        return $this->success($this->statusService->status());
    }

    /**
     * 检测安装环境。
     *
     * @param Request $request 请求对象
     * @return Response 响应对象
     */
    public function checkEnv(Request $request): Response
    {
        return $this->success($this->environmentCheckService->check());
    }

    /**
     * 生成安装可用的随机密钥。
     *
     * @param Request $request 请求对象
     * @return Response 响应对象
     */
    public function secrets(Request $request): Response
    {
        return $this->success([
            'auth_jwt_secret' => $this->keyGeneratorService->randomSecret(),
            'auth_admin_jwt_secret' => $this->keyGeneratorService->randomSecret(),
            'auth_merchant_jwt_secret' => $this->keyGeneratorService->randomSecret(),
        ]);
    }

    /**
     * 测试数据库连接和建表能力。
     *
     * @param Request $request 请求对象
     * @return Response 响应对象
     */
    public function testDb(Request $request): Response
    {
        try {
            $payload = $request->all();
            return $this->success($this->configService->diagnoseDatabase($payload), '数据库检测通过');
        } catch (\Throwable $e) {
            return $this->fail('数据库检测失败：' . $e->getMessage(), 500);
        }
    }

    /**
     * 测试 Redis 连接和队列库选择能力。
     *
     * @param Request $request 请求对象
     * @return Response 响应对象
     */
    public function testRedis(Request $request): Response
    {
        try {
            return $this->success($this->configService->diagnoseRedis($request->all()), 'Redis 检测通过');
        } catch (\Throwable $e) {
            return $this->fail('Redis 检测失败：' . $e->getMessage(), 500);
        }
    }

    /**
     * 执行安装流程。
     *
     * @param Request $request 请求对象
     * @return Response 响应对象
     */
    public function run(Request $request): Response
    {
        try {
            return $this->success($this->installService->run($request->all()), '安装完成');
        } catch (\Throwable $e) {
            return $this->fail('安装失败：' . $e->getMessage(), 500);
        }
    }
}
