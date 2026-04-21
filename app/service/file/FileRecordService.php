<?php

namespace app\service\file;

use app\common\base\BaseService;
use app\service\file\storage\StorageManager;
use Webman\Http\UploadFile;

/**
 * 文件记录服务。
 *
 * @property FileRecordQueryService $queryService 查询服务
 * @property FileRecordCommandService $commandService 命令服务
 * @property StorageManager $storageManager 存储管理器
 */
class FileRecordService extends BaseService
{
    /**
     * 构造方法。
     *
     * @param FileRecordQueryService $queryService 查询服务
     * @param FileRecordCommandService $commandService 命令服务
     * @param StorageManager $storageManager 存储管理器
     * @return void
     */
    public function __construct(
        protected FileRecordQueryService $queryService,
        protected FileRecordCommandService $commandService,
        protected StorageManager $storageManager
    ) {
    }

    /**
     * 分页查询文件记录。
     *
     * @param array $filters 筛选条件
     * @param int $page 页码
     * @param int $pageSize 每页条数
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator 分页结果
     */
    public function paginate(array $filters = [], int $page = 1, int $pageSize = 10)
    {
        return $this->queryService->paginate($filters, $page, $pageSize);
    }

    /**
     * 查询文件记录详情。
     *
     * @param int $id 文件记录ID
     * @return array 文件详情
     */
    public function detail(int $id): array
    {
        return $this->queryService->detail($id);
    }

    /**
     * 获取文件记录选项。
     *
     * @return array 选项数据
     */
    public function options(): array
    {
        return $this->queryService->options();
    }

    /**
     * 上传文件并创建记录。
     *
     * @param UploadFile $file 上传文件
     * @param array $data 文件参数
     * @param int $createdBy 创建人ID
     * @param string $createdByName 创建人名称
     * @return array 文件记录
     */
    public function upload(UploadFile $file, array $data, int $createdBy = 0, string $createdByName = ''): array
    {
        return $this->commandService->upload($file, $data, $createdBy, $createdByName);
    }

    /**
     * 导入远程文件并创建记录。
     *
     * @param string $remoteUrl 远程地址
     * @param array $data 文件参数
     * @param int $createdBy 创建人ID
     * @param string $createdByName 创建人名称
     * @return array 文件记录
     */
    public function importRemote(string $remoteUrl, array $data, int $createdBy = 0, string $createdByName = ''): array
    {
        return $this->commandService->importRemote($remoteUrl, $data, $createdBy, $createdByName);
    }

    /**
     * 删除文件记录。
     *
     * @param int $id 文件记录ID
     * @return bool 是否删除成功
     */
    public function delete(int $id): bool
    {
        return $this->commandService->delete($id);
    }

    /**
     * 获取文件预览响应。
     *
     * @param int $id 文件记录ID
     * @return \support\Response 响应对象
     */
    public function previewResponse(int $id)
    {
        $asset = $this->queryService->detail($id);

        return $this->storageManager->previewResponse($asset);
    }

    /**
     * 获取文件下载响应。
     *
     * @param int $id 文件记录ID
     * @return \support\Response 响应对象
     */
    public function downloadResponse(int $id)
    {
        $asset = $this->queryService->detail($id);

        return $this->storageManager->downloadResponse($asset);
    }
}
