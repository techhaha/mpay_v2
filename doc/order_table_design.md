# 支付订单表设计说明

## 一、订单表设计原因

### 1.1 订单号设计（双重订单号）

**系统订单号 (`pay_order_id`)**
- **作用**：系统内部唯一标识，用于查询、对账、退款等操作
- **生成规则**：`P` + `YYYYMMDDHHmmss` + `6位随机数`（如：P20240101120000123456）
- **唯一性**：通过 `uk_pay_order_id` 唯一索引保证
- **优势**：
  - 全局唯一，不受商户影响
  - 便于系统内部查询和关联
  - 对账时作为主键

**商户订单号 (`mch_order_no`)**
- **作用**：商户传入的订单号，用于幂等性校验
- **唯一性**：通过 `uk_mch_order_no(merchant_id, mch_order_no)` 联合唯一索引保证
- **优势**：
  - 同一商户下订单号唯一，防止重复提交
  - 商户侧可以自定义订单号规则
  - 支持商户订单号查询订单

**为什么需要两个订单号？**
- 系统订单号：保证全局唯一，便于系统内部管理
- 商户订单号：保证商户侧唯一，防止重复支付（幂等性）

### 1.2 关联关系设计

**商户与应用关联 (`merchant_id` + `app_id`)**
- **作用**：标识订单所属商户和应用
- **用途**：
  - 权限控制（商户只能查询自己的订单）
  - 对账统计（按商户/应用维度）
  - 通知路由（根据应用配置的通知地址）

**支付通道关联 (`channel_id`)**
- **作用**：记录实际使用的支付通道
- **用途**：
  - 退款时找到对应的插件和配置
  - 对账时关联通道信息
  - 统计通道使用情况

**支付方式与产品 (`method_code` + `product_code`)**
- **method_code**：支付方式（alipay/wechat/unionpay）
  - 用于统计、筛选、报表
- **product_code**：支付产品（alipay_h5/alipay_life/wechat_jsapi等）
  - 由插件根据用户环境自动选择
  - 用于记录实际使用的支付产品

### 1.3 金额字段设计

**订单金额 (`amount`)**
- 商户实际收款金额（扣除手续费前）
- 用于退款金额校验、对账

**手续费 (`fee`)**
- 可选字段，记录通道手续费
- 用于对账、结算、利润统计
- 如果不需要详细记录手续费，可以留空或通过 `extra` 存储

**币种 (`currency`)**
- 默认 CNY，支持国际化扩展
- 预留字段，便于后续支持多币种

### 1.4 状态流转设计

```
PENDING（待支付）
  ├─> SUCCESS（支付成功）← 收到渠道回调并验签通过
  ├─> FAIL（支付失败）← 用户取消、超时、渠道返回失败
  └─> CLOSED（已关闭）← 全额退款后
```

**状态说明**：
- **PENDING**：订单创建后，等待用户支付
- **SUCCESS**：支付成功，已收到渠道回调并验签通过
- **FAIL**：支付失败（用户取消、订单超时、渠道返回失败等）
- **CLOSED**：已关闭（全额退款后）

### 1.5 渠道信息设计

**渠道订单号 (`channel_order_no`)**
- 渠道返回的订单号
- 用于查询订单状态、退款等操作

**渠道交易号 (`channel_trade_no`)**
- 部分渠道有交易号概念（如支付宝的 trade_no）
- 用于对账、查询等

### 1.6 通知机制设计

**通知状态 (`notify_status`)**
- 0：未通知
- 1：已通知成功

**通知次数 (`notify_count`)**
- 记录通知次数，用于重试控制
- 配合 `ma_notify_task` 表实现异步通知

### 1.7 扩展性设计

**扩展字段 (`extra`)**
- JSON 格式，存储：
  - 支付参数（`pay_params`）：前端支付所需的参数
  - 退款信息（`refund_info`）：退款结果
  - 自定义字段：业务扩展字段

**订单过期时间 (`expire_time`)**
- 用于自动关闭超时订单
- 默认 30 分钟，可配置

## 二、索引设计说明

### 2.1 唯一索引

- **`uk_pay_order_id`**：保证系统订单号唯一
- **`uk_mch_order_no(merchant_id, mch_order_no)`**：保证同一商户下商户订单号唯一（幂等性）

