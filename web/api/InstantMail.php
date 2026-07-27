<?php
/**
 * Instant Mail PHP Client
 * Port of instant_mail_api.py — full feature parity.
 *
 * Critical: email addresses containing "@" must be URL-encoded as %40
 * whenever they appear in a path or query string, otherwise upstream
 * APIs will not deliver / return mail correctly.
 */

declare(strict_types=1);

if (!defined('BEATMAIL_APP')) {
    http_response_code(404);
    exit;
}

class InstantMailError extends RuntimeException
{
    public int $httpStatus;
    /** @var mixed */
    public $body;

    /** @param mixed $body */
    public function __construct(int $status, $body)
    {
        $this->httpStatus = $status;
        $this->body = $body;
        $msg = is_string($body) ? $body : json_encode($body, JSON_UNESCAPED_UNICODE);
        parent::__construct("Instant Mail API error {$status}: {$msg}");
    }
}

final class InstantMailCrypto
{
    /** encryptKey pairs: ao-sq-=x-5b-4*-Bz */
    private const SWAPS = ['ao', 'sq', '=x', '5b', '4*', 'Bz'];

    public static function swapChars(string $value): string
    {
        foreach (self::SWAPS as $pair) {
            if (strlen($pair) !== 2) {
                continue;
            }
            $left = $pair[0];
            $right = $pair[1];
            // bidirectional swap via placeholder
            $value = str_replace($left, "\0", $value);
            $value = str_replace($right, $left, $value);
            $value = str_replace("\0", $right, $value);
        }
        return $value;
    }

    /** @param array<string, mixed> $payload */
    public static function encrypt(array $payload): string
    {
        $raw = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($raw === false) {
            throw new RuntimeException('JSON encode failed');
        }
        $b64 = base64_encode($raw);
        return strrev(self::swapChars($b64));
    }

    /** @return mixed */
    public static function decrypt(string $cipher)
    {
        // Mode 1: reverse only (simple)
        try {
            $reversed = strrev($cipher);
            $padded = $reversed;
            while (strlen($padded) % 4 !== 0) {
                $padded .= '=';
            }
            $decoded = base64_decode($padded, true);
            if ($decoded !== false) {
                $json = json_decode($decoded, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    return $json;
                }
            }
        } catch (Throwable $e) {
            // fall through
        }

        // Mode 2: reverse + char swap (full)
        try {
            $swapped = strrev(self::swapChars($cipher));
            $padded = $swapped;
            while (strlen($padded) % 4 !== 0) {
                $padded .= '=';
            }
            $decoded = base64_decode($padded, true);
            if ($decoded !== false) {
                $json = json_decode($decoded, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    return $json;
                }
            }
        } catch (Throwable $e) {
            // fall through
        }

        throw new RuntimeException('Unable to decrypt response payload');
    }
}

abstract class BaseHttpClient
{
    protected string $baseUrl;
    protected int $timeout;

    public function __construct(string $baseUrl, int $timeout = 20)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->timeout = $timeout;
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, string>|null $headers
     * @return mixed
     */
    protected function request(string $method, string $path, ?array $body = null, ?array $headers = null)
    {
        $reqHeaders = [
            'Accept: application/json',
            'User-Agent: okhttp/4.12.0',
        ];
        if ($headers) {
            foreach ($headers as $k => $v) {
                $reqHeaders[] = "{$k}: {$v}";
            }
        }

        $payload = null;
        if ($body !== null) {
            $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $reqHeaders[] = 'Content-Type: application/json';
        }

        $url = $this->baseUrl . $path;
        $ch = curl_init();
        if ($ch === false) {
            throw new RuntimeException('curl_init failed');
        }

        $opts = [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => min(15, $this->timeout),
            CURLOPT_HTTPHEADER => $reqHeaders,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_ENCODING => '',
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
        ];

        // 路径里可能含字面量 @（temp-mail-io 要求），禁止 curl 再改写。
        // 老 curl 编译版本没有此常量；一旦数组里出现未定义常量，
        // curl_setopt_array 会整体失败（超时/SSL/UA 全部丢失），故必须条件设置。
        if (defined('CURLOPT_PATH_AS_IS')) {
            $opts[CURLOPT_PATH_AS_IS] = true;
        }

        if (curl_setopt_array($ch, $opts) === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('curl_setopt_array failed: ' . $err);
        }

        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }

        $text = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        if ($errno) {
            throw new InstantMailError(0, "cURL error {$errno}: {$error}");
        }

        if (!is_string($text) || $text === '') {
            throw new InstantMailError($status, 'Empty response from upstream');
        }

