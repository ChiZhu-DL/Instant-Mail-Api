# Instant Mail API & BeatMail

临时邮箱全套解决方案。包含两个部分：

- **Instant Mail API** — 从 Instant Mail App（React Native）反编译还原的 Python API 客户端，支持 4 种邮箱服务 具体查看[instant_mail_api.py](instant_mail_api.py)
- **BeatMail** — 基于该 API 构建的 PHP 全栈临时邮箱 Web 站点，游戏化 UI，无需数据库  **具体查看**[web](./web)。

## 项目结构

```
instant-mail-api/
├── instant_mail_api.py         # Python API 客户端（无第三方依赖）
├── INSTANT_MAIL_API_USAGE.md   # API 完整文档
├── web/                        # BeatMail PHP Web 站点
│   ├── index.php               # 前端主页
│   ├── assets/                 # CSS / JS 静态资源
│   └── api/                    # 后端 API 路由
└── deploy/                     # 部署配置参考
    ├── DEPLOY.md               # 部署说明
    ├── nginx.conf.example      # Nginx 伪静态规则
    └── apache-vhost.conf.example
```

---

## Python API 客户端

纯标准库实现，无需安装任何第三方依赖。

```powershell
# 查看所有可用域名
python instant_mail_api.py list-domains

# 创建临时邮箱（标准域）
python instant_mail_api.py --service temp-mail-io create --email test@bltiwd.com

# 创建临时邮箱（高级域）
python instant_mail_api.py --service hd-premium create --email test@tempmail.edu.pl

# 创建 Gmail 别名
python instant_mail_api.py --service hd-gmail create --email random@gmail.com

# 读取邮件（通用接口，适用于所有邮箱类型）
python instant_mail_api.py messages test@bltiwd.com --token TOKEN

# 读取单封邮件详情
python instant_mail_api.py message MESSAGE_ID --email test@bltiwd.com --token TOKEN
```

支持 4 种服务：

| 服务 | 类型 | 域名数 | 加密 |
|------|------|--------|------|
| temp-mail-io | 标准域名 | ~7 个 | 无 |
| hd-premium | 高级域名 | 9 个 | ✅ |
| hd-gmail | Gmail 点别名 | 4 个入口 | ✅ |
| hd-random | 随机域名 | 不定 | 无 |

详细文档见 [INSTANT_MAIL_API_USAGE.md](./INSTANT_MAIL_API_USAGE.md)。

---

## BeatMail Web 站点

游戏化 UI 的临时邮箱网站，BeatCraft 像素风设计。

### 特性

- 游戏化界面，愉悦的视觉体验
- 4 套邮箱引擎（标准域 / 高级域 / Gmail / 随机）
- 响应式设计，PC + 移动端适配
- 邮件内容 sandbox iframe 隔离渲染
- 后台静默轮询新邮件（默认 8s）
- 无需数据库，无需第三方依赖

### 快速部署

详细部署指南见
[web/README.md](./web/README.md)。
[deploy/DEPLOY.md](./deploy/DEPLOY.md)。

```bash
# 1. 将 web/ 下所有文件上传到网站根目录
# 2. PHP 7.4+，开启 curl / openssl 扩展
# 3. Nginx 用户需配置伪静态规则（见 deploy/nginx.conf.example）
# 4. Apache 用户需开启 AllowOverride All

# 本地开发
cd web
php -S 127.0.0.1:8080
```

### 环境要求

| 项 | 要求 |
|----|------|
| PHP | 7.4+（推荐 8.0 - 8.3） |
| PHP 扩展 | curl, json, openssl（建议 mbstring） |
| 网络 | 服务器必须能访问外网 HTTPS |

---

## 免责声明

本项目仅供学习与技术交流。邮件收发依赖于公开的第三方上游服务接口（通过应用逆向得出），如果上游服务更新导致接口失效，需自行更新密钥与端点。请勿将临时邮箱用于注册重要账号。
