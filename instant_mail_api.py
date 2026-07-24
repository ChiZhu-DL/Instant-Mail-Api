#!/usr/bin/env python3
"""
Instant Mail Python API Client
从 Instant Mail (React Native/Hermes) 反编译+HAR抓包还原的临时邮箱服务接口。

已验证接口:
  - 创建邮箱: 4种服务类型 (temp-mail-io, hd-premium, hd-gmail, hd-random)
  - 读取邮件: 通用接口 (适用于所有邮箱类型)
  - 获取域名: 各服务的域名列表

加密协议: encryptKey = "ao-sq-=x-5b-4*-Bz"
App Key:  b9db03078622

用法:
  # 创建邮箱
  python instant_mail_api.py --service temp-mail-io create --email test@bltiwd.com
  python instant_mail_api.py --service hd-premium create --email test@tempmail.edu.pl
  python instant_mail_api.py --service hd-gmail create --email random@gmail.com

  # 读取邮件 (通用接口)
  python instant_mail_api.py messages EMAIL --token TOKEN
  python instant_mail_api.py message MESSAGE_ID --email EMAIL --token TOKEN

  # 查看域名列表
  python instant_mail_api.py list-domains

  # 测试连通性
  python instant_mail_api.py test
"""

from __future__ import annotations

import argparse
import base64
import json
import re
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from typing import Any

# ──────────────────────────────────────────────
# 服务端点
# ──────────────────────────────────────────────
TEMP_MAIL_IO_BASE = "https://api.internal.temp-mail.io/api"
HD_PREMIUM_BASE = "https://mail-server.1timetech.com/api"
HD_GMAIL_BASE = "https://mail-server-2.1timetech.com/api"
HD_RANDOM_BASE = "https://mob2.temp-mail.org"
HD_APP_KEY = "b9db03078622"

# ──────────────────────────────────────────────
# 已验证域名列表
# ──────────────────────────────────────────────
# 静态兜底；以 GET /v4/domains 实时列表为准。
# 注意：已下线域名（如 wnbaldwy.com）再 create 会被上游随机改到其它域。
TEMP_MAIL_IO_DOMAINS = [
    "bltiwd.com",
    "bwmyga.com",
    "ozsaip.com",
    "yzcalo.com",
    "lnovic.com",
    "ruutukf.com",
    "gmeenramy.com",
]

HD_PREMIUM_DOMAINS = [
    "tempmail.edu.pl",
    "rommiui.com",
    "gmail10p.com",
    "oletters.com",
    "oemails.com",
    "oegmail.com",
    "suiemail.com",
    "voewo.com",
    "yanemail.com",
]

HD_GMAIL_ENTRY_DOMAINS = [
    "gmail.com",
    "+gmail.com",
    "googlemail.com",
    "+googlemail.com",
]

HD_RANDOM_SEEN_DOMAINS = [
    "usxxoo.com",
    "xgshare.com",
    "xbmotor.com",
]

# ──────────────────────────────────────────────
# 加密: encryptKey = "ao-sq-=x-5b-4*-Bz"
# ──────────────────────────────────────────────
ENCRYPT_SWAPS = ["ao", "sq", "=x", "5b", "4*", "Bz"]


# ──────────────────────────────────────────────
# 工具函数
# ──────────────────────────────────────────────
class InstantMailError(RuntimeError):
    def __init__(self, status: int, body: Any):
        super().__init__(f"Instant Mail API error {status}: {body!r}")
        self.status = status
        self.body = body


def split_email(email: str) -> tuple[str, str]:
    name, sep, domain = email.partition("@")
    if not sep or not name or not domain:
        raise ValueError(f"邮箱格式无效: {email}")
    return name, domain


def normalize_hex_token(value: Any) -> str | None:
    if not isinstance(value, str):
        return None
    cleaned = re.sub(r"[^0-9a-fA-F]", "", value)
    if len(cleaned) == 32:
        return cleaned.lower()
    return value if value else None


def _swap_chars(value: str) -> str:
    for pair in ENCRYPT_SWAPS:
        if len(pair) != 2:
            continue
        left, right = pair[0], pair[1]
        value = value.replace(left, "\0").replace(right, left).replace("\0", right)
    return value


