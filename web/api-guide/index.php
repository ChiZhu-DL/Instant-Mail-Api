<!DOCTYPE html>
<html lang="zh-CN">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, maximum-scale=5" />
  <meta name="theme-color" content="#4FC3F7" />
  <meta name="description" content="BeatMail 开放 API 使用指南：接口说明、鉴权开关、curl / Python / PHP 调用示例。" />
  <title>BeatMail — 开放 API 使用指南</title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@400;500;600;700&family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="../assets/css/style.css" />
</head>
<body>
  <div class="bg-orbs" aria-hidden="true">
    <span class="orb orb-a"></span>
    <span class="orb orb-b"></span>
    <span class="orb orb-c"></span>
  </div>

  <div class="app guide-page">
    <header class="hud guide-hud">
      <a class="brand guide-brand" href="../" aria-label="返回 BeatMail 首页">
        <span class="brand-mark" aria-hidden="true">✉</span>
        <div class="brand-text">
          <h1 class="brand-title">BeatMail</h1>
          <p class="brand-tag">open api · examples · docs</p>
        </div>
      </a>
      <a class="btn btn-secondary guide-home" href="../">← 返回首页</a>
    </header>

    <main class="guide-shell">
      <section class="guide-hero hero-card">
        <div class="hero-badge">OPEN API</div>
        <h2 class="guide-title">BeatMail 开放 API 使用指南</h2>
        <p class="guide-subtitle">当前开放接口沿用稳定的 <code>action=</code> 路由：<code>/api/index.php?action=...</code>。适合外部脚本、工具和学习研究调用。</p>
        <div class="guide-actions">
          <a class="btn btn-play guide-main-action" href="#examples"><span class="btn-shine"></span>查看调用示例</a>
          <a class="btn btn-ghost" href="../api/index.php?action=health" target="_blank" rel="noopener noreferrer">测试 health</a>
        </div>
      </section>

      <section class="guide-grid">
        <article class="guide-card">
          <div class="panel-title">BASE</div>
          <h3 class="guide-section-title">基础地址与响应格式</h3>
          <p>把示例里的 <code>https://你的域名</code> 替换为你的站点域名。如果部署在子目录，例如 <code>/mail/</code>，Base URL 应包含子目录。</p>
          <pre class="api-code"><code>BASE_URL = "https://你的域名/api/index.php"
# 子目录示例："https://你的域名/mail/api/index.php"</code></pre>
          <p>接口统一返回 JSON：</p>
          <pre class="api-code"><code>{
  "ok": true,
  "data": {}
}</code></pre>
          <p>失败时：</p>
          <pre class="api-code"><code>{
  "ok": false,
  "error": "错误说明"
}</code></pre>
        </article>

        <article class="guide-card guide-callout">
          <div class="panel-title">AUTH</div>
          <h3 class="guide-section-title">API 鉴权</h3>
          <p>鉴权<strong>默认已开启</strong>。本站页面自身的请求免密放行，<strong>外部脚本调用必须携带密钥</strong>：</p>
          <pre class="api-code"><code>Authorization: Bearer YOUR_API_KEY</code></pre>
          <p>密钥与开关都在 <code>web/api/config.php</code>：</p>
          <pre class="api-code"><code>define('API_AUTH_ENABLED', true);   // 关掉则 API 完全公开
define('API_KEY', '请替换成你的密钥');
define('API_ALLOW_SAME_ORIGIN', true); // 本站页面免密</code></pre>
          <p>无密钥或密钥错误时返回 <code>401</code>：</p>
          <pre class="api-code"><code>{
  "ok": false,
  "error": "缺少凭证。外部调用需携带 Authorization: Bearer &lt;API_KEY&gt;"
}</code></pre>
          <p class="resource-note"><code>action=health</code> 始终公开，方便部署后探活。注意：创建邮箱返回的 <code>token</code> 是读取该邮箱邮件的业务 token，不等于 API 鉴权密钥。</p>
        </article>

        <article class="guide-card guide-callout warn">
          <div class="panel-title">RATE LIMIT</div>
          <h3 class="guide-section-title">调用频率限制</h3>
          <p>按客户端 IP 固定窗口限流，默认配置：</p>
          <ul class="api-endpoints guide-list">
            <li>一般接口：<strong>60 次 / 分钟</strong></li>
            <li><code>action=create</code>（创建邮箱代价高）：<strong>10 次 / 分钟</strong></li>
          </ul>
          <p>每个响应都会带上余量头，超限时返回 <code>429</code> 并附 <code>Retry-After</code>：</p>
          <pre class="api-code"><code>X-RateLimit-Limit: 60
X-RateLimit-Remaining: 42

