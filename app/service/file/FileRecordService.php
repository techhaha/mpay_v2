<?php

namespace app\service\file;

use app\common\base\BaseService;
use app\service\file\storage\StorageManager;
use Webman\Http\UploadFile;

/**
 * 文件门面服务。
 */
class FileRecordService extends BaseService
{
    public function __construct(
        protected FileRecordQueryService $queryService,
        protected FileRecordCommandService $commandService,
        protected StorageManager $storageManager
    ) {
    }

    public function paginate(array $filters = [], int $page = 1, int $pageSize = 10)
    {
        return $this->queryService->paginate($filters, $page, $pageSize);
    }

    public function detail(int $id): array
    {
        return $this->queryService->detail($id);
    }

    public function options(): array
    {
        return $this->queryService->options();
    }

    public function upload(UploadFile $file, array $data, int $createdBy = 0, string $createdByName = ''): array
    {
        return $this->commandService->upload($file, $data, $createdBy, $createdByName);
    }

    public function importRemote(string $remoteUrl, array $data, int $createdBy = 0, string $createdByName = ''): array
    {
        return $this->commandService->importRemote($remoteUrl, $data, $createdBy, $createdByName);
    }

    public function delete(int $id): bool
    {
        return $this->commandService->delete($id);
    }

    public function previewResponse(int $id)
    {
        $asset = $this->queryService->detail($id);

        return $this->storageManager->previewResponse($asset);
    }

    public function downloadResponse(int $id)
    {
        $asset = $this->queryService->detail($id);

        return $this->storageManager->downloadResponse($asset);
    }
}
