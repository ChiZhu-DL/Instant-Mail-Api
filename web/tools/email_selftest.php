<?php
/**
 * 邮箱 @ 规范化 / 防双重编码 自测（无需网络、无需 PHP 在 PATH 时也可上传到主机跑）
 *
 * 浏览器访问: https://你的域名/tools/email_selftest.php
 * 测完请删除本文件或靠 Nginx/Apache 规则 deny /tools/
 */
declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');

require_once dirname(__DIR__) . '/api/InstantMail.php';

$failed = 0;

function check(bool $cond, string $msg): void
{
    global $failed;
    if ($cond) {
        echo "OK  {$msg}\n";
    } else {
        echo "FAIL {$msg}\n";
        $failed++;
    }
}

echo "=== BeatMail email @ contract self-test ===\n\n";

// 1) 字面量
$a = normalize_email_address('User.Name@bltiwd.com');
check($a === 'User.Name@bltiwd.com', "literal stays literal: {$a}");

// 2) 单次 %40
$b = normalize_email_address('user%40bltiwd.com');
check($b === 'user@bltiwd.com', "decode %40 once: {$b}");

// 3) 双重编码 %2540
$c = normalize_email_address('user%2540bltiwd.com');
check($c === 'user@bltiwd.com', "decode double %2540: {$c}");

// 4) 混合
$d = normalize_email_address('zu.leik.a.dene.k.e%40gmail.com');
check($d === 'zu.leik.a.dene.k.e@gmail.com', "gmail alias decode: {$d}");

// 5) encode path 只编码一次
$path = encode_email_path('user@bltiwd.com');
check($path === 'user%40bltiwd.com', "path encode once: {$path}");

// 6) 对已是 %40 的输入 encode 也不会双重编码（内部先 normalize）
$path2 = encode_email_path('user%40bltiwd.com');
check($path2 === 'user%40bltiwd.com', "no double-encode: {$path2}");
check(strpos($path2, '%2540') === false, "no %2540 in path");

// 7) 加密 payload 内必须是字面量 @
$enc = InstantMailCrypto::encrypt(['email' => email_for_payload('user%40bltiwd.com')]);
$dec = InstantMailCrypto::decrypt($enc);
check(is_array($dec) && ($dec['email'] ?? '') === 'user@bltiwd.com', 'encrypt payload uses literal @');

// 8) buildParamsQuery 密文 query 无裸 @，且解密后是字面量
$client = new UniversalMailClient(5);
$ref = new ReflectionClass($client);
$m = $ref->getMethod('buildParamsQuery');
$m->setAccessible(true);
$q = $m->invoke($client, 'user%40gmail.com');
check(strpos($q, '@') === false, 'params query has no raw @ char');
check(strpos($q, '?params=') === 0, 'params prefix ok');
// 抽出密文并解密
$cipher = rawurldecode(substr($q, strlen('?params=')));
$payload = InstantMailCrypto::decrypt($cipher);
check(is_array($payload) && ($payload['email'] ?? '') === 'user@gmail.com', 'params decrypt email is literal @');

// 9) 业务层拒绝「像邮箱但无 @」
try {
    normalize_email_address('not-an-email');
    check(false, 'should reject no-at');
} catch (InvalidArgumentException $e) {
    check(true, 'reject no-at');
}

// 10) JSON 响应形态模拟（@ 不被转义成别的）
$json = json_encode(['mailbox' => 'a@b.com'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
check($json === '{"mailbox":"a@b.com"}', 'JSON keeps literal @');

echo "\n=== result: " . ($failed === 0 ? 'ALL PASSED' : "{$failed} FAILED") . " ===\n";
if ($failed > 0) {
    http_response_code(500);
    exit(1);
}
