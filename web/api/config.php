<?php
/**
 * BeatMail 站点配置
 *
 * 部署后请务必修改 API_KEY。
 */

declare(strict_types=1);

// 只允许被 index.php require，直接访问一律 404。
// 虚拟主机改不了 nginx 规则时，这是唯一的自我保护手段。
if (!defined('BEATMAIL_APP')) {
    http_response_code(404);
    exit;
}

// ── 鉴权 ─────────────────────────────────────────────

/** 是否要求外部调用携带密钥。关掉则 API 完全公开。 */
define('API_AUTH_ENABLED', true);

/** 外部调用密钥：Authorization: Bearer <API_KEY> */
define('API_KEY', 'c4c204a4420ca74ee3b32c2f49fcb1a51c4239abdf34b49d');

/**
 * 本站页面自身的 fetch 免密放行（靠 Origin/Referer 判断）。
 * 注意：Origin 头可被伪造，这只挡自动化刷量，不是强安全边界。
 * 真正的保护来自限流。
 */
define('API_ALLOW_SAME_ORIGIN', true);

/**
 * 额外允许免密的来源（host，不含协议）。本站域名会自动放行。
 * 例：['example.com', 'www.example.com']
 */
const API_EXTRA_ALLOWED_HOSTS = [];

// ── 限流（按 IP 固定窗口）────────────────────────────

define('RATE_LIMIT_ENABLED', true);

/** 窗口长度（秒） */
define('RATE_LIMIT_WINDOW', 60);

/** 一般接口窗口内最大请求数 */
define('RATE_LIMIT_MAX', 60);

/** 创建邮箱代价高，单独限更严 */
define('RATE_LIMIT_CREATE_MAX', 10);

// ── 缓存 ─────────────────────────────────────────────

/** 缓存目录，需对 PHP 可写 */
define('CACHE_DIR', __DIR__ . '/cache');

/** 上游域名列表缓存时长（秒） */
define('CACHE_TTL_DOMAINS', 300);
