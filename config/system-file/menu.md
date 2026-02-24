# 系统菜单配置

## 配置说明
系统菜单基于路由配置实现，以下是完整的菜单路由配置项说明，所有配置项均为 JSON 格式，可直接用于前端路由解析。

## 核心配置结构
```json
{
  "id": "01",
  "parentId": "0",
  "path": "/home",
  "name": "home",
  "component": "home/home",
  "meta": {
    "title": "home",
    "hide": false,
    "disable": false,
    "keepAlive": false,
    "affix": true,
    "link": "",
    "iframe": false,
    "isFull": false,
    "roles": ["admin", "common"],
    "svgIcon": "home",
    "icon": "",
    "sort": 1,
    "type": 2
  },
  "children": null
}
```

## 字段详细说明
| 一级字段 | 类型   | 必填 | 说明                                                                 |
|----------|--------|------|----------------------------------------------------------------------|
| `id`     | string | 是   | 路由唯一标识，建议按「层级+序号」命名（如 01=首页，0201=订单管理）|
| `parentId` | string | 是 | 父路由ID，顶层路由固定为 `0`，子路由对应父路由的 `id` |
| `path`   | string | 是   | 路由访问路径（如 `/home`、`/order/order-list`）|
| `name`   | string | 是   | 路由名称，需与组件名/路径名保持一致，用于路由跳转标识                 |
| `component` | string | 否 | 组件文件路径（基于 `src/views` 目录），如 `home/home` 对应 `src/views/home/home.vue`；目录级路由可省略 |
| `meta`   | object | 是   | 路由元信息，包含菜单展示、权限、样式等核心配置                       |
| `children` | array | 否 | 子路由列表，目录级路由（`type:1`）需配置，菜单级路由（`type:2`）默认为 `null` |

### `meta` 子字段说明
| 子字段    | 类型    | 必填 | 默认值 | 说明                                                                 |
|-----------|---------|------|--------|----------------------------------------------------------------------|
| `title`   | string  | 是   | -      | 菜单显示标题：<br>1. 填写国际化key（如 `home`），自动匹配多语言；<br>2. 无对应key则直接展示文字 |
| `hide`    | boolean | 否   | false  | 是否隐藏菜单：<br>✅ true = 不显示在侧边栏，但可正常访问；<br>❌ false = 正常显示 |
| `disable` | boolean | 否   | false  | 是否停用路由：<br>✅ true = 不显示+不可访问；<br>❌ false = 正常可用 |
| `keepAlive` | boolean | 否 | false | 是否缓存组件：<br>✅ true = 切换路由不销毁组件；<br>❌ false = 切换后销毁 |
| `affix`   | boolean | 否   | false  | 是否固定在标签栏：<br>✅ true = 标签栏无关闭按钮；<br>❌ false = 可关闭 |
| `link`    | string  | 否   | ""     | 外链地址，填写后路由跳转至该地址（优先级高于 `component`）|
| `iframe`  | boolean | 否   | false  | 是否内嵌外链：<br>✅ true = 在页面内以iframe展示 `link` 地址；<br>❌ false = 跳转新页面 |
| `isFull`  | boolean | 否   | false  | 是否全屏显示：<br>✅ true = 菜单页面占满整个视口；<br>❌ false = 保留侧边栏/头部 |
| `roles`   | array   | 否   | []     | 路由权限角色：<br>如 `["admin", "common"]`，仅对应角色可访问该菜单     |
| `svgIcon` | string  | 否   | ""     | SVG菜单图标：<br>优先级高于 `icon`，取值为 `src/assets/svgs` 目录下的SVG文件名 |
| `icon`    | string  | 否   | ""     | 普通图标：<br>默认使用 Arco Design 图标库，填写图标名（如 `icon-file`）即可 |
| `sort`    | number  | 否   | 0      | 菜单排序：数值越小，展示越靠前                                       |
| `type`    | number  | 是   | -      | 路由类型：<br>1 = 目录（仅作为父级，无组件）；<br>2 = 菜单（可访问的页面）；<br>3 = 按钮（权限控制用） |

## 配置示例
### 1. 顶层菜单（首页）
```json
{
  "id": "01",
  "parentId": "0",
  "path": "/home",
  "name": "home",
  "component": "home/home",
  "meta": {
    "title": "平台首页",
    "hide": false,
    "disable": false,
    "keepAlive": false,
    "affix": true,
    "link": "",
    "iframe": false,
    "isFull": false,
    "roles": ["admin", "common"],
    "svgIcon": "home",
    "icon": "",
    "sort": 1,
    "type": 2
  },
  "children": null
}
```

### 2. 目录级路由（收款订单）
```json
{
  "id": "02",
  "parentId": "0",
  "path": "/order",
  "name": "order",
  "redirect": "/order/order-list",
  "meta": {
    "title": "收款订单",
    "hide": false,
    "disable": false,
    "keepAlive": true,
    "affix": false,
    "link": "",
    "iframe": false,
    "isFull": false,
    "roles": ["admin", "common"],
    "svgIcon": "order",
    "icon": "",
    "sort": 2,
    "type": 1
  },
  "children": [
    {
      "id": "0201",
      "parentId": "02",
      "path": "/order/order-list",
      "name": "order-list",
      "component": "order/order-list/index",
      "meta": {
        "title": "订单管理",
        "hide": false,
        "disable": false,
        "keepAlive": true,
        "affix": false,
        "link": "",
        "iframe": false,
        "isFull": false,
        "roles": ["admin", "common"],
        "icon": "icon-file",
        "sort": 1,
        "type": 2
      },
      "children": null
    }
  ]
}
```
