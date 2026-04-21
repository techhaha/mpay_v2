<?php

namespace app\service\file\storage;

use app\common\constant\FileConstant;
use app\exception\BusinessStateException;
use app\exception\ResourceNotFoundException;
use support\Response;

/**
 * 远程引用文件存储驱动。
 *
 * 仅保存原始远程 URL，不做本地落盘或对象存储复制。
 */
class RemoteUrlStorageDriver extends AbstractStorageDriver
{
    /**
     * 获取远程引用引擎标识。
     *
     * @return int 存储引擎常量
     */
    public function engine(): int
    {
        return FileConstant::STORAGE_REMOTE_URL;
    }

    /**
     * 远程引用模式不支持直接上传。
     *
     * @param string $sourcePath 待上传文件路径
     * @param array $context 上传上下文
     * @return array 上传后的资产数据
     * @throws BusinessStateException
     */
    public function storeFromPath(string $sourcePath, array $context): array
    {
        throw new BusinessStateException('远程引用模式不支持直接上传，请先下载后再入库');
    }

    /**
     * 远程引用模式不需要真正删除对象。
     *
     * @param array $asset 文件资产数据
     * @return bool 是否删除成功
     */
    public function delete(array $asset): bool
    {
        return true;
    }

    /**
     * 直接跳转到源站地址进行预览。
     *
     * @param array $asset 文件资产数据
     * @return Response 响应对象
     * @throws ResourceNotFoundException
     */
    public function previewResponse(array $asset): Response
    {
        $url = (string) ($asset['source_url'] ?? $asset['url'] ?? '');
        if ($url === '') {
            throw new ResourceNotFoundException('文件不存在');
        }

        return redirect($url);
    }

    /**
     * 远程引用文件的下载行为与预览保持一致。
     *
     * @param array $asset 文件资产数据
     * @return Response 响应对象
     */
    public function downloadResponse(array $asset): Response
    {
        return $this->previewResponse($asset);
    }

    /**
     * 获取原始远程地址。
     *
     * @param array $asset 文件资产数据
     * @return string 远程 URL
     */
    public function publicUrl(array $asset): string
    {
        return (string) ($asset['source_url'] ?? $asset['url'] ?? '');
    }

    /**
     * 获取原始远程地址。
     *
     * @param array $asset 文件资产数据
     * @return string 远程 URL
     */
    public function temporaryUrl(array $asset): string
    {
        return (string) ($asset['source_url'] ?? $asset['url'] ?? '');
    }
}
