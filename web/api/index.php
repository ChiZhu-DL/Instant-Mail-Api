<?php
/**
 * Instant Mail Web API Router
 *
 * 共享主机（InfinityFree 等）常在输出后注入 HTML 广告脚本，
 * 导致前端 res.json() 失败并报「非 JSON 响应 HTTP 200」。
 * 本文件：输出缓冲 + 致命错误转 JSON + 尽量纯净输出。
 */

declare(strict_types=1);

// 标记「正规入口」。被 require 的文件靠它判断是否遭直接访问。
define('BEATMAIL_APP', true);

// 尽早开缓冲，吞掉 notice / 主机注入前缀
if (function_exists('ob_start')) {
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    ob_start();
}

// 禁止把错误直接打成 HTML
@ini_set('display_errors', '0');
@ini_set('html_errors', '0');
error_reporting(E_ALL);

try {
    require_once __DIR__ . '/config.php';
    require_once __DIR__ . '/cache.php';
    require_once __DIR__ . '/auth.php';
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => '配置加载失败: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
header('Vary: Origin');

/**
 * 开启鉴权后不再对所有来源开放 CORS——否则任意站点的 JS
 * 都能带着受害者的浏览器免密调用（同源判断只看 Origin）。
 */
$reqOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (!API_AUTH_ENABLED) {
    header('Access-Control-Allow-Origin: *');
} elseif (is_string($reqOrigin) && $reqOrigin !== '' && is_same_origin_request()) {
    header('Access-Control-Allow-Origin: ' . $reqOrigin);
    header('Access-Control-Allow-Credentials: true');
}
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Max-Age: 600');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

/**
 * 安全输出 JSON：清缓冲，避免主机/PHP 警告污染。
 */
function json_out(int $status, array $payload): void
{
    // 丢掉之前的意外输出（warning、BOM、广告注入前缀）
    if (function_exists('ob_get_level')) {
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
    }

    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        // CORS 头已在文件顶部按鉴权策略设置好，这里不要再覆盖成 *
    }

    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    $json = json_encode($payload, $flags);
    if ($json === false) {
        $json = '{"ok":false,"error":"json_encode failed"}';
        http_response_code(500);
    }

    echo $json;
    exit;
}

