<?php
/**
 * Instant Mail API - PHP SDK (管理系统版)
 */

require_once __DIR__ . '/config.php';

class InstantMailAPI {
    private $timeout;
    private $enableCache;
    private $cacheFile;
    private $cacheTTL;

    const API_PUBLIC = 'https://api.internal.temp-mail.io';
    const API_HD = 'https://mail-server.1timetech.com';
    const API_GMAIL = 'https://mail-server-2.1timetech.com';
    const APP_KEY = 'b9db03078622';

    public function __construct($timeout = 30, $enableCache = true) {
        $this->timeout = $timeout;
        $this->enableCache = $enableCache;
        $this->cacheFile = __DIR__ . '/cache/domains.json';
        $this->cacheTTL = API_CACHE_TTL;

        if ($enableCache && !is_dir(__DIR__ . '/cache')) {
            @mkdir(__DIR__ . '/cache', 0755, true);
        }
    }

    /**
     * HTTP 请求封装
     * - 使用 CURLOPT_POST / CURLOPT_HTTPGET，避免依赖 CURLOPT_CUSTOMREQUEST
     * - 所有请求设置超时、跟随重定向、SSL 证书宽松校验（目标 API 自签证书常见）
     * - 返回值优先解析为数组，失败时抛出 Exception
     */
    private function request($method, $url, $params = null, $data = null, $withAppKey = true) {
        $methodUpper = strtoupper($method);

        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, max(5, (int)($this->timeout / 3)));
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_USERAGENT, 'InstantMailAPI/2.0 (+https://github.com/temp-mail)');

        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
        ];
        if ($withAppKey) {
            $headers[] = 'x-app-key: ' . self::APP_KEY;
        }

        $hasBody = !empty($data) && in_array($methodUpper, ['POST', 'PUT', 'PATCH', 'DELETE'], true);
        if ($methodUpper === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
        } elseif ($methodUpper === 'GET') {
            curl_setopt($ch, CURLOPT_HTTPGET, true);
        } else {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $methodUpper);
        }

        if ($hasBody) {
            $body = is_string($data) ? $data : json_encode($data, JSON_UNESCAPED_UNICODE);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            $headers[] = 'Content-Length: ' . strlen($body);
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error || $response === false) {
            throw new Exception('网络请求失败: ' . ($error ?: 'empty response'));
        }
        if ($httpCode >= 400) {
            // 即使 HTTP 4xx/5xx，仍可能返回有意义的 JSON 错误（比如 {"detail":"..."}）
            $decoded = json_decode($response, true);
            if (is_array($decoded)) {
                $msg = $decoded['detail'] ?? $decoded['message'] ?? $decoded['error'] ?? null;
                if ($msg) {
                    throw new Exception('API 错误: ' . $msg . ' (HTTP ' . $httpCode . ')');
                }
            }
            throw new Exception('API 返回错误状态码: HTTP ' . $httpCode);
        }
        if ($response === null || trim($response) === '') {
            return null;
        }

        $decoded = json_decode($response, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }

        // 不是 JSON，原样返回（调用方自行处理）
        return $response;
    }

    /**
     * 获取所有域名（缓存版）
     */
    public function getDomains($forceRefresh = false, $category = null) {
        global $DOMAIN_CATEGORIES;

        // 如果指定了分类 —— 直接从配置返回，不调用 API
        if ($category) {
            if (!isset($DOMAIN_CATEGORIES[$category])) {
                throw new Exception('无效的类别: ' . $category);
            }
            $domains = [];
            foreach ($DOMAIN_CATEGORIES[$category]['domains'] as $domain) {
                $domains[] = [
                    'domain' => $domain,
                    'category' => $category,
                    'type' => $category === 'public' ? 'stable' : 'experimental',
                    'available' => true
                ];
            }
            return $domains;
        }

        // 检查本地缓存
        if ($this->enableCache && !$forceRefresh && file_exists($this->cacheFile)) {
            $fp = @fopen($this->cacheFile, 'r');
            if ($fp) {
                $locked = @flock($fp, LOCK_SH);
                $raw = '';
                while (!feof($fp)) {
                    $raw .= fread($fp, 8192);
                }
                if ($locked) {
                    @flock($fp, LOCK_UN);
                }
                fclose($fp);
                $cacheData = json_decode($raw, true);
                if (is_array($cacheData)
                    && isset($cacheData['timestamp'], $cacheData['domains'])
                    && (time() - $cacheData['timestamp']) < $this->cacheTTL
                    && is_array($cacheData['domains'])) {
                    return $cacheData['domains'];
                }
            } else {
                // 退化为只读 file_get_contents
                $raw = @file_get_contents($this->cacheFile);
                if ($raw) {
                    $cacheData = json_decode($raw, true);
                    if (is_array($cacheData)
                        && isset($cacheData['timestamp'], $cacheData['domains'])
                        && (time() - $cacheData['timestamp']) < $this->cacheTTL) {
                        return $cacheData['domains'];
                    }
                }
            }
        }

        // 从 API 获取
        $apiDomains = [];
        try {
            $response = $this->request('GET', self::API_PUBLIC . '/api/v3/domains');
            if (is_array($response)) {
                // 返回可能是 [ "bltiwd.com", "wnbaldwy.com", ... ]
                // 也可能是 [ {"name":"bltiwd.com"}, ... ]
                $apiDomains = [];
                foreach ($response as $item) {
                    if (is_string($item)) {
                        $apiDomains[] = $item;
                    } elseif (is_array($item)) {
                        $apiDomains[] = $item['name'] ?? $item['domain'] ?? null;
                    }
                }
                $apiDomains = array_filter($apiDomains, 'strlen');
            }
        } catch (Exception $e) {
            // 忽略失败，使用内置域名兜底
        }

        // 合并内置域名
        $allDomains = [];
        $seen = [];

        foreach ($apiDomains as $d) {
            $key = strtolower($d);
            if (!in_array($key, $seen, true)) {
                $seen[] = $key;
                $allDomains[] = [
                    'domain' => $d,
                    'category' => 'public',
                    'type' => 'stable',
                    'available' => true
                ];
            }
        }

        foreach ($DOMAIN_CATEGORIES as $catName => $catInfo) {
            foreach ($catInfo['domains'] as $domain) {
                $key = strtolower($domain);
                if (!in_array($key, $seen, true)) {
                    $seen[] = $key;
                    $allDomains[] = [
                        'domain' => $domain,
                        'category' => $catName,
                        'type' => $catName === 'public' ? 'stable' : 'experimental',
                        'available' => true
                    ];
                }
            }
        }

        // 写缓存（原子写 + 独占锁）
        if ($this->enableCache) {
            $cacheDir = dirname($this->cacheFile);
            if (!is_dir($cacheDir)) {
                @mkdir($cacheDir, 0755, true);
            }
            $cacheJson = json_encode([
                'timestamp' => time(),
                'domains' => $allDomains
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

            $tmp = $this->cacheFile . '.tmp.' . $this->randomHex(8);
            $written = @file_put_contents($tmp, $cacheJson, LOCK_EX);
            if ($written !== false) {
                if (!@rename($tmp, $this->cacheFile)) {
                    @file_put_contents($this->cacheFile, $cacheJson, LOCK_EX);
                    @unlink($tmp);
                } else {
                    @chmod($this->cacheFile, 0644);
                }
            } else {
                @unlink($tmp);
            }
        }

        return $allDomains;
    }

    /**
     * 获取随机域名
     */
    public function getRandomDomain($category = 'public') {
        global $DOMAIN_CATEGORIES;
        if (!isset($DOMAIN_CATEGORIES[$category])) {
            $category = 'public';
        }
        $domains = $DOMAIN_CATEGORIES[$category]['domains'];
        return $domains[array_rand($domains)];
    }

    /**
     * 创建临时邮箱
     */
    public function createEmail($name = null, $domain = null) {
        global $DOMAIN_CATEGORIES;

        // 验证域名合法性
        if ($domain) {
            $isValid = false;
            foreach ($DOMAIN_CATEGORIES as $cat) {
                foreach ($cat['domains'] as $allowed) {
                    if (strtolower($allowed) === strtolower($domain)) {
                        $isValid = true;
                        break 2;
                    }
                }
            }
            if (!$isValid) {
                throw new Exception('不支持的域名: ' . $domain);
            }
        } else {
            $domain = $this->getRandomDomain('public');
        }

        // 验证用户名
        if ($name !== null && $name !== '') {
            if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $name)) {
                throw new Exception('无效的用户名格式');
            }
        }

        $payload = ['domain' => $domain];
        if ($name !== null && $name !== '') {
            $payload['name'] = $name;
        }

        try {
            $response = $this->request('POST', self::API_PUBLIC . '/api/v3/email/new', null, $payload);
            if (is_array($response) && !empty($response['email'])) {
                return [
                    'success' => true,
                    'email' => $response['email'],
                    'token' => $response['token'] ?? '',
                    'domain' => $domain,
                    'service' => 'public',
                    'name' => explode('@', $response['email'])[0]
                ];
            }
            if (is_array($response) && isset($response['error'])) {
                throw new Exception($response['error']);
            }
            if (is_string($response)) {
                throw new Exception('API 返回: ' . substr($response, 0, 120));
            }
            throw new Exception('API 返回无效响应');
        } catch (Exception $e) {
            // HD / 自定义域名创建失败时 —— 回退到一个随机 public 域名重试一次
            if (!in_array(strtolower($domain), array_map('strtolower', $DOMAIN_CATEGORIES['public']['domains']), true)) {
                try {
                    $fallbackDomain = $this->getRandomDomain('public');
                    return $this->createEmail($name, $fallbackDomain);
                } catch (Exception $e2) {
                    throw new Exception('创建邮箱失败: ' . $e2->getMessage());
                }
            }
            throw new Exception('创建邮箱失败: ' . $e->getMessage());
        }
    }

    /**
     * 创建 Gmail 类邮箱
     */
    public function createGmailEmail() {
        try {
            $response = $this->request('POST', self::API_GMAIL . '/api/g-mail', ['params' => 'x03e']);
            if (is_array($response) && isset($response['data'])) {
                return [
                    'success' => true,
                    'raw_data' => $response['data'],
                    'service' => 'gmail',
                    'message' => 'Gmail 类邮箱创建成功，数据需要解码'
                ];
            }
            if (is_array($response) && isset($response['error'])) {
                throw new Exception($response['error']);
            }
            throw new Exception('Gmail API 返回无效响应');
        } catch (Exception $e) {
            throw new Exception('创建 Gmail 邮箱失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取邮件列表
     * 注意：邮箱地址会被做 rawurlencode，防止特殊字符（/、.、空格等）被解释为路径
     * 会尝试多个 API 端点以提高成功率
     */
    public function getMessages($email, $maxRetries = 2, $retryDelay = 0.5) {
        if (empty($email) || strpos($email, '@') === false) {
            throw new Exception('邮箱格式无效');
        }

        $email = strtolower(trim($email));

        // 多个 API 端点
        $apiEndpoints = [
            self::API_PUBLIC . '/api/v3/email/' . rawurlencode($email) . '/messages',
            self::API_HD . '/api/email/' . rawurlencode($email) . '/messages',
        ];

        $lastError = null;
        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            foreach ($apiEndpoints as $apiUrl) {
                try {
                    $response = $this->request('GET', $apiUrl, null, null, false);

                    $messages = [];
                    if (is_array($response)) {
                        if (isset($response['messages']) && is_array($response['messages'])) {
                            $messages = $response['messages'];
                        } elseif (isset($response[0]) && is_array($response[0])) {
                            $messages = $response;
                        }
                    }

                    $formatted = [];
                    foreach ($messages as $msg) {
                        if (is_array($msg)) {
                            $formatted[] = [
                                'id' => isset($msg['id']) ? (string)$msg['id'] : '',
                                'from' => $msg['from'] ?? '',
                                'to' => $msg['to'] ?? $email,
                                'cc' => $msg['cc'] ?? null,
                                'subject' => $msg['subject'] ?? '（无主题）',
                                'body' => $msg['body'] ?? $msg['body_text'] ?? '',
                                'body_html' => $msg['body_html'] ?? '',
                                'date' => $msg['date'] ?? $msg['created_at'] ?? date('Y-m-d H:i:s'),
                                'attachments' => $msg['attachments'] ?? [],
                                'read' => $msg['read'] ?? false
                            ];
                        }
                    }

                    return [
                        'success' => true,
                        'email' => $email,
                        'total' => count($formatted),
                        'messages' => $formatted
                    ];
                } catch (Exception $e) {
                    $errMsg = $e->getMessage();
                    // 如果是 "Email not found" 或 HTTP 400，认为是邮箱暂无邮件，不算错误
                    if (strpos($errMsg, 'Email not found') !== false || strpos($errMsg, 'HTTP 400') !== false) {
                        return [
                            'success' => true,
                            'email' => $email,
                            'total' => 0,
                            'messages' => []
                        ];
                    }
                    $lastError = $e;
                }
            }
            // 如果第一次尝试失败，稍后重试
            if ($attempt < $maxRetries) {
                sleep($retryDelay);
            }
        }

        throw new Exception('获取邮件失败: ' . ($lastError ? $lastError->getMessage() : '未知错误'));
    }

    /**
     * 获取单封邮件
     */
    public function getMessage($email, $messageId) {
        $messages = $this->getMessages($email);
        foreach ($messages['messages'] as $msg) {
            if ((string)$msg['id'] === (string)$messageId) {
                return $msg;
            }
        }
        return null;
    }

    /**
     * 删除邮箱
     */
    public function deleteEmail($email, $token) {
        if (empty($email) || empty($token)) {
            return [
                'success' => false,
                'email' => $email,
                'message' => '邮箱和令牌不能为空'
            ];
        }

        try {
            $url = self::API_PUBLIC . '/api/v3/email/' . rawurlencode($email);
            $response = $this->request('DELETE', $url, null, ['token' => $token]);
            $deleted = false;
            if (is_bool($response)) {
                $deleted = $response;
            } elseif (is_array($response)) {
                $deleted = !empty($response['success']) || !empty($response['deleted']);
            }

            return [
                'success' => $deleted,
                'email' => $email,
                'message' => $deleted ? '邮箱已删除' : '删除失败或邮箱不存在'
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'email' => $email,
                'message' => '删除失败: ' . $e->getMessage()
            ];
        }
    }

    /**
     * 批量创建邮箱
     */
    public function createMultipleEmails($count = 5, $domain = null) {
        if ($count < 1 || $count > 50) {
            throw new Exception('批量创建数量必须在 1-50 之间');
        }

        $results = [];
        $successCount = 0;

        for ($i = 0; $i < $count; $i++) {
            try {
                $result = $this->createEmail(null, $domain);
                $results[] = $result;
                $successCount++;
                if ($i < $count - 1) {
                    usleep(300000); // 0.3 秒间隔，降低被限流概率
                }
            } catch (Exception $e) {
                $results[] = [
                    'success' => false,
                    'error' => $e->getMessage()
                ];
            }
        }

        return [
            'success' => $successCount > 0,
            'total' => $count,
            'success_count' => $successCount,
            'failed_count' => $count - $successCount,
            'emails' => $results
        ];
    }

    /**
     * 获取邮箱统计信息
     */
    public function getStats($email) {
        $result = $this->getMessages($email);
        $messages = $result['messages'];

        $unreadCount = 0;
        $hasAttachments = false;
        foreach ($messages as $msg) {
            if (empty($msg['read'])) {
                $unreadCount++;
            }
            if (!empty($msg['attachments'])) {
                $hasAttachments = true;
            }
        }

        return [
            'email' => $email,
            'total_messages' => count($messages),
            'unread_messages' => $unreadCount,
            'has_attachments' => $hasAttachments
        ];
    }

    /**
     * 获取 API 状态（连通性探测）
     */
    public function getAPIStatus() {
        $status = [];

        // Public API — 使用 GET 请求检测连通性
        try {
            $this->request('GET', self::API_PUBLIC . '/api/v3/domains');
            $status['public'] = ['status' => 'online', 'message' => '正常运行'];
        } catch (Exception $e) {
            $status['public'] = ['status' => 'offline', 'message' => mb_substr($e->getMessage(), 0, 120, 'UTF-8')];
        }

        // HD API — 使用 OPTIONS/GET 探测（避免 POST 产生副作用）
        try {
            $ch = curl_init(self::API_HD . '/api/email');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_NOBODY => true,
            ]);
            curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            if ($error) {
                throw new Exception($error);
            }
            $status['hd'] = ['status' => $httpCode > 0 ? 'online' : 'offline', 'message' => $httpCode > 0 ? '正常运行' : '连接失败'];
        } catch (Exception $e) {
            $status['hd'] = ['status' => 'experimental', 'message' => '实验性功能'];
        }

        // Gmail API — 同上
        try {
            $ch = curl_init(self::API_GMAIL . '/api/g-mail');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_NOBODY => true,
            ]);
            curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            if ($error) {
                throw new Exception($error);
            }
            $status['gmail'] = ['status' => $httpCode > 0 ? 'online' : 'offline', 'message' => $httpCode > 0 ? '正常运行' : '连接失败'];
        } catch (Exception $e) {
            $status['gmail'] = ['status' => 'experimental', 'message' => '实验性功能'];
        }

        return $status;
    }

    /**
     * 清除缓存
     */
    public function clearCache() {
        $cacheDir = dirname($this->cacheFile);
        if (is_dir($cacheDir)) {
            // 清理主缓存文件
            if (file_exists($this->cacheFile)) {
                @unlink($this->cacheFile);
            }
            // 清理残留的临时文件
            $tmpFiles = glob($this->cacheFile . '.tmp.*');
            if (is_array($tmpFiles)) {
                foreach ($tmpFiles as $tmp) {
                    @unlink($tmp);
                }
            }
        }
        return true;
    }

    /**
     * 内部工具：健壮的随机十六进制字符串
     */
    private function randomHex($bytes = 8) {
        if (function_exists('random_bytes')) {
            try {
                return bin2hex(random_bytes($bytes));
            } catch (Throwable $e) {
                // fallthrough
            }
        }
        return dechex(time()) . dechex(mt_rand());
    }
}
