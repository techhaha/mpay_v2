# 认证策略设计说明

## 设计理念

采用**策略模式**替代中间件方式处理认证，具有以下优势：

1. **灵活扩展**：可以轻松添加新的接口标准（如易支付、OpenAPI、自定义标准等）
2. **按需使用**：控制器可以根据需要选择认证策略，而不是在路由层面强制
3. **易于测试**：策略类可以独立测试，不依赖中间件
4. **代码复用**：不同接口可以共享相同的认证逻辑

## 架构设计

### 1. 核心接口

**`AuthStrategyInterface`** - 认证策略接口
```php
interface AuthStrategyInterface
{
    public function authenticate(Request $request): MerchantApp;
}
```

### 2. 策略实现

#### EpayAuthStrategy（易支付认证）
- 使用 `pid` + `key` + `MD5签名`
- 参数格式：`application/x-www-form-urlencoded`
- 签名算法：MD5(排序后的参数字符串 + KEY)

#### OpenApiAuthStrategy（OpenAPI认证）
- 使用 `app_id` + `timestamp` + `nonce` + `HMAC-SHA256签名`
- 支持请求头或参数传递
- 签名算法：HMAC-SHA256(签名字符串, app_secret)

### 3. 认证服务

**`AuthService`** - 认证服务，负责：
- 自动检测接口标准类型
- 根据类型选择对应的认证策略
- 支持手动注册新的认证策略

```php
// 自动检测
$app = $authService->authenticate($request);

// 指定策略类型
$app = $authService->authenticate($request, 'epay');

// 注册新策略
$authService->registerStrategy('custom', CustomAuthStrategy::class);
```

## 使用示例

### 控制器中使用

```php
class PayController extends BaseController
{
    public function __construct(
        protected PayOrderService $payOrderService,
        protected AuthService $authService
    ) {
    }
    
    public function submit(Request $request)
    {
        // 自动检测或指定策略类型
        $app = $this->authService->authenticate($request, 'epay');
        
        // 使用 $app 进行后续业务处理
        // ...
    }
}
```

### 添加新的认证策略

1. **实现策略接口**
```php
class CustomAuthStrategy implements AuthStrategyInterface
{
    public function authenticate(Request $request): MerchantApp
    {
        // 实现自定义认证逻辑
        // ...
    }
}
```

2. **注册策略**
```php
// 在服务提供者或启动文件中
$authService = new AuthService();
$authService->registerStrategy('custom', CustomAuthStrategy::class);
```

3. **在控制器中使用**
```php
$app = $this->authService->authenticate($request, 'custom');
```

## 自动检测机制

`AuthService` 会根据请求特征自动检测接口标准：

- **易支付**：检测到 `pid` 参数
- **OpenAPI**：检测到 `X-App-Id` 请求头或 `app_id` 参数

如果无法自动检测，可以手动指定策略类型。

## 优势对比

### 中间件方式（旧方案）
- ❌ 路由配置复杂，每个接口标准需要不同的中间件
- ❌ 难以在同一路由支持多种认证方式
- ❌ 扩展新标准需要修改路由配置

### 策略模式（新方案）
- ✅ 控制器按需选择认证策略
- ✅ 同一路由可以支持多种认证方式（通过参数区分）
- ✅ 扩展新标准只需实现策略接口并注册
- ✅ 代码更清晰，职责分离

## 路由配置

由于不再使用中间件，路由配置更简洁：

```php
// 易支付接口
Route::any('/submit.php', [PayController::class, 'submit']);
Route::post('/mapi.php', [PayController::class, 'mapi']);
Route::get('/api.php', [PayController::class, 'queryOrder']);

// 所有接口都在控制器内部进行认证，无需中间件
```

## 总结

通过策略模式重构认证逻辑，系统具备了：
- **高扩展性**：轻松添加新的接口标准
- **高灵活性**：控制器可以自由选择认证方式
- **高可维护性**：代码结构清晰，易于理解和维护