def encrypt_payload(payload: dict[str, Any]) -> str:
    raw = json.dumps(payload, separators=(",", ":"), ensure_ascii=False)
    b64 = base64.b64encode(raw.encode("utf-8")).decode("ascii")
    return _swap_chars(b64)[::-1]


def decrypt_payload(cipher: str) -> Any:
    # 模式1: 只有反转（简单模式）
    try:
        reversed_str = cipher[::-1]
        padded = reversed_str
        while len(padded) % 4 != 0:
            padded += '='
        decoded = base64.b64decode(padded).decode("utf-8", errors="replace")
        return json.loads(decoded)
    except Exception:
        pass
    
    # 模式2: 反转 + 字符替换（完整模式）
    try:
        swapped = _swap_chars(cipher)[::-1]
        decoded = base64.b64decode(swapped).decode("utf-8", errors="replace")
        return json.loads(decoded)
    except Exception:
        pass
    
    raise ValueError("无法解密响应数据")


# ──────────────────────────────────────────────
# HTTP 基础客户端
# ──────────────────────────────────────────────
class BaseHttpClient:
    def __init__(self, base_url: str, timeout: int = 20):
        self.base_url = base_url.rstrip("/")
        self.timeout = timeout

    def _request(
        self,
        method: str,
        path: str,
        body: dict[str, Any] | None = None,
        headers: dict[str, str] | None = None,
    ) -> Any:
        req_headers = {
            "Accept": "application/json",
            "User-Agent": "okhttp/4.12.0",
        }
        if headers:
            req_headers.update(headers)

        payload = None
        if body is not None:
            payload = json.dumps(body, separators=(",", ":"), ensure_ascii=False).encode("utf-8")
            req_headers["Content-Type"] = "application/json"

        url = self.base_url + path
        req = urllib.request.Request(url, data=payload, headers=req_headers, method=method)
        try:
            with urllib.request.urlopen(req, timeout=self.timeout) as resp:
                text = resp.read().decode("utf-8", errors="replace")
                return json.loads(text) if text else {}
        except urllib.error.HTTPError as exc:
            text = exc.read().decode("utf-8", errors="replace")
            try:
                body_data = json.loads(text)
            except json.JSONDecodeError:
                body_data = text
            raise InstantMailError(exc.code, body_data) from exc


# ──────────────────────────────────────────────
# temp-mail-io: 8 个固定域名，无需加密
# ──────────────────────────────────────────────
class TempMailIoClient(BaseHttpClient):
    def __init__(self, timeout: int = 20):
        super().__init__(TEMP_MAIL_IO_BASE, timeout=timeout)

    def get_domains(self) -> list[str]:
        data = self._request("GET", "/v4/domains")
        domains = data.get("domains", []) if isinstance(data, dict) else data
        return [item.get("name", item) if isinstance(item, dict) else item for item in domains]

    def create_email(self, email: str | None = None, name: str | None = None, domain: str | None = None) -> dict[str, Any]:
        if email:
            name, domain = split_email(email)
        if not name or not domain:
            raise ValueError("temp-mail-io 需要 --email 或同时提供 --name 和 --domain")

        data = self._request("POST", "/v3/email/new", {"domain": domain, "name": name})
        mailbox = data.get("email")
        token = data.get("token")
        return {
            "status": 200,
            "data": {
                "mailbox": mailbox or f"{name}@{domain}",
                "token": token,
                "service": "temp-mail-io",
            },
            "raw": data,
        }

    def get_messages(self, email: str) -> dict[str, Any]:
        # 关键: path 必须保留字面量 @；%40 会返回 400 Email not found
        # 正确: /v3/email/user@domain.com/messages
        path = f"/v3/email/{email}/messages"
        data = self._request("GET", path)
        messages = data if isinstance(data, list) else (data.get("messages", []) if isinstance(data, dict) else [])
        return {
            "status": 200,
            "data": {"mailbox": email, "messages": messages},
            "raw": data,
        }

    def get_message(self, message_id: str) -> dict[str, Any]:
        path = f"/v3/message/{urllib.parse.quote(str(message_id), safe='')}"
        data = self._request("GET", path)
        return {"status": 200, "data": data, "raw": data}


