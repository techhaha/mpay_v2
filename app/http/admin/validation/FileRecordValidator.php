<?php

namespace app\http\admin\validation;

use support\validation\Validator;

/**
 * 文件参数校验器。
 */
class FileRecordValidator extends Validator
{
    protected array $rules = [
        'id' => 'sometimes|integer|min:1',
        'keyword' => 'sometimes|string|max:128',
        'scene' => 'nullable|integer|in:1,2,3,4',
        'source_type' => 'nullable|integer|in:1,2',
        'visibility' => 'nullable|integer|in:1,2',
        'storage_engine' => 'nullable|integer|in:1,2,3,4',
        'remote_url' => 'nullable|string|max:2048|url',
        'page' => 'sometimes|integer|min:1',
        'page_size' => 'sometimes|integer|min:1|max:100',
    ];

    protected array $attributes = [
        'id' => '文件ID',
        'keyword' => '关键字',
        'scene' => '文件场景',
        'source_type' => '来源类型',
        'visibility' => '可见性',
        'storage_engine' => '存储引擎',
        'remote_url' => '远程地址',
        'page' => '页码',
        'page_size' => '每页条数',
    ];

    protected array $scenes = [
        'index' => ['keyword', 'scene', 'source_type', 'visibility', 'storage_engine', 'page', 'page_size'],
        'show' => ['id'],
        'destroy' => ['id'],
        'preview' => ['id'],
        'download' => ['id'],
        'store' => ['scene', 'visibility'],
        'importRemote' => ['remote_url', 'scene', 'visibility'],
    ];
}
