<?php
/**
 * 文件缓存 + 按 IP 限流。
 *
 * 共享主机没有 APCu / Redis，用文件即可。所有失败都静默降级——
 * 缓存目录不可写时站点仍应正常工作，只是慢一点。
 */

declare(strict_types=1);

if (!defined('BEATMAIL_APP')) {
    http_response_code(404);
    exit;
}

/**
 * 缓存文件一律用 .php 扩展名，并以 CACHE_GUARD 开头。
 *
 * 虚拟主机改不了 nginx 规则时，这是唯一可靠的防下载手段：
 * 直接访问 → PHP 执行到 exit 立即结束，只吐出空白；
 * 程序读取 → 跳过前缀取后面的 JSON。
 * 纯 .json 会被 nginx 当静态文件原样返回，绝不能用。
 */
const CACHE_GUARD = "<?php exit; ?>\n";

function cache_dir(): ?string
{
    static $dir = false;
    if ($dir !== false) {
        return $dir;
    }

    $path = CACHE_DIR;
    if (!is_dir($path)) {
        @mkdir($path, 0755, true);
    }
    $dir = (is_dir($path) && is_writable($path)) ? $path : null;
    return $dir;
}

function cache_path(string $key): ?string
{
    $dir = cache_dir();
    if ($dir === null) {
        return null;
    }
    // 强制 .php 扩展，杜绝生成可被直接下载的 .json
    $safe = preg_replace('/[^a-z0-9_-]/i', '_', $key);
    return $dir . '/' . $safe . '.php';
}

/**
 * 读缓存。未命中/过期/损坏都返回 null。
 * @return mixed
 */
function cache_get(string $key, int $ttl)
{
    $file = cache_path($key);
    if ($file === null || !is_file($file)) {
        return null;
    }
    $age = time() - (int) @filemtime($file);
    if ($age > $ttl) {
        return null;
    }
    $raw = @file_get_contents($file);
    if (!is_string($raw) || $raw === '') {
        return null;
    }
    // 剥掉 PHP 守卫前缀
    if (strncmp($raw, CACHE_GUARD, strlen(CACHE_GUARD)) === 0) {
        $raw = substr($raw, strlen(CACHE_GUARD));
    }
    if ($raw === '') {
        return null;
    }
    $data = json_decode($raw, true);
    return json_last_error() === JSON_ERROR_NONE ? $data : null;
}

/** @param mixed $value */
function cache_set(string $key, $value): void
{
    $file = cache_path($key);
    if ($file === null) {
        return;
    }
    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return;
    }
    // 先写临时文件再 rename，避免并发读到半截内容
    $tmp = $file . '.' . getmypid() . '.tmp';
    if (@file_put_contents($tmp, CACHE_GUARD . $json, LOCK_EX) !== false) {
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
        }
    }
}

/**
 * 取缓存，未命中则调用 $producer 并回填。
 * producer 抛异常时向上传播，不写入缓存。
 * @return mixed
 */
function cache_remember(string $key, int $ttl, callable $producer)
{
    $hit = cache_get($key, $ttl);
    if ($hit !== null) {
        return $hit;
    }
    $value = $producer();
    if ($value !== null) {
        cache_set($key, $value);
    }
    return $value;
}

/**
 * 取客户端 IP。
 *
 * 只在确实位于反向代理后面时才该信任 XFF；这里默认取 REMOTE_ADDR，
 * 因为伪造 XFF 就能绕开限流。
 */
function client_ip(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    return is_string($ip) && $ip !== '' ? $ip : '0.0.0.0';
}

/**
 * 固定窗口限流。
 *
 * @return array{allowed:bool,remaining:int,retry_after:int,limit:int}
 */
function rate_limit_check(string $bucket, int $max, int $window): array
{
    $ok = ['allowed' => true, 'remaining' => $max, 'retry_after' => 0, 'limit' => $max];

    if (!RATE_LIMIT_ENABLED || cache_dir() === null) {
        return $ok;
    }

    $key = 'rl_' . $bucket . '_' . substr(hash('sha256', client_ip()), 0, 16);
    $file = cache_path($key);
    if ($file === null) {
        return $ok;
    }

    $fh = @fopen($file, 'c+');
    if ($fh === false) {
        return $ok;
    }
    if (!@flock($fh, LOCK_EX)) {
        fclose($fh);
        return $ok;
    }

    $now = time();
    $raw = stream_get_contents($fh);
    if (is_string($raw) && strncmp($raw, CACHE_GUARD, strlen(CACHE_GUARD)) === 0) {
        $raw = substr($raw, strlen(CACHE_GUARD));
    }
    $state = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;

    $start = is_array($state) && isset($state['start']) ? (int) $state['start'] : 0;
    $count = is_array($state) && isset($state['count']) ? (int) $state['count'] : 0;

    if ($start <= 0 || ($now - $start) >= $window) {
        $start = $now;
        $count = 0;
    }
    $count++;

    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, CACHE_GUARD . json_encode(['start' => $start, 'count' => $count]));
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);

    $retryAfter = max(1, $window - ($now - $start));

    return [
        'allowed' => $count <= $max,
        'remaining' => max(0, $max - $count),
        'retry_after' => $retryAfter,
        'limit' => $max,
    ];
}

/**
 * 清掉过期的限流文件，避免目录无限增长。
 * 抽样触发，不必每次请求都扫。
 */
function cache_gc(int $probability = 50): void
{
    $dir = cache_dir();
    if ($dir === null || random_int(1, $probability) !== 1) {
        return;
    }
    $files = @glob($dir . '/rl_*.php');
    if (!is_array($files)) {
        return;
    }
    $cutoff = time() - (RATE_LIMIT_WINDOW * 5);
    foreach ($files as $f) {
        if ((int) @filemtime($f) < $cutoff) {
            @unlink($f);
        }
    }
    // 清掉可能残留的旧 .json 缓存（升级前的格式，可被直接下载）
    foreach ((array) @glob($dir . '/*.json') as $stale) {
        @unlink($stale);
    }
}
