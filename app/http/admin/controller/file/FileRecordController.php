<?php

namespace app\http\admin\controller\file;

use app\common\base\BaseController;
use app\exception\ValidationException;
use app\http\admin\validation\FileRecordValidator;
use app\service\file\FileRecordService;
use Webman\Http\UploadFile;
use support\Request;
use support\Response;

/**
 * 文件控制器。
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
     * 查询文件记录列表
     *
     * @param Request $request 请求对象
     * @return Response 响应对象
     */
    public function index(Request $request): Response
    {
        $data = $this->validated($request->all(), FileRecordValidator::class, 'index');

        return $this->page(
            $this->fileRecordService->paginate(
                $data,
                (int) ($data['page'] ?? 1),
                (int) ($data['page_size'] ?? 10)
            )
        );
    }

    /**
     * 获取文件记录选项
     *
     * @param Request $request 请求对象
     * @return Response 响应对象
     */
    public function options(Request $request): Response
    {
        return $this->success($this->fileRecordService->options());
    }

    /**
     * 查询文件记录详情
     *
     * @param Request $request 请求对象
     * @param string $id 文件记录ID
     * @return Response 响应对象
     */
    public function show(Request $request, string $id): Response
    {
        $data = $this->validated(['id' => (int) $id], FileRecordValidator::class, 'show');

        return $this->success($this->fileRecordService->detail((int) $data['id']));
    }

    /**
     * 上传文件记录
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

        $createdBy = $this->currentAdminId($request);
        $createdByName = (string) $this->requestAttribute($request, 'auth.admin_username', '');

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
     * 导入远程文件记录
     *
     * @param Request $request 请求对象
     * @return Response 响应对象
     */
    public function importRemote(Request $request): Response
    {
        $data = $this->validated($this->payload($request), FileRecordValidator::class, 'importRemote');
        $createdBy = $this->currentAdminId($request);
        $createdByName = (string) $this->requestAttribute($request, 'auth.admin_username', '');

        return $this->success(
            $this->fileRecordService->importRemote(
                (string) $data['remote_url'],
                $data,
                $createdBy,
                $createdByName
            )
        );
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

    /**
     * 删除文件记录
     *
     * @param Request $request 请求对象
     * @param string $id 文件记录ID
     * @return Response 响应对象
     */
    public function destroy(Request $request, string $id): Response
    {
        $data = $this->validated(['id' => (int) $id], FileRecordValidator::class, 'destroy');
        $this->fileRecordService->delete((int) $data['id']);

        return $this->success(true);
    }
}





