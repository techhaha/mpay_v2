<?php

namespace app\service\system\user;

use app\common\base\BaseService;
use app\common\constant\CommonConstant;
use app\exception\ResourceNotFoundException;
use app\model\admin\AdminUser;
use app\repository\system\user\AdminUserRepository;

/**
 * 管理员用户管理服务。
 *
 * 负责管理员账号的列表查询、新增、修改和删除，以及密码字段的统一处理。
 */
class AdminUserService extends BaseService
{
    /**
     * 构造函数，注入管理员用户仓库。
     */
    public function __construct(
        protected AdminUserRepository $adminUserRepository
    ) {
    }

    /**
     * 分页查询管理员用户。
     */
    public function paginate(array $filters = [], int $page = 1, int $pageSize = 10)
    {
        $query = $this->adminUserRepository->query()->from('ma_admin_user as u');

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->where(function ($builder) use ($keyword) {
                $builder->where('u.username', 'like', '%' . $keyword . '%')
                    ->orWhere('u.real_name', 'like', '%' . $keyword . '%')
                    ->orWhere('u.mobile', 'like', '%' . $keyword . '%')
                    ->orWhere('u.email', 'like', '%' . $keyword . '%');
            });
        }

        $status = (string) ($filters['status'] ?? '');
        if ($status !== '') {
            $query->where('u.status', (int) $status);
        }

        $isSuper = (string) ($filters['is_super'] ?? '');
        if ($isSuper !== '') {
            $query->where('u.is_super', (int) $isSuper);
        }

        $paginator = $query
            ->select([
                'u.id',
                'u.username',
                'u.real_name',
                'u.mobile',
                'u.email',
                'u.is_super',
                'u.status',
                'u.last_login_at',
                'u.last_login_ip',
                'u.remark',
                'u.created_at',
                'u.updated_at',
            ])
            ->orderByDesc('u.id')
            ->paginate(max(1, $pageSize), ['*'], 'page', max(1, $page));

        $paginator->getCollection()->transform(function ($row) {
            $row->status_text = (string) ((int) $row->status === CommonConstant::STATUS_ENABLED ? '启用' : '禁用');
            $row->is_super_text = (string) ((int) $row->is_super === 1 ? '超级管理员' : '普通管理员');

            return $row;
        });

        return $paginator;
    }

    /**
     * 根据 ID 查询管理员用户。
     */
    public function findById(int $id): ?AdminUser
    {
        return $this->adminUserRepository->find($id);
    }

    /**
     * 新增管理员用户。
     */
    public function create(array $data): AdminUser
    {
        return $this->adminUserRepository->create($this->normalizePayload($data, false));
    }

    /**
     * 修改管理员用户。
     */
    public function update(int $id, array $data): ?AdminUser
    {
        $current = $this->adminUserRepository->find($id);
        if (!$current) {
            return null;
        }

        if (!$this->adminUserRepository->updateById($id, $this->normalizePayload($data, true))) {
            return null;
        }

        return $this->adminUserRepository->find($id);
    }

    /**
     * 删除管理员用户。
     */
    public function delete(int $id): bool
    {
        return $this->adminUserRepository->deleteById($id);
    }

    /**
     * 当前管理员资料。
     */
    public function profile(int $adminId, string $adminUsername = ''): array
    {
        $admin = $this->adminUserRepository->find($adminId);
        if (!$admin) {
            throw new ResourceNotFoundException('管理员不存在', ['admin_id' => $adminId]);
        }

        $isSuper = (int) $admin->is_super === 1;
        $role = [
            'code' => 'admin',
            'name' => $isSuper ? '超级管理员' : '普通管理员',
            'admin' => $isSuper,
            'disabled' => false,
        ];

        $user = [
            'id' => (int) $admin->id,
            'deptId' => '0',
            'deptName' => '管理中心',
            'userName' => (string) ($admin->username !== '' ? $admin->username : trim($adminUsername)),
            'nickName' => (string) ($admin->real_name !== '' ? $admin->real_name : $admin->username),
            'email' => (string) ($admin->email ?? ''),
            'phone' => (string) ($admin->mobile ?? ''),
            'sex' => 2,
            'avatar' => '',
            'status' => (int) $admin->status,
            'description' => trim((string) ($admin->remark ?? '')) !== '' ? (string) $admin->remark : '平台后台管理员账号',
            'roles' => [$role],
            'loginIp' => (string) ($admin->last_login_ip ?? ''),
            'loginDate' => $this->formatDateTime($admin->last_login_at ?? null),
            'createBy' => '系统',
            'createTime' => $this->formatDateTime($admin->created_at ?? null),
            'updateBy' => null,
            'updateTime' => $this->formatDateTime($admin->updated_at ?? null),
            'admin' => $isSuper,
        ];

        return [
            'admin_id' => (int) $admin->id,
            'admin_username' => (string) ($admin->username !== '' ? $admin->username : trim($adminUsername)),
            'user' => $user,
            'roles' => ['admin'],
            'permissions' => $isSuper ? ['*:*:*'] : [],
        ];
    }

    /**
     * 统一整理写入字段，并处理密码哈希。
     */
    private function normalizePayload(array $data, bool $isUpdate): array
    {
        $payload = [
            'username' => trim((string) ($data['username'] ?? '')),
            'real_name' => trim((string) ($data['real_name'] ?? '')),
            'mobile' => trim((string) ($data['mobile'] ?? '')),
            'email' => trim((string) ($data['email'] ?? '')),
            'is_super' => (int) ($data['is_super'] ?? 0),
            'status' => (int) ($data['status'] ?? CommonConstant::STATUS_ENABLED),
            'remark' => trim((string) ($data['remark'] ?? '')),
        ];

        $password = trim((string) ($data['password'] ?? ''));
        if ($password !== '') {
            $payload['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
        } elseif (!$isUpdate) {
            $payload['password_hash'] = '';
        }

        return $payload;
    }

}
