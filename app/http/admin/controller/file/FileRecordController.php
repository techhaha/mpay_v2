<?php

namespace app\http\admin\controller\file;

use app\common\base\BaseController;
use app\http\admin\validation\FileRecordValidator;
use app\service\file\FileRecordService;
use Webman\Http\UploadFile;
use support\Request;
use support\Response;

/**
 * 文件控制器。
 */
class FileRecordController extends BaseController
{
    public function __construct(
        protected FileRecordService $fileRecordService
    ) {
    }

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

    public function options(Request $request): Response
    {
        return $this->success($this->fileRecordService->options());
    }

    public function show(Request $request, string $id): Response
    {
        $data = $this->validated(['id' => (int) $id], FileRecordValidator::class, 'show');

        return $this->success($this->fileRecordService->detail((int) $data['id']));
    }

    public function upload(Request $request): Response
    {
        $data = $this->validated(array_merge($this->payload($request), ['scene' => $request->input('scene')]), FileRecordValidator::class, 'store');
        $uploadedFile = $request->file('file');
        if ($uploadedFile === null) {
            return $this->fail('请先选择上传文件', 400);
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

            return $this->success([
                'list' => $items,
                'total' => count($items),
            ]);
        }

        if (!$uploadedFile instanceof UploadFile) {
            return $this->fail('上传文件无效', 400);
        }

        return $this->success($this->fileRecordService->upload($uploadedFile, $data, $createdBy, $createdByName));
    }

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

    public function preview(Request $request, string $id): Response
    {
        $data = $this->validated(['id' => (int) $id], FileRecordValidator::class, 'preview');

        return $this->fileRecordService->previewResponse((int) $data['id']);
    }

    public function download(Request $request, string $id): Response
    {
        $data = $this->validated(['id' => (int) $id], FileRecordValidator::class, 'download');

        return $this->fileRecordService->downloadResponse((int) $data['id']);
    }

    public function destroy(Request $request, string $id): Response
    {
        $data = $this->validated(['id' => (int) $id], FileRecordValidator::class, 'destroy');
        if (!$this->fileRecordService->delete((int) $data['id'])) {
            return $this->fail('文件不存在', 404);
        }

        return $this->success(true);
    }
}
