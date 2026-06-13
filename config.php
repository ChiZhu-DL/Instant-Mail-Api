<?php
/**
 * Instant Mail API 管理系统 - 配置文件
 */

// 基础配置
define('APP_NAME', 'Instant Mail API 管理系统');
define('APP_VERSION', '2.0.0');
define('APP_TIMEZONE', 'Asia/Shanghai');

// API 配置
define('API_TIMEOUT', 30);
define('API_CACHE_ENABLE', true);
define('API_CACHE_TTL', 300); // 5分钟
// API 调用密钥 — curl/脚本调用时通过 X-API-Key 头传入即可绕过 CSRF
define('API_SECRET_KEY', 'tmpmail_' . md5(__DIR__));

// 域名分类
$DOMAIN_CATEGORIES = [
    'public' => [
        'name' => 'Public 域名',
        'description' => '稳定的公开域名，推荐使用',
        'icon' => 'fa-solid fa-globe',
        'color' => '#2563eb',
        'domains' => ['bltiwd.com', 'wnbaldwy.com', 'bwmyga.com', 'ozsaip.com', 'yzcalo.com', 'lnovic.com', 'ruutukf.com', 'gmeenramy.com']
    ],
    'hd' => [
        'name' => 'HD 域名',
        'description' => '高级域名，需要解码支持',
        'icon' => 'fa-solid fa-gem',
        'color' => '#7c3aed',
        'domains' => ['gmail10p.com', 'oegmail.com', 'oletters.com', 'oemailbox.com', 'ohotmail.com', 'omailforce.com', 'oboxmail.com', 'oyahoo.com', 'ooutlook.com', 'oemails.com', 'suiemail.com', 'voewo.com', 'yanemail.com', 'tempmail.edu.pl', 'rommiui.com']
    ],
    'hot' => [
        'name' => '热门类域名',
        'description' => '类似 Gmail 的热门域名',
        'icon' => 'fa-solid fa-fire',
        'color' => '#ef4444',
        'domains' => ['gmail.com', 'outlook.com', 'protonmail.com', 'icloud.com']
    ],
    'google' => [
        'name' => '谷歌变体',
        'description' => 'Google 邮箱变体',
        'icon' => 'fa-brands fa-google',
        'color' => '#f59e0b',
        'domains' => ['googlemail.com', '+gmail.com', '+googlemail.com']
    ]
];

// 设置时区
date_default_timezone_set(APP_TIMEZONE);

// 错误处理
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// 工具函数
function json_response($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function log_message($message, $level = 'info') {
    $logDir = __DIR__ . '/cache';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    $logFile = $logDir . '/app.log';
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[{$timestamp}] [{$level}] {$message}" . PHP_EOL;
    @file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
}

?>
