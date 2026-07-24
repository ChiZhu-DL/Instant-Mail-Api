# 梦奈宝塔（Nginx）+ InfinityFree（Apache）部署指南

## 一、邮箱 `@` 铁律（两台主机通用，后续开放 API 也必须遵守）

| 层级 | 正确形态 | 错误形态 |
|------|----------|----------|
| JSON 响应 / 数据库 / 前端存储 | `user@domain.com` | `user%40domain.com` |
| 加密前 payload `{"email":...}` | `user@domain.com` | `user%40domain.com` |
| HTTP query 传输（自动） | `email=user%40domain.com` | 手动编两次 → `%2540` |
| 上游 URL path（仅服务端内部一次） | `.../email/user%40domain.com/...` | 业务层就存 `%40` |

**本站已做：**

1. 所有入口 `normalize_email_address()`：把 `%40` / `%2540` 还原成字面量 `@`
2. 创建 / 收信 / 详情：加密与 JSON **只用字面量 `@`**
3. 仅 `encode_email_path()` 在拼上游 path 时编码 **一次**
4. API 响应的 `mailbox` / `email` **永远返回字面量 `@`**

调用示例（后续做开放 API 时照此写）：

```http
# ✓ 推荐：JSON body 字面量
POST /api/index.php?action=create
{"service":"temp-mail-io","domain":"bltiwd.com","name":"demo"}

# ✓ GET：query 里 @ 被客户端编成 %40 是正常的（PHP 会解码）
GET /api/index.php?action=messages&email=demo%40bltiwd.com&token=TOKEN

# ✗ 不要：先把邮箱改成 demo%40bltiwd.com 再 encodeURIComponent
#   → demo%2540bltiwd.com → 双重编码，历史上会导致收不到信
#   （现已在服务端纠正，但调用方仍不要这么写）
```

自测（部署后可临时访问，完事删除）：

```
https://你的域名/tools/email_selftest.php
```

应输出 `ALL PASSED`。

---

## 二、梦奈宝塔（Nginx 虚拟主机）

### 1. 上传

把本地 `web/` **目录内的文件** 上传到网站根目录，例如：

```
/www/wwwroot/你的域名/
├── index.php
├── .htaccess          （Nginx 会忽略，无妨）
├── .user.ini
├── assets/
└── api/
```

### 2. 面板设置

1. **网站** → 添加站点 → PHP 选 **7.4 / 8.0 / 8.1+**（有 curl）
2. **网站目录** = 上面的根目录  
3. **伪静态**：可留空（我们用 `index.php?action=`，不依赖复杂 rewrite）  
4. **配置文件** → 在 `server { ... }` 内确认/合并以下要点：

```nginx
root /www/wwwroot/你的域名;
index index.php index.html;

# 禁止核心库与自测
location ~* /api/InstantMail\.php$ { deny all; }
location ^~ /tools/ { deny all; }   # 自测完建议开启

location / {
    try_files $uri $uri/ /index.php?$query_string;
}

location /api/ {
    try_files $uri /api/index.php?$query_string;
}

location ~ \.php$ {
    try_files $uri =404;
    # 梦奈/宝塔常见（按面板 PHP 版本改 sock 名）:
    fastcgi_pass unix:/tmp/php-cgi-80.sock;
    # 或: unix:/www/server/php/80/var/run/php-fpm.sock
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    fastcgi_read_timeout 60s;
    fastcgi_send_timeout 60s;
}
```

完整样例见：`deploy/nginx.conf.example`

5. **软件商店** → PHP → 安装扩展：**curl**、**openssl**、**json**  
6. 保存 → **重载 Nginx**

### 3. 自检

```text
https://你的域名/api/index.php?action=health
https://你的域名/api/index.php?action=domains
https://你的域名/
```

health 里应有 `"curl":true`。若 create 一直失败，多半是主机 **出网防火墙** 拦了上游 HTTPS。

