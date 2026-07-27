# BeatMail - 开源临时邮箱系统

基于 Instant Mail API 的 PHP 全栈临时邮箱站点。
视觉风格采用 **BeatCraft** 游戏化设计：像素风粗线条、鲜明色彩（天蓝/亮黄/珊瑚粉/草绿）、圆角字体、居中画布。

## ⚠️ 项目属性声明

> 本项目的核心代码结构、功能逻辑、文档内容均由AI辅助生成，经人工校验、调整后开源发布。
> 生成过程中使用了大语言模型辅助完成代码编写、框架搭建与文档输出工作。

## 🌟 特性

- 🎮 **游戏化 UI**：BeatCraft 风格界面，提供愉悦的视觉体验
- 🚀 **四套邮箱引擎**：支持标准域、高级域、Gmail、随机生成
- 📱 **响应式设计**：PC端三栏布局，移动端完美适配防缩放
- 🔒 **安全渲染**：邮件内容在 sandbox iframe 中隔离渲染
- ⚡ **无缝自动刷新**：后台静默拉取新邮件（默认 8s）
- 🔑 **API 鉴权 + 限流**：本站页面免密，外部调用需密钥，按 IP 限频
- 🗂 **上游结果缓存**：域名列表落盘缓存，避免每次请求都打上游
- 🛠 **纯净架构**：无需数据库，无三方依赖，纯原生 PHP + JS 实现

## 📁 目录结构

```
web/                          # 网站根目录（整个目录上传到服务器）
├── index.php                 # 前端主页面
├── .htaccess                 # Apache 规则（nginx 不读此文件）
├── assets/                   # 静态资源 (CSS/JS)
├── api-guide/                # 开放 API 使用文档页
├── api/                      # 后端接口
│   ├── index.php             # API 路由（鉴权 + 限流入口）
│   ├── InstantMail.php       # 核心通信组件
│   ├── config.php            # 密钥 / 限流 / 缓存配置 ← 部署后必改
│   ├── auth.php              # 同源判断与 Bearer 校验
│   ├── cache.php             # 文件缓存与限流器
│   └── cache/                # 运行时缓存目录（需可写）
└── deploy/
    └── nginx.conf.example    # Nginx 规则参考
```

## 🛠️ 环境要求

- **PHP**: 7.4+ (推荐 8.0+)
- **扩展**: curl, json, openssl (建议 mbstring)
- **网络**: 服务器必须能访问外部网络 (需调用上游邮箱 API)
- **权限**: `api/cache/` 需对 PHP 可写（否则缓存与限流自动降级，站点仍可用）

## 🚀 快速部署

1. 将 `web/` 目录下的所有文件上传至你的网站根目录。
2. 确保已开启 **curl** 和 **openssl** 扩展。
3. **修改 `api/config.php` 里的 `API_KEY`**（仓库里的是示例值，务必替换）。
4. 确保 `api/cache/` 目录可写（权限 755，属主为 PHP 运行用户）。
5. 配置 Web 服务器规则（**可选**，PHP 层已自带防护）：
   - 项目已在 PHP 层面自我保护：`config.php` / `auth.php` / `cache.php` /
     `InstantMail.php` 被直接访问时返回 404，缓存文件带 `<?php exit;` 守卫前缀。
     **因此虚拟主机改不了服务器配置也是安全的。**
   - 若你有权限改配置，加上 [`deploy/nginx.conf.example`](./deploy/nginx.conf.example)
     的规则可以多一层纵深防御（Apache 用户则确保 `AllowOverride All`）。
6. 访问 `/api/index.php?action=health` 自检，确认：
   - `cache_writable` 为 `true`
   - `auth_enabled` 为 `true`
   - `curl_path_as_is` 为 `false` 属正常（老 curl 缺该常量，已做降级处理）

## 🔒 鉴权与限流

默认策略（在 `api/config.php` 调整）：

| 来源 | 是否需要密钥 |
|------|-------------|
| 本站页面自身的请求 | 否（靠 `Sec-Fetch-Site` / Origin 判断） |
| 外部脚本 / curl | 是，需 `Authorization: Bearer <API_KEY>` |
| `action=health` | 否（始终公开，方便探活） |

限流按 IP 固定窗口：一般接口 60 次/分钟，创建邮箱 10 次/分钟，
超限返回 `429` 并带 `Retry-After`。

> 同源判断依赖请求头，而请求头可被伪造，因此它只挡「随手 curl 白嫖」，
> 不是强安全边界。真正兜底的是限流。

## 💻 本地开发调试

```bash
cd web
php -S 127.0.0.1:8080
```

然后浏览器访问 http://127.0.0.1:8080 即可体验。

## 🔌 API 接口概览

前端与后端通过轻量级 JSON API 交互，统一入口为 `/api/index.php?action=...`
详细示例见站内 `/api-guide/` 页面。

- GET `action=health`: 服务健康检查（公开）
- GET `action=domains`: 获取可用域名列表
- POST `action=create`: 创建新的临时邮箱
- GET `action=messages`: 获取收件箱列表
- GET `action=message`: 获取单封邮件完整详情

## ⚠️ 免责声明

本项目仅供学习与技术交流。邮件收发依赖于公开的第三方上游服务接口（由应用逆向得出），如果上游服务更新导致接口失效，需自行更新 InstantMail.php 中的密钥与端点。**请勿将临时邮箱用于注册重要账号。**