# ──────────────────────────────────────────────
# HD 加密协议客户端
# ──────────────────────────────────────────────
class HdMailServerClient(BaseHttpClient):
    def __init__(self, base_url: str, timeout: int = 20, app_key: str = HD_APP_KEY):
        super().__init__(base_url, timeout=timeout)
        self.app_key = app_key

    def _mail_headers(self) -> dict[str, str]:
        return {
            "x-app-key": self.app_key,
            "User-Agent": "okhttp/4.12.0",
        }

    def _request_mail_api(self, method: str, path: str, body: dict[str, Any] | None = None) -> Any:
        if "?" not in path:
            path = path + "?params=" + urllib.parse.quote(encrypt_payload({}), safe="")
        wrapped = {"data": encrypt_payload(body)} if body is not None else None
        raw = self._request(method, path, wrapped, headers=self._mail_headers())
        if isinstance(raw, dict) and isinstance(raw.get("data"), str):
            try:
                return decrypt_payload(raw["data"])
            except Exception:
                return {"_encrypted": raw.get("data"), "_type": raw.get("type"), "_raw": raw}
        return raw

    def _build_params_query(self, email: str) -> str:
        encoded = encrypt_payload({"email": email})
        return "?params=" + urllib.parse.quote(encoded, safe="")


# ──────────────────────────────────────────────
# hd-premium: 9 个高级域名
# ──────────────────────────────────────────────
class HdPremiumClient(HdMailServerClient):
    def __init__(self, timeout: int = 20):
        super().__init__(HD_PREMIUM_BASE, timeout=timeout)

    def get_domains(self) -> list[str]:
        return HD_PREMIUM_DOMAINS[:]

    def create_email(self, email: str) -> dict[str, Any]:
        data = self._request_mail_api("POST", "/email?params=x03e", {"email": email})
        mailbox = data.get("email") if isinstance(data, dict) else None
        token = normalize_hex_token(data.get("id")) if isinstance(data, dict) else None
        return {
            "status": 200,
            "data": {
                "mailbox": mailbox or email,
                "token": token,
                "service": "hd-premium",
            },
            "raw": data,
        }


# ──────────────────────────────────────────────
# hd-gmail: Gmail/Googlemail 点别名生成
# ──────────────────────────────────────────────
class HdGmailClient(HdMailServerClient):
    def __init__(self, timeout: int = 20):
        super().__init__(HD_GMAIL_BASE, timeout=timeout)

    def get_domains(self) -> list[str]:
        return HD_GMAIL_ENTRY_DOMAINS[:]

    def create_email(self, email: str) -> dict[str, Any]:
        data = self._request_mail_api("POST", "/g-mail?params=x03e", {"email": email})
        mailbox = data.get("email") if isinstance(data, dict) else None
        token = normalize_hex_token(data.get("id")) if isinstance(data, dict) else None
        return {
            "status": 200,
            "data": {
                "mailbox": mailbox,
                "token": token,
                "service": "hd-gmail",
            },
            "raw": data,
        }


# ──────────────────────────────────────────────
# hd-random: 随机邮箱
# ──────────────────────────────────────────────
class HdRandomClient(BaseHttpClient):
    def __init__(self, timeout: int = 20):
        super().__init__(HD_RANDOM_BASE, timeout=timeout)

    def get_domains(self) -> list[str]:
        return HD_RANDOM_SEEN_DOMAINS[:]

    def create_email(self) -> dict[str, Any]:
        data = self._request("POST", "/mailbox")
        return {
            "status": 200,
            "data": {
                "mailbox": data.get("mailbox"),
                "token": data.get("token"),
                "service": "hd-random",
            },
            "raw": data,
        }


# ──────────────────────────────────────────────
# 通用消息读取 (适用于所有邮箱类型)
# ──────────────────────────────────────────────
class UniversalMailClient(HdMailServerClient):
    def __init__(self, timeout: int = 20):
        super().__init__(HD_PREMIUM_BASE, timeout=timeout)

    def get_messages(self, email: str, token: str) -> dict[str, Any]:
        path = f"/email/{urllib.parse.quote(str(token), safe='')}/messages" + self._build_params_query(email)
        data = self._request_mail_api("GET", path)
        messages = data if isinstance(data, list) else data.get("messages", [])
        return {"status": 200, "data": {"mailbox": email, "messages": messages}, "raw": data}

    def get_message(self, email: str, token: str, message_id: str) -> dict[str, Any]:
        path = (
            f"/email/{urllib.parse.quote(str(token), safe='')}/messages/"
            f"{urllib.parse.quote(str(message_id), safe='')}{self._build_params_query(email)}"
        )
        data = self._request_mail_api("GET", path)
        return {"status": 200, "data": data, "raw": data}