// 超限时
HTTP/1.1 429
Retry-After: 37
{
  "ok": false,
  "error": "请求过于频繁，请稍后再试",
  "retry_after": 37
}</code></pre>
          <p class="resource-note">阈值可在 <code>api/config.php</code> 的 <code>RATE_LIMIT_MAX</code> / <code>RATE_LIMIT_CREATE_MAX</code> 调整。</p>
        </article>
      </section>

      <section class="guide-card">
        <div class="panel-title">ENDPOINTS</div>
        <h3 class="guide-section-title">接口列表</h3>
        <div class="guide-table-wrap">
          <table class="guide-table">
            <thead>
              <tr>
                <th>action</th>
                <th>方法</th>
                <th>说明</th>
                <th>参数</th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td><code>health</code></td>
                <td>GET</td>
                <td>健康检查</td>
                <td>无</td>
              </tr>
              <tr>
                <td><code>domains</code></td>
                <td>GET</td>
                <td>获取全部域名</td>
                <td>无</td>
              </tr>
              <tr>
                <td><code>create</code></td>
                <td>POST</td>
                <td>创建邮箱</td>
                <td><code>service</code>、<code>domain</code>、<code>name</code>、<code>email</code></td>
              </tr>
              <tr>
                <td><code>messages</code></td>
                <td>GET</td>
                <td>读取收件箱列表</td>
                <td><code>email</code>、<code>token</code></td>
              </tr>
              <tr>
                <td><code>message</code></td>
                <td>GET</td>
                <td>读取单封邮件详情</td>
                <td><code>email</code>、<code>token</code>、<code>id</code></td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section class="guide-card guide-callout warn">
        <div class="panel-title">EMAIL CONTRACT</div>
        <h3 class="guide-section-title">邮箱 <code>@</code> / <code>%40</code> 规则</h3>
        <ul class="api-endpoints guide-list">
          <li>业务数据、JSON body、保存变量里都使用字面量邮箱：<code>demo01@bltiwd.com</code>。</li>
          <li>放进 URL query 时，<code>@</code> 被编码成 <code>%40</code> 是正确的。</li>
          <li>不要双重编码成 <code>%2540</code>。</li>
          <li>推荐让 <code>URLSearchParams</code>、Python <code>requests</code>、PHP <code>http_build_query</code> 或 <code>curl --data-urlencode</code> 自动编码一次。</li>
        </ul>
      </section>

      <section id="examples" class="guide-card">
        <div class="panel-title">EXAMPLES</div>
        <h3 class="guide-section-title">curl 调用示例</h3>
        <p>把 <code>YOUR_API_KEY</code> 换成 <code>api/config.php</code> 里的密钥。</p>
        <div class="api-snippet">
          <div class="api-snippet-title">健康检查（无需密钥）</div>
          <pre class="api-code"><code>curl -s "https://你的域名/api/index.php?action=health"</code></pre>
        </div>
        <div class="api-snippet">
          <div class="api-snippet-title">获取域名</div>
          <pre class="api-code"><code>curl -s "https://你的域名/api/index.php?action=domains" \
  -H "Authorization: Bearer YOUR_API_KEY"</code></pre>
        </div>
        <div class="api-snippet">
          <div class="api-snippet-title">创建邮箱</div>
          <pre class="api-code"><code>curl -s -X POST "https://你的域名/api/index.php?action=create" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -d '{"service":"temp-mail-io","domain":"bltiwd.com","name":"demo01"}'</code></pre>
        </div>
        <div class="api-snippet">
          <div class="api-snippet-title">读取收件箱</div>
          <pre class="api-code"><code>curl -s "https://你的域名/api/index.php?action=messages&amp;email=demo01%40bltiwd.com&amp;token=TOKEN" \
  -H "Authorization: Bearer YOUR_API_KEY"</code></pre>
        </div>
      </section>

      <section class="guide-card">
        <div class="panel-title">PYTHON</div>
        <h3 class="guide-section-title">Python 调用示例</h3>
        <pre class="api-code"><code>import requests

BASE = "https://你的域名/api/index.php"
API_KEY = "YOUR_API_KEY"  # 见 api/config.php

headers = {
    "Content-Type": "application/json",
    "Authorization": f"Bearer {API_KEY}",
}

created = requests.post(
    BASE,
    params={"action": "create"},
    json={
        "service": "temp-mail-io",
        "domain": "bltiwd.com",
        "name": "demo01",
    },
    headers=headers,
    timeout=45,
).json()

if not created.get("ok"):
    raise RuntimeError(created.get("error"))

mailbox = created["data"]["mailbox"]  # 字面量 @，例如 demo01@bltiwd.com
token = created["data"]["token"]

inbox = requests.get(
    BASE,
    params={
        "action": "messages",
        "email": mailbox,  # requests 会把 @ 编码为 %40 一次
        "token": token,
    },
    headers=headers,
    timeout=45,
).json()

print(inbox)</code></pre>
      </section>

      <section class="guide-card">
        <div class="panel-title">PHP</div>
        <h3 class="guide-section-title">PHP 调用示例</h3>
        <pre class="api-code"><code>&lt;?php

$base = 'https://你的域名/api/index.php';
$apiKey = 'YOUR_API_KEY'; // 见 api/config.php

