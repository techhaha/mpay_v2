<?php

namespace app\service\file;

use app\common\base\BaseService;
use app\common\constant\FileConstant;
use app\exception\ResourceNotFoundException;
use app\repository\file\FileRecordRepository;
use app\service\file\storage\StorageManager;

/**
 * 文件查询服务。
 *
 * 负责文件记录的分页、详情、选项和展示数据格式化。
 *
 * @property FileRecordRepository $fileRecordRepository 文件记录仓库
 * @property StorageManager $storageManager 存储管理器
 */
class FileRecordQueryService extends BaseService
{
    /**
     * 构造方法。
     *
     * @param FileRecordRepository $fileRecordRepository 文件记录仓库
     * @param StorageManager $storageManager 存储管理器
     * @return void
     */
    public function __construct(
        protected FileRecordRepository $fileRecordRepository,
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
        $query = $this->fileRecordRepository->query()->from('ma_file_asset as f');

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->where(function ($builder) use ($keyword) {
                $builder->where('f.original_name', 'like', '%' . $keyword . '%')
                    ->orWhere('f.file_name', 'like', '%' . $keyword . '%')
                    ->orWhere('f.object_key', 'like', '%' . $keyword . '%')
                    ->orWhere('f.source_url', 'like', '%' . $keyword . '%');
            });
        }

        foreach (['scene', 'source_type', 'visibility', 'storage_engine'] as $field) {
            if (array_key_exists($field, $filters) && $filters[$field] !== '' && $filters[$field] !== null) {
                $query->where('f.' . $field, (int) $filters[$field]);
            }
        }

        $query->orderByDesc('f.id');

        $paginator = $query->paginate(max(1, $pageSize), ['f.*'], 'page', max(1, $page));
        $collection = $paginator->getCollection();
        $collection->transform(function ($row): array {
            return $this->formatModel($row);
        });

