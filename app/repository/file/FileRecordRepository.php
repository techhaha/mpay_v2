<?php

namespace app\repository\file;

use app\common\base\BaseRepository;
use app\model\file\FileRecord;

/**
 * 文件记录仓储。
 *
 * 封装文件资产表的基础查询方法。
 */
class FileRecordRepository extends BaseRepository
{
    /**
     * 构造方法。
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct(new FileRecord());
    }

    /**
     * 按 ID 查询文件记录。
     *
     * @param int $id 文件记录ID
     * @param array $columns 字段列表
     * @return FileRecord|null 文件记录
     */
    public function findById(int $id, array $columns = ['*']): ?FileRecord
    {
        $model = $this->find($id, $columns);

        return $model instanceof FileRecord ? $model : null;
    }
}