        // 上游偶发返回 HTML 错误页
        $trim = ltrim($text);
        if ($trim !== '' && ($trim[0] === '<' || stripos($contentType, 'text/html') !== false)) {
            $snippet = function_exists('mb_substr') ? mb_substr($trim, 0, 180) : substr($trim, 0, 180);
            throw new InstantMailError($status, 'Upstream returned HTML instead of JSON: ' . $snippet);
        }

        $data = json_decode($text, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $snippet = function_exists('mb_substr') ? mb_substr($text, 0, 180) : substr($text, 0, 180);
            throw new InstantMailError($status, 'Upstream non-JSON body: ' . $snippet);
        }

        if ($status >= 400) {
            throw new InstantMailError($status, $data);
        }

        return $data;
    }
}

/**
 * ═══════════════════════════════════════════════════════════════════
 * EMAIL @ 处理铁律（整站最重要）
 * ═══════════════════════════════════════════════════════════════════
 *
 * 业务层（PHP 变量 / JSON 响应 / 创建请求 body / 加密前 payload）:
 *   必须是字面量邮箱:  user@domain.com
 *   绝对禁止:          user%40domain.com
 *
 * 传输层（仅在「最终拼进 HTTP URL 的 path/query」那一瞬间）:
 *   才允许编码一次:   user%40domain.com
 *
 * 错误示例（会导致收不到信）:
 *   - 把已编码的 user%40x.com 再 rawurlencode → user%2540x.com
 *   - 把 %40 写进 JSON body / 加密 payload 当邮箱
 *   - 把 %40 存进 session 再当真实地址发给上游
 *
 * 正确流程:
 *   客户端传入 email（可能是 user@x 或 user%40x）
 *     → normalize_email_address() 得到 user@x
 *     → 业务逻辑 / 加密 / JSON 全程用 user@x
 *     → 仅 encode_email_path() 拼 URL path 时变成 user%40x
 */

/**
 * 把任意来源的邮箱还原成「字面量 @」形式。
 * 可安全处理: 已是 user@x、误传 user%40x、双重编码 user%2540x。
 *
 * @throws InvalidArgumentException
 */
function normalize_email_address(string $email): string
{
    $email = trim($email);
    if ($email === '') {
        throw new InvalidArgumentException('邮箱地址为空');
    }

    // 反复 URL 解码，直到没有 %XX 形态（防止 %2540 → %40 → @）
    // 最多 5 次，避免异常输入死循环
    for ($i = 0; $i < 5; $i++) {
        if (strpos($email, '%') === false) {
            break;
        }
        $decoded = rawurldecode($email);
        if ($decoded === $email) {
            // rawurldecode 无变化时再试 urldecode（+ → 空格等）
            $decoded = urldecode($email);
        }
        if ($decoded === $email) {
            break;
        }
        $email = $decoded;
    }

    $email = trim($email);

    // 业务层禁止残留 %40 / %2540
    if (preg_match('/%40|%2540/i', $email)) {
        throw new InvalidArgumentException(
            '邮箱仍含百分号编码，无法还原为字面量 @: ' . $email
        );
    }

    // 必须恰好有一个 @（Gmail 别名本地部分可含 + .）
    $atCount = substr_count($email, '@');
    if ($atCount !== 1) {
        throw new InvalidArgumentException(
            "邮箱格式无效（需要恰好一个 @，当前 {$atCount} 个）: {$email}"
        );
    }

    [$local, $domain] = explode('@', $email, 2);
    if ($local === '' || $domain === '' || strpos($domain, '.') === false) {
        throw new InvalidArgumentException("邮箱格式无效: {$email}");
    }

    return $local . '@' . $domain;
}

/**
 * 仅用于「拼进上游 URL 的 path 段」。编码一次 @ → %40。
 * 用于 HD 通用接口等需要 %40 的上游。
 * 入口会先 normalize，杜绝双重编码。
 *
 * ⚠ temp-mail-io 原生收件路径不要用本函数 —— 见 encode_email_path_raw_at()
 */
function encode_email_path(string $email): string
{
    $literal = normalize_email_address($email);
    if (strpos($literal, '@') === false || stripos($literal, '%40') !== false) {
        throw new RuntimeException('encode_email_path: 内部邮箱不是字面量 @ 形式');
    }
    return rawurlencode($literal);
}

/**
 * temp-mail-io 专用：路径里必须保留字面量 @。
 * 实测：user%40domain → 400 Email not found
 *       user@domain   → 200 []
 * 其它特殊字符仍编码，仅把 @ 还原。
 */
function encode_email_path_raw_at(string $email): string
{
    $literal = normalize_email_address($email);
    // 分段编码，中间保留 @
    [$local, $domain] = split_email($literal);
    // local/domain 中的保留字编码；@ 不编码
    return rawurlencode($local) . '@' . rawurlencode($domain);
}

/**
 * 编码 path/query 段（token、message id、密文等）。
 */
