<?php

namespace app\repositories;

use app\common\base\BaseRepository;
use app\common\base\BaseDao;

/**
 * 管理后台用户仓库（当前阶段使用内存数据模拟）
 * 
 * 后续接入数据库时：
 * 1. 创建 AdminUserDao 继承 BaseDao
 * 2. 在构造函数中注入：public function __construct(AdminUserDao $dao) { parent::__construct($dao); }
 * 3. 将内存数据方法改为调用 $this->dao 的方法
 */
class AdminUserRepository extends BaseRepository
{
    /**
     * 构造函数：支持注入 DAO（当前阶段为可选）
     */
    public function __construct(?BaseDao $dao = null)
    {
        parent::__construct($dao);
    }
    /**
     * 模拟账户数据（对齐前端 mock accountData）
     */
    protected function accounts(): array
    {
        return [
            [
                'id' => 1,
                'deptId' => '100',
                'deptName' => '研发部门',
                'userName' => 'admin',
                'nickName' => '超级管理员',
                'email' => '2547096351@qq.com',
                'phone' => '15888888888',
                'sex' => 1,
                'avatar' => 'https://ooo.0x0.ooo/2025/04/10/O0dG7r.jpg',
                'status' => 1,
                'description' => '系统初始用户',
                'roles' => ['admin'],
                'loginIp' => '0:0:0:0:0:0:0:1',
                'loginDate' => '2025-03-31 10:30:59',
                'createBy' => 'admin',
                'createTime' => '2024-03-19 11:21:01',
                'updateBy' => null,
                'updateTime' => null,
                'admin' => true,
            ],
            [
                'id' => 2,
                'deptId' => '100010101',
                'deptName' => '研发部门',
                'userName' => 'common',
                'nickName' => '普通用户',
                'email' => '2547096351@qq.com',
                'phone' => '15222222222',
                'sex' => 0,
                'avatar' => 'https://ooo.0x0.ooo/2025/04/10/O0ddJI.jpg',
                'status' => 1,
                'description' => 'UI组用户',
                'roles' => ['common'],
                'loginIp' => '0:0:0:0:0:0:0:1',
                'loginDate' => '2025-03-31 10:30:59',
                'createBy' => 'admin',
                'createTime' => '2024-03-19 11:21:01',
                'updateBy' => null,
                'updateTime' => null,
                'admin' => false,
            ],
        ];
    }

    /**
     * 模拟角色数据（对齐前端 mock roleData）
     */
    protected function roles(): array
    {
        return [
            [
                'id' => 1,
                'name' => '超级管理员',
                'code' => 'admin',
                'sort' => 1,
                'status' => 1,
                'admin' => true,
                'description' => '默认角色，超级管理员，上帝角色',
            ],
            [
                'id' => 2,
                'name' => '普通员工',
                'code' => 'common',
                'sort' => 2,
                'status' => 1,
                'admin' => false,
                'description' => '负责一些基础功能',
            ],
        ];
    }

    /**
     * 模拟权限数据（对齐前端 mock permissionData）
     */
    protected function permissions(): array
    {
        return [
            [
                'meta' => [
                    'roles' => ['admin'],
                    'permission' => 'sys:btn:add',
                ],
            ],
            [
                'meta' => [
                    'roles' => ['admin'],
                    'permission' => 'sys:btn:edit',
                ],
            ],
            [
                'meta' => [
                    'roles' => ['admin'],
                    'permission' => 'sys:btn:delete',
                ],
            ],
            [
                'meta' => [
                    'roles' => ['admin', 'common'],
                    'permission' => 'common:btn:add',
                ],
            ],
            [
                'meta' => [
                    'roles' => ['admin', 'common'],
                    'permission' => 'common:btn:edit',
                ],
            ],
            [
                'meta' => [
                    'roles' => ['admin', 'common'],
                    'permission' => 'common:btn:delete',
                ],
            ],
        ];
    }

    public function findByUsername(string $username): ?array
    {
        foreach ($this->accounts() as $account) {
            if ($account['userName'] === $username) {
                return $account;
            }
        }
        return null;
    }

    public function findById(int $id): ?array
    {
        foreach ($this->accounts() as $account) {
            if ($account['id'] === $id) {
                return $account;
            }
        }
        return null;
    }

    public function getRoleInfoByCodes(array $codes): array
    {
        if (!$codes) {
            return [];
        }
        $roles = [];
        foreach ($this->roles() as $role) {
            if (in_array($role['code'], $codes, true)) {
                $roles[] = $role;
            }
        }
        return $roles;
    }

    public function getPermissionsByRoleCodes(array $codes): array
    {
        if (!$codes) {
            return [];
        }
        $permissions = [];
        foreach ($this->permissions() as $item) {
            $meta = $item['meta'] ?? [];
            $roles = $meta['roles'] ?? [];
            $permission = $meta['permission'] ?? null;
            if (!$permission) {
                continue;
            }
            foreach ($codes as $code) {
                if (in_array($code, $roles, true)) {
                    $permissions[] = $permission;
                    break;
                }
            }
        }
        // 去重
        return array_values(array_unique($permissions));
    }
}


