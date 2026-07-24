<!DOCTYPE html>
<html lang="zh-CN">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, maximum-scale=5" />
  <meta name="theme-color" content="#4FC3F7" />
  <meta name="mobile-web-app-capable" content="yes" />
  <meta name="apple-mobile-web-app-capable" content="yes" />
  <meta name="apple-mobile-web-app-status-bar-style" content="default" />
  <meta name="description" content="BeatMail — 临时邮箱，专注、快速、用起来很爽。" />
  <title>BeatMail — 临时邮箱</title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@400;500;600;700&family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="assets/css/style.css" />
</head>
<body>
  <div class="bg-orbs" aria-hidden="true">
    <span class="orb orb-a"></span>
    <span class="orb orb-b"></span>
    <span class="orb orb-c"></span>
  </div>

  <div id="app" class="app">
    <!-- ── HUD ──────────────────────────────────────── -->
    <header class="hud">
      <div class="brand">
        <span class="brand-mark" aria-hidden="true">✉</span>
        <div class="brand-text">
          <h1 class="brand-title">BeatMail</h1>
          <p class="brand-tag">focused · fast · fun</p>
        </div>
      </div>
      <div class="hud-metrics">
        <div class="metric" title="收件数">
          <span class="metric-label">MAIL</span>
          <span class="metric-value" id="metric-count">0</span>
        </div>
        <div class="metric" title="自动刷新倒计时">
          <span class="metric-label">NEXT</span>
          <span class="metric-value" id="metric-timer">—</span>
        </div>
        <div class="metric metric-status">
          <span class="status-dot" id="status-dot"></span>
          <span class="metric-value sm" id="status-text">就绪</span>
        </div>
      </div>
      <div class="hud-toggles">
        <button type="button" class="icon-btn" id="btn-sound" title="音效开关" aria-pressed="true">🔊</button>
        <button type="button" class="icon-btn" id="btn-motion" title="减少动效" aria-pressed="false">✨</button>
      </div>
    </header>

    <!-- ── START SCREEN ─────────────────────────────── -->
    <section id="screen-start" class="screen screen-start is-active">
      <div class="hero-card">
        <div class="hero-inner">
          <div class="hero-left">
            <div class="hero-badge">NEW GAME</div>
            <h2 class="hero-title">BeatMail</h2>
            <p class="hero-tagline">专注、快速、用起来很爽。<br />一键拿到临时邮箱，验证码即到即看。</p>
            <ul class="rules">
              <li><span class="rule-ico good">①</span> 选域名 → 生成邮箱</li>
              <li><span class="rule-ico good">②</span> 复制地址去注册 / 收码</li>
              <li><span class="rule-ico good">③</span> 收件箱自动刷新，点开看详情</li>
            </ul>
          </div>
          <div class="hero-right">
            <div class="start-form">
              <label class="field">
                <span class="field-label">用户名 <em>(可选)</em></span>
                <input type="text" id="input-name" class="field-input" placeholder="随机生成" maxlength="32" autocomplete="off" spellcheck="false" />
              </label>
              <label class="field">
                <span class="field-label">域名</span>
                <select id="select-domain" class="field-input field-select">
                  <option value="">加载中…</option>
                </select>
              </label>
            </div>
            <button type="button" class="btn btn-play" id="btn-create">
              <span class="btn-shine"></span>
              PLAY · 生成邮箱
            </button>
            <p class="hint">支持 17+ 稳定域名 · Gmail 点别名 · 随机邮箱</p>

            <div class="hero-resources">
              <div class="resource-card resource-api">
                <div class="resource-icon" aria-hidden="true">📘</div>
                <div class="resource-header">
                  <div class="resource-title">开放 API</div>
                  <div class="resource-subtitle">接口文档 / 代码示例</div>
                </div>
                <a class="btn btn-ghost resource-link" href="api-guide/">查看 API 指南</a>
              </div>

              <div class="resource-card resource-source">
                <div class="resource-icon" aria-hidden="true">⭐</div>
                <div class="resource-header">
                  <div class="resource-title">开源入口</div>
                  <div class="resource-subtitle">仅供学习研究用途</div>
                </div>
                <a class="btn btn-ghost resource-link" href="https://github.com/ChiZhu-DL/Instant-Mail-Api" target="_blank" rel="noopener noreferrer">前往 GitHub</a>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- ── GAME BOARD (inbox) ───────────────────────── -->
    <section id="screen-board" class="screen screen-board">
      <div class="board-layout">
        <!-- Left: mailbox card -->
        <aside class="mailbox-panel">
          <div class="panel-title">YOUR MAILBOX</div>
          <div class="mailbox-card">
            <div class="mailbox-addr" id="mailbox-addr">—</div>
            <div class="mailbox-meta">
              <span class="chip" id="mailbox-service">—</span>
              <span class="chip chip-muted" id="mailbox-token-hint">token 已保存</span>
            </div>
            <div class="mailbox-actions">
              <button type="button" class="btn btn-secondary" id="btn-copy">📋 复制</button>
              <button type="button" class="btn btn-secondary" id="btn-refresh">⟳ 刷新</button>
              <button type="button" class="btn btn-ghost" id="btn-new">＋ 换号</button>
            </div>
          </div>

          <div class="tips-card">
            <div class="panel-title">QUICK TIPS</div>
            <p>把邮箱粘到需要验证的网站，回来这里等信。</p>
            <p class="tip-warn">临时邮箱会过期，别用来收重要邮件。</p>
          </div>
        </aside>

        <!-- Center: inbox list -->
        <main class="inbox-panel">
          <div class="inbox-header">
            <div class="panel-title">INBOX</div>
            <div class="inbox-controls">
              <label class="auto-toggle">
                <input type="checkbox" id="chk-auto" checked />
                <span>自动刷新</span>
              </label>
            </div>
          </div>

          <div id="inbox-empty" class="empty-state">
            <div class="empty-art" aria-hidden="true">📭</div>
            <h3>还没有邮件</h3>
            <p>把地址发出去，验证码会在这里闪现。</p>
            <div class="pulse-ring"></div>
          </div>

          <ul id="inbox-list" class="inbox-list" hidden></ul>
        </main>

        <!-- Right / overlay: message detail -->
        <aside id="detail-panel" class="detail-panel" hidden>
          <div class="detail-header">
            <button type="button" class="icon-btn btn-close-detail" id="btn-close-detail" title="关闭" aria-label="关闭邮件">✕</button>
            <div class="panel-title">MESSAGE</div>
          </div>
          <div class="detail-meta">
            <div class="detail-subject" id="detail-subject">—</div>
            <div class="detail-row"><span>From</span><strong id="detail-from">—</strong></div>
            <div class="detail-row"><span>To</span><strong id="detail-to">—</strong></div>
            <div class="detail-row"><span>Time</span><strong id="detail-time">—</strong></div>
          </div>
          <div class="detail-body">
            <iframe id="detail-frame" title="邮件内容" sandbox="allow-same-origin" referrerpolicy="no-referrer"></iframe>
            <pre id="detail-text" hidden></pre>
          </div>
        </aside>
      </div>
    </section>

    <!-- ── RESULTS / toast overlay ──────────────────── -->
    <div id="toast" class="toast" role="status" aria-live="polite" hidden></div>
    <div id="loading" class="loading-overlay" hidden>
      <div class="spinner"></div>
      <p id="loading-text">生成邮箱中…</p>
    </div>
  </div>

  <footer class="site-footer">
    <span>BeatMail · 临时邮箱</span>
    <span class="sep">·</span>
    <span>@ 已正确转义 · 邮件收得到</span>
  </footer>

  <script src="assets/js/app.js"></script>
</body>
</html>
