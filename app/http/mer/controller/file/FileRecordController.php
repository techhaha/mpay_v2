<?php

namespace app\http\mer\controller\file;

use app\common\base\BaseController;
use app\exception\ValidationException;
use app\http\admin\validation\FileRecordValidator;
use app\service\file\FileRecordService;
use support\Request;
use support\Response;
use Webman\Http\UploadFile;

/**
 * 商户端文件控制器。
 *
 * 供插件配置动态表单中的上传字段使用。
 *
 * @property FileRecordService $fileRecordService 文件记录服务
 */
class FileRecordController extends BaseController
{
    /**
     * 构造方法。
     *
     * @param FileRecordService $fileRecordService 文件记录服务
     * @return void
     */
    public function __construct(
        protected FileRecordService $fileRecordService
    ) {
    }

    /**
     * 上传文件记录。
     *
     * @param Request $request 请求对象
     * @return Response 响应对象
     * @throws ValidationException
     */
    public function upload(Request $request): Response
    {
        $data = $this->validated(array_merge($this->payload($request), ['scene' => $request->input('scene')]), FileRecordValidator::class, 'store');
        $uploadedFile = $request->file('file');
        if ($uploadedFile === null) {
            throw new ValidationException('请先选择上传文件');
        }

        $createdBy = $this->currentMerchantId($request);
        $createdByName = $this->currentMerchantNo($request);

        if (is_array($uploadedFile)) {
            $items = [];
            foreach ($uploadedFile as $file) {
                if ($file instanceof UploadFile) {
                    $items[] = $this->fileRecordService->upload($file, $data, $createdBy, $createdByName);
                }
            }

            if ($items === []) {
                throw new ValidationException('上传文件无效');
            }

            return $this->success([
                'list' => $items,
                'total' => count($items),
            ]);
        }

        if (!$uploadedFile instanceof UploadFile) {
            throw new ValidationException('上传文件无效');
        }

        return $this->success($this->fileRecordService->upload($uploadedFile, $data, $createdBy, $createdByName));
    }

    /**
     * 获取文件预览响应。
     *
     * @param Request $request 请求对象
     * @param string $id 文件记录ID
     * @return Response 响应对象
     */
    public function preview(Request $request, string $id): Response
    {
        $data = $this->validated(['id' => (int) $id], FileRecordValidator::class, 'preview');

        return $this->fileRecordService->previewResponse((int) $data['id']);
    }

    /**
     * 获取文件下载响应。
     *
     * @param Request $request 请求对象
     * @param string $id 文件记录ID
     * @return Response 响应对象
     */
    public function download(Request $request, string $id): Response
    {
        $data = $this->validated(['id' => (int) $id], FileRecordValidator::class, 'download');

        return $this->fileRecordService->downloadResponse((int) $data['id']);
    }
}
