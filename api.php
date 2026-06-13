<?php
/**
 * Instant Mail API - 后端 API 处理
 * 处理前端的 AJAX 请求
 */

// 必须在任何输出之前开启输出缓冲 —— 防止 "Headers already sent"
ob_start();

require_once __DIR__ . '/InstantMailAPI.php';

// 明确清除缓冲中任何可能存在的意外输出（BOM、warning 等）
if (ob_get_length()) {
    ob_clean();
}

header('Content-Type: application/json; charset=utf-8');
// CORS: 限制为当前站点（根据实际域名修改）
$allowedOrigin = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
header('Access-Control-Allow-Origin: ' . $allowedOrigin);
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, x-app-key, x-csrf-token');
// 防止 nginx/浏览器/CDN 缓存 GET 请求的 API 响应
header('Cache-Control: no-cache, no-store, must-revalidate, private');
header('Pragma: no-cache');
header('Expires: 0');

// 处理预检请求
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ——— CSRF Token（基于 cookie，不依赖 session）———
// 从 cookie 读取 token（前端每次请求自动带上 cookie）
$CSRF_TOKEN = $_COOKIE['csrf_token'] ?? '';

// ——— 工具函数 ———
function api_rawurlencode_email($email) {
    return rawurlencode($email);
}

function api_random_suffix() {
    if (function_exists('random_bytes')) {
        try {
            return bin2hex(random_bytes(8));
        } catch (Throwable $e) {
            // fallthrough
        }
    }
    return dechex(time()) . dechex(mt_rand());
}

function api_sanitize_error($message) {
    // 去除可能的路径信息，只保留安全的错误描述
    $message = preg_replace('# in .*\.php(:\d+)?#i', '', $message);
    $message = preg_replace('#[A-Z]:\\\\[^\s]+#', '', $message);
    $message = preg_replace('#/[^\s]+\.php#', '', $message);
    return $message;
}

// ——— 存储文件路径 ———
define('EMAILS_STORAGE_FILE', __DIR__ . '/cache/emails.json');
define('EMAILS_LOCK_FILE', __DIR__ . '/cache/emails.lock');

// 确保缓存目录存在且可写
$cacheDir = __DIR__ . '/cache';
if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0755, true);
}
if (is_dir($cacheDir) && !is_writable($cacheDir)) {
    @chmod($cacheDir, 0755);
}

