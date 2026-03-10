验证器 webman/validation
基于 illuminate/validation，提供手动验证、注解验证、参数级验证，以及可复用的规则集。

安装
composer require webman/validation
基本概念
规则集复用：通过继承 support\validation\Validator 定义可复用的 rules messages attributes scenes，可在手动与注解中复用。
方法级注解（Attribute）验证：使用 PHP 8 属性注解 #[Validate] 绑定控制器方法。
参数级注解（Attribute）验证：使用 PHP 8 属性注解 #[Param] 绑定控制器方法参数。
异常处理：验证失败抛出 support\validation\ValidationException，异常类可通过配置自定义
数据库验证：如果涉及数据库验证，需要安装 composer require webman/database
手动验证
基本用法
use support\validation\Validator;

$data = ['email' => 'user@example.com'];

Validator::make($data, [
    'email' => 'required|email',
])->validate();
提示
validate() 校验失败会抛出 support\validation\ValidationException。如果你不希望抛异常，请使用下方的 fails() 写法获取错误信息。

自定义 messages 与 attributes
use support\validation\Validator;

$data = ['contact' => 'user@example.com'];

Validator::make(
    $data,
    ['contact' => 'required|email'],
    ['contact.email' => '邮箱格式不正确'],
    ['contact' => '邮箱']
)->validate();
不抛异常并获取错误信息
如果你不希望抛异常，可以使用 fails() 判断，并通过 errors()（返回 MessageBag）获取错误信息：

use support\validation\Validator;

$data = ['email' => 'bad-email'];

$validator = Validator::make($data, [
    'email' => 'required|email',
]);

if ($validator->fails()) {
    $firstError = $validator->errors()->first();      // string
    $allErrors = $validator->errors()->all();         // array
    $errorsByField = $validator->errors()->toArray(); // array
    // 处理错误...
}
规则集复用（自定义 Validator）
namespace app\validation;

use support\validation\Validator;

class UserValidator extends Validator
{
    protected array $rules = [
        'id' => 'required|integer|min:1',
        'name' => 'required|string|min:2|max:20',
        'email' => 'required|email',
    ];

    protected array $messages = [
        'name.required' => '姓名必填',
        'email.required' => '邮箱必填',
        'email.email' => '邮箱格式不正确',
    ];

    protected array $attributes = [
        'name' => '姓名',
        'email' => '邮箱',
    ];
}
手动验证复用
use app\validation\UserValidator;

UserValidator::make($data)->validate();
使用 scenes（可选）
scenes 是可选能力，只有在你调用 withScene(...) 时，才会按场景只验证部分字段。

namespace app\validation;

use support\validation\Validator;

class UserValidator extends Validator
{
    protected array $rules = [
        'id' => 'required|integer|min:1',
        'name' => 'required|string|min:2|max:20',
        'email' => 'required|email',
    ];

    protected array $scenes = [
        'create' => ['name', 'email'],
        'update' => ['id', 'name', 'email'],
    ];
}
use app\validation\UserValidator;

// 不指定场景 -> 验证全部规则
UserValidator::make($data)->validate();

// 指定场景 -> 只验证该场景包含的字段
UserValidator::make($data)->withScene('create')->validate();
注解验证（方法级）
直接规则
use support\Request;
use support\validation\annotation\Validate;

class AuthController
{
    #[Validate(
        rules: [
            'email' => 'required|email',
            'password' => 'required|string|min:6',
        ],
        messages: [
            'email.required' => '邮箱必填',
            'password.required' => '密码必填',
        ],
        attributes: [
            'email' => '邮箱',
            'password' => '密码',
        ]
    )]
    public function login(Request $request)
    {
        return json(['code' => 0, 'msg' => 'ok']);
    }
}
复用规则集
use app\validation\UserValidator;
use support\Request;
use support\validation\annotation\Validate;

class UserController
{
    #[Validate(validator: UserValidator::class, scene: 'create')]
    public function create(Request $request)
    {
        return json(['code' => 0, 'msg' => 'ok']);
    }
}
多重验证叠加
use support\validation\annotation\Validate;

class UserController
{
    #[Validate(rules: ['email' => 'required|email'])]
    #[Validate(rules: ['token' => 'required|string'])]
    public function send()
    {
        return json(['code' => 0, 'msg' => 'ok']);
    }
}
验证数据来源
use support\validation\annotation\Validate;

class UserController
{
    #[Validate(
        rules: ['email' => 'required|email'],
        in: ['query', 'body', 'path']
    )]
    public function send()
    {
        return json(['code' => 0, 'msg' => 'ok']);
    }
}
通过in参数来指定数据来源，其中：

query http请求的query参数，取自 $request->get()
body http请求的包体，取自 $request->post()
path http请求的路径参数，取自 $request->route->param()
in可为字符串或数组；为数组时按顺序合并，后者覆盖前者。未传递in时默认等效于 ['query', 'body', 'path']。

参数级验证（Param）
基本用法
use support\validation\annotation\Param;

class MailController
{
    public function send(
        #[Param(rules: 'required|email')] string $from,
        #[Param(rules: 'required|email')] string $to,
        #[Param(rules: 'required|string|min:1|max:500')] string $content
    ) {
        return json(['code' => 0, 'msg' => 'ok']);
    }
}
验证数据来源
类似的，参数级也支持in参数指定来源