function encode_path_segment(string $value): string
{
    return rawurlencode($value);
}

/**
 * 创建/加密用的邮箱：保证字面量 @，供 JSON body 与 encrypt payload。
 */
function email_for_payload(string $email): string
{
    return normalize_email_address($email);
}

function split_email(string $email): array
{
    $email = normalize_email_address($email);
    $pos = strpos($email, '@');
    return [substr($email, 0, $pos), substr($email, $pos + 1)];
}

/**
 * 根据邮箱域名判断是否应走 temp-mail-io 原生收件接口。
 */
function is_temp_mail_io_mailbox(string $email): bool
{
    try {
        [, $domain] = split_email($email);
        $domain = strtolower($domain);
        if (in_array($domain, TEMP_MAIL_IO_DOMAINS, true)) {
            return true;
        }
        if (in_array($domain, TEMP_MAIL_IO_RETIRED_DOMAINS, true)) {
            return true;
        }
        // getDomains() 自身带落盘缓存，这里再加一层 per-request 静态量
        // 避免同一次请求内重复走文件 IO
        static $liveCache = null;
        if ($liveCache === null) {
            try {
                $liveCache = array_map('strtolower', (new TempMailIoClient(8))->getDomains());
            } catch (Throwable $e) {
                $liveCache = TEMP_MAIL_IO_DOMAINS;
            }
        }
        return in_array($domain, $liveCache, true);
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * 统一收件箱：标准域 → temp-mail-io 原生；其它 → HD 通用加密接口。
 * @return array{status:int,data:array,raw:mixed,via:string}
 */
function fetch_messages(string $email, string $token, int $timeout = 25): array
{
    $email = normalize_email_address($email);
    $token = trim($token);

    if (is_temp_mail_io_mailbox($email)) {
        $client = new TempMailIoClient($timeout);
        $result = $client->getMessages($email);
        $result['via'] = 'temp-mail-io';
        return $result;
    }

    $reader = new UniversalMailClient($timeout);
    $result = $reader->getMessages($email, $token);
    $result['via'] = 'hd-universal';
    return $result;
}

/**
 * 统一读单封：标准域 → temp-mail-io /v3/message/{id}；其它 → HD 通用。
 * @return array{status:int,data:mixed,raw:mixed,via:string}
 */
function fetch_message(string $email, string $token, string $messageId, int $timeout = 25): array
{
    $email = normalize_email_address($email);
    $token = trim($token);
    $messageId = trim($messageId);

    if (is_temp_mail_io_mailbox($email)) {
        $client = new TempMailIoClient($timeout);
        $result = $client->getMessage($messageId);
        $result['via'] = 'temp-mail-io';
        return $result;
    }

    $reader = new UniversalMailClient($timeout);
    $result = $reader->getMessage($email, $token, $messageId);
    $result['via'] = 'hd-universal';
    return $result;
}

function normalize_hex_token($value): ?string
{
    if (!is_string($value) || $value === '') {
        return null;
    }
    $cleaned = preg_replace('/[^0-9a-fA-F]/', '', $value) ?? '';
    if (strlen($cleaned) === 32) {
        return strtolower($cleaned);
    }
    return $value;
}

function random_username(int $len = 10): string
{
    $chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
    $out = '';
    for ($i = 0; $i < $len; $i++) {
        $out .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $out;
}

// ── Domain catalogs ──────────────────────────────────────────

const TEMP_MAIL_IO_BASE = 'https://api.internal.temp-mail.io/api';
const HD_PREMIUM_BASE = 'https://mail-server.1timetech.com/api';
const HD_GMAIL_BASE = 'https://mail-server-2.1timetech.com/api';
const HD_RANDOM_BASE = 'https://mob2.temp-mail.org';
const HD_APP_KEY = 'b9db03078622';

/**
 * temp-mail-io 静态兜底列表。
 * 注意：上游会下线域名（如 wnbaldwy.com 已不在 /v4/domains），
 * 对已下线域名 POST 创建会「成功」但随机换到其它标准域 —— 不是我们的 bug。
 * 下拉框应优先用 getDomains() 实时列表。
 */
const TEMP_MAIL_IO_DOMAINS = [
    'bltiwd.com',
    'bwmyga.com',
    'ozsaip.com',
    'yzcalo.com',
    'lnovic.com',
    'ruutukf.com',
    'gmeenramy.com',
];

/** 历史上出现过、现已下线的域名（仅用于提示，不进下拉） */
const TEMP_MAIL_IO_RETIRED_DOMAINS = [
    'wnbaldwy.com',
];

const HD_PREMIUM_DOMAINS = [
    'tempmail.edu.pl',
    'rommiui.com',
    'gmail10p.com',
    'oletters.com',
    'oemails.com',
    'oegmail.com',
    'suiemail.com',
    'voewo.com',
    'yanemail.com',
];

const HD_GMAIL_ENTRY_DOMAINS = [
    'gmail.com',
    '+gmail.com',
    'googlemail.com',
    '+googlemail.com',
];

const HD_RANDOM_SEEN_DOMAINS = [
    'usxxoo.com',
    'xgshare.com',
    'xbmotor.com',
];

// ── temp-mail-io ─────────────────────────────────────────────

final class TempMailIoClient extends BaseHttpClient
{
    public function __construct(int $timeout = 20)
    {
        parent::__construct(TEMP_MAIL_IO_BASE, $timeout);
    }

    /**
     * 实时域名列表。
     *
     * 上游单次约 1.7s，而首页、创建、收件判断都要用它，
     * 因此按 TTL 落盘缓存；缓存不可用时退化为每次直连。
     *
     * @return list<string>
     */
    public function getDomains(): array
    {
        if (function_exists('cache_remember') && defined('CACHE_TTL_DOMAINS')) {
            // fetchDomains 失败时返回 null，cache_remember 不写入，
            // 避免一次网络抖动把兜底列表钉死 5 分钟
            $cached = cache_remember(
                'domains_temp_mail_io.json',
                CACHE_TTL_DOMAINS,
                function () {
                    return $this->fetchDomains();
                }
            );
            if (is_array($cached) && $cached !== []) {
                return array_values(array_map('strval', $cached));
            }
            return TEMP_MAIL_IO_DOMAINS;
        }

        return $this->fetchDomains() ?? TEMP_MAIL_IO_DOMAINS;
    }

    /**
     * 拉取上游域名。失败返回 null（供调用方区分「失败」与「空列表」）。
     * @return list<string>|null
     */
    private function fetchDomains(): ?array
    {
        try {
            $data = $this->request('GET', '/v4/domains');
            $domains = is_array($data) && isset($data['domains']) ? $data['domains'] : (is_array($data) ? $data : []);
            $out = [];
            foreach ($domains as $item) {
                if (is_array($item)) {
                    $out[] = (string) ($item['name'] ?? $item['domain'] ?? '');
                } else {
                    $out[] = (string) $item;
                }
            }
            $out = array_values(array_filter($out));
            return $out ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /** @return array{status:int,data:array,raw:mixed} */
    public function createEmail(?string $email = null, ?string $name = null, ?string $domain = null): array
    {
        // 与 Python CLI 一致：POST {"domain":"...","name":"..."}
        if ($email) {
            [$name, $domain] = split_email($email);
        }
        $name = is_string($name) ? trim($name) : '';
        $domain = is_string($domain) ? strtolower(trim($domain)) : '';
        if ($name === '' || $domain === '') {
            throw new InvalidArgumentException('temp-mail-io 需要完整邮箱，或同时提供 name 与 domain');
        }
        $domain = ltrim($domain, '@');

        // 创建前核对实时域名：已下线域名上游会「成功」但随机改域
        $live = $this->getDomains();
        $liveLower = array_map('strtolower', $live);
        if (!in_array($domain, $liveLower, true)) {
            $hint = in_array($domain, TEMP_MAIL_IO_RETIRED_DOMAINS, true)
                ? "域名 {$domain} 已从 temp-mail-io 下线"
                : "域名 {$domain} 当前不在 temp-mail-io 可用列表";
            throw new InstantMailError(400, [
                'error' => $hint . '，请换下拉框中的其它标准域（如 bltiwd.com）',
                'requested_domain' => $domain,
                'available_domains' => $live,
            ]);
        }

        $data = $this->request('POST', '/v3/email/new', [
            'domain' => $domain,
            'name' => $name,
        ]);

        $mailbox = is_array($data) ? ($data['email'] ?? null) : null;
        $token = is_array($data) ? ($data['token'] ?? null) : null;

        if (!is_string($mailbox) || $mailbox === '') {
            $mailbox = "{$name}@{$domain}";
        } else {
            try {
                $mailbox = normalize_email_address($mailbox);
            } catch (Throwable $e) {
                $mailbox = "{$name}@{$domain}";
            }
        }

        $returnedDomain = '';
        try {
            [, $returnedDomain] = split_email($mailbox);
            $returnedDomain = strtolower($returnedDomain);
        } catch (Throwable $e) {
            $returnedDomain = '';
        }

        // 可用域上仍被改域：再试一次（偶发 name 冲突）
        if ($returnedDomain !== '' && $returnedDomain !== $domain) {
            $retryName = $name . random_username(4);
            $data2 = $this->request('POST', '/v3/email/new', [
                'domain' => $domain,
                'name' => $retryName,
            ]);
            $mailbox2 = is_array($data2) ? ($data2['email'] ?? null) : null;
            $token2 = is_array($data2) ? ($data2['token'] ?? null) : null;
            if (is_string($mailbox2) && $mailbox2 !== '') {
                try {
                    $mailbox2 = normalize_email_address($mailbox2);
                    [, $d2] = split_email($mailbox2);
                    if (strtolower($d2) === $domain && $token2) {
                        return [
                            'status' => 200,
                            'data' => [
                                'mailbox' => $mailbox2,
                                'token' => $token2,
                                'service' => 'temp-mail-io',
                                'requested_domain' => $domain,
                            ],
                            'raw' => $data2,
                        ];
                    }
                } catch (Throwable $e) {
                    // fall through
                }
            }
            throw new InstantMailError(502, [
                'error' => "上游未按指定域名创建（请求 {$domain}，返回 {$mailbox}）。该域可能刚下线，请刷新页面重选域名。",
                'requested_domain' => $domain,
                'returned_mailbox' => $mailbox,
                'available_domains' => $live,
            ]);
        }

        return [
            'status' => 200,
            'data' => [
                'mailbox' => $mailbox,
                'token' => $token,
                'service' => 'temp-mail-io',
                'requested_domain' => $domain,
            ],
            'raw' => $data,
        ];
    }

    /**
     * Native inbox for temp-mail-io domains only.
     *
     * 关键：path 里必须是字面量 @，不能 %40。
     *   GET /v3/email/user@domain.com/messages  → 200
     *   GET /v3/email/user%40domain.com/messages → 400 Email not found
     *
     * @return array{status:int,data:array,raw:mixed}
     */
    public function getMessages(string $email): array
    {
        $literal = normalize_email_address($email);
        // 保留字面量 @（temp-mail-io 特殊要求）
        $path = '/v3/email/' . encode_email_path_raw_at($literal) . '/messages';
        $data = $this->request('GET', $path);
        $messages = [];
        if (is_array($data)) {
            // 可能是纯列表，或 {messages:[...]}
            $isList = $data === [] || array_keys($data) === range(0, count($data) - 1);
            if ($isList) {
                $messages = $data;
            } elseif (isset($data['messages']) && is_array($data['messages'])) {
                $messages = $data['messages'];
            } else {
                $messages = [];
            }
        }
        return [
            'status' => 200,
            'data' => ['mailbox' => $literal, 'messages' => $messages],
            'raw' => $data,
        ];
    }

    /**
     * 单封详情：GET /v3/message/{id}
     * @return array{status:int,data:mixed,raw:mixed}
     */
    public function getMessage(string $messageId): array
    {
        $path = '/v3/message/' . encode_path_segment($messageId);
        $data = $this->request('GET', $path);
        return ['status' => 200, 'data' => $data, 'raw' => $data];
    }
}

// ── HD encrypted server base ─────────────────────────────────

abstract class HdMailServerClient extends BaseHttpClient
{
    protected string $appKey;

    public function __construct(string $baseUrl, int $timeout = 20, string $appKey = HD_APP_KEY)
    {
        parent::__construct($baseUrl, $timeout);
        $this->appKey = $appKey;
    }

    /** @return array<string, string> */
    protected function mailHeaders(): array
    {
        return [
            'x-app-key' => $this->appKey,
            'User-Agent' => 'okhttp/4.12.0',
        ];
    }

    /**
     * Build ?params=... with encrypted email payload.
     *
     * 关键: 加密前的 JSON 必须是字面量 {"email":"user@domain.com"}
     *       绝不能是 {"email":"user%40domain.com"} —— 否则上游按错误地址投递/查询，收不到信。
     * 密文本身再 rawurlencode 放进 query（这与邮箱 @ 无关，是密文字符的传输编码）。
     */
    protected function buildParamsQuery(string $email): string
    {
        $literal = email_for_payload($email); // 强制字面量 @
        $encoded = InstantMailCrypto::encrypt(['email' => $literal]);
        return '?params=' . rawurlencode($encoded);
    }

    /**
     * @param array<string, mixed>|null $body
     * @return mixed
     */
    protected function requestMailApi(string $method, string $path, ?array $body = null)
    {
        if (strpos($path, '?') === false) {
            $path .= '?params=' . rawurlencode(InstantMailCrypto::encrypt([]));
        }

        $wrapped = $body !== null ? ['data' => InstantMailCrypto::encrypt($body)] : null;
        $raw = $this->request($method, $path, $wrapped, $this->mailHeaders());

        if (is_array($raw) && isset($raw['data']) && is_string($raw['data'])) {
            try {
                return InstantMailCrypto::decrypt($raw['data']);
            } catch (Throwable $e) {
                return [
                    '_encrypted' => $raw['data'],
                    '_type' => $raw['type'] ?? null,
                    '_raw' => $raw,
                ];
            }
        }
        return $raw;
    }
}

// ── hd-premium ───────────────────────────────────────────────

final class HdPremiumClient extends HdMailServerClient
{
    public function __construct(int $timeout = 20)
    {
        parent::__construct(HD_PREMIUM_BASE, $timeout);
    }

    /** @return list<string> */
    public function getDomains(): array
    {
        return HD_PREMIUM_DOMAINS;
    }

    /** @return array{status:int,data:array,raw:mixed} */
    public function createEmail(string $email): array
    {
        // JSON/加密 body 必须是字面量 user@domain，禁止 %40
        $literal = email_for_payload($email);
        $data = $this->requestMailApi('POST', '/email?params=x03e', ['email' => $literal]);
        $mailbox = is_array($data) ? ($data['email'] ?? null) : null;
        if (is_string($mailbox) && $mailbox !== '') {
            try {
                $mailbox = normalize_email_address($mailbox);
            } catch (Throwable $e) {
                // 上游偶发异常格式时保留原值
            }
        }
        $token = is_array($data) ? normalize_hex_token($data['id'] ?? null) : null;

        return [
            'status' => 200,
            'data' => [
                'mailbox' => $mailbox ?: $literal,
                'token' => $token,
                'service' => 'hd-premium',
            ],
            'raw' => $data,
        ];
    }
}

// ── hd-gmail ─────────────────────────────────────────────────

final class HdGmailClient extends HdMailServerClient
{
    public function __construct(int $timeout = 20)
    {
        parent::__construct(HD_GMAIL_BASE, $timeout);
    }

    /** @return list<string> */
    public function getDomains(): array
    {
        return HD_GMAIL_ENTRY_DOMAINS;
    }

    /** @return array{status:int,data:array,raw:mixed} */
    public function createEmail(string $email): array
    {
        $literal = email_for_payload($email);
        $data = $this->requestMailApi('POST', '/g-mail?params=x03e', ['email' => $literal]);
        $mailbox = is_array($data) ? ($data['email'] ?? null) : null;
        if (is_string($mailbox) && $mailbox !== '') {
            try {
                $mailbox = normalize_email_address($mailbox);
            } catch (Throwable $e) {
                // keep raw
            }
        }
        $token = is_array($data) ? normalize_hex_token($data['id'] ?? null) : null;

        // Sometimes response stays encrypted — surface raw for debugging
        if ($mailbox === null && is_array($data) && isset($data['_encrypted'])) {
            return [
                'status' => 200,
                'data' => [
                    'mailbox' => null,
                    'token' => null,
                    'service' => 'hd-gmail',
                    'note' => 'Response could not be fully decrypted',
                ],
                'raw' => $data,
            ];
        }

        return [
            'status' => 200,
            'data' => [
                'mailbox' => $mailbox,
                'token' => $token,
                'service' => 'hd-gmail',
            ],
            'raw' => $data,
        ];
    }
}

// ── hd-random ────────────────────────────────────────────────

final class HdRandomClient extends BaseHttpClient
{
    public function __construct(int $timeout = 20)
    {
        parent::__construct(HD_RANDOM_BASE, $timeout);
    }

    /** @return list<string> */
    public function getDomains(): array
    {
        return HD_RANDOM_SEEN_DOMAINS;
    }

    /** @return array{status:int,data:array,raw:mixed} */
    public function createEmail(): array
    {
        $data = $this->request('POST', '/mailbox');
        return [
            'status' => 200,
            'data' => [
                'mailbox' => is_array($data) ? ($data['mailbox'] ?? null) : null,
                'token' => is_array($data) ? ($data['token'] ?? null) : null,
                'service' => 'hd-random',
            ],
            'raw' => $data,
        ];
    }
}

// ── Universal reader (works for all mailbox types) ───────────

final class UniversalMailClient extends HdMailServerClient
{
    public function __construct(int $timeout = 20)
    {
        parent::__construct(HD_PREMIUM_BASE, $timeout);
    }

    /**
     * Inbox via universal HD endpoint.
     * email → 先 normalize 成字面量 @，再放进加密 params（加密前绝不是 %40）。
     * token 只做 path 编码。
     * @return array{status:int,data:array,raw:mixed}
     */
    public function getMessages(string $email, string $token): array
    {
        $literal = normalize_email_address($email);
        $path = '/email/' . encode_path_segment($token) . '/messages' . $this->buildParamsQuery($literal);
        $data = $this->requestMailApi('GET', $path);
        $messages = [];
        if (is_array($data)) {
            $isList = $data === [] || array_keys($data) === range(0, count($data) - 1);
            $messages = $isList ? $data : ($data['messages'] ?? []);
        }

        return [
            'status' => 200,
            'data' => [
                'mailbox' => $literal, // 响应永远返回字面量 @
                'messages' => $messages,
            ],
            'raw' => $data,
        ];
    }

    /** @return array{status:int,data:mixed,raw:mixed} */
    public function getMessage(string $email, string $token, string $messageId): array
    {
        $literal = normalize_email_address($email);
        $path = '/email/' . encode_path_segment($token)
            . '/messages/' . encode_path_segment($messageId)
            . $this->buildParamsQuery($literal);
        $data = $this->requestMailApi('GET', $path);
        return ['status' => 200, 'data' => $data, 'raw' => $data];
    }
}

// ── Helpers for the website layer ────────────────────────────

/**
 * Resolve which create service owns a domain.
 */
function resolve_service_for_domain(string $domain): string
{
    $domain = strtolower(ltrim($domain, '+'));
    $withPlus = '+' . $domain;

    if (in_array($domain, TEMP_MAIL_IO_DOMAINS, true)) {
        return 'temp-mail-io';
    }
    if (in_array($domain, HD_PREMIUM_DOMAINS, true)) {
        return 'hd-premium';
    }
    // gmail entries may be gmail.com / +gmail.com / googlemail.com / +googlemail.com
    if (
        in_array($domain, HD_GMAIL_ENTRY_DOMAINS, true)
        || in_array($withPlus, HD_GMAIL_ENTRY_DOMAINS, true)
        || $domain === 'gmail.com'
        || $domain === 'googlemail.com'
    ) {
        return 'hd-gmail';
    }
    if (in_array($domain, HD_RANDOM_SEEN_DOMAINS, true)) {
        return 'hd-random';
    }
    // default: try premium then io
    return 'hd-premium';
}

/**
 * @return array{status:int,data:array,raw?:mixed}
 */
function create_mailbox(
    string $service,
    ?string $email = null,
    ?string $name = null,
    ?string $domain = null,
    int $timeout = 20
): array {
    // 入口邮箱若存在，一律还原为字面量 @
    if (is_string($email) && $email !== '') {
        $email = normalize_email_address($email);
    } else {
        $email = null;
    }

    // 规范化 domain / name（禁止把 domain 弄丢后静默随机）
    if (is_string($domain)) {
        $domain = strtolower(trim($domain));
        $domain = ltrim($domain, '@');
        if ($domain === '' || $domain === 'random') {
            // random 仅 hd-random 使用
            if ($service !== 'hd-random') {
                $domain = null;
            }
        }
    } else {
        $domain = null;
    }
    if (is_string($name)) {
        $name = trim($name);
        if ($name === '') {
            $name = null;
        }
    } else {
        $name = null;
    }

    // 若给了 email，从中提取 domain，作为「用户指定域名」的权威来源
    $requestedDomain = $domain;
    if ($email) {
        [, $emailDomain] = split_email($email);
        $requestedDomain = strtolower($emailDomain);
    }

    switch ($service) {
        case 'temp-mail-io': {
            $client = new TempMailIoClient($timeout);
            // 用实时列表校验（含静态兜底）
            $liveIo = array_map('strtolower', $client->getDomains());
            if (!$email) {
                if (!$domain) {
                    throw new InvalidArgumentException(
                        'temp-mail-io 创建必须指定 domain（例如 bltiwd.com），或传完整 email'
                    );
                }
                if (!in_array($domain, $liveIo, true) && !in_array($domain, TEMP_MAIL_IO_DOMAINS, true)) {
                    // 仍交给 createEmail 用实时列表给明确错误
                }
                $local = $name ?: random_username(10);
                $email = $local . '@' . $domain;
            } else {
                if ($domain && $requestedDomain !== $domain) {
                    throw new InvalidArgumentException(
                        "email 域名 ({$requestedDomain}) 与 domain 参数 ({$domain}) 不一致"
                    );
                }
            }
            $result = $client->createEmail($email);
            break;
        }

        case 'hd-premium': {
            $client = new HdPremiumClient($timeout);
            if (!$email) {
                if (!$domain) {
                    throw new InvalidArgumentException('hd-premium 创建必须指定 domain 或完整 email');
                }
                if (!in_array($domain, HD_PREMIUM_DOMAINS, true)) {
                    throw new InvalidArgumentException("不是高级域名: {$domain}");
                }
                $email = ($name ?: random_username(10)) . '@' . $domain;
            }
            $result = $client->createEmail($email);
            // 校验返回域名
            $result = assert_mailbox_domain($result, $requestedDomain ?: $domain);
            break;
        }

        case 'hd-gmail': {
            $client = new HdGmailClient($timeout);
            if (!$email) {
                $entry = $domain ?: 'gmail.com';
                // 保留 +gmail.com 等形式
                $email = 'random@' . $entry;
            }
            $result = $client->createEmail($email);
            // gmail 会生成点别名，域名应为 gmail.com / googlemail.com，不强制等于入口
            break;
        }

        case 'hd-random': {
            $client = new HdRandomClient($timeout);
            $result = $client->createEmail();
            break;
        }

        default:
            throw new InvalidArgumentException("Unknown service: {$service}");
    }

    // 统一：对外 mailbox 永远是字面量 @
    if (isset($result['data']['mailbox']) && is_string($result['data']['mailbox']) && $result['data']['mailbox'] !== '') {
        try {
            $result['data']['mailbox'] = normalize_email_address($result['data']['mailbox']);
        } catch (Throwable $e) {
            // 上游异常格式时保留
        }
    }

    // 回传用户请求的域名，便于前端核对
    if ($requestedDomain) {
        $result['data']['requested_domain'] = $requestedDomain;
    }

    return $result;
}

/**
 * 若指定了期望域名，校验返回 mailbox 的域名一致。
 * @param array{status:int,data:array,raw?:mixed} $result
 * @return array{status:int,data:array,raw?:mixed}
 */
function assert_mailbox_domain(array $result, ?string $expectedDomain): array
{
    if (!$expectedDomain) {
        return $result;
    }
    $expectedDomain = strtolower(ltrim(trim($expectedDomain), '@'));
    // gmail 入口带 + 前缀时不在此校验
    if ($expectedDomain === '' || $expectedDomain[0] === '+') {
        return $result;
    }
    $mailbox = $result['data']['mailbox'] ?? null;
    if (!is_string($mailbox) || $mailbox === '') {
        return $result;
    }
    try {
        [, $got] = split_email($mailbox);
        if (strtolower($got) !== $expectedDomain) {
            throw new InstantMailError(502, [
                'error' => "返回邮箱域名与请求不一致（请求 {$expectedDomain}，返回 {$mailbox}）",
                'requested_domain' => $expectedDomain,
                'returned_mailbox' => $mailbox,
            ]);
        }
    } catch (InstantMailError $e) {
        throw $e;
    } catch (Throwable $e) {
        // ignore parse errors
    }
    return $result;
}

/** @return array<string, mixed> */
function list_all_domains(): array
{
    // 标准域：优先上游实时列表（避免 wnbaldwy.com 这类已下线域名进下拉）
    $ioLive = TEMP_MAIL_IO_DOMAINS;
    try {
        $ioLive = (new TempMailIoClient(12))->getDomains();
        if (!$ioLive) {
            $ioLive = TEMP_MAIL_IO_DOMAINS;
        }
    } catch (Throwable $e) {
        $ioLive = TEMP_MAIL_IO_DOMAINS;
    }
    // 去重 + 小写规范化展示
    $ioLive = array_values(array_unique(array_map(static function ($d) {
        return strtolower((string) $d);
    }, $ioLive)));

    return [
        'temp-mail-io' => [
            'count' => count($ioLive),
            'type' => '可指定创建（实时）',
            'domains' => $ioLive,
            'retired' => TEMP_MAIL_IO_RETIRED_DOMAINS,
            'note' => '已下线域名（如 wnbaldwy.com）不会出现在列表；强行创建会被上游随机改域',
        ],
        'hd-premium' => [
            'count' => count(HD_PREMIUM_DOMAINS),
            'type' => '可指定创建 (需加密协议)',
            'domains' => HD_PREMIUM_DOMAINS,
        ],
        'hd-gmail' => [
            'count' => count(HD_GMAIL_ENTRY_DOMAINS),
            'type' => 'Gmail 点别名生成入口',
            'domains' => HD_GMAIL_ENTRY_DOMAINS,
        ],
        'hd-random' => [
            'count' => count(HD_RANDOM_SEEN_DOMAINS),
            'type' => '随机 (域名由服务端分配)',
            'domains' => HD_RANDOM_SEEN_DOMAINS,
        ],
        'total_stable_domains' => count($ioLive) + count(HD_PREMIUM_DOMAINS),
        'total_entry_points' => count($ioLive) + count(HD_PREMIUM_DOMAINS)
            + count(HD_GMAIL_ENTRY_DOMAINS) + count(HD_RANDOM_SEEN_DOMAINS),
        'flat' => array_merge(
            array_map(static function ($d) {
                return ['domain' => $d, 'service' => 'temp-mail-io', 'label' => $d];
            }, $ioLive),
            array_map(static function ($d) {
                return ['domain' => $d, 'service' => 'hd-premium', 'label' => $d . ' ★'];
            }, HD_PREMIUM_DOMAINS),
            array_map(static function ($d) {
                return ['domain' => $d, 'service' => 'hd-gmail', 'label' => $d . ' (Gmail)'];
            }, HD_GMAIL_ENTRY_DOMAINS),
            [['domain' => 'random', 'service' => 'hd-random', 'label' => '完全随机']]
        ),
    ];
}
