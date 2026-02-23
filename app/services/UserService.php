<?php

namespace app\services;

use app\common\base\BaseService;
use app\common\constants\RoleCode;
use app\repositories\UserRepository;

/**
 * 用户相关业务服务示例
 */
class UserService extends BaseService
{
    public function __construct(
        protected UserRepository $users
    ) {}

    /**
     * 根据 ID 获取用户信息（附带角色与权限）
     *
     * 返回结构尽量与前端 mock 的 /user/getUserInfo 保持一致：
     * {
     *   "user": {...},          // 用户信息，roles 字段为角色对象数组
     *   "roles": ["admin"],     // 角色 code 数组
     *   "permissions": ["*:*:*"] // 权限标识数组
     * }
     */
    public function getUserInfoById(int $id): array
    {
        $user = $this->users->find($id);
        if (!$user) {
            throw new \RuntimeException('用户不存在', 404);
        }

        $userArray = $user->toArray();

        return [
            'user'        => $userArray,
            'roles'       => ['admin'],
            'permissions' => ['*:*:*'],
        ];
    }
}