### 2.2 普通索引

- **`idx_merchant_app(merchant_id, app_id)`**：商户/应用维度查询
- **`idx_channel_id`**：通道维度查询
- **`idx_method_code`**：支付方式维度统计
- **`idx_status`**：状态筛选
- **`idx_pay_time`**：按支付时间查询（对账、统计）
- **`idx_created_at`**：按创建时间查询（分页、统计）

## 三、可能遗漏的字段（后续扩展）

### 3.1 退款相关字段

如果后续需要支持**部分退款**或**多次退款**，可以考虑添加：

```sql
`refund_amount` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '已退款金额（累计）',
`refund_status` varchar(20) NOT NULL DEFAULT '' COMMENT '退款状态：PENDING-退款中, SUCCESS-退款成功, FAIL-退款失败',
`refund_time` datetime DEFAULT NULL COMMENT '最后退款时间',
```

**当前设计**：
- 退款信息存储在 `extra['refund_info']` 中
- 全额退款后订单状态改为 `CLOSED`
- 如果只需要全额退款，当前设计已足够

### 3.2 结算相关字段

如果后续需要**分账/结算**功能，可以考虑添加：

```sql
`settlement_status` varchar(20) NOT NULL DEFAULT '' COMMENT '结算状态：PENDING-待结算, SUCCESS-已结算, FAIL-结算失败',
`settlement_time` datetime DEFAULT NULL COMMENT '结算时间',
`settlement_amount` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '结算金额',
```

**当前设计**：
- 结算信息可以通过 `extra` 存储
- 如果不需要复杂的结算流程，当前设计已足够

### 3.3 风控相关字段

如果需要**风控功能**，可以考虑添加：

```sql
`risk_level` varchar(20) NOT NULL DEFAULT '' COMMENT '风险等级：LOW-低, MEDIUM-中, HIGH-高',
`risk_score` int(11) NOT NULL DEFAULT 0 COMMENT '风险评分',
`risk_reason` varchar(255) NOT NULL DEFAULT '' COMMENT '风险原因',
```

**当前设计**：
- 风控信息可以通过 `extra` 存储
- 如果不需要复杂的风控系统，当前设计已足够

### 3.4 其他扩展字段

- **`user_id`**：用户ID（如果需要关联用户）
- **`device_info`**：设备信息（用于风控）
- **`remark`**：备注（管理员备注）
- **`close_reason`**：关闭原因（用户取消/超时/管理员关闭等）

## 四、设计原则总结

1. **幂等性**：通过 `uk_mch_order_no` 保证同一商户下订单号唯一
2. **可追溯性**：记录完整的订单信息、渠道信息、时间信息
3. **可扩展性**：通过 `extra` JSON 字段存储扩展信息
4. **性能优化**：合理的索引设计，支持常见查询场景
5. **业务完整性**：覆盖订单全生命周期（创建→支付→退款→关闭）

## 五、与代码的对应关系

| SQL 字段 | 代码字段 | 说明 |
|---------|---------|------|
| `pay_order_id` | `pay_order_id` | 系统订单号 |
| `merchant_id` | `merchant_id` | 商户ID |
| `app_id` | `app_id` | 应用ID |
| `mch_order_no` | `mch_order_no` | 商户订单号 |
| `method_code` | `method_code` | 支付方式 |
| `product_code` | `product_code` | 支付产品 |
| `channel_id` | `channel_id` | 通道ID |
| `amount` | `amount` | 订单金额 |
| `currency` | `currency` | 币种 |
| `status` | `status` | 订单状态 |
| `channel_order_no` | `channel_order_no` | 渠道订单号 |
| `channel_trade_no` | `channel_trade_no` | 渠道交易号 |
| `extra` | `extra` | 扩展字段（JSON） |

## 六、注意事项

1. **字段命名统一**：SQL 和代码中的字段名必须一致
2. **索引维护**：定期检查索引使用情况，优化慢查询
3. **数据归档**：历史订单数据量大时，考虑归档策略
4. **JSON 字段**：`extra` 字段使用 JSON 类型，便于扩展但查询性能略低
5. **时间字段**：`pay_time`、`expire_time` 等时间字段使用 `datetime` 类型，便于查询和统计

