# 文件资产

文件资产由后端记录元数据，实际文件交给本地、远程 URL、OSS 或 COS 驱动处理。

## 当前入口

- API 前缀：`/adminapi/file-asset`
- 控制器：`FileRecordController`
- 模型：`FileRecord`
- 数据表：`ma_file_asset`

## 接口

| 方法 | 路径 | 作用 |
| --- | --- | --- |
| `GET` | `/file-asset/options` | 文件选项 |
| `GET` | `/file-asset` | 文件列表 |
| `POST` | `/file-asset/upload` | 上传文件 |
| `POST` | `/file-asset/import-remote` | 导入远程文件 |
| `GET` | `/file-asset/{id}/preview` | 文件预览 |
| `GET` | `/file-asset/{id}/download` | 文件下载 |
| `GET` | `/file-asset/{id}` | 文件详情 |
| `DELETE` | `/file-asset/{id}` | 删除文件 |

## 服务与驱动

- `FileRecordService`
- `FileRecordQueryService`
- `FileRecordCommandService`
- `StorageConfigService`
- `StorageManager`
- `LocalStorageDriver`
- `RemoteUrlStorageDriver`
- `OssStorageDriver`
- `CosStorageDriver`

## 字段口径

- `object_key`：站点相对路径或对象存储 key。
- `url`：公开文件的完整访问地址，私有文件可以为空。
- `preview_url`：后台预览使用，不作为长期业务访问地址。
- `previewable`：列表返回给前端判断是否允许在线预览。

## 表单上传

系统配置和插件配置中的上传字段仍使用 `type: "upload"`。需要走项目定制选择器时，在 `props.fileUpload` 中声明 `selectorType`、`scene`、`isLocal`、`isPublic`、`getKey` 等配置。前端说明见 [管理后台前端](../frontend/admin.md)。
