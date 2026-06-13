# Instant Mail API 管理系统

## 本项目是抓取的 Instant Mail软件  com.temporary.email.prp

基于 PHP 的完整的临时邮箱 API 管理系统，支持邮箱创建、邮件获取、域名管理、批量操作等功能。

## 项目特性

### 核心功能

- **邮箱管理**：创建临时邮箱，支持指定域名和自定义用户名
- **邮件获取**：查看指定邮箱的收件列表
- **域名管理**：管理所有可用域名，按类别筛选
- **批量操作**：批量创建多个临时邮箱
- **邮箱删除**：使用令牌删除指定邮箱
- **邮箱统计**：查看邮箱统计信息
- **API 状态**：实时查看各 API 服务状态
- **缓存机制**：支持域名数据缓存，提高响应速度

### 技术特性

- **完整的前后端分离**：前端 HTML/CSS/JS，后端 PHP
- **响应式设计**：支持桌面端和移动端
- **模块化架构**：清晰的代码结构，易于维护
- **错误处理**：完整的异常处理和用户反馈
- **现代化 UI**：美观的界面设计，流畅的交互体验

## 项目结构

```
temp-mail-api/
├── index.php              # 主页面（前端 UI）
├── api.php               # 后端 API 处理
├── InstantMailAPI.php    # 核心 API 类
├── config.php           # 配置文件
├── README.md            # 项目文档
├── css/
│   └── style.css        # 样式文件
├── js/
│   └── app.js         # 前端交互逻辑
└── cache/               # 缓存目录（自动创建）
```

## 快速开始

### 环境要求

- PHP 7.0 或更高版本
- 支持 cURL 扩展
- Web 服务器（Apache / Nginx）

### 安装步骤

1. **上传文件**
   将整个 `temp-mail-api` 目录上传到您的 Web 服务器

2. **设置权限**
   确保 cache 目录可写（777 或 755）

3. **访问系统**
   在浏览器中访问 `http://your-domain/temp-mail-api/index.php

4. **完成**
   系统会自动初始化，您可以立即使用

### 本地测试

使用 PHP 内置服务器快速测试：

```bash
cd temp-mail-api
php -S localhost:8000
```

然后在浏览器访问：http://localhost:8000

## 使用说明

### 1. 控制台

查看系统状态、已创建邮箱数量、支持域名数量等概览信息

### 2. 域名管理

查看所有可用的域名列表，按类别筛选：
- **Public 域名**：稳定的公开域名，推荐使用
- **HD 域名**：高级域名，需要解码支持
- **热门类域名**：类似 Gmail 的热门域名
- **谷歌变体**：Google 邮箱变体

### 3. 创建邮箱

- 选择域名（或随机选择）
- 可选自定义用户名
- 点击"创建邮箱"按钮
- 获取邮箱地址和删除令牌

### 4. 邮件查看

- 输入邮箱地址
- 点击"获取邮件"查看收件列表
- 点击邮件查看详细内容

### 5. 批量操作

- 设置要创建的邮箱数量（1-50）
- 可选指定统一域名
- 批量创建多个邮箱

## API 接口说明

### 获取域名列表

```
GET api.php?action=get_domains
```

**参数**：
- `category`（可选）：按类别筛选
- `refresh`（可选）：是否强制刷新缓存

**响应**：
```json
{
  "success": true,
  "total": 30,
  "data": [
    {
      "domain": "bltiwd.com",
      "category": "public",
      "type": "stable",
      "available": true
    }
  ]
}
```

### 创建邮箱

```
POST api.php?action=create_email
```

**参数**：
- `domain`（可选）：指定域名
- `name`（可选）：自定义用户名

**响应**：
```json
{
  "success": true,
  "email": "example@bltiwd.com",
  "token": "your-token-here",
  "domain": "bltiwd.com",
  "service": "public"
}
```

### 获取邮件

```
GET api.php?action=get_messages&email=xxx@bltiwd.com
```

**参数**：
- `email`（必填）：邮箱地址

**响应**：
```json
{
  "success": true,
  "email": "xxx@bltiwd.com",
  "total": 5,
  "messages": [...]
}
```

### 删除邮箱

```
POST api.php?action=delete_email
```

**参数**：
- `email`（必填）：邮箱地址
- `token`（必填）：删除令牌

### 批量创建邮箱

```
POST api.php?action=batch_create
```

**参数**：
- `count`（必填）：创建数量（1-50）
- `domain`（可选）：指定域名

### 快速创建邮箱

```
POST api.php?action=quick_create
```

简化版的创建邮箱接口，无需参数

### 获取邮箱统计

```
GET api.php?action=get_stats&email=xxx@bltiwd.com
```

### 获取 API 状态

```
GET api.php?action=get_status
```

### 获取域名分类

```
GET api.php?action=get_categories
```

### 清除缓存

```
POST api.php?action=clear_cache
```

## 技术实现

### 后端架构

- **核心类**：`InstantMailAPI` - 封装所有 API 操作
- **API 处理**：`api.php` - 处理前端请求
- **配置管理**：`config.php` - 系统配置和工具函数
- **缓存机制**：文件缓存域名数据

### 前端架构

- **HTML**：语义化结构
- **CSS**：现代化设计，支持响应式
- **JavaScript**：模块化交互逻辑，原生 JS 实现

### UI 设计理念

- 简洁明了的操作流程
- 清晰的视觉层级
- 丰富的交互反馈
- 流畅的动画效果

## 域名说明

系统支持多种域名类型：

### Public 域名（推荐）
- 稳定、公开的邮箱域名
- API 响应稳定
- 推荐日常使用

### HD 域名
- 高级域名，需要解码支持
- API 可访问
- 部分功能可能受限

### 热门类域名
- 类似 Gmail、Outlook 的热门域名
- 实验性功能
- 可能需要特殊处理

### 谷歌变体
- Google 邮箱变体域名
- 实验性功能
- 数据解码可能需要额外处理

## 常见问题

### Q: 为什么创建邮箱失败？
A: 可能的原因：
1. API 服务暂时不可用
2. 网络连接问题
3. 指定的域名不支持创建

### Q: 为什么获取不到邮件？
A: 可能的原因：
1. 邮箱尚未收到邮件
2. API 响应延迟
3. 邮箱地址格式错误

### Q: 如何清除缓存？
A: 在侧边栏点击"清除缓存"按钮，或调用 `api.php?action=clear_cache`

### Q: 支持哪些浏览器？
A: 推荐使用现代浏览器，包括 Chrome、Firefox、Edge、Safari 等

## 安全注意事项

1. 本系统仅供学习和测试使用
2. 请勿用于非法用途
3. 临时邮箱不保证隐私安全
4. 请勿在重要账户中使用
5. 定期检查系统日志，确保正常运行

## 技术支持

如有问题或建议，欢迎反馈。
# [github]（https://github.com/ChiZhu-DL/Instant-Mail-Api）
## 版本信息

- **版本**：2.0.0
- **更新日期**：2026-06-13
- **PHP 要求**：7.0+
- **License**：MIT

## 更新日志

### v2.0.0 (2026-06-13)
- 全新的 API 管理系统
- 完整的前后端分离架构
- 支持域名管理、邮箱创建、邮件获取
- 批量操作功能
- 现代化 UI 设计
- 完整的 API 文档

### v1.0.0
- 初始版本发布
- 基础 SDK 功能
