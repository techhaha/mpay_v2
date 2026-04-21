<?php

namespace app\model\file;

use app\common\base\BaseModel;

/**
 * 文件记录模型。
 */
class FileRecord extends BaseModel
{
    /**
     * 数据表名
     *
     * @var mixed
     */
    protected $table = 'ma_file_asset';

    /**
     * 可批量赋值字段
     *
     * @var mixed
     */
    protected $fillable = [
        'scene',
        'source_type',
        'visibility',
        'storage_engine',
        'original_name',
        'file_name',
        'file_ext',
        'mime_type',
        'size',
        'md5',
        'object_key',
        'url',
        'source_url',
        'created_by',
        'created_by_name',
        'remark',
    ];

    /**
     * 字段类型转换配置
     *
     * @var mixed
     */
    protected $casts = [
        'scene' => 'integer',
        'source_type' => 'integer',
        'visibility' => 'integer',
        'storage_engine' => 'integer',
        'size' => 'integer',
        'created_by' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}


