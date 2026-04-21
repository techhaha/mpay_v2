<?php

namespace app\service\file\storage;

use support\Response;

/**
 * 文件存储驱动接口。
 *
 * 统一定义文件存储驱动能力。
 */
interface StorageDriverInterface
{
    /**
     * 获取存储引擎标识。
     *
     * @return int 存储引擎常量
     */
    public function engine(): int;

    /**
     * 将本地临时文件写入存储后端。
     *
     * @param string $sourcePath 待上传的本地临时文件路径
     * @param array $context 上传上下文，通常包含 object_key、visibility 等信息
     * @return array 上传后的资产数据
     */
    public function storeFromPath(string $sourcePath, array $context): array;

    /**
     * 删除指定文件资产。
     *
     * @param array $asset 文件资产数据
     * @return bool 是否删除成功
     */
    public function delete(array $asset): bool;

    /**
     * 构造文件预览响应。
     *
     * @param array $asset 文件资产数据
     * @return Response 响应对象
     */
    public function previewResponse(array $asset): Response;

    /**
     * 构造文件下载响应。
     *
     * @param array $asset 文件资产数据
     * @return Response 响应对象
     */
    public function downloadResponse(array $asset): Response;

    /**
     * 获取公开访问地址。
     *
     * @param array $asset 文件资产数据
     * @return string 公开 URL
     */
    public function publicUrl(array $asset): string;

    /**
     * 获取临时访问地址。
     *
     * @param array $asset 文件资产数据
     * @return string 临时 URL
     */
    public function temporaryUrl(array $asset): string;
}
