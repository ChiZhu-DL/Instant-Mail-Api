# Instant Mail API 完整文档

## 仓库 https://github.com/ChiZhu-DL/Instant-Mail-Api

## 概述

从 Instant Mail (React Native/Hermes) 应用反编译还原的临时邮箱 API 接口。纯 Python 实现，无需安装第三方依赖。

## 核心发现

**创建邮箱**需要按服务类型使用不同接口，但**读取邮件**可以使用通用接口（hd-premium 接口适用于所有邮箱类型）。

```
创建: 按服务类型选择接口
  temp-mail-io → /v3/email/new
  hd-premium   → /email
  hd-gmail     → /g-mail
  hd-random    → /mailbox

读取: 通用接口 (hd-premium 接口)
  messages → /email/{token}/messages
  message  → /email/{token}/messages/{id}
```

## 快速开始

```powershell
# 查看全部域名
python instant_mail_api.py list-domains

# 创建邮箱
python instant_mail_api.py --service temp-mail-io create --email test@bltiwd.com

# 读取邮件 (通用接口，适用于所有邮箱)
python instant_mail_api.py messages test@bltiwd.com --token TOKEN

# 读取单封邮件详情
python instant_mail_api.py message MESSAGE_ID --email test@bltiwd.com --token TOKEN
```

## 四套服务详解

### 1. temp-mail-io（最简单，推荐优先使用）

- **Base URL**: `https://api.internal.temp-mail.io/api`
- **无需加密**
- **8 个固定域名**，可指定创建

| 域名 | 说明 |
|------|------|
| bltiwd.com | 固定域名（实时列表为准） |
| bwmyga.com | 固定域名 |
| ozsaip.com | 固定域名 |
| yzcalo.com | 固定域名 |
| lnovic.com | 固定域名 |
| ruutukf.com | 固定域名 |
| gmeenramy.com | 固定域名 |

> **域名会上下线**：请以 `GET /v4/domains` 或网站 `action=domains` 返回为准。  
> 例如 `wnbaldwy.com` 已从上游列表移除；再指定它创建时，上游仍返回 200，但邮箱会被**随机换到其它标准域**（不是客户端编码问题）。

**创建邮箱:**

```powershell
python instant_mail_api.py --service temp-mail-io create --email myuser@bltiwd.com
```

**接口:**

```
GET  /v4/domains                         获取域名列表
POST /v3/email/new                       创建邮箱
     Body: {"domain":"bltiwd.com","name":"test"}
GET  /v3/email/{email}/messages          拉取收件箱 (仅支持 temp-mail-io 域名)
GET  /v3/message/{message_id}            读取单封邮件
```

---

### 2. hd-premium（高级域名，需加密协议）

- **Base URL**: `https://mail-server.1timetech.com/api`
- **需要加密**: encryptKey 字符替换
- **App Key**: `b9db03078622`
- **9 个高级域名**，可指定创建

| 域名 | 说明 |
|------|------|
| tempmail.edu.pl | .edu.pl 高级域名 |
| rommiui.com | 定制域名 |
| gmail10p.com | Gmail 变体 |
| oletters.com | 定制域名 |
| oemails.com | 定制域名 |
| oegmail.com | Gmail 变体 |
| suiemail.com | 定制域名 |
| voewo.com | 定制域名 |
| yanemail.com | 定制域名 |

**创建邮箱:**

```powershell
python instant_mail_api.py --service hd-premium create --email myuser@tempmail.edu.pl
```

**接口:**

```
POST /email?params=x03e                   创建邮箱
     Header: x-app-key: b9db03078622
     Body: {"data":"加密后的 {\"email\":\"user@tempmail.edu.pl\"}"}

GET  /email/{token}/messages?params=...   拉取收件箱 (通用接口)
GET  /email/{token}/messages/{id}?params=...  读取单封邮件
```

---

### 3. hd-gmail（Gmail/Googlemail 点别名生成器）

- **Base URL**: `https://mail-server-2.1timetech.com/api`
- **需要加密**: 同 hd-premium
- **App Key**: `b9db03078622`

**入口域名:**

| 入口 | 说明 |
|------|------|
| gmail.com | 普通入口 |
| +gmail.com | Plus 别名入口 |
| googlemail.com | Googlemail 入口 |
| +googlemail.com | Plus 别名入口 |

**生成规则:**
- `random@gmail.com` → `xxx.xxxx.xxxx@gmail.com`
- `random@+gmail.com` → `xxx+随机@gmail.com`
- `random@googlemail.com` → `xxx.xxxx.xxxx@googlemail.com`

