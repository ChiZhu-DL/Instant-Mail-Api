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
- 🛠 **纯净架构**：无需数据库，无三方依赖，纯原生 PHP + JS 实现

## 📁 目录结构

```
beatmail/
├── web/                     # 网站核心文件（上传至服务器的根目录）
│   ├── index.php            # 前端主页面
│   ├── assets/              # 静态资源 (CSS/JS)
│   └── api/                 # 后端接口
│       ├── index.php        # API 路由
│       └── InstantMail.php  # 核心通信组件
└── deploy/                  # 部署参考配置
    ├── DEPLOY.md            # 部署说明
    ├── nginx.conf.example   # Nginx 伪静态参考
    └── apache-vhost.conf.example
```

## 🛠️ 环境要求

- **PHP**: 7.4+ (推荐 8.0+)
- **扩展**: curl, json, openssl (建议 mbstring)
- **网络**: 服务器必须能访问外部网络 (需调用上游邮箱 API)

## 🚀 快速部署 (服务器/面板)

> 详细的面板配置（宝塔/1Panel）和 Apache/Nginx 规则，请查阅 [部署文档 (DEPLOY.md)](./deploy/DEPLOY.md)

1. 将 web/ 目录下的所有文件上传至你的网站根目录。
2. 确保已开启 **curl** 和 **openssl** 扩展。
3. **Nginx 用户**：请确保添加了伪静态和安全规则，避免暴露敏感文件（详见 deploy/nginx.conf.example）。
4. **Apache 用户**：确保开启了 AllowOverride All 以使 .htaccess 规则生效。

## 💻 本地开发调试

你可以使用 PHP 内置服务器快速启动：

```bash
cd web
php -S 127.0.0.1:8080
```

然后浏览器访问 http://127.0.0.1:8080 即可体验。

## 🔌 API 接口概览

前端与后端通过轻量级 JSON API 交互，统一入口为 /api/index.php?action=...

- GET action=health: 服务健康检查
- GET action=domains: 获取可用域名列表
- POST action=create: 创建新的临时邮箱
- GET action=messages: 获取收件箱列表
- GET action=message: 获取单封邮件完整详情

## ⚠️ 免责声明

本项目仅供学习与技术交流。邮件收发依赖于公开的第三方上游服务接口（由应用逆向得出），如果上游服务更新导致接口失效，需自行更新 InstantMail.php 中的密钥与端点。**请勿将临时邮箱用于注册重要账号。**

