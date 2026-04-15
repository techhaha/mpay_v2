<?php

namespace app\repository\file;

use app\common\base\BaseRepository;
use app\model\file\FileRecord;

/**
 * 文件仓储。
 */
class FileRecordRepository extends BaseRepository
{
    public function __construct()
    {
        parent::__construct(new FileRecord());
    }

    public function findById(int $id, array $columns = ['*']): ?FileRecord
    {
        $model = $this->find($id, $columns);

        return $model instanceof FileRecord ? $model : null;
    }
}