**创建邮箱:**

```powershell
python instant_mail_api.py --service hd-gmail create --email random@gmail.com
```

---

### 4. hd-random（随机邮箱）

- **Base URL**: `https://mob2.temp-mail.org`
- **无需加密**
- 域名由服务端随机分配
- **不稳定**: 可能返回 403 或 429

**创建邮箱:**

```powershell
python instant_mail_api.py --service hd-random create
```

---

## 读取邮件

**标准域 (temp-mail-io)** 与 **高级域/Gmail** 使用不同接口：

```
标准域 temp-mail-io:
  GET https://api.internal.temp-mail.io/api/v3/email/{email}/messages
  GET https://api.internal.temp-mail.io/api/v3/message/{message_id}
  ⚠ path 中必须是字面量 @，不能 %40
     ✓ /v3/email/user@lnovic.com/messages
     ✗ /v3/email/user%40lnovic.com/messages  → 400 Email not found

高级域 / Gmail（通用 HD 接口）:
  GET https://mail-server.1timetech.com/api/email/{token}/messages?params=加密(email)
  GET .../messages/{id}?params=加密(email)
```

网站 / Python 的 `messages` 命令会按域名自动分流。

```powershell
# 标准域（会走 temp-mail-io 原生接口）
python instant_mail_api.py messages test@lnovic.com --token TOKEN

# 高级域 / gmail（会走通用 HD 接口）
python instant_mail_api.py messages test@tempmail.edu.pl --token TOKEN
```

**示例:**

```powershell
# 读取 gmail 别名邮箱的邮件
python instant_mail_api.py messages "zu.leik.a.dene.k.e@gmail.com" --token 4d4a008c5b9ec9800d9bf041bb46aaeb

# 读取 temp-mail-io 邮箱的邮件
python instant_mail_api.py messages "test@bltiwd.com" --token TOKEN

# 读取邮件详情
python instant_mail_api.py message c8e94d52-63f2-4150-b537-ddacec0127ed --email "zu.leik.a.dene.k.e@gmail.com" --token 4d4a008c5b9ec9800d9bf041bb46aaeb
```

**返回示例:**

```json
{
  "status": 200,
  "data": {
    "id": "c8e94d52-63f2-4150-b537-ddacec0127ed",
    "to": "zu.leik.a.dene.k.e@gmail.com",
    "from": "3636431767@qq.com",
    "subject": "测试",
    "text": "邮件内容",
    "html": "<div>HTML 内容</div>",
    "attachments": 0,
    "files": [],
    "createdAt": 1781788676674
  }
}
```

---

## 域名汇总

### 可指定创建（稳定）: 17 个

**temp-mail-io（以实时 /v4/domains 为准，静态兜底约 7 个）:**
```
bltiwd.com, bwmyga.com, ozsaip.com, yzcalo.com,
lnovic.com, ruutukf.com, gmeenramy.com
```
`wnbaldwy.com` 等已下线域名勿再使用。

**hd-premium (9 个):**
```
tempmail.edu.pl, rommiui.com, gmail10p.com, oletters.com,
oemails.com, oegmail.com, suiemail.com, voewo.com, yanemail.com
```

### Gmail 点别名生成: 4 个入口

```
gmail.com, +gmail.com, googlemail.com, +googlemail.com
```

### 随机接口: 3 个已知域名

```
usxxoo.com, xgshare.com, xbmotor.com
```

---

## 加密协议

**encryptKey**: `ao-sq-=x-5b-4*-Bz` (从 instruction.hasm 字符串 7047 还原)

字符替换对（双向互换）:

| 对 | 替换 |
|----|------|
| ao | a ↔ o |
| sq | s ↔ q |
| =x | = ↔ x |
| 5b | 5 ↔ b |
| 4* | 4 ↔ * |
| Bz | B ↔ z |

**加密流程:**

```
JSON payload
  → compact JSON: {"email":"test@gmail.com"}
  → Base64: eyJlbWFpbCI6InRlc3RAZ21haWwuY29tIn0=
  → 字符替换: 按 encryptKey 做双向替换
  → 字符串反转: 最终密文
```

**响应解密有两种模式:**

1. **简单模式**: 只有反转（无字符替换）
2. **完整模式**: 反转 + 字符替换

脚本会自动尝试两种模式。

---

## Python API 调用