// 未捕获致命错误 → JSON
register_shutdown_function(static function (): void {
    $err = error_get_last();
    if ($err === null) {
        return;
    }
    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array($err['type'], $fatalTypes, true)) {
        return;
    }
    // 若已经正常 json_out 过则不会到这里（exit 了）
    if (function_exists('ob_get_level')) {
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
    }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode([
        'ok' => false,
        'error' => 'PHP fatal: ' . ($err['message'] ?? 'unknown'),
        'file' => basename((string) ($err['file'] ?? '')),
        'line' => $err['line'] ?? null,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
});

try {
    require_once __DIR__ . '/InstantMail.php';
} catch (Throwable $e) {
    json_out(500, [
        'ok' => false,
        'error' => 'Failed to load InstantMail.php: ' . $e->getMessage(),
    ]);
}

function read_json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function param(string $key, $default = null)
{
    if (isset($_GET[$key]) && $_GET[$key] !== '') {
        return $_GET[$key];
    }
    static $body = null;
    if ($body === null) {
        $body = read_json_body();
    }
    if (isset($body[$key]) && $body[$key] !== '') {
        return $body[$key];
    }
    if (isset($_POST[$key]) && $_POST[$key] !== '') {
        return $_POST[$key];
    }
    return $default;
}

/**
 * 读取 email 参数并强制还原为字面量 @。
 */
function require_literal_email(string $key = 'email'): string
{
    $raw = param($key);
    if (!is_string($raw) || trim($raw) === '') {
        json_out(400, [
            'ok' => false,
            'error' => "参数 {$key} 必填，且应为邮箱字面量（含 @）",
            'hint' => '正确示例: user@domain.com',
        ]);
    }
    try {
        return normalize_email_address($raw);
    } catch (InvalidArgumentException $e) {
        json_out(400, [
            'ok' => false,
            'error' => $e->getMessage(),
            'received' => $raw,
        ]);
    }
    return ''; // unreachable
}

$action = (string) (param('action') ?? '');

// ── 鉴权闸门 ────────────────────────────────────────
// health 保持公开，方便部署后直接探活。
if ($action !== 'health') {
    $auth = check_auth();
    if (!$auth['ok']) {
        header('WWW-Authenticate: Bearer realm="BeatMail API"');
        json_out(401, [
            'ok' => false,
            'error' => $auth['reason'],
            'hint' => '在请求头加 Authorization: Bearer <API_KEY>，密钥见 api/config.php',
        ]);
    }
}

// ── 限流 ────────────────────────────────────────────
cache_gc();

$isCreate = ($action === 'create');
$rl = rate_limit_check(
    $isCreate ? 'create' : 'general',
    $isCreate ? RATE_LIMIT_CREATE_MAX : RATE_LIMIT_MAX,
    RATE_LIMIT_WINDOW
);

if (!headers_sent()) {
    header('X-RateLimit-Limit: ' . $rl['limit']);
    header('X-RateLimit-Remaining: ' . $rl['remaining']);
}

if (!$rl['allowed']) {
    if (!headers_sent()) {
        header('Retry-After: ' . $rl['retry_after']);
    }
    json_out(429, [
        'ok' => false,
        'error' => '请求过于频繁，请稍后再试',
        'retry_after' => $rl['retry_after'],
        'limit' => $rl['limit'],
        'window' => RATE_LIMIT_WINDOW,
    ]);
}

try {
    switch ($action) {
        case 'health':
            json_out(200, [
                'ok' => true,
                'service' => 'BeatMail',
                'php' => PHP_VERSION,
                'curl' => function_exists('curl_init'),
                'openssl' => extension_loaded('openssl'),
                'curl_path_as_is' => defined('CURLOPT_PATH_AS_IS'),
                'auth_enabled' => API_AUTH_ENABLED,
                'rate_limit' => RATE_LIMIT_ENABLED ? (RATE_LIMIT_MAX . '/' . RATE_LIMIT_WINDOW . 's') : 'off',
                'cache_writable' => cache_dir() !== null,
                'time' => time(),
                'email_contract' => [
                    'business_layer' => 'literal @ only (user@domain.com)',
                    'never_return' => 'user%40domain.com',
                ],
            ]);
            break;

        case 'domains':
            json_out(200, [
                'ok' => true,
                'data' => list_all_domains(),
            ]);
            break;

        case 'create': {
            $service = (string) (param('service') ?? '');
            $emailIn = param('email');
            $name = param('name');
            $domain = param('domain');

            $email = null;
            if (is_string($emailIn) && trim($emailIn) !== '') {
                $email = normalize_email_address($emailIn);
            }
            $name = is_string($name) ? trim($name) : null;
            $domain = is_string($domain) ? strtolower(trim(ltrim(trim($domain), '@'))) : null;
            if ($domain === '') {
                $domain = null;
            }

            if ($name !== null && $name !== '') {
                $name = preg_replace('/[^a-zA-Z0-9._+-]/', '', $name);
                if ($name === null || $name === '') {
                    $name = null;
                }
            } else {
                $name = null;
            }

            if ($service === '' || $service === 'auto') {
                if ($domain === 'random') {
                    $service = 'hd-random';
                    $domain = null;
                } elseif ($domain) {
                    $service = resolve_service_for_domain($domain);
                } elseif ($email) {
                    $parts = split_email($email);
                    $service = resolve_service_for_domain($parts[1]);
                } else {
                    $service = 'temp-mail-io';
                }
            }

            // 标准/高级：始终带完整 email，避免随机域
            if (
                !$email
                && $domain
                && $domain !== 'random'
                && in_array($service, ['temp-mail-io', 'hd-premium'], true)
            ) {
                $local = $name ?: random_username(10);
                $email = $local . '@' . $domain;
            }

            $result = create_mailbox($service, $email, $name, $domain, 45);
            $data = isset($result['data']) && is_array($result['data']) ? $result['data'] : [];

            $mailbox = isset($data['mailbox']) ? $data['mailbox'] : null;
            if (is_string($mailbox) && $mailbox !== '') {
                $mailbox = normalize_email_address($mailbox);
            }

            if (
                $domain
                && $domain !== 'random'
                && is_string($mailbox)
                && in_array($service, ['temp-mail-io', 'hd-premium'], true)
            ) {
                $parts = split_email($mailbox);
                $gotDomain = $parts[1];
                if (strtolower($gotDomain) !== $domain) {
                    json_out(502, [
                        'ok' => false,
                        'error' => "创建结果域名与选择不一致：选择 {$domain}，得到 {$mailbox}",
                        'requested_domain' => $domain,
                        'mailbox' => $mailbox,
                    ]);
                }
            }

            if ((!$mailbox || $mailbox === '') && empty($data['token'])) {
                json_out(502, [
                    'ok' => false,
                    'error' => '上游未返回 mailbox/token',
                    'raw' => isset($result['raw']) ? $result['raw'] : null,
                    'hint' => '检查服务器 curl 出网；InfinityFree 可能拦截外连',
                ]);
            }

            json_out(200, [
                'ok' => true,
                'data' => [
                    'mailbox' => $mailbox,
                    'email' => $mailbox,
                    'token' => isset($data['token']) ? $data['token'] : null,
                    'service' => isset($data['service']) ? $data['service'] : $service,
                    'requested_domain' => $domain ?: (isset($data['requested_domain']) ? $data['requested_domain'] : null),
                ],
            ]);
            break;
        }

        case 'messages': {
            $email = require_literal_email('email');
            $token = param('token');
            // temp-mail-io 原生收件其实不需要 token，但前端总会传；HD 通用接口需要
            if (!is_string($token)) {
                $token = '';
            }
            $token = trim($token);

            // 标准域 → temp-mail-io /v3/email/user@domain/messages（字面量 @）
            // 其它 → HD 通用加密接口
            $result = fetch_messages($email, $token, 45);
            $messages = isset($result['data']['messages']) ? $result['data']['messages'] : [];
            $via = isset($result['via']) ? $result['via'] : 'unknown';

            $list = [];
            if (is_array($messages)) {
                foreach ($messages as $m) {
                    if (!is_array($m)) {
                        continue;
                    }
                    $list[] = normalize_message_summary($m);
                }
            }

            usort($list, static function ($a, $b) {
                $aa = isset($a['createdAt']) ? (int) $a['createdAt'] : 0;
                $bb = isset($b['createdAt']) ? (int) $b['createdAt'] : 0;
                return $bb <=> $aa;
            });

            json_out(200, [
                'ok' => true,
                'data' => [
                    'mailbox' => $email,
                    'count' => count($list),
                    'messages' => $list,
                    'via' => $via,
                ],
            ]);
            break;
        }

        case 'message': {
            $email = require_literal_email('email');
            $token = param('token');
            $id = param('id');
            if (!is_string($id) || trim($id) === '') {
                json_out(400, ['ok' => false, 'error' => 'id is required']);
            }
            if (!is_string($token)) {
                $token = '';
            }
            $token = trim($token);
            $id = trim($id);

            $result = fetch_message($email, $token, $id, 45);
            $detail = isset($result['data']) ? $result['data'] : $result;
            $via = isset($result['via']) ? $result['via'] : 'unknown';

            $normalized = normalize_message_detail(is_array($detail) ? $detail : []);
            $normalized['via'] = $via;

            json_out(200, [
                'ok' => true,
                'data' => $normalized,
            ]);
            break;
        }

        default:
            json_out(400, [
                'ok' => false,
                'error' => 'Unknown or missing action. Use domains|create|messages|message|health',
                'got_action' => $action,
            ]);
    }
} catch (InstantMailError $e) {
    $body = $e->body;
    $msg = $e->getMessage();
    if (is_array($body) && isset($body['error']) && is_string($body['error'])) {
        $msg = $body['error'];
    }
    $code = $e->httpStatus > 0 ? $e->httpStatus : 502;
    if ($code < 400 || $code > 599) {
        $code = 502;
    }
    // 客户端错误（如下线域名）用 400，上游故障用 502
    if ($code === 400) {
        json_out(400, [
            'ok' => false,
            'error' => $msg,
            'detail' => is_array($body) ? $body : null,
        ]);
    }
    json_out(502, [
        'ok' => false,
        'error' => $msg,
        'upstream_status' => $e->httpStatus,
        'upstream_body' => $body,
    ]);
} catch (InvalidArgumentException $e) {
    json_out(400, ['ok' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    json_out(500, [
        'ok' => false,
        'error' => $e->getMessage(),
        'type' => get_class($e),
    ]);
}

/**
 * @param array<string, mixed> $m
 * @return array<string, mixed>
 */
function normalize_message_summary(array $m): array
{
    $id = isset($m['id']) ? $m['id'] : (isset($m['_id']) ? $m['_id'] : (isset($m['messageId']) ? $m['messageId'] : (isset($m['mail_id']) ? $m['mail_id'] : null)));
    $from = isset($m['from']) ? $m['from'] : (isset($m['sender']) ? $m['sender'] : (isset($m['from_address']) ? $m['from_address'] : ''));
    $to = isset($m['to']) ? $m['to'] : (isset($m['recipient']) ? $m['recipient'] : '');
    $subject = isset($m['subject']) ? $m['subject'] : (isset($m['title']) ? $m['title'] : '(无主题)');
    $preview = isset($m['text']) ? $m['text'] : (isset($m['body']) ? $m['body'] : (isset($m['intro']) ? $m['intro'] : (isset($m['preview']) ? $m['preview'] : '')));
    if (is_array($preview)) {
        $preview = '';
    }
    $preview = trim(preg_replace('/\s+/', ' ', strip_tags((string) $preview)));
    $len = function_exists('mb_strlen') ? mb_strlen($preview) : strlen($preview);
    if ($len > 160) {
        $preview = (function_exists('mb_substr') ? mb_substr($preview, 0, 160) : substr($preview, 0, 160)) . '…';
    }

    $created = isset($m['createdAt']) ? $m['createdAt'] : (isset($m['created_at']) ? $m['created_at'] : (isset($m['date']) ? $m['date'] : (isset($m['timestamp']) ? $m['timestamp'] : null)));
    if (is_string($created) && !is_numeric($created)) {
        $ts = strtotime($created);
        $created = $ts !== false ? $ts * 1000 : null;
    } elseif (is_numeric($created)) {
        $created = (int) $created;
        if ($created > 0 && $created < 1000000000000) {
            $created *= 1000;
        }
    } else {
        $created = null;
    }

    $attachments = isset($m['attachments']) ? $m['attachments'] : (isset($m['attachment_count']) ? $m['attachment_count'] : 0);
    if (is_array($attachments)) {
        $attachments = count($attachments);
    }

    if (is_string($from) && stripos($from, '%40') !== false) {
        try {
            $from = normalize_email_address($from);
        } catch (Throwable $e) {
            $from = rawurldecode($from);
        }
    }
    if (is_string($to) && stripos($to, '%40') !== false) {
        try {
            $to = normalize_email_address($to);
        } catch (Throwable $e) {
            $to = rawurldecode($to);
        }
    }

    return [
        'id' => $id !== null ? (string) $id : null,
        'from' => (string) $from,
        'to' => (string) $to,
        'subject' => (string) $subject,
        'preview' => $preview,
        'createdAt' => $created,
        'attachments' => (int) $attachments,
    ];
}

/**
 * @param array<string, mixed> $m
 * @return array<string, mixed>
 */
function normalize_message_detail(array $m): array
{
    $base = normalize_message_summary($m);
    $html = isset($m['html']) ? $m['html'] : (isset($m['bodyHtml']) ? $m['bodyHtml'] : (isset($m['body_html']) ? $m['body_html'] : null));
    $text = isset($m['text']) ? $m['text'] : (isset($m['body']) ? $m['body'] : (isset($m['bodyText']) ? $m['bodyText'] : (isset($m['body_text']) ? $m['body_text'] : null)));
    if (is_array($html)) {
        $html = implode("\n", $html);
    }
    if (is_array($text)) {
        $text = implode("\n", $text);
    }
    $files = isset($m['files']) ? $m['files'] : (isset($m['attachments_list']) ? $m['attachments_list'] : []);
    if (!is_array($files)) {
        $files = [];
    }

    return array_merge($base, [
        'html' => is_string($html) ? $html : null,
        'text' => is_string($text) ? $text : null,
        'files' => $files,
    ]);
}