use support\validation\annotation\Param;

class MailController
{
    public function send(
        #[Param(rules: 'required|email', in: ['body'])] string $from
    ) {
        return json(['code' => 0, 'msg' => 'ok']);
    }
}
rules 支持字符串或数组
use support\validation\annotation\Param;

class MailController
{
    public function send(
        #[Param(rules: ['required', 'email'])] string $from
    ) {
        return json(['code' => 0, 'msg' => 'ok']);
    }
}
自定义 messages / attribute
use support\validation\annotation\Param;

class UserController
{
    public function updateEmail(
        #[Param(
            rules: 'required|email',
            messages: ['email.email' => '邮箱格式不正确'],
            attribute: '邮箱'
        )]
        string $email
    ) {
        return json(['code' => 0, 'msg' => 'ok']);
    }
}
规则常量复用
final class ParamRules
{
    public const EMAIL = ['required', 'email'];
}

class UserController
{
    public function send(
        #[Param(rules: ParamRules::EMAIL)] string $email
    ) {
        return json(['code' => 0, 'msg' => 'ok']);
    }
}
方法级 + 参数级混合
use support\Request;
use support\validation\annotation\Param;
use support\validation\annotation\Validate;

class UserController
{
    #[Validate(rules: ['token' => 'required|string'])]
    public function send(
        Request $request,
        #[Param(rules: 'required|email')] string $from,
        #[Param(rules: 'required|integer')] int $id
    ) {
        return json(['code' => 0, 'msg' => 'ok']);
    }
}
自动规则推导（基于参数签名）
当方法上使用 #[Validate]，或该方法的任意参数使用了 #[Param] 时，本组件会根据方法参数签名自动推导并补全基础验证规则，再与已有规则合并后执行验证。

示例：#[Validate] 等价展开
1) 只开启 #[Validate]，不手写规则：

use support\validation\annotation\Validate;

class DemoController
{
    #[Validate]
    public function create(string $content, int $uid)
    {
    }
}
等价于：

use support\validation\annotation\Validate;

class DemoController
{
    #[Validate(rules: [
        'content' => 'required|string',
        'uid' => 'required|integer',
    ])]
    public function create(string $content, int $uid)
    {
    }
}
2) 只写了部分规则，其余由参数签名补全：

use support\validation\annotation\Validate;

class DemoController
{
    #[Validate(rules: [
        'content' => 'min:2',
    ])]
    public function create(string $content, int $uid)
    {
    }
}
等价于：

use support\validation\annotation\Validate;

class DemoController
{
    #[Validate(rules: [
        'content' => 'required|string|min:2',
        'uid' => 'required|integer',
    ])]
    public function create(string $content, int $uid)
    {
    }
}
3) 默认值/可空类型：

use support\validation\annotation\Validate;

class DemoController
{
    #[Validate]
    public function create(string $content = '默认值', ?int $uid = null)
    {
    }
}
等价于：

use support\validation\annotation\Validate;

class DemoController
{
    #[Validate(rules: [
        'content' => 'string',
        'uid' => 'integer|nullable',
    ])]
    public function create(string $content = '默认值', ?int $uid = null)
    {
    }
}
异常处理
默认异常
验证失败默认抛出 support\validation\ValidationException，继承 Webman\Exception\BusinessException，不会记录错误日志。

默认响应行为由 BusinessException::render() 处理：

普通请求：返回字符串消息，例如 token 为必填项。
JSON 请求：返回 JSON 响应，例如 {"code": 422, "msg": "token 为必填项。", "data":....}
通过自定义异常修改处理方式
全局配置：config/plugin/webman/validation/app.php 的 exception
多语言支持
组件内置中英文语言包，并支持项目覆盖。加载顺序：

项目语言包 resource/translations/{locale}/validation.php
组件内置 vendor/webman/validation/resources/lang/{locale}/validation.php
Illuminate 内置英文（兜底）
提示
webman默认语言由 config/translation.php 配置，也可以通过函数 locale('en'); 更改。

本地覆盖示例
resource/translations/zh_CN/validation.php

return [
    'email' => ':attribute 不是有效的邮件格式。',
];
中间件自动加载
组件安装后会通过 config/plugin/webman/validation/middleware.php 自动加载验证中间件，无需手动注册。

命令行生成注解
使用命令 make:validator 生成验证器类（默认生成到 app/validation 目录）。

提示
需要安装 composer require webman/console

基础用法
生成空模板
php webman make:validator UserValidator
覆盖已存在文件
php webman make:validator UserValidator --force
php webman make:validator UserValidator -f
从表结构生成规则
指定表名生成基础规则（会根据字段类型/可空/长度等推导 $rules；默认排除字段与 ORM 相关：laravel 为 created_at/updated_at/deleted_at，thinkorm 为 create_time/update_time/delete_time）
php webman make:validator UserValidator --table=wa_users
php webman make:validator UserValidator -t wa_users
指定数据库连接（多连接场景）
php webman make:validator UserValidator --table=wa_users --database=mysql
php webman make:validator UserValidator -t wa_users -d mysql
场景（scenes）
生成 CRUD 场景：create/update/delete/detail
php webman make:validator UserValidator --table=wa_users --scenes=crud
php webman make:validator UserValidator -t wa_users -s crud
update 场景会包含主键字段（用于定位记录）以及其余字段；delete/detail 默认仅包含主键字段。