def is_temp_mail_io_domain(email: str) -> bool:
    try:
        _, domain = split_email(email)
    except ValueError:
        return False
    domain = domain.lower()
    if domain in TEMP_MAIL_IO_DOMAINS:
        return True
    try:
        live = [d.lower() for d in TempMailIoClient().get_domains()]
        return domain in live
    except Exception:
        return False


def fetch_messages(email: str, token: str, timeout: int = 20) -> dict[str, Any]:
    """标准域走 temp-mail-io 原生接口；其它走 HD 通用接口。"""
    if is_temp_mail_io_domain(email):
        result = TempMailIoClient(timeout=timeout).get_messages(email)
        result["via"] = "temp-mail-io"
        return result
    result = UniversalMailClient(timeout=timeout).get_messages(email, token)
    result["via"] = "hd-universal"
    return result


def fetch_message(email: str, token: str, message_id: str, timeout: int = 20) -> dict[str, Any]:
    if is_temp_mail_io_domain(email):
        result = TempMailIoClient(timeout=timeout).get_message(message_id)
        result["via"] = "temp-mail-io"
        return result
    result = UniversalMailClient(timeout=timeout).get_message(email, token, message_id)
    result["via"] = "hd-universal"
    return result



# ──────────────────────────────────────────────
# HAR 解析
# ──────────────────────────────────────────────
def har_extract(har_path: str) -> dict[str, Any]:
    with open(har_path, "r", encoding="utf-8", errors="replace") as handle:
        har = json.load(handle)

    entries = har.get("log", {}).get("entries", [])
    creates: list[dict[str, Any]] = []
    inboxes: list[dict[str, Any]] = []
    timeline: list[dict[str, Any]] = []

    for index, entry in enumerate(entries):
        request = entry.get("request", {})
        response = entry.get("response", {})
        url = request.get("url", "")
        method = request.get("method", "")
        post_text = request.get("postData", {}).get("text") or ""

        if "mail-server" in url and method == "POST" and post_text:
            try:
                post_data = json.loads(post_text)
                decoded = decrypt_payload(post_data["data"])
            except Exception as exc:
                decoded = {"decode_error": repr(exc), "raw": post_text}
            record = {
                "kind": "create",
                "entry": index,
                "url": url,
                "status": response.get("status"),
                "request": decoded,
            }
            creates.append(record)
            timeline.append(record)

        if "mail-server" in url and "/messages" in url:
            match = re.search(r"/email/([^/]+)/messages", url)
            token = match.group(1) if match else None
            params = urllib.parse.parse_qs(urllib.parse.urlparse(url).query).get("params", [""])[0]
            try:
                decoded = decrypt_payload(params)
            except Exception as exc:
                decoded = {"decode_error": repr(exc), "raw": params}
            record = {
                "kind": "inbox",
                "entry": index,
                "url": url,
                "status": response.get("status"),
                "token": token,
                "params": decoded,
            }
            inboxes.append(record)
            timeline.append(record)

    timeline.sort(key=lambda item: item["entry"])
    unique_inbox_by_token: dict[str, dict[str, Any]] = {}
    for inbox in inboxes:
        token = inbox.get("token")
        if token and token not in unique_inbox_by_token:
            unique_inbox_by_token[token] = inbox

    inferred: list[dict[str, Any]] = []
    create_entries = [item for item in timeline if item["kind"] == "create"]
    for idx, create in enumerate(create_entries):
        start = create["entry"]
        end = create_entries[idx + 1]["entry"] if idx + 1 < len(create_entries) else 10**9
        token = None
        inbox_match = None
        for item in timeline:
            if item["kind"] != "inbox":
                continue
            if not (start < item["entry"] < end):
                continue
            token = item.get("token")
            inbox_match = item
            break
        inferred.append(
            {
                "create_entry": create["entry"],
                "create_request": create["request"],
                "create_url": create["url"],
                "inferred_token": token,
                "first_inbox": inbox_match,
            }
        )

    return {
        "har": har_path,
        "creates": creates,
        "inboxes": inboxes,
        "inferred_sessions": inferred,
    }