### 4. 注意事项（宝塔）

- 开启 **防跨站** 时，确保站点目录在允许范围内  
- 若用了 CDN，回源要支持 POST + 较长超时  
- HTTPS 在面板「SSL」一键申请即可  

---

## 三、InfinityFree（Apache 共享主机）

面板：https://dash.infinityfree.com  

### 1. 已知限制（务必读）

| 项 | 说明 |
|----|------|
| 出站请求 | 部分套餐对 `curl` 外连有限制或慢；临时邮箱 **强依赖** 服务器访问外网 API |
| PHP | 控制台选 PHP 8.x，确认 **curl** 启用 |
| 域名 | 免费域名可能有广告脚本注入；建议自定义域名 |
| 路径 | `htdocs/` 为网站根 |

若 `action=health` 正常但 `create` 报 cURL error / 超时，优先怀疑 **InfinityFree 出网策略**，可换能出网的 VPS/梦奈主机做 API 端。

### 2. 上传

使用文件管理器或 FTP，上传到 **`htdocs/`**（或绑定域名后的根）：

```
htdocs/
├── index.php
├── .htaccess          ← 必须上传，Apache 依赖它
├── .user.ini
├── assets/
└── api/
    ├── index.php
    ├── InstantMail.php
    └── .htaccess
```

### 3. 面板设置

1. **Accounts** → 进入账号 → **Control Panel**  
2. **Subdomains / Domains** 指到该 `htdocs`  
3. **PHP Configuration**（或 Softaculous 旁设置）：PHP **8.1+**，启用 curl  
4. 不需要改虚拟主机 conf：共享主机用根目录 **`.htaccess`** 即可  

### 4. `.htaccess` 已内置

`web/.htaccess` 包含：

- `DirectoryIndex index.php`  
- 禁止直链 `InstantMail.php`  
- rewrite 兜底  
- 安全头（主机允许时）  

子目录部署时（例如 `https://xxx.infinityfreeapp.com/mail/`），编辑 `.htaccess`：

```apache
RewriteBase /mail/
```

### 5. 自检

```text
https://你的域名/api/index.php?action=health
```

看到 `ok:true` 且 `curl:true` 后，再试创建邮箱。

### 6. InfinityFree 常见问题

| 现象 | 处理 |
|------|------|
| 403 / 空白 | 查错误页；确认上传到 `htdocs` 不是上级 |
| curl error 7/28 | 出网被拒或超时 → 换主机或联系客服放行 |
| 间歇 500 | 免费机资源限制；降自动刷新频率 |
| 强制 HTTPS | 面板 SSL 或 `.htaccess` 跳转 |

---

## 四、两台主机怎么分工（推荐）

若 InfinityFree 出网不稳、梦奈宝塔正常：

```
用户浏览器
   → InfinityFree（只放静态前端，可选）
   → 或全部放在梦奈宝塔（推荐：前端+API 同域，无 CORS 麻烦）
```

**推荐：整站（`web/`）只部署在梦奈宝塔 Nginx**；InfinityFree 作备用或仅静态演示。  
后续「开放 PHP API」时，把 API 固定在能稳定出网的那台，并在文档中写明：

- 响应邮箱 **永远** `user@domain.com`  
- 调用方 **不要** 把 `%40` 当业务数据存储  

---

## 五、部署检查清单

- [ ] 上传的是 `web/` **内容**，不是整个 `mail` 仓库  
- [ ] PHP ≥ 7.4，扩展 **curl** 已开  
- [ ] `action=health` 返回 ok  
- [ ] `tools/email_selftest.php` 显示 ALL PASSED（然后删或 deny）  
- [ ] 创建邮箱后，页面显示含 **`@`** 的地址（不是 `%40`）  
- [ ] 复制邮箱粘贴到别处仍是 `user@domain.com`  
- [ ] 收件箱可刷新（上游可达）  