        return $paginator;
    }

    /**
     * 查询文件记录详情。
     *
     * @param int $id 文件记录查询ID
     * @return array 文件详情
     * @throws ResourceNotFoundException
     */
    public function detail(int $id): array
    {
        $asset = $this->fileRecordRepository->findById($id);
        if (!$asset) {
            throw new ResourceNotFoundException('文件不存在', ['id' => $id]);
        }

        return $this->formatModel($asset);
    }

    /**
     * 将文件记录格式化为前端展示结构。
     *
     * @param array|object|null $asset 文件记录
     * @return array<string, mixed> 展示数据
     */
    public function formatModel(array|object|null $asset): array
    {
        $id = (int) $this->field($asset, 'id', 0);
        $scene = (int) $this->field($asset, 'scene', FileConstant::SCENE_OTHER);
        $visibility = (int) $this->field($asset, 'visibility', FileConstant::VISIBILITY_PRIVATE);
        $storageEngine = (int) $this->field($asset, 'storage_engine', FileConstant::STORAGE_LOCAL);
        $sourceType = (int) $this->field($asset, 'source_type', FileConstant::SOURCE_UPLOAD);
        $size = (int) $this->field($asset, 'size', 0);
        $mimeType = strtolower((string) $this->field($asset, 'mime_type', ''));
        $fileExt = strtolower((string) $this->field($asset, 'file_ext', ''));
        $normalizedAsset = $this->normalizeAsset($asset);
        $publicUrl = $visibility === FileConstant::VISIBILITY_PUBLIC
            ? $this->storageManager->publicUrl($normalizedAsset)
            : '';
        $previewable = $this->isPreviewable($scene, $mimeType, $fileExt);
        $previewUrl = '';
        if ($previewable) {
            $previewUrl = $publicUrl !== '' ? $publicUrl : $this->storageManager->temporaryUrl($normalizedAsset);
            if ($previewUrl === '' && $id > 0) {
                $previewUrl = '/adminapi/file-asset/' . $id . '/preview';
            }
        }

        return [
            'id' => $id,
            'scene' => $scene,
            'scene_text' => (string) (FileConstant::sceneMap()[$scene] ?? '未知'),
            'source_type' => $sourceType,
            'source_type_text' => (string) (FileConstant::sourceTypeMap()[$sourceType] ?? '未知'),
            'visibility' => $visibility,
            'visibility_text' => (string) (FileConstant::visibilityMap()[$visibility] ?? '未知'),
            'storage_engine' => $storageEngine,
            'storage_engine_text' => (string) (FileConstant::storageEngineMap()[$storageEngine] ?? '未知'),
            'original_name' => (string) $this->field($asset, 'original_name', ''),
            'file_name' => (string) $this->field($asset, 'file_name', ''),
            'file_ext' => (string) $this->field($asset, 'file_ext', ''),
            'mime_type' => (string) $this->field($asset, 'mime_type', ''),
            'size' => $size,
            'size_text' => $this->formatSize($size),
            'md5' => (string) $this->field($asset, 'md5', ''),
            'object_key' => (string) $this->field($asset, 'object_key', ''),
            'source_url' => (string) $this->field($asset, 'source_url', ''),
            'url' => $publicUrl,
            'public_url' => $publicUrl,
            'preview_url' => $previewUrl,
            'download_url' => $id > 0 ? '/adminapi/file-asset/' . $id . '/download' : '',
            'previewable' => $previewable,
            'created_by' => (int) $this->field($asset, 'created_by', 0),
            'created_by_name' => (string) $this->field($asset, 'created_by_name', ''),
            'remark' => (string) $this->field($asset, 'remark', ''),
            'is_image' => $scene === FileConstant::SCENE_IMAGE || str_starts_with(strtolower((string) $this->field($asset, 'mime_type', '')), 'image/'),
            'created_at' => $this->formatDateTime($this->field($asset, 'created_at', null)),
            'updated_at' => $this->formatDateTime($this->field($asset, 'updated_at', null)),
        ];
    }

    /**
     * 获取文件记录选项。
     *
     * @return array<string, array<int, array{label: string, value: int}>> 选项数据
     */
    public function options(): array
    {
        return [
            'sourceTypes' => $this->toOptions(FileConstant::sourceTypeMap()),
            'visibilities' => $this->toOptions(FileConstant::visibilityMap()),
            'scenes' => $this->toOptions(FileConstant::sceneMap()),
            'storageEngines' => $this->toOptions(FileConstant::storageEngineMap()),
            'selectableStorageEngines' => $this->toOptions(FileConstant::selectableStorageEngineMap()),
        ];
    }

    /**
     * 格式化文件大小。
     *
     * @param int $size 文件大小（字节）
     * @return string 格式化后的大小
     */
    private function formatSize(int $size): string
    {
        if ($size <= 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $index = 0;
        $value = (float) $size;
        while ($value >= 1024 && $index < count($units) - 1) {
            $value /= 1024;
            $index++;
        }

        return $index === 0 ? (string) (int) $value . ' ' . $units[$index] : number_format($value, 2) . ' ' . $units[$index];
    }

    /**
     * 将映射表转换为前端选项。
     *
     * @param array $map 映射表
     * @return array 选项列表
     */
    private function toOptions(array $map): array
    {
        $options = [];
        foreach ($map as $value => $label) {
            $options[] = [
                'label' => (string) $label,
                'value' => (int) $value,
            ];
        }

        return $options;
    }

    /**
     * 从数组或对象中读取字段值。
     *
     * @param array|object|null $asset 文件记录数据
     * @param string $key 字段名
     * @param mixed $default 默认值
     * @return mixed 文件字段值
     */
    private function field(array|object|null $asset, string $key, mixed $default = null): mixed
    {
        if (is_array($asset)) {
            return $asset[$key] ?? $default;
        }

        if (is_object($asset) && isset($asset->{$key})) {
            return $asset->{$key};
        }

        return $default;
    }

    /**
     * 归一化文件记录。
     *
     * @param array|object|null $asset 原始记录
     * @return array<string, mixed> 标准化记录
     */
    private function normalizeAsset(array|object|null $asset): array
    {
        return $this->field($asset, 'id', null) === null ? [] : [
            'id' => (int) $this->field($asset, 'id', 0),
            'storage_engine' => (int) $this->field($asset, 'storage_engine', FileConstant::STORAGE_LOCAL),
            'visibility' => (int) $this->field($asset, 'visibility', FileConstant::VISIBILITY_PRIVATE),
            'original_name' => (string) $this->field($asset, 'original_name', ''),
            'object_key' => (string) $this->field($asset, 'object_key', ''),
            'source_url' => (string) $this->field($asset, 'source_url', ''),
            'url' => (string) $this->field($asset, 'url', ''),
            'mime_type' => (string) $this->field($asset, 'mime_type', ''),
        ];
    }

    /**
     * 判断文件是否支持预览。
     *
     * @param int $scene 场景
     * @param string $mimeType MIME 类型
     * @param string $fileExt 文件扩展名
     * @return bool 是否支持预览
     */
    private function isPreviewable(int $scene, string $mimeType, string $fileExt): bool
    {
        if ($scene === FileConstant::SCENE_IMAGE || str_starts_with($mimeType, 'image/')) {
            return true;
        }

        if ($scene === FileConstant::SCENE_TEXT || str_starts_with($mimeType, 'text/')) {
            return true;
        }

        return in_array($fileExt, ['pem', 'crt', 'cer', 'key'], true);
    }
}
