<?php

namespace app\services;

use app\common\base\BaseService;
use app\exceptions\NotFoundException;
use app\repositories\AdminRepository;

/**
 * 管理员业务服务
 */
class AdminService extends BaseService
{
    public function __construct(
        protected AdminRepository $adminRepository
    ) {
    }

    /**
     * 根据 ID 获取管理员信息
     *
     * @return array ['user' => array, 'roles' => array, 'permissions' => array]
     */
    public function getInfoById(int $id): array
    {
        $admin = $this->adminRepository->find($id);
        if (!$admin) {
            throw new NotFoundException('管理员不存在');
        }

        return [
            'user' => $admin->toArray(),
            'roles' => ['admin'],
            'permissions' => ['*:*:*'],
        ];
    }
}