// 读取邮箱列表（带共享锁 / 空文件容错 / 损坏文件自动重建）
function getStoredEmails() {
    $file = EMAILS_STORAGE_FILE;
    if (!file_exists($file)) {
        return [];
    }

    // 尝试加共享锁读取
    $fp = @fopen($file, 'r');
    if (!$fp) {
        $raw = @file_get_contents($file);
        if ($raw === false || trim($raw) === '') {
            return [];
        }
    } else {
        $locked = @flock($fp, LOCK_SH);
        $raw = '';
        while (!feof($fp)) {
            $raw .= fread($fp, 8192);
        }
        if ($locked) {
            @flock($fp, LOCK_UN);
        }
        fclose($fp);
    }

    if ($raw === '' || trim($raw) === '') {
        return [];
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        // 文件损坏 —— 尝试在锁内安全重置
        try {
            with_emails_lock(function () use ($file) {
                @file_put_contents(
                    $file,
                    json_encode([], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
                    LOCK_EX
                );
            });
        } catch (RuntimeException $e) {
            // 无法获取锁，跳过重置，返回空即可
        }
        return [];
    }

    // 过滤无效条目
    $clean = [];
    foreach ($data as $item) {
        if (is_array($item) && !empty($item['email'])) {
            $clean[] = $item;
        }
    }
    return $clean;
}

// 保存邮箱列表（先写临时文件 + rename 原子替换，再 fflush 落盘）
function saveEmails($emails) {
    $file = EMAILS_STORAGE_FILE;
    $dir = dirname($file);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    $json = json_encode($emails, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    $tmp = $file . '.tmp.' . api_random_suffix();
    $written = @file_put_contents($tmp, $json, LOCK_EX);
    if ($written === false) {
        @unlink($tmp);
        return false;
    }

    // 尝试强制落盘（Windows 下可能不支持 fflush，但无害）
    if ($fh = @fopen($tmp, 'c')) {
        @fflush($fh);
        fclose($fh);
    }

    if (!@rename($tmp, $file)) {
        // rename 失败（跨卷/权限问题）时退回到直接覆写（已在 with_emails_lock 内，有锁保护）
        @file_put_contents($file, $json, LOCK_EX);
        @unlink($tmp);
    }

    @chmod($file, 0644);
    return true;
}

// 在独占锁下执行一个回调（原子读-修改-写）
function with_emails_lock(callable $fn) {
    $lockFile = EMAILS_LOCK_FILE;
    $fp = @fopen($lockFile, 'c');
    if (!$fp) {
        throw new RuntimeException('无法获取锁文件，系统繁忙，请稍后重试');
    }
    if (!@flock($fp, LOCK_EX)) {
        fclose($fp);
        throw new RuntimeException('无法获取文件锁，系统繁忙，请稍后重试');
    }
    try {
        return $fn();
    } finally {
        @flock($fp, LOCK_UN);
        fclose($fp);
    }
}

// 添加邮箱到存储（原子读-判断-写）
function addEmailToStorage($email, $token, $domain, $service) {
    if (empty($email)) {
        return false;
    }
    return with_emails_lock(function () use ($email, $token, $domain, $service) {
        $emails = getStoredEmails();
        $exists = false;
        foreach ($emails as $e) {
            if (isset($e['email']) && $e['email'] === $email) {
                $exists = true;
                break;
            }
        }
        if (!$exists) {
            $emailData = [
                'email' => $email,
                'token' => (string)$token,
                'domain' => (string)$domain,
                'service' => (string)$service,
                'created_at' => date('Y-m-d H:i:s'),
                'status' => 'active'
            ];
            array_unshift($emails, $emailData);
            if (count($emails) > 100) {
                $emails = array_slice($emails, 0, 100);
            }
            saveEmails($emails);
        }
        return true;
    });
}

// 从存储中删除邮箱（原子操作）
function removeEmailFromStorage($email) {
    if (empty($email)) {
        return false;
    }
    return with_emails_lock(function () use ($email) {
        $emails = getStoredEmails();
        $emails = array_values(array_filter($emails, function ($e) use ($email) {
            return isset($e['email']) && $e['email'] !== $email;
        }));
        saveEmails($emails);
        return true;
    });
}

// 清空所有邮箱
function clearAllEmails() {
    return with_emails_lock(function () {
        saveEmails([]);
        return true;
    });
}

// ——— 请求路由 ———
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// 破坏性操作必须使用 POST，防止被 <img src=...>、访问链接等触发
$destructiveActions = ['delete_email', 'clear_emails', 'remove_email_record', 'add_email', 'clear_cache', 'create_email', 'create_gmail', 'batch_create', 'quick_create'];
if ($method !== 'POST' && in_array($action, $destructiveActions, true)) {
    http_response_code(405);
    json_response([
        'success' => false,
        'error' => '该操作必须使用 POST 请求'
    ]);
}

// CSRF 验证：POST 请求需要 token 或 API Key
// 通过 X-API-Key 头传入 API_SECRET_KEY 即可绕过 CSRF（适用于 curl / 脚本调用）
if ($method === 'POST') {
    $apiKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
    if ($apiKey !== '' && hash_equals(API_SECRET_KEY, $apiKey)) {
        // API Key 验证通过，跳过 CSRF
    } else {
        $csrfInput = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if ($csrfInput === '' || !hash_equals($CSRF_TOKEN, $csrfInput)) {
            // 记录调试信息
            try {
                $debugFile = __DIR__ . '/cache/csrf_debug.log';
                $debugDir = dirname($debugFile);
                if (!is_dir($debugDir)) @mkdir($debugDir, 0755, true);
                $debugEntry = '[' . date('Y-m-d H:i:s') . '] '
                    . 'session_id=' . session_id() . ' '
                    . 'session_token=' . substr($CSRF_TOKEN, 0, 8) . '... '
                    . 'post_token=' . substr($csrfInput, 0, 8) . '... '
                    . 'match=' . ($csrfInput !== '' && hash_equals($CSRF_TOKEN, $csrfInput) ? 'Y' : 'N')
                    . PHP_EOL;
                @file_put_contents($debugFile, $debugEntry, FILE_APPEND | LOCK_EX);
            } catch (Throwable $ignore) {}
            http_response_code(403);
            json_response([
                'success' => false,
                'error' => 'CSRF 令牌无效，请刷新页面后重试'
            ]);
        }
    }
}

$api = new InstantMailAPI(API_TIMEOUT, API_CACHE_ENABLE);

try {
    switch ($action) {
        // 1. 获取域名列表
        case 'get_domains':
            $category = $_GET['category'] ?? null;
            $forceRefresh = isset($_GET['refresh']) && $_GET['refresh'] === '1';
            $domains = $api->getDomains($forceRefresh, $category);
            json_response([
                'success' => true,
                'total' => count($domains),
                'data' => $domains
            ]);
            break;

        // 2. 创建邮箱
        case 'create_email':
            $name = $_POST['name'] ?? null;
            $domain = $_POST['domain'] ?? null;
            $result = $api->createEmail($name, $domain);

            if (is_array($result) && isset($result['email']) && isset($result['token'])) {
                addEmailToStorage(
                    $result['email'],
                    $result['token'],
                    $result['domain'] ?? $domain ?? 'unknown',
                    $result['service'] ?? 'public'
                );
            }

            json_response($result);
            break;

        // 3. 创建 Gmail 类邮箱
        case 'create_gmail':
            $result = $api->createGmailEmail();
            json_response($result);
            break;

        // 4. 获取邮件列表
        case 'get_messages':
            $email = trim((string)($_GET['email'] ?? ''));
            if (empty($email)) {
                throw new Exception("邮箱不能为空");
            }
            try {
                $email = strtolower($email);

                // 多个 API 端点（同时尝试带编码和不带编码的 @）
                $apiEndpoints = [
                    'https://api.internal.temp-mail.io/api/v3/email/' . rawurlencode($email) . '/messages',
                    'https://api.internal.temp-mail.io/api/v3/email/' . $email . '/messages',
                    'https://mail-server.1timetech.com/api/email/' . rawurlencode($email) . '/messages',
                ];

                $messages = [];
                $allErrors = [];
                $foundMessages = false;

                foreach ($apiEndpoints as $apiUrl) {
                    try {
                        $ch = curl_init($apiUrl);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
                        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
                        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
                        curl_setopt($ch, CURLOPT_HTTPHEADER, [
                            'Accept: application/json',
                            'Content-Type: application/json',
                        ]);
                        $response = curl_exec($ch);
                        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                        $curlErr = curl_error($ch);
                        curl_close($ch);

                        if ($response === false || $curlErr) {
                            $allErrors[] = "{$apiUrl}: cURL错误 - {$curlErr}";
                            continue;
                        }

                        if ($httpCode >= 400) {
                            $decoded = json_decode($response, true);
                            $msg = $decoded['message'] ?? $decoded['detail'] ?? 'HTTP ' . $httpCode;
                            $allErrors[] = "{$apiUrl}: {$msg}";
                            continue;
                        }

                        // 清理响应：移除可能的 BOM 和空白控制字符（保留 UTF-8 多字节字符）
                        $cleanResponse = trim($response);
                        // 只移除 BOM 和空字符，不移除 \x80-\xFF（UTF-8 需要）
                        $cleanResponse = preg_replace('/^[\xEF\xBB\xBF]+/', '', $cleanResponse);
                        $cleanResponse = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $cleanResponse);

                        $decoded = json_decode($cleanResponse, true);
                        if (!is_array($decoded)) {
                            // 尝试提取 JSON 数组或对象（从第一个 [ 或 { 到最后一个 ] 或 }）
                            $rawTrim = trim($response);
                            $firstBracket = strpos($rawTrim, '[');
                            $firstBrace = strpos($rawTrim, '{');
                            $startPos = false;
                            if ($firstBracket === 0 || $firstBrace === 0) {
                                $startPos = 0;
                            } elseif ($firstBracket !== false || $firstBrace !== false) {
                                $startPos = min(array_filter([$firstBracket, $firstBrace], function($v) { return $v !== false; }));
                            }
                            if ($startPos !== false) {
                                $startChar = $rawTrim[$startPos];
                                $endChar = $startChar === '[' ? ']' : '}';
                                $endPos = strrpos($rawTrim, $endChar);
                                if ($endPos !== false && $endPos > $startPos) {
                                    $jsonCandidate = substr($rawTrim, $startPos, $endPos - $startPos + 1);
                                    $decoded = json_decode($jsonCandidate, true);
                                }
                            }
                            if (!is_array($decoded)) {
                                $allErrors[] = "{$apiUrl}: JSON解析失败 (原始响应长度: " . strlen($response) . ")";
                                continue;
                            }
                        }

                        // 解析消息
                        $raw = [];
                        if (isset($decoded['messages']) && is_array($decoded['messages'])) {
                            $raw = $decoded['messages'];
                        } elseif (isset($decoded['data']) && is_array($decoded['data'])) {
                            if (isset($decoded['data'][0]) && is_array($decoded['data'][0])) {
                                $raw = $decoded['data'];
                            } else {
                                $raw = [$decoded['data']];
                            }
                        } elseif (isset($decoded[0]) && is_array($decoded[0])) {
                            $raw = $decoded;
                        } elseif (isset($decoded['id'])) {
                            // 单封邮件
                            $raw = [$decoded];
                        }

                        if (!empty($raw)) {
                            $foundMessages = true;
                            foreach ($raw as $msg) {
                                if (is_array($msg)) {
                                    $messages[] = [
                                        'id' => isset($msg['id']) ? (string)$msg['id'] : md5(serialize($msg)),
                                        'from' => $msg['from'] ?? '',
                                        'to' => $msg['to'] ?? $email,
                                        'cc' => $msg['cc'] ?? null,
                                        'subject' => $msg['subject'] ?? '（无主题）',
                                        'body' => $msg['body_text'] ?? $msg['body'] ?? '',
                                        'body_html' => $msg['body_html'] ?? '',
                                        'date' => $msg['date'] ?? $msg['created_at'] ?? date('Y-m-d H:i:s'),
                                        'attachments' => $msg['attachments'] ?? [],
                                        'read' => $msg['read'] ?? false
                                    ];
                                }
                            }
                            break;
                        } else {
                            $allErrors[] = "{$apiUrl}: 响应无邮件数据";
                        }
                    } catch (Exception $e) {
                        $allErrors[] = "{$apiUrl}: " . $e->getMessage();
                    }
                }

                if ($foundMessages) {
                    json_response([
                        'success' => true,
                        'email' => $email,
                        'total' => count($messages),
                        'messages' => $messages
                    ]);
                } else {
                    json_response([
                        'success' => true,
                        'email' => $email,
                        'total' => 0,
                        'messages' => [],
                        'debug' => $allErrors
                    ]);
                }
            } catch (Exception $e) {
                json_response([
                    'success' => false,
                    'error' => api_sanitize_error($e->getMessage())
                ]);
            }
            break;

        // 5. 获取单封邮件
        case 'get_message':
            $email = trim((string)($_GET['email'] ?? ''));
            $messageId = trim((string)($_GET['message_id'] ?? ''));
            if (empty($email) || empty($messageId)) {
                http_response_code(400);
                json_response(['success' => false, 'error' => '邮箱和邮件ID不能为空']);
            }
            $message = $api->getMessage($email, $messageId);
            if ($message) {
                json_response(['success' => true, 'data' => $message]);
            } else {
                http_response_code(404);
                json_response(['success' => false, 'error' => '邮件不存在']);
            }
            break;

        // 6. 删除邮箱
        case 'delete_email':
            $email = trim((string)($_POST['email'] ?? ''));
            $token = $_POST['token'] ?? '';
            if (empty($email) || empty($token)) {
                throw new Exception("邮箱和令牌不能为空");
            }

            // 先从本地存储删除（本地列表是用户本地的记录，必须优先保证删除成功）
            $localDeleted = removeEmailFromStorage($email);

            // 再尝试调用后端 API 删除（通知远程服务）
            $result = $api->deleteEmail($email, $token);

            // 如果后端删除成功，或者本地已删除，返回成功
            // 用户关心的是邮箱从系统消失，不关心远程 API 是否成功
            if ($result['success'] || $localDeleted) {
                json_response([
                    'success' => true,
                    'email' => $email,
                    'message' => '邮箱已从系统移除',
                    'local_removed' => $localDeleted,
                    'remote_deleted' => $result['success'] ?? false
                ]);
            } else {
                json_response([
                    'success' => false,
                    'email' => $email,
                    'message' => '删除失败: ' . ($result['message'] ?? '未知错误')
                ]);
            }
            break;

        // 7. 批量创建邮箱
        case 'batch_create':
            $count = intval($_POST['count'] ?? 5);
            $domain = $_POST['domain'] ?? null;
            if ($count < 1 || $count > 50) {
                throw new Exception("批量创建数量必须在 1-50 之间");
            }
            $result = $api->createMultipleEmails($count, $domain);

            if (is_array($result) && isset($result['emails'])) {
                foreach ($result['emails'] as $emailData) {
                    if (is_array($emailData) && isset($emailData['email']) && isset($emailData['token'])) {
                        addEmailToStorage(
                            $emailData['email'],
                            $emailData['token'],
                            $emailData['domain'] ?? $domain ?? 'unknown',
                            $emailData['service'] ?? 'public'
                        );
                    }
                }
            }

            json_response($result);
            break;

        // 8. 获取邮箱统计
        case 'get_stats':
            $email = trim((string)($_GET['email'] ?? ''));
            if (empty($email)) {
                throw new Exception("邮箱不能为空");
            }
            $stats = $api->getStats($email);
            json_response(['success' => true, 'data' => $stats]);
            break;

        // 9. 获取 API 状态
        case 'get_status':
            $status = $api->getAPIStatus();
            json_response(['success' => true, 'data' => $status]);
            break;

        // 10. 清除缓存
        case 'clear_cache':
            $api->clearCache();
            json_response(['success' => true, 'message' => '缓存已清除']);
            break;

        // 11. 快速创建邮箱
        case 'quick_create':
            $result = $api->createEmail();
            if (is_array($result) && isset($result['email']) && isset($result['token'])) {
                addEmailToStorage(
                    $result['email'],
                    $result['token'],
                    $result['domain'] ?? 'unknown',
                    $result['service'] ?? 'public'
                );
                json_response([
                    'success' => true,
                    'email' => $result['email'],
                    'token' => $result['token'],
                    'domain' => $result['domain'] ?? 'unknown',
                    'service' => $result['service'] ?? 'public',
                    'message' => '邮箱创建成功'
                ]);
            } else {
                throw new Exception($result['error'] ?? '创建邮箱失败');
            }
            break;

        // 12. 获取分类信息
        case 'get_categories':
            global $DOMAIN_CATEGORIES;
            $categories = [];
            foreach ($DOMAIN_CATEGORIES as $key => $value) {
                $categories[] = [
                    'key' => $key,
                    'name' => $value['name'],
                    'description' => $value['description'],
                    'icon' => $value['icon'],
                    'color' => $value['color'],
                    'count' => count($value['domains'])
                ];
            }
            json_response(['success' => true, 'data' => $categories]);
            break;

        // 13. 获取已保存的邮箱列表
        case 'get_stored_emails':
            $emails = getStoredEmails();
            json_response([
                'success' => true,
                'total' => count($emails),
                'data' => $emails
            ]);
            break;

        // 14. 手动添加邮箱
        case 'add_email':
            $email = trim((string)($_POST['email'] ?? ''));
            $token = $_POST['token'] ?? '';
            $domain = $_POST['domain'] ?? '';
            if (empty($email) || empty($token)) {
                throw new Exception("邮箱和令牌不能为空");
            }
            addEmailToStorage($email, $token, $domain, 'manual');
            json_response(['success' => true, 'message' => '邮箱已添加']);
            break;

        // 15. 清空邮箱列表
        case 'clear_emails':
            clearAllEmails();
            json_response(['success' => true, 'message' => '已清空所有邮箱记录']);
            break;

        // 16. 删除单个邮箱记录
        case 'remove_email_record':
            $email = trim((string)($_POST['email'] ?? ''));
            if (empty($email)) {
                throw new Exception("邮箱不能为空");
            }
            removeEmailFromStorage($email);
            json_response(['success' => true, 'message' => '邮箱记录已删除']);
            break;

        // 默认动作
        default:
            if (empty($action)) {
                json_response([
                    'success' => true,
                    'app' => APP_NAME,
                    'version' => APP_VERSION,
                    'endpoints' => [
                        'get_domains' => '获取域名列表',
                        'create_email' => '创建邮箱',
                        'create_gmail' => '创建Gmail类邮箱',
                        'get_messages' => '获取邮件列表',
                        'delete_email' => '删除邮箱',
                        'batch_create' => '批量创建邮箱',
                        'get_stats' => '获取邮箱统计',
                        'get_status' => '获取API状态',
                        'clear_cache' => '清除缓存',
                        'get_categories' => '获取域名分类',
                        'get_stored_emails' => '获取已保存邮箱列表'
                    ]
                ]);
            } else {
                http_response_code(400);
                json_response([
                    'success' => false,
                    'error' => '未知的操作'
                ]);
            }

    }
} catch (Throwable $e) {
    http_response_code(400);
    // 写错误日志到 cache 目录（不可通过 web 直接访问）
    try {
        $logFile = __DIR__ . '/cache/app.log';
        $logDir = dirname($logFile);
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        $logEntry = '[' . date('Y-m-d H:i:s') . '] [ERROR] '
            . $e->getMessage()
            . ' (action=' . preg_replace('/[^a-z_]/i', '', $action) . ', ip=' . ($_SERVER['REMOTE_ADDR'] ?? '?') . ')'
            . PHP_EOL;
        @file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
    } catch (Throwable $ignore) {
    }
    json_response([
        'success' => false,
        'error' => api_sanitize_error($e->getMessage())
    ]);
}