```python
from instant_mail_api import (
    TempMailIoClient,
    HdPremiumClient,
    HdGmailClient,
    UniversalMailClient,
    encrypt_payload,
    decrypt_payload,
)

# 创建邮箱
client = HdGmailClient()
result = client.create_email("random@gmail.com")
mailbox = result["data"]["mailbox"]
token = result["data"]["token"]

# 读取邮件 (通用接口，适用于所有邮箱)
reader = UniversalMailClient()
inbox = reader.get_messages(mailbox, token)
detail = reader.get_message(mailbox, token, message_id)
```

---

## HAR 抓包提取

```powershell
python instant_mail_api.py har-extract path/to/file.har
```

输出:
- `creates`: 所有创建请求
- `inboxes`: 所有 /messages 请求
- `inferred_sessions`: create → token 关联

---

## 测试结果

### 创建邮箱测试 (21/21 成功)

| 服务 | 域名 | 状态 |
|------|------|------|
| temp-mail-io | bltiwd.com | ✓ |
| temp-mail-io | wnbaldwy.com | ✓ |
| temp-mail-io | bwmyga.com | ✓ |
| temp-mail-io | ozsaip.com | ✓ |
| temp-mail-io | yzcalo.com | ✓ |
| temp-mail-io | lnovic.com | ✓ |
| temp-mail-io | ruutukf.com | ✓ |
| temp-mail-io | gmeenramy.com | ✓ |
| hd-premium | tempmail.edu.pl | ✓ |
| hd-premium | rommiui.com | ✓ |
| hd-premium | gmail10p.com | ✓ |
| hd-premium | oletters.com | ✓ |
| hd-premium | oemails.com | ✓ |
| hd-premium | oegmail.com | ✓ |
| hd-premium | suiemail.com | ✓ |
| hd-premium | voewo.com | ✓ |
| hd-premium | yanemail.com | ✓ |
| hd-gmail | gmail.com | ✓ |
| hd-gmail | +gmail.com | ✓ |
| hd-gmail | googlemail.com | ✓ |
| hd-gmail | +googlemail.com | ✓ |

### 读取邮件测试

| 邮箱 | 类型 | 状态 |
|------|------|------|
| b.uim.inh.ch.a.u5.095@gmail.com | hd-gmail | ✓ 收到 2 封邮件 |
| o2oikv1pmi@tempmail.edu.pl | hd-premium | ✓ 收到 1 封邮件 |
| zu.leik.a.dene.k.e@gmail.com | hd-gmail | ✓ 收到 1 封邮件 |

---

## 限制与说明

1. **读取邮件必须用通用接口**: temp-mail-io 的 `/v3/email/{email}/messages` 不支持 gmail 别名。使用 `messages` 命令会自动走通用接口。

2. **hd-gmail 创建响应**: 部分响应可能在 `_encrypted`/`_raw` 字段中。使用 `har-extract` 提取 token 更可靠。

3. **hd-random 不稳定**: 可能返回 403 或 429。

4. **App Key**: `b9db03078622` 从抓包还原。如果服务端更新需要重新抓包。

5. **无依赖**: 纯 Python 标准库实现。

6. **User-Agent**: 使用原应用的 `okhttp/4.12.0`，避免被识别为机器人。

---

## 文件结构

```
instant_mail_api.py          # 主脚本
INSTANT_MAIL_API_USAGE.md    # 本文档
slow_test.py                 # 慢速创建测试脚本
test_inbox.py                # 收件箱测试脚本
slow_test_results.json       # 创建测试结果
inbox_test_results.json      # 收件箱测试结果
```

---

## 常见问题

### Q: 为什么读取邮件要用通用接口？

A: temp-mail-io 的读取接口 `/v3/email/{email}/messages` 只支持 temp-mail-io 域名。对于 hd-premium 和 hd-gmail 的邮箱，需要使用通用接口 `mail-server.1timetech.com`。

### Q: 如何获取 token？

A: 创建邮箱时会返回 token。例如:
```json
{
  "status": 200,
  "data": {
    "mailbox": "test@tempmail.edu.pl",
    "token": "77c41c400380b82277bc3bb95285a207"
  }
}
```

### Q: 为什么有些创建响应在 `_encrypted` 字段中？

A: hd-gmail 的响应加密尚未 100% 还原。这种情况下，token 可能无法正确解析。建议使用 `har-extract` 从抓包文件中提取 token。

### Q: 如何避免被限流？

A: 使用随机用户名（不要重复使用同一个用户名），并在请求之间添加延迟（15秒以上）。
