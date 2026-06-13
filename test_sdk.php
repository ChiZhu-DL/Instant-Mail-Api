<?php
/**
 * PHP SDK 完整功能测试
 */

require_once __DIR__ . '/InstantMailAPI.php';

$api = new InstantMailAPI(30, true);
$totalTests = 0;
$passedTests = 0;

function test($name, $callback) {
    global $totalTests, $passedTests;
    $totalTests++;
    
    echo "【测试】{$name}... ";
    try {
        $result = $callback();
        if ($result !== false) {
            echo "✓ 通过\n";
            $passedTests++;
            return true;
        } else {
            echo "✗ 失败\n";
            return false;
        }
    } catch (Exception $e) {
        echo "✗ 异常: " . $e->getMessage() . "\n";
        return false;
    }
}

echo "======================================================================\n";
echo "  Instant Mail API - PHP SDK 完整功能测试\n";
echo "======================================================================\n\n";

// 测试邮箱
$testEmail = "czqyol695b3tqcoj@bwmyga.com";
$testToken = "7Aomopvh1MFxySpd7YgJ";

// === 1. 域名管理测试 ===
echo "【1】域名管理测试\n";
echo str_repeat('-', 70) . "\n";

test('获取所有域名', function() use ($api) {
    $domains = $api->getDomains();
    return is_array($domains) && count($domains) >= 8;
});

test('获取 Public 域名', function() use ($api) {
    $domains = $api->getDomains(false, 'public');
    return is_array($domains) && count($domains) == 8;
});

test('获取 HD 域名', function() use ($api) {
    $domains = $api->getDomains(false, 'hd');
    return is_array($domains) && count($domains) == 15;
});

test('获取随机域名', function() use ($api) {
    $domain = $api->getRandomDomain('public');
    return !empty($domain) && strpos($domain, '.') !== false;
});

echo "\n";

// === 2. 邮箱创建测试 ===
echo "【2】邮箱创建测试\n";
echo str_repeat('-', 70) . "\n";

$createdEmail = null;
$createdToken = null;

test('创建随机 Public 邮箱', function() use ($api, &$createdEmail, &$createdToken) {
    $result = $api->createEmail();
    if (is_array($result) && isset($result['email']) && isset($result['token'])) {
        $createdEmail = $result['email'];
        $createdToken = $result['token'];
        echo "({$result['email']}) ";
        return true;
    }
    return false;
});

test('创建指定域名的邮箱', function() use ($api) {
    $result = $api->createEmail(null, 'bltiwd.com');
    if (is_array($result) && isset($result['email'])) {
        echo "({$result['email']}) ";
        return true;
    }
    return false;
});

test('创建带自定义用户名的邮箱', function() use ($api) {
    $result = $api->createEmail('testuser_' . time(), 'bltiwd.com');
    if (is_array($result) && isset($result['email'])) {
        echo "({$result['email']}) ";
        return true;
    }
    return false;
});

echo "\n";

// === 3. 邮件管理测试 ===
echo "【3】邮件管理测试\n";
echo str_repeat('-', 70) . "\n";

test("获取邮件列表 ({$testEmail})", function() use ($api, $testEmail) {
    $result = $api->getMessages($testEmail);
    echo "(找到 " . (is_array($result) ? count($result) : 0) . " 封) ";
    return is_array($result);
});

test("获取邮箱统计", function() use ($api, $testEmail) {
    $stats = $api->getStats($testEmail);
    echo "(总邮件: {$stats['total_messages']}) ";
    return is_array($stats) && isset($stats['total_messages']);
});

echo "\n";

// === 4. 批量操作测试 ===
echo "【4】批量操作测试\n";
echo str_repeat('-', 70) . "\n";

test('批量创建 3 个邮箱', function() use ($api) {
    $result = $api->createMultipleEmails(3);
    if (is_array($result) && isset($result['success_count'])) {
        echo "({$result['success_count']}/{$result['total']}) ";
        return true;
    }
    return false;
});

echo "\n";

// === 5. API 状态测试 ===
echo "【5】API 状态测试\n";
echo str_repeat('-', 70) . "\n";

test('获取 API 服务状态', function() use ($api) {
    $status = $api->getAPIStatus();
    return is_array($status) && isset($status['public']);
});

echo "\n";

// === 6. 邮箱删除测试 ===
echo "【6】邮箱删除测试\n";
echo str_repeat('-', 70) . "\n";

if ($createdEmail && $createdToken) {
    test('删除刚刚创建的邮箱', function() use ($api, $createdEmail, $createdToken) {
        $result = $api->deleteEmail($createdEmail, $createdToken);
        return is_array($result);
    });
} else {
    echo "【跳过】删除邮箱测试 (未创建邮箱)\n";
}

echo "\n";

// === 结果汇总 ===
echo str_repeat('=', 70) . "\n";
echo "【测试结果汇总】\n";
echo str_repeat('-', 70) . "\n";
echo "总计: {$totalTests} 个测试\n";
echo "通过: {$passedTests}\n";
echo "失败: " . ($totalTests - $passedTests) . "\n";
echo str_repeat('=', 70) . "\n\n";

if ($passedTests == $totalTests) {
    echo "✅ 所有测试通过！PHP SDK 功能完整，可以正常使用。\n";
} else {
    echo "⚠️  有测试失败，请检查 SDK 代码。\n";
}

echo "\n" . str_repeat('=', 70) . "\n";
