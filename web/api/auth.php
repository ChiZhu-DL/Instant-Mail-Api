<?php
/**
 * 鉴权：本站页面免密，外部调用需 Authorization: Bearer <API_KEY>。
 *
 * 同源判断以 Sec-Fetch-Site 为准（浏览器盖章，JS 改不了）。
 * 2026-10-24 加固：无 Sec-Fetch-Site 时（老浏览器或 curl 等命令行工具），
 * 不再凭 Origin/Referer 放行本站自身——该头可被 curl 任意伪造，
 * 实测 `curl -H "Origin: https://站点"` 即可白嫖免密接口。
 * 现在只有显式配置在 API_EXTRA_ALLOWED_HOSTS 里的外部合作域名
 * 才走 Origin/Referer 兜底；本站页面请求一律要求 Sec-Fetch-Site。
 * (Sec-Fetch-Site 自 2021 年起全主流浏览器支持；极老浏览器请用 Bearer 调用)
 */

declare(strict_types=1);

if (!defined('BEATMAIL_APP')) {
    http_response_code(404);
    exit;
}

/** 本站 host（含端口时一并比较 host 部分） */
function site_host(): string
{
    $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? '');
    if (!is_string($host)) {
        return '';
    }
    $host = strtolower(trim($host));
    // 去掉端口
    if (($pos = strrpos($host, ':')) !== false && strpos($host, ']') === false) {
        $host = substr($host, 0, $pos);
    }
    return $host;
}

/** 从 URL 里取 host */
function url_host(string $url): string
{
    $h = parse_url(trim($url), PHP_URL_HOST);
    return is_string($h) ? strtolower($h) : '';
}

/** 请求是否来自本站页面 */
function is_same_origin_request(): bool
{
    $self = site_host();
    if ($self === '') {
        return false;
    }

    // 兜底只认显式配置的外部白名单，本站自身不再进兜底列表（防 Origin 伪造）
    $allowed = array_map('strtolower', API_EXTRA_ALLOWED_HOSTS);

    /**
     * Sec-Fetch-Site 是 forbidden header name，页面 JS 无法伪造，
     * 由浏览器自己盖章，比 Origin / Referer 可靠。现代浏览器都会发。
     * curl 等命令行工具默认不带，因此不影响「外部必须带密钥」。
     */
    $fetchSite = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? '';
    if (is_string($fetchSite) && $fetchSite !== '') {
        $fetchSite = strtolower(trim($fetchSite));
        if ($fetchSite === 'same-origin') {
            return true;
        }
        // 明确来自别的站点（或直接粘贴地址栏 none）：只认白名单 Origin
        return in_array(url_host((string) ($_SERVER['HTTP_ORIGIN'] ?? '')), $allowed, true);
    }

    // 无 Sec-Fetch-Site（老浏览器或 curl）：Origin/Referer 只认外部白名单，
    // 不再放行本站自身 host——防止伪造 Origin 免密调用
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if (is_string($origin) && $origin !== '') {
        return in_array(url_host($origin), $allowed, true);
    }

    // 同源 GET 常常没有 Origin，退回 Referer（同样只认外部白名单）
    // 前提：Referrer-Policy 不能是 no-referrer，否则这里拿不到值
    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    if (is_string($referer) && $referer !== '') {
        return in_array(url_host($referer), $allowed, true);
    }

    return false;
}

/** 取 Authorization: Bearer 的 token */
function bearer_token(): string
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');

    // 部分 CGI/FastCGI 不透传 Authorization，需从 apache_request_headers 兜底
    if ((!is_string($header) || $header === '') && function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        if (is_array($headers)) {
            foreach ($headers as $k => $v) {
                if (strcasecmp((string) $k, 'Authorization') === 0) {
                    $header = (string) $v;
                    break;
                }
            }
        }
    }

    if (!is_string($header) || $header === '') {
        return '';
    }
    if (preg_match('/^\s*Bearer\s+(.+)$/i', $header, $m)) {
        return trim($m[1]);
    }
    return '';
}

/**
 * @return array{ok:bool,reason:string,via:string}
 */
function check_auth(): array
{
    if (!API_AUTH_ENABLED) {
        return ['ok' => true, 'reason' => '', 'via' => 'public'];
    }

    if (API_ALLOW_SAME_ORIGIN && is_same_origin_request()) {
        return ['ok' => true, 'reason' => '', 'via' => 'same-origin'];
    }

    $token = bearer_token();
    if ($token === '') {
        return [
            'ok' => false,
            'reason' => '缺少凭证。外部调用需携带 Authorization: Bearer <API_KEY>',
            'via' => 'none',
        ];
    }

    if (!hash_equals(API_KEY, $token)) {
        return ['ok' => false, 'reason' => 'API 密钥无效', 'via' => 'bad-key'];
    }

    return ['ok' => true, 'reason' => '', 'via' => 'api-key'];
}
