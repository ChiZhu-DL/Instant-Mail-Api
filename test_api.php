<?php
/**
 * 测试 API 接口 - 模拟 get_stored_emails 返回
 */
require_once __DIR__ . '/config.php';

$EMAILS_FILE = __DIR__ . '/cache/emails.json';

echo "<h2>API 接口测试</h2>";

echo "<h3>1. 文件检查</h3>";
echo "<p>文件路径: $EMAILS_FILE</p>";
echo "<p>文件存在: " . (file_exists($EMAILS_FILE) ? '✅ YES' : '❌ NO') . "</p>";

if (file_exists($EMAILS_FILE)) {
    $content = file_get_contents($EMAILS_FILE);
    echo "<h3>2. 文件内容</h3>";
    echo "<pre>" . htmlspecialchars($content) . "</pre>";
    
    echo "<h3>3. JSON 解析测试</h3>";
    $data = json_decode($content, true);
    if (is_array($data)) {
        echo "<p>✅ 解析成功，包含 " . count($data) . " 个邮箱</p>";
        echo "<h3>4. 模拟 API 响应 (api.php?action=get_stored_emails)</h3>";
        
        // 模拟 api.php 的响应
        require_once __DIR__ . '/InstantMailAPI.php';
        $emails = getStoredEmails();
        $apiResponse = [
            'success' => true,
            'total' => count($emails),
            'data' => $emails
        ];
        echo "<pre>" . json_encode($apiResponse, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "</pre>";
        
        echo "<h3>5. 邮箱列表</h3>";
        echo "<table border='1' style='border-collapse:collapse;'>";
        echo "<tr><th>邮箱</th><th>域名</th><th>服务</th><th>创建时间</th><th>Token</th></tr>";
        foreach ($emails as $e) {
            echo "<tr>";
            echo "<td>" . ($e['email'] ?? 'N/A') . "</td>";
            echo "<td>" . ($e['domain'] ?? 'N/A') . "</td>";
            echo "<td>" . ($e['service'] ?? 'N/A') . "</td>";
            echo "<td>" . ($e['created_at'] ?? 'N/A') . "</td>";
            echo "<td>" . substr($e['token'] ?? '', 0, 10) . "...</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p>❌ JSON 解析失败</p>";
    }
}

echo "<h3>6. 创建测试邮箱到存储</h3>";
echo "<form method='post'>";
echo "<input type='submit' name='test_create' value='创建一个测试邮箱'>";
echo "</form>";

if (isset($_POST['test_create'])) {
    require_once __DIR__ . '/InstantMailAPI.php';
    
    $testEmail = 'test_' . time() . '@bltiwd.com';
    $testToken = 'tk_' . md5(time());
    addEmailToStorage($testEmail, $testToken, 'bltiwd.com', 'public');
    
    echo "<p>✅ 已添加测试邮箱: <strong>$testEmail</strong></p>";
    echo "<p><a href='?'>刷新</a> 查看是否在列表中</p>";
}

echo "<hr>";
echo "<h3>问题诊断</h3>";
echo "<ul>";
echo "<li>如果这里能看到邮箱，但网页上看不到：问题在前端 JavaScript 加载逻辑</li>";
echo "<li>如果这里也看不到邮箱：问题在后端文件存储或路径</li>";
echo "</ul>";

echo "<hr>";
echo "<h3>前端加载流程测试</h3>";
echo "<p>请在浏览器访问 index.php 后，按 F12 打开控制台，查看 JavaScript 日志</p>";
echo "<p>特别关注：'从后端加载 X 个邮箱' 这条消息</p>";
?>
