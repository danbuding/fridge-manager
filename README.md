# 冰箱库存管理系统

一个基于 LNMP 架构的冰箱库存管理单页面应用，帮助家庭或小团队管理多台冰箱中的食材库存、跟踪保质期并支持物品在冰箱之间转移。

## 功能概览

| 模块 | 说明 |
|------|------|
| **汇总看板** | 系统首页，展示冰箱总数、物品总数、临期物品数（3天内）；各冰箱物品分布柱状图；临期提醒列表；最近添加物品；常用物品排名；未标注物品预警 |
| **库存浏览** | 选择冰箱和分类浏览物品；7 种排序方式；搜索筛选；快速取用（−1减量）；展示过期推算及临期/已过期状态；物品增删改操作 |
| **物品转移** | 将物品从一台冰箱转移到另一台冰箱；支持部分数量转移；分类筛选和名称搜索；自动记录转移日志 |
| **数据导入导出** | 支持 CSV/Markdown/SQL 三种格式导出和导入；支持下载空白模板；所有操作在界面内完成，无需外部工具 |
| **冰箱管理** | 冰箱的增删改操作；每台冰箱显示物品数量统计 |

## 技术架构

```
┌────────────────────────────────────────┐
│  LNMP 环境                               │
│ ├── Nginx       ← 站点配置               │
│ ├── PHP-FPM     ← API 路由               │
│ ├── MySQL       ← 数据存储               │
│ └── phpMyAdmin  ← 数据库管理             │
│                                          │
│ 应用文件：                               │
│ └── /var/www/fridge-manager/             │
│     ├── index.html         前端 SPA      │
│     ├── api/               后端 REST API │
│     │   ├── config.php     数据库配置    │
│     │   ├── dashboard.php  汇总看板 API  │
│     │   ├── fridge.php     冰箱 CRUD     │
│     │   ├── items.php      物品 CRUD     │
│     │   ├── transfer.php   转移操作 API  │
│     │   ├── categories.php 分类查询     │
│     │   ├── export_md.php  Markdown 导出 │
│     │   ├── export_sql.php  SQL 导出     │
│     │   ├── import_md.php  Markdown 导入 │
│     │   └── import_sql.php SQL 导入     │
│     └── assets/            前端静态资源  │
│         ├── app.js         核心逻辑      │
│         └── style.css      样式表        │
└────────────────────────────────────────┘
```

## 数据库表结构

| 表名 | 说明 | 关键字段 |
|------|------|----------|
| `fridges` | 冰箱列表 | name, location, description |
| `categories` | 物品分类（预置8类） | name, icon, sort_order |
| `items` | 物品详情 | name, category_id, fridge_id, quantity, unit, production_date, shelf_life_value, shelf_life_unit, storage_type, added_date |
| `transfer_logs` | 转移记录 | item_id, from_fridge_id, to_fridge_id, quantity, transfer_time |

## 快速部署

### 前提条件

- LNMP 环境已就绪（Nginx + PHP 7.4+ + MySQL 5.7+）
- PHP 已启用 PDO MySQL 扩展

### 方式一：Web 安装向导（推荐）

1. 将整个项目文件夹放到 Nginx 的 web 根目录下（如 `/var/www/fridge-manager/`）
2. 浏览器访问 `http://你的服务器IP/fridge-manager/install.php`
3. 填写数据库连接信息 → 点击「开始安装」
4. 安装完成后自动生成 `install.lock`，点击「进入系统」

```
访问 install.php
  ├─ install.lock 存在 → "系统已安装"，拒绝运行
  └─ 配置表单 → 测试连接 → 创建/切换数据库
       ├─ 无旧数据 → 建表 → 写 config.php → 创建 lock → 完成
       └─ 有旧数据 → 冲突确认
            ├─ 保留数据：仅更新 config.php
            ├─ 覆盖安装：DROP → 重建
            └─ 取消：返回修改配置
```

> 如需重新安装，删除项目目录下的 `install.lock` 文件后重新访问 `install.php`。

### 方式二：手动部署

**Step 1：创建数据库**

在 phpMyAdmin 中新建数据库 `fridge_manager`，导入 `init.sql` 完成建表和预置数据。

**Step 2：配置数据库连接**

编辑 `api/config.php`，将占位符替换为实际值：

```php
define('DB_HOST', 'localhost');
define('DB_PORT', '3306');
define('DB_NAME', 'fridge_manager');
define('DB_USER', '你的数据库用户名');
define('DB_PASS', '你的数据库密码');
```

**Step 3：部署应用文件**

将整个项目文件夹放到 Nginx 的 web 根目录，配置 Nginx 站点指向该目录。

### 升级

浏览器访问 `http://你的服务器IP/fridge-manager/upgrade.php`，自动检测并执行数据库结构升级。也可手动导入 `migrate_v3.sql`。

### 访问

应用入口：`http://你的服务器IP/fridge-manager/`

## 过期时间推算规则

系统根据以下优先级推算物品过期时间：

| 生产日期 | 保质期 | 推算方式 | 说明 |
|:---:|:---:|------|------|
| ✓ | ✓ | 生产日期 + 保质期 | 直接计算 |
| ✗ | ✓ | 添加日期 + 保质期÷2 | 对半折算（保守估算） |
| ✓ / ✗ | ✗ | 添加日期 + 默认天数 | 冷藏 5 天 / 冷冻 90 天 |

- 无生产日期且无保质期的物品，存放超过 3 天会在汇总看板中预警提醒。
- 临期定义为距过期时间 ≤ 3 天。

## 预置分类

🥬 蔬菜 · 🥩 肉类 · 🍎 水果 · 🥛 乳制品 · 🥤 饮料 · 🧂 调料 · 🍜 速食 · 📦 其他

## API 接口

所有接口返回 JSON 格式数据。

| 方法 | 路径 | 说明 |
|------|------|------|
| GET | `api/fridge.php` | 冰箱列表 |
| POST | `api/fridge.php` | 新增冰箱 |
| PUT | `api/fridge.php` | 更新冰箱 |
| DELETE | `api/fridge.php?id=` | 删除冰箱 |
| GET | `api/items.php?fridge_id=&category_id=&search=&limit=&expiring=&sort=&page=&per_page=` | 物品列表（支持分页和排序） |
| POST | `api/items.php` | 新增物品 |
| PUT | `api/items.php` | 更新物品 |
| DELETE | `api/items.php?id=` | 删除物品 |
| GET | `api/transfer.php` | 转移记录 |
| POST | `api/transfer.php` | 执行转移 |
| GET | `api/categories.php` | 分类列表 |
| GET | `api/dashboard.php` | 汇总看板数据 |
| GET | `api/export_md.php` | 导出 Markdown 格式数据（文件下载）。`?template=1` 导出空白模板 |
| GET | `api/export_csv.php` | 导出 CSV 格式数据（文件下载，兼容 Excel） |
| GET | `api/export_csv.php?template=1` | 导出空白 CSV 模板（仅表头） |
| GET | `api/export_sql.php` | 导出完整 SQL 数据（文件下载） |
| POST | `api/import_md.php` | 导入 Markdown 格式数据 |
| POST | `api/import_csv.php` | 导入 CSV 格式数据 |
| POST | `api/import_sql.php` | 导入 SQL 数据 |
| POST | `api/consume.php` | 快速取用（减量/归零删除） |
