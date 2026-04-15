<?php

namespace app\service\file;

use app\common\base\BaseService;
use app\common\constant\FileConstant;
use app\exception\ResourceNotFoundException;
use app\repository\file\FileRecordRepository;
use app\service\file\storage\StorageManager;

/**
 * 文件查询服务。
 */
class FileRecordQueryService extends BaseService
{
    public function __construct(
        protected FileRecordRepository $fileRecordRepository,
        protected StorageManager $storageManager
    ) {
    }

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

    public function detail(int $id): array
    {
        $asset = $this->fileRecordRepository->findById($id);
        if (!$asset) {
            throw new ResourceNotFoundException('文件不存在', ['id' => $id]);
        }

        return $this->formatModel($asset);
    }

    public function formatModel(mixed $asset): array
    {
        $id = (int) $this->field($asset, 'id', 0);
        $scene = (int) $this->field($asset, 'scene', FileConstant::SCENE_OTHER);
        $visibility = (int) $this->field($asset, 'visibility', FileConstant::VISIBILITY_PRIVATE);
        $storageEngine = (int) $this->field($asset, 'storage_engine', FileConstant::STORAGE_LOCAL);
        $sourceType = (int) $this->field($asset, 'source_type', FileConstant::SOURCE_UPLOAD);
        $size = (int) $this->field($asset, 'size', 0);
        $publicUrl = (string) $this->field($asset, 'url', '');
        $previewUrl = $publicUrl !== '' ? $publicUrl : $this->storageManager->temporaryUrl($this->normalizeAsset($asset));
        if ($previewUrl === '' && $id > 0) {
            $previewUrl = '/adminapi/file-asset/' . $id . '/preview';
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
            'url' => $previewUrl,
            'public_url' => $publicUrl,
            'preview_url' => $previewUrl,
            'download_url' => $id > 0 ? '/adminapi/file-asset/' . $id . '/download' : '',
            'created_by' => (int) $this->field($asset, 'created_by', 0),
            'created_by_name' => (string) $this->field($asset, 'created_by_name', ''),
            'remark' => (string) $this->field($asset, 'remark', ''),
            'is_image' => $scene === FileConstant::SCENE_IMAGE || str_starts_with(strtolower((string) $this->field($asset, 'mime_type', '')), 'image/'),
            'created_at' => $this->formatDateTime($this->field($asset, 'created_at', null)),
            'updated_at' => $this->formatDateTime($this->field($asset, 'updated_at', null)),
        ];
    }

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

    private function field(mixed $asset, string $key, mixed $default = null): mixed
    {
        if (is_array($asset)) {
            return $asset[$key] ?? $default;
        }

        if (is_object($asset) && isset($asset->{$key})) {
            return $asset->{$key};
        }

        return $default;
    }

    private function normalizeAsset(mixed $asset): array
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
}
