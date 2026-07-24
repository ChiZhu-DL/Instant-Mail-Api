<?php
/**
 * Offline crypto parity check (no network).
 * Run: php tools/crypto_selftest.php
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/api/InstantMail.php';

function assert_true(bool $cond, string $msg): void
{
    if (!$cond) {
        fwrite(STDERR, "FAIL: {$msg}\n");
        exit(1);
    }
    echo "OK: {$msg}\n";
}

$empty = InstantMailCrypto::encrypt([]);
$round = InstantMailCrypto::decrypt($empty);
assert_true(is_array($round), 'decrypt empty payload');

$payload = ['email' => 'test@gmail.com'];
$enc = InstantMailCrypto::encrypt($payload);
$dec = InstantMailCrypto::decrypt($enc);
assert_true(is_array($dec) && ($dec['email'] ?? null) === 'test@gmail.com', 'email roundtrip');

$email = 'zu.leik.a.dene.k.e@gmail.com';
$path = encode_email_path($email);
assert_true($path === 'zu.leik.a.dene.k.e%40gmail.com', 'email path encodes @ as %40 got=' . $path);

$token = '4d4a008c5b9ec9800d9bf041bb46aaeb';
assert_true(encode_path_segment($token) === $token, 'hex token stays unescaped');

// buildParamsQuery must not contain raw @
$client = new UniversalMailClient(5);
$ref = new ReflectionClass($client);
$m = $ref->getMethod('buildParamsQuery');
$m->setAccessible(true);
$q = $m->invoke($client, $email);
assert_true(strpos($q, '@') === false, 'params query has no raw @');
assert_true(strpos($q, '?params=') === 0, 'params query prefix');

// list domains flat
$all = list_all_domains();
assert_true(($all['total_stable_domains'] ?? 0) === 17, '17 stable domains');
assert_true(count($all['flat']) >= 18, 'flat domain list');

// resolve services
assert_true(resolve_service_for_domain('bltiwd.com') === 'temp-mail-io', 'resolve io');
assert_true(resolve_service_for_domain('tempmail.edu.pl') === 'hd-premium', 'resolve premium');
assert_true(resolve_service_for_domain('gmail.com') === 'hd-gmail', 'resolve gmail');

echo "\nAll self-tests passed.\n";