# ──────────────────────────────────────────────
# 列出全部域名
# ──────────────────────────────────────────────
def list_all_domains() -> dict[str, Any]:
    return {
        "temp-mail-io": {
            "count": len(TEMP_MAIL_IO_DOMAINS),
            "type": "可指定创建",
            "domains": TEMP_MAIL_IO_DOMAINS,
        },
        "hd-premium": {
            "count": len(HD_PREMIUM_DOMAINS),
            "type": "可指定创建 (需加密协议)",
            "domains": HD_PREMIUM_DOMAINS,
        },
        "hd-gmail": {
            "count": len(HD_GMAIL_ENTRY_DOMAINS),
            "type": "Gmail 点别名生成入口",
            "domains": HD_GMAIL_ENTRY_DOMAINS,
        },
        "hd-random": {
            "count": len(HD_RANDOM_SEEN_DOMAINS),
            "type": "随机 (域名由服务端分配)",
            "domains": HD_RANDOM_SEEN_DOMAINS,
        },
        "total_stable_domains": len(TEMP_MAIL_IO_DOMAINS) + len(HD_PREMIUM_DOMAINS),
        "total_entry_points": (
            len(TEMP_MAIL_IO_DOMAINS) + len(HD_PREMIUM_DOMAINS)
            + len(HD_GMAIL_ENTRY_DOMAINS) + len(HD_RANDOM_SEEN_DOMAINS)
        ),
    }


# ──────────────────────────────────────────────
# 连通性测试
# ──────────────────────────────────────────────
def run_test(timeout: int = 15) -> dict[str, Any]:
    results: list[dict[str, Any]] = []

    t0 = time.time()
    try:
        client = TempMailIoClient(timeout=timeout)
        domains = client.get_domains()
        results.append({
            "service": "temp-mail-io",
            "test": "domains",
            "status": "ok",
            "data": {"count": len(domains)},
        })
    except Exception as exc:
        results.append({"service": "temp-mail-io", "test": "domains", "status": "error", "error": str(exc)})

    try:
        client = HdPremiumClient(timeout=timeout)
        test_email = f"apitest_{int(time.time())}@tempmail.edu.pl"
        resp = client.create_email(test_email)
        token = resp.get("data", {}).get("token")
        mailbox = resp.get("data", {}).get("mailbox")
        results.append({
            "service": "hd-premium",
            "test": "create",
            "status": "ok",
            "data": {"email": mailbox, "token": token},
        })
    except Exception as exc:
        results.append({"service": "hd-premium", "test": "create", "status": "error", "error": str(exc)})

    try:
        client = HdGmailClient(timeout=timeout)
        test_email = f"apitest_{int(time.time())}@gmail.com"
        resp = client.create_email(test_email)
        token = resp.get("data", {}).get("token")
        mailbox = resp.get("data", {}).get("mailbox")
        results.append({
            "service": "hd-gmail",
            "test": "create",
            "status": "ok",
            "data": {"email": mailbox, "token": token},
        })
    except Exception as exc:
        results.append({"service": "hd-gmail", "test": "create", "status": "error", "error": str(exc)})

    return {"tests": results, "elapsed_seconds": round(time.time() - t0, 2)}


