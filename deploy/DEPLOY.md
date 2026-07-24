# BeatMail 虚拟主机部署说明

支持 **Nginx 虚拟主机** 与 **Apache 虚拟主机 / 共享主机**。

> **提示**: 本项目不需要数据库，只需配置好 Web 服务及 PHP 即可运行。

## 1. 上传什么？

请把 **`web/` 目录里面的所有内容** 上传到你的网站根目录（**注意：不是上传整个仓库，只上传 `web/` 里面的文件**）。

上传后，你的服务器目录结构应当类似如下：

```text
/你的网站根目录/
├── index.php
├── .htaccess          ← Apache 用户所需
├── .user.ini          ← 部分共享主机的 PHP 配置文件
├── assets/
│   ├── css/style.css
│   └── js/app.js
└── api/
    ├── index.php
    ├── InstantMail.php
    └── .htaccess
```

**`deploy/` 目录包含的是示例配置和说明文档，不需要上传到网站根目录。**

## 2. 环境要求

| 项 | 要求 |
|----|------|
| PHP版本 | 7.4+（推荐 8.0 - 8.3） |
| PHP扩展 | 必须开启 **`curl`**、`json`、`openssl`（建议开启 `mbstring`） |
| 网络要求 | 服务器**必须能够访问外网 HTTPS**（因为需要调用上游的邮箱 API） |
| 运行超时 | PHP `max_execution_time` 建议 ≥ 30 秒（推荐 60 秒） |

> **排错提示**：如果创建邮箱一直失败（显示 Error 或 502），绝大多数情况是因为服务器**无法出网**（被墙）或者**没有安装 curl 扩展**。

---

## 3. 面板配置指南 (1Panel / 宝塔面板)

如果你使用的是 1Panel 或宝塔等可视化面板，部署极其简单：

1. **创建网站**：新建一个纯 PHP 站点，绑定你的域名，PHP 选 7.4 以上。
2. **设置根目录**：将站点的根目录指向你刚才上传的那些文件（即确保站点的入口是上传好的 `index.php`）。
3. **设置伪静态 (Nginx)**：
   如果是 Nginx 环境，面板默认是不支持 Apache 的 `.htaccess` 的，所以你需要在站点的【伪静态】设置里，填入以下规则以保障安全及路由正常：

```nginx
# 禁止访问隐藏文件、核心库、自测工具
location ~ /\. {
    deny all;
}
location ~* /api/InstantMail\.php$ {
    deny all;
}
location ^~ /tools/ {
    deny all;
}

# 静态资源缓存
location ~* \.(css|js|png|jpg|jpeg|gif|ico|svg|woff2?)$ {
    expires 7d;
    access_log off;
    try_files $uri =404;
}

# 前台：真实文件优先，否则 index.php
location / {
    try_files $uri $uri/ /index.php?$query_string;
}

# API：保证 /api 与 /api/ 都进 index.php
location = /api {
    return 301 /api/;
}
location /api/ {
    try_files $uri /api/index.php?$query_string;
}
```

---

## 4. 自管服务器配置 (独立配置)

### 4.1 Apache

如果你使用的是 Apache，且开启了 `AllowOverride All`，那么自带的 `.htaccess` 文件就会生效，**一般无需修改 Apache 配置文件**。

如果你的站点放在子目录（例如 `https://domain.com/mail/`）：
请编辑根目录的 `.htaccess`，取消注释并修改为你的路径：
```apache
RewriteBase /mail/
```

### 4.2 Nginx

参考自带的 [`nginx.conf.example`](./nginx.conf.example)。
你需要将配置合并到你的服务器中（如 `/etc/nginx/conf.d/mail.conf`）。

核心配置：
1. `root /www/wwwroot/你的目录;`
2. 引入第 3 节中的安全与伪静态 `location`。
3. 配置 PHP 的 `fastcgi_pass`。

配置完成后使用 `sudo nginx -t && sudo nginx -s reload` 重载生效。

---

## 5. 部署后自检

部署完成后，可以通过以下方式验证部署是否成功（替换成你的实际域名）：

1. **健康检查**：浏览器访问 `https://你的域名/api/index.php?action=health`。
   - 期望返回：`{"ok":true,"service":"BeatMail",...}`
2. **前台测试**：打开首页，点击大大的 **PLAY** 按钮，若成功生成邮箱并能显示卡片，则大功告成！

---

## 6. 常见问题 (FAQ)

| 现象 | 处理方法 |
|------|------|
| 打开首页直接下载了 PHP 文件，或页面是代码 | 服务器未启用 PHP，或 Nginx 没有正确配置解析 `location ~ \.php$` |
| 页面正常，但点击生成提示 "接口返回错误 / API 404" | 确认 Nginx 是否添加了那段关于 `/api/` 的**伪静态规则** |
| 接口提示 502 / curl error | 检查你的服务器是否**能访问外网**；检查 PHP 是否开启了 **curl 扩展** |
| 生成成功但收不到信件 | 正常情况下邮件抵达有延迟，需等待对方发出并点击界面上的刷新。 |