function beatmail_headers($apiKey) {
    return [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey,
    ];
}

function beatmail_post($base, $action, array $body, array $headers) {
    $url = $base . '?' . http_build_query(['action' => $action]);
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers),
            'content' => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'timeout' => 45,
        ],
    ]);
    return json_decode(file_get_contents($url, false, $context), true);
}

function beatmail_get($base, array $query, array $headers) {
    $url = $base . '?' . http_build_query($query); // 会把 @ 编码为 %40 一次
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => implode("\r\n", $headers),
            'timeout' => 45,
        ],
    ]);
    return json_decode(file_get_contents($url, false, $context), true);
}

$headers = beatmail_headers($apiKey);

$created = beatmail_post($base, 'create', [
    'service' => 'temp-mail-io',
    'domain' => 'bltiwd.com',
    'name' => 'demo01',
], $headers);

if (empty($created['ok'])) {
    throw new RuntimeException($created['error'] ?? 'create failed');
}

$mailbox = $created['data']['mailbox']; // 字面量 @
$token = $created['data']['token'];

$inbox = beatmail_get($base, [
    'action' => 'messages',
    'email' => $mailbox,
    'token' => $token,
], $headers);

print_r($inbox);</code></pre>
      </section>

      <section class="guide-card guide-callout">
        <div class="panel-title">FLOW</div>
        <h3 class="guide-section-title">推荐调用流程</h3>
        <ol class="api-endpoints guide-list">
          <li>调用 <code>domains</code> 获取可用域名。</li>
          <li>调用 <code>create</code> 创建邮箱。</li>
          <li>保存返回的 <code>data.mailbox</code> 和 <code>data.token</code>。</li>
          <li>调用 <code>messages</code> 轮询收件箱。</li>
          <li>拿到邮件 <code>id</code> 后调用 <code>message</code> 读取正文。</li>
        </ol>
        <div class="guide-actions bottom-actions">
          <a class="btn btn-play guide-main-action" href="../"><span class="btn-shine"></span>返回 BeatMail 首页</a>
        </div>
      </section>
    </main>
  </div>

  <footer class="site-footer">
    <span>BeatMail · 开放 API</span>
    <span class="sep">·</span>
    <span>仅供学习研究与合规用途</span>
  </footer>

  <script>
    (function () {
      'use strict';

      function fallbackCopy(text) {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.setAttribute('readonly', 'readonly');
        ta.style.position = 'fixed';
        ta.style.left = '-9999px';
        document.body.appendChild(ta);
        ta.select();
        try {
          document.execCommand('copy');
          return true;
        } catch (e) {
          return false;
        } finally {
          document.body.removeChild(ta);
        }
      }

      function copyText(text, btn) {
        function done(ok) {
          var old = btn.textContent;
          btn.textContent = ok ? '已复制' : '复制失败';
          setTimeout(function () { btn.textContent = old; }, 1200);
        }

        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(text).then(function () {
            done(true);
          }).catch(function () {
            done(fallbackCopy(text));
          });
          return;
        }
        done(fallbackCopy(text));
      }

      function enhanceCodeBlocks() {
        var blocks = document.querySelectorAll('pre.api-code');
        Array.prototype.forEach.call(blocks, function (pre) {
          if (pre.parentNode && pre.parentNode.classList && pre.parentNode.classList.contains('codebox')) {
            return;
          }

          var raw = pre.textContent.replace(/\n$/, '');
          var titleNode = pre.previousElementSibling;
          var title = 'CODE';
          if (titleNode && titleNode.classList && titleNode.classList.contains('api-snippet-title')) {
            title = titleNode.textContent.trim() || title;
            titleNode.hidden = true;
          } else {
            var section = pre.parentNode ? pre.parentNode.querySelector('.guide-section-title') : null;
            if (section) title = section.textContent.trim() || title;
          }

          var box = document.createElement('div');
          box.className = 'codebox';

          var head = document.createElement('div');
          head.className = 'codebox-head';

          var label = document.createElement('div');
          label.className = 'codebox-title';
          label.textContent = title;

          var btn = document.createElement('button');
          btn.type = 'button';
          btn.className = 'codecopy-btn';
          btn.textContent = '复制';
          btn.addEventListener('click', function () { copyText(raw, btn); });

          head.appendChild(label);
          head.appendChild(btn);

          var ol = document.createElement('ol');
          ol.className = 'codebox-body';
          raw.split('\n').forEach(function (line) {
            var li = document.createElement('li');
            var code = document.createElement('code');
            code.textContent = line || ' ';
            li.appendChild(code);
            ol.appendChild(li);
          });

          box.appendChild(head);
          box.appendChild(ol);
          pre.parentNode.replaceChild(box, pre);
        });
      }

      if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', enhanceCodeBlocks);
      } else {
        enhanceCodeBlocks();
      }
    })();
  </script>
</body>
</html>