# ──────────────────────────────────────────────
# CLI
# ──────────────────────────────────────────────
def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(
        description="Instant Mail API 客户端",
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog="""
创建邮箱 (按服务类型):
  --service temp-mail-io  8 个固定域名
  --service hd-premium    9 个高级域名
  --service hd-gmail      Gmail/Googlemail 点别名

读取邮件 (通用接口，适用于所有邮箱):
  messages EMAIL --token TOKEN
  message MESSAGE_ID --email EMAIL --token TOKEN

示例:
  %(prog)s --service temp-mail-io create --email test@bltiwd.com
  %(prog)s --service hd-premium create --email test@tempmail.edu.pl
  %(prog)s --service hd-gmail create --email random@gmail.com
  %(prog)s messages test@bltiwd.com --token TOKEN
  %(prog)s list-domains
""",
    )
    parser.add_argument(
        "--service",
        choices=["temp-mail-io", "hd-premium", "hd-gmail", "hd-random"],
        default="temp-mail-io",
        help="创建邮箱时选择服务",
    )
    parser.add_argument("--timeout", type=int, default=20, help="请求超时秒数")
    sub = parser.add_subparsers(dest="cmd", required=True)

    sub.add_parser("domains", help="列出当前服务支持的域名")
    sub.add_parser("list-domains", help="列出全部服务的所有域名")
    sub.add_parser("test", help="测试连通性")

    create = sub.add_parser("create", help="创建临时邮箱")
    create.add_argument("--email", help="完整邮箱地址 (name@domain)")
    create.add_argument("--name", help="用户名 (temp-mail-io 专用)")
    create.add_argument("--domain", help="域名 (temp-mail-io 专用)")

    inbox = sub.add_parser("messages", help="拉取收件箱 (通用接口)")
    inbox.add_argument("email", help="邮箱地址")
    inbox.add_argument("--token", required=True, help="token")

    message = sub.add_parser("message", help="读取单封邮件 (通用接口)")
    message.add_argument("id", help="邮件 ID")
    message.add_argument("--email", required=True, help="邮箱地址")
    message.add_argument("--token", required=True, help="token")

    har_cmd = sub.add_parser("har-extract", help="从 HAR 文件提取邮箱/token 映射")
    har_cmd.add_argument("path", help="HAR 文件路径")

    args = parser.parse_args(argv)

    if args.cmd == "list-domains":
        print(json.dumps(list_all_domains(), ensure_ascii=False, indent=2))
        return 0

    if args.cmd == "test":
        print(json.dumps(run_test(args.timeout), ensure_ascii=False, indent=2))
        return 0

    # 读取邮件：标准域走 temp-mail-io 原生接口，其它走 HD 通用接口
    if args.cmd == "messages":
        result = fetch_messages(args.email, args.token, timeout=args.timeout)
        print(json.dumps(result, ensure_ascii=False, indent=2))
        return 0

    if args.cmd == "message":
        result = fetch_message(args.email, args.token, args.id, timeout=args.timeout)
        print(json.dumps(result, ensure_ascii=False, indent=2))
        return 0

    # 创建邮箱使用对应服务
    if args.cmd == "create":
        if args.service == "temp-mail-io":
            client = TempMailIoClient(timeout=args.timeout)
            result = client.create_email(email=args.email, name=args.name, domain=args.domain)
        elif args.service == "hd-premium":
            client = HdPremiumClient(timeout=args.timeout)
            if not args.email:
                parser.error("--email 是 hd-premium 的必填参数")
            result = client.create_email(args.email)
        elif args.service == "hd-gmail":
            client = HdGmailClient(timeout=args.timeout)
            if not args.email:
                parser.error("--email 是 hd-gmail 的必填参数")
            result = client.create_email(args.email)
        elif args.service == "hd-random":
            client = HdRandomClient(timeout=args.timeout)
            result = client.create_email()
        else:
            parser.error(f"未知服务: {args.service}")
        
        print(json.dumps(result, ensure_ascii=False, indent=2))
        return 0

    if args.cmd == "domains":
        if args.service == "temp-mail-io":
            client = TempMailIoClient(timeout=args.timeout)
        elif args.service == "hd-premium":
            client = HdPremiumClient(timeout=args.timeout)
        elif args.service == "hd-gmail":
            client = HdGmailClient(timeout=args.timeout)
        elif args.service == "hd-random":
            client = HdRandomClient(timeout=args.timeout)
        else:
            parser.error(f"未知服务: {args.service}")
        result = client.get_domains()
        print(json.dumps(result, ensure_ascii=False, indent=2))
        return 0

    if args.cmd == "har-extract":
        result = har_extract(args.path)
        print(json.dumps(result, ensure_ascii=False, indent=2))
        return 0

    parser.print_help()
    return 1


if __name__ == "__main__":
    raise SystemExit(main())
