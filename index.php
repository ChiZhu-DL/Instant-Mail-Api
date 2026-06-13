<?php
require_once __DIR__ . '/config.php';

// CSRF token：用 cookie 传递给前端，不依赖 session
// 生成随机 token 并设置 cookie（1小时有效）
if (!empty($_COOKIE['csrf_token'])) {
    $csrfToken = $_COOKIE['csrf_token'];
} else {
    $csrfToken = bin2hex(function_exists('random_bytes') ? random_bytes(32) : md5(uniqid(mt_rand(), true)));
    setcookie('csrf_token', $csrfToken, time() + 3600, '/');
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Instant Mail API 管理系统</title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <!-- 顶部导航栏 -->
    <nav class="navbar">
        <div class="nav-container">
            <div style="display:flex;align-items:center;gap:12px;">
                <button class="nav-toggle" onclick="toggleSidebar()"><i class="fa-solid fa-bars"></i></button>
                <div class="nav-brand">
                    <i class="fa-solid fa-envelope-open-text"></i>
                    <span>Instant Mail</span>
                </div>
            </div>
            <div class="nav-status" id="nav-status">
                <span class="status-dot"></span>
                <span id="status-text">系统初始化中...</span>
            </div>
        </div>
    </nav>

    <!-- 移动端侧边栏遮罩 -->
    <div class="sidebar-overlay" id="sidebar-overlay" onclick="closeSidebar()"></div>

    <!-- 主体容器 -->
    <div class="main-container">
        <!-- 左侧边栏 -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-section">
                <h3 class="sidebar-title"><i class="fa-solid fa-bars-progress"></i> 功能菜单</h3>
                <ul class="nav-list">
                    <li class="nav-item active" data-section="dashboard">
                        <i class="fa-solid fa-gauge-high"></i>
                        <span>控制台</span>
                    </li>
                    <li class="nav-item" data-section="domains">
                        <i class="fa-solid fa-globe"></i>
                        <span>域名管理</span>
                        <span class="badge" id="domain-count">0</span>
                    </li>
                    <li class="nav-item" data-section="create">
                        <i class="fa-solid fa-plus"></i>
                        <span>创建邮箱</span>
                    </li>
                    <li class="nav-item" data-section="messages">
                        <i class="fa-solid fa-inbox"></i>
                        <span>邮件查看</span>
                    </li>
                    <li class="nav-item" data-section="batch">
                        <i class="fa-solid fa-layer-group"></i>
                        <span>批量操作</span>
                    </li>
                    <li class="nav-item" data-section="status">
                        <i class="fa-solid fa-server"></i>
                        <span>API 状态</span>
                    </li>
                    <li class="nav-item" data-section="docs">
                        <i class="fa-solid fa-book"></i>
                        <span>API 文档</span>
                    </li>
                </ul>
            </div>

            <div class="sidebar-section">
                <h3 class="sidebar-title"><i class="fa-solid fa-toolbox"></i> 系统工具</h3>
                <div class="tools-grid">
                    <button class="tool-btn" onclick="clearCache()">
                        <i class="fa-solid fa-broom"></i>
                        <span>清除缓存</span>
                    </button>
                    <button class="tool-btn" onclick="refreshStatus()">
                        <i class="fa-solid fa-rotate"></i>
                        <span>刷新状态</span>
                    </button>
                </div>
            </div>

            <div class="sidebar-section stats-section">
                <h3 class="sidebar-title"><i class="fa-solid fa-chart-pie"></i> 统计信息</h3>
                <div class="stats-mini">
                    <div class="stat-mini-item">
                        <span class="stat-mini-label">已创建邮箱</span>
                        <span class="stat-mini-value" id="mini-email-count">0</span>
                    </div>
                    <div class="stat-mini-item">
                        <span class="stat-mini-label">支持域名</span>
                        <span class="stat-mini-value" id="mini-domain-count">0</span>
                    </div>
                </div>
            </div>
        </aside>

        <!-- 右侧内容区 -->
        <main class="content">
            <!-- 1. 控制台 -->
            <section class="content-section active" id="section-dashboard">
                <div class="section-header">
                    <h1><i class="fa-solid fa-gauge-high"></i> 控制台</h1>
                    <p class="section-desc">快速查看系统状态和常用功能</p>
                </div>

                <!-- 状态卡片 -->
                <div class="cards-grid">
                    <div class="card card-primary">
                        <div class="card-icon"><i class="fa-solid fa-envelope-circle-check"></i></div>
                        <div class="card-content">
                            <h3 class="card-label">已创建邮箱</h3>
                            <p class="card-value" id="stat-email-count">0</p>
                        </div>
                    </div>
                    <div class="card card-success">
                        <div class="card-icon"><i class="fa-solid fa-globe-asia"></i></div>
                        <div class="card-content">
                            <h3 class="card-label">支持域名</h3>
                            <p class="card-value" id="stat-domain-count">0</p>
                        </div>
                    </div>
                    <div class="card card-warning">
                        <div class="card-icon"><i class="fa-solid fa-server"></i></div>
                        <div class="card-content">
                            <h3 class="card-label">API 状态</h3>
                            <p class="card-value"><span class="status-badge online">正常</span></p>
                        </div>
                    </div>
                    <div class="card card-info">
                        <div class="card-icon"><i class="fa-solid fa-layer-group"></i></div>
                        <div class="card-content">
                            <h3 class="card-label">域名分类</h3>
                            <p class="card-value">4 类</p>
                        </div>
                    </div>
                </div>

                <!-- 快速操作 -->
                <div class="panel">
                    <div class="panel-header">
                        <h2><i class="fa-solid fa-bolt"></i> 快速操作</h2>
                    </div>
                    <div class="quick-actions">
                        <button class="action-btn action-primary" onclick="switchSection('create')">
                            <i class="fa-solid fa-plus-circle"></i>
                            <span>快速创建邮箱</span>
                        </button>
                        <button class="action-btn action-success" onclick="loadDomains(); switchSection('domains');">
                            <i class="fa-solid fa-list"></i>
                            <span>查看所有域名</span>
                        </button>
                        <button class="action-btn action-warning" onclick="switchSection('batch');">
                            <i class="fa-solid fa-layer-group"></i>
                            <span>批量创建邮箱</span>
                        </button>
                        <button class="action-btn action-info" onclick="showQuickCreateModal()">
                            <i class="fa-solid fa-envelope"></i>
                            <span>一键创建邮箱</span>
                        </button>
                    </div>
                </div>

                <!-- 最近创建的邮箱（自动保存） -->
                <div class="panel">
                    <div class="panel-header" style="display: flex; justify-content: space-between; align-items: center;">
                        <h2 style="display: flex; align-items: center; gap: 8px;">
                            <i class="fa-solid fa-envelopes-bulk"></i> 
                            已保存的邮箱 
                            <span style="font-size: 12px; background: var(--primary-color); color: white; padding: 2px 8px; border-radius: 10px;" id="saved-email-badge">0</span>
                        </h2>
                        <div style="display: flex; gap: 8px;">
                            <button class="btn btn-info btn-sm" onclick="refreshEmailList()">
                                <i class="fa-solid fa-rotate"></i> 刷新列表
                            </button>
                        </div>
                    </div>
                    <div style="padding: 12px 16px; background: linear-gradient(135deg, #f0f7ff, #e8f5e9); border-bottom: 1px solid #e0e0e0; font-size: 13px; color: #555;">
                        <i class="fa-solid fa-info-circle"></i> 
                        <strong>所有创建的邮箱会自动保存</strong>，刷新页面后也不会丢失。数据保存在服务器上。
                    </div>
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th style="width: 40%;">邮箱地址</th>
                                    <th style="width: 20%;">域名</th>
                                    <th style="width: 15%;">服务</th>
                                    <th style="width: 25%;">操作</th>
                                </tr>
                            </thead>
                            <tbody id="recent-emails-body">
                                <tr>
                                    <td colspan="4" style="text-align: center; padding: 40px;">
                                        <div style="font-size: 48px; color: #ccc; margin-bottom: 12px;">
                                            <i class="fa-solid fa-envelope-open"></i>
                                        </div>
                                        <strong style="font-size: 16px; color: #666;">暂无已保存的邮箱</strong>
                                        <p style="color: #999; margin-top: 8px; font-size: 14px;">
                                            点击左侧 <strong>"创建邮箱"</strong> 或下方 <strong>"快速创建邮箱"</strong> 开始使用
                                        </p>
                                        <button class="btn btn-primary" onclick="switchSection('create');" style="margin-top: 12px;">
                                            <i class="fa-solid fa-plus"></i> 创建第一个邮箱
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <!-- 2. 域名管理 -->
            <section class="content-section" id="section-domains">
                <div class="section-header">
                    <h1><i class="fa-solid fa-globe"></i> 域名管理</h1>
                    <p class="section-desc">查看和管理所有可用的邮箱域名</p>
                </div>

                <!-- 域名分类 -->
                <div class="category-cards" id="category-cards"></div>

                <!-- 筛选栏 -->
                <div class="panel">
                    <div class="filter-bar">
                        <div class="filter-group">
                            <label>按类别筛选：</label>
                            <select id="filter-category" onchange="loadDomains()">
                                <option value="">全部</option>
                                <option value="public">Public 域名</option>
                                <option value="hd">HD 域名</option>
                                <option value="hot">热门类域名</option>
                                <option value="google">谷歌变体</option>
                            </select>
                        </div>
                        <button class="btn btn-primary" onclick="loadDomains(true)">
                            <i class="fa-solid fa-rotate"></i> 刷新域名
                        </button>
                    </div>
                </div>

                <!-- 域名列表 -->
                <div class="panel">
                    <div class="panel-header">
                        <h2><i class="fa-solid fa-list"></i> 可用域名列表</h2>
                        <span class="panel-count" id="domains-count">0 个</span>
                    </div>
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>域名</th>
                                    <th>类别</th>
                                    <th>类型</th>
                                    <th>可用</th>
                                    <th>操作</th>
                                </tr>
                            </thead>
                            <tbody id="domains-body">
                                <tr><td colspan="6" class="text-center text-muted">加载中...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <!-- 3. 创建邮箱 -->
            <section class="content-section" id="section-create">
                <div class="section-header">
                    <h1><i class="fa-solid fa-plus"></i> 创建临时邮箱</h1>
                    <p class="section-desc">创建一个临时邮箱用于接收邮件</p>
                </div>

                <div class="panel">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="create-domain">选择域名 <span class="required">*</span></label>
                            <select id="create-domain">
                                <option value="">随机选择</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="create-name">自定义用户名 <span class="text-muted">(可选)</span></label>
                            <input type="text" id="create-name" placeholder="留空则随机生成" maxlength="64">
                            <small class="form-hint">支持字母、数字、下划线、减号</small>
                        </div>
                    </div>
                    <div class="form-actions">
                        <button class="btn btn-primary btn-lg" onclick="createEmail()">
                            <i class="fa-solid fa-envelope-plus"></i> 创建邮箱
                        </button>
                        <button class="btn btn-warning" onclick="createGmailEmail()">
                            <i class="fa-brands fa-google"></i> 创建 Gmail 类邮箱
                        </button>
                    </div>
                </div>

                <!-- 创建结果 -->
                <div class="panel" id="create-result-panel" style="display:none;">
                    <div class="panel-header">
                        <h2><i class="fa-solid fa-check"></i> 创建结果</h2>
                    </div>
                    <div id="create-result"></div>
                </div>
            </section>

            <!-- 4. 邮件查看 -->
            <section class="content-section" id="section-messages">
                <div class="section-header">
                    <h1><i class="fa-solid fa-inbox"></i> 邮件查看</h1>
                    <p class="section-desc">查看指定邮箱的收件列表</p>
                </div>

                <!-- 邮箱输入 -->
                <div class="panel">
                    <div class="form-grid">
                        <div class="form-group" style="grid-column: span 2;">
                            <label for="check-email">邮箱地址 <span class="required">*</span></label>
                            <input type="text" id="check-email" placeholder="example@bltiwd.com">
                        </div>
                    </div>
                    <div class="form-actions">
                        <button class="btn btn-primary btn-lg" onclick="getMessages()">
                            <i class="fa-solid fa-magnifying-glass"></i> 获取邮件
                        </button>
                        <button class="btn btn-info" onclick="getEmailStats()">
                            <i class="fa-solid fa-chart-column"></i> 查看统计
                        </button>
                    </div>
                </div>

                <!-- 统计信息 -->
                <div class="panel" id="email-stats-panel" style="display:none;">
                    <div class="panel-header">
                        <h2><i class="fa-solid fa-chart-pie"></i> 邮箱统计</h2>
                    </div>
                    <div id="email-stats"></div>
                </div>

                <!-- 邮件列表 -->
                <div class="panel">
                    <div class="panel-header">
                        <h2><i class="fa-solid fa-envelope-open"></i> 收件箱</h2>
                        <span class="panel-count" id="messages-count">0 封</span>
                    </div>
                    <div id="messages-list">
                        <p class="text-center text-muted">请先输入邮箱地址并点击获取邮件</p>
                    </div>
                </div>
            </section>

            <!-- 5. 批量操作 -->
            <section class="content-section" id="section-batch">
                <div class="section-header">
                    <h1><i class="fa-solid fa-layer-group"></i> 批量操作</h1>
                    <p class="section-desc">批量创建多个临时邮箱</p>
                </div>

                <div class="panel">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="batch-count">创建数量 <span class="required">*</span></label>
                            <input type="number" id="batch-count" value="5" min="1" max="50">
                            <small class="form-hint">支持 1-50 个</small>
                        </div>
                        <div class="form-group">
                            <label for="batch-domain">指定域名 <span class="text-muted">(可选)</span></label>
                            <select id="batch-domain">
                                <option value="">随机选择</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-actions">
                        <button class="btn btn-primary btn-lg" onclick="batchCreateEmails()">
                            <i class="fa-solid fa-layer-group"></i> 批量创建
                        </button>
                    </div>
                </div>

                <!-- 批量结果 -->
                <div class="panel" id="batch-result-panel" style="display:none;">
                    <div class="panel-header">
                        <h2><i class="fa-solid fa-list-check"></i> 批量创建结果</h2>
                        <span class="panel-count" id="batch-summary">0/0</span>
                    </div>
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>邮箱</th>
                                    <th>令牌</th>
                                    <th>状态</th>
                                </tr>
                            </thead>
                            <tbody id="batch-result-body"></tbody>
                        </table>
                    </div>
                </div>
            </section>

            <!-- 6. API 状态 -->
            <section class="content-section" id="section-status">
                <div class="section-header">
                    <h1><i class="fa-solid fa-server"></i> API 服务状态</h1>
                    <p class="section-desc">查看各 API 服务的运行状态</p>
                </div>

                <div class="status-cards" id="status-cards">
                    <div class="status-card">
                        <div class="status-card-icon"><i class="fa-solid fa-globe"></i></div>
                        <h3>Public API</h3>
                        <p class="status-text" id="status-public">检测中...</p>
                        <small>api.internal.temp-mail.io</small>
                    </div>
                    <div class="status-card">
                        <div class="status-card-icon"><i class="fa-solid fa-gem"></i></div>
                        <h3>HD API</h3>
                        <p class="status-text" id="status-hd">检测中...</p>
                        <small>mail-server.1timetech.com</small>
                    </div>
                    <div class="status-card">
                        <div class="status-card-icon"><i class="fa-brands fa-google"></i></div>
                        <h3>Gmail API</h3>
                        <p class="status-text" id="status-gmail">检测中...</p>
                        <small>mail-server-2.1timetech.com</small>
                    </div>
                </div>

                <div class="panel">
                    <div class="panel-header">
                        <h2><i class="fa-solid fa-circle-info"></i> 系统信息</h2>
                    </div>
                    <div class="system-info">
                        <div class="info-item">
                            <span class="info-label">系统名称：</span>
                            <span class="info-value">Instant Mail API 管理系统</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">版本号：</span>
                            <span class="info-value">2.0.0</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">时区：</span>
                            <span class="info-value">Asia/Shanghai</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">开源链接</span>
                            <span class="info-value">https://github.com/ChiZhu-DL/Instant-Mail-Api</span>
                        </div>
                    </div>
                </div>
            </section>

            <!-- 7. API 文档 -->
            <section class="content-section" id="section-docs">
                <div class="section-header">
                    <h1><i class="fa-solid fa-book"></i> API 接口文档</h1>
                    <p class="section-desc">支持网页内调用和 curl / 脚本直接调用</p>
                </div>

                <!-- 认证说明 -->
                <div class="panel">
                    <div class="panel-header">
                        <h2><i class="fa-solid fa-key"></i> 认证方式</h2>
                    </div>
                    <div class="doc-section">
                        <div class="doc-item">
                            <h3>方式一：网页内调用（自动 CSRF）</h3>
                            <p>通过本页面操作时，系统自动处理 CSRF 令牌，无需额外配置。</p>
                        </div>
                        <div class="doc-item">
                            <h3>方式二：curl / 脚本调用（API Key）</h3>
                            <p>通过 <code>X-API-Key</code> 请求头传入 API 密钥，即可绕过 CSRF 验证：</p>
                            <div class="code-block" style="margin:12px 0 0 0">curl -X POST "http://你的域名/api.php?action=create_email" \
  -H "X-API-Key: <?php echo htmlspecialchars(API_SECRET_KEY, ENT_QUOTES, 'UTF-8'); ?>" \
  -d "domain=bltiwd.com"</div>
                        </div>
                    </div>
                </div>

                <!-- 接口列表 -->
                <div class="panel">
                    <div class="panel-header">
                        <h2><i class="fa-solid fa-link"></i> 接口列表</h2>
                    </div>
                    <div class="doc-section">
                        <div class="doc-item">
                            <h3><span class="method-get">GET</span> api.php?action=get_domains</h3>
                            <p>获取所有可用的域名列表</p>
                            <div class="doc-params">
                                <strong>参数：</strong>
                                <ul>
                                    <li><code>category</code> - 可选，按类别筛选 (public/hd/hot/google)</li>
                                    <li><code>refresh</code> - 可选，是否强制刷新缓存 (1/0)</li>
                                </ul>
                            </div>
                        </div>

                        <div class="doc-item">
                            <h3><span class="method-post">POST</span> api.php?action=create_email</h3>
                            <p>创建一个临时邮箱</p>
                            <div class="doc-params">
                                <strong>参数：</strong>
                                <ul>
                                    <li><code>domain</code> - 可选，指定域名</li>
                                    <li><code>name</code> - 可选，自定义用户名</li>
                                </ul>
                            </div>
                        </div>

                        <div class="doc-item">
                            <h3><span class="method-post">POST</span> api.php?action=create_gmail</h3>
                            <p>创建 Gmail 类邮箱（实验性功能）</p>
                        </div>

                        <div class="doc-item">
                            <h3><span class="method-get">GET</span> api.php?action=get_messages&amp;email=xxx</h3>
                            <p>获取指定邮箱的邮件列表</p>
                            <div class="doc-params">
                                <strong>参数：</strong>
                                <ul>
                                    <li><code>email</code> - 必填，邮箱地址</li>
                                </ul>
                            </div>
                        </div>

                        <div class="doc-item">
                            <h3><span class="method-post">POST</span> api.php?action=delete_email</h3>
                            <p>删除指定邮箱</p>
                            <div class="doc-params">
                                <strong>参数：</strong>
                                <ul>
                                    <li><code>email</code> - 必填，邮箱地址</li>
                                    <li><code>token</code> - 必填，删除令牌</li>
                                </ul>
                            </div>
                        </div>

                        <div class="doc-item">
                            <h3><span class="method-post">POST</span> api.php?action=batch_create</h3>
                            <p>批量创建多个邮箱</p>
                            <div class="doc-params">
                                <strong>参数：</strong>
                                <ul>
                                    <li><code>count</code> - 必填，数量 (1-50)</li>
                                    <li><code>domain</code> - 可选，指定域名</li>
                                </ul>
                            </div>
                        </div>

                        <div class="doc-item">
                            <h3><span class="method-get">GET</span> api.php?action=get_stats&amp;email=xxx</h3>
                            <p>获取邮箱统计信息</p>
                        </div>

                        <div class="doc-item">
                            <h3><span class="method-get">GET</span> api.php?action=get_status</h3>
                            <p>获取各 API 服务状态</p>
                        </div>

                        <div class="doc-item">
                            <h3><span class="method-get">GET</span> api.php?action=get_categories</h3>
                            <p>获取域名分类信息</p>
                        </div>

                        <div class="doc-item">
                            <h3><span class="method-get">GET</span> api.php?action=quick_create</h3>
                            <p>快速创建邮箱（简化接口）</p>
                        </div>

                        <div class="doc-item">
                            <h3><span class="method-post">POST</span> api.php?action=clear_cache</h3>
                            <p>清除系统缓存</p>
                        </div>
                    </div>
                </div>

                <div class="panel">
                    <div class="panel-header">
                        <h2><i class="fa-solid fa-code"></i> 响应格式</h2>
                    </div>
                    <pre class="code-block"><code>{
  "success": true,
  "email": "example@bltiwd.com",
  "token": "your-token-here",
  "domain": "bltiwd.com",
  "service": "public"
}</code></pre>
                </div>

                <!-- curl 示例 -->
                <div class="panel">
                    <div class="panel-header">
                        <h2><i class="fa-solid fa-terminal"></i> curl 调用示例</h2>
                    </div>
                    <div class="doc-section">
                        <div class="doc-item">
                            <h3>创建邮箱</h3>
                            <pre class="code-block" style="margin:8px 0 0 0">curl -X POST "http://你的域名/api.php?action=create_email" \
  -H "X-API-Key: <?php echo htmlspecialchars(API_SECRET_KEY, ENT_QUOTES, 'UTF-8'); ?>" \
  -d "domain=bltiwd.com"</pre>
                        </div>
                        <div class="doc-item">
                            <h3>获取域名列表</h3>
                            <pre class="code-block" style="margin:8px 0 0 0">curl "http://你的域名/api.php?action=get_domains"</pre>
                        </div>
                        <div class="doc-item">
                            <h3>获取邮件</h3>
                            <pre class="code-block" style="margin:8px 0 0 0">curl "http://你的域名/api.php?action=get_messages&email=xxx@bltiwd.com"</pre>
                        </div>
                        <div class="doc-item">
                            <h3>获取已保存邮箱</h3>
                            <pre class="code-block" style="margin:8px 0 0 0">curl "http://你的域名/api.php?action=get_stored_emails"</pre>
                        </div>
                        <div class="doc-item">
                            <h3>删除邮箱</h3>
                            <pre class="code-block" style="margin:8px 0 0 0">curl -X POST "http://你的域名/api.php?action=delete_email" \
  -H "X-API-Key: <?php echo htmlspecialchars(API_SECRET_KEY, ENT_QUOTES, 'UTF-8'); ?>" \
  -d "email=xxx@bltiwd.com&token=your-token"</pre>
                        </div>
                        <div class="doc-item">
                            <h3>批量创建</h3>
                            <pre class="code-block" style="margin:8px 0 0 0">curl -X POST "http://你的域名/api.php?action=batch_create" \
  -H "X-API-Key: <?php echo htmlspecialchars(API_SECRET_KEY, ENT_QUOTES, 'UTF-8'); ?>" \
  -d "count=5&domain=bltiwd.com"</pre>
                        </div>
                        <div class="doc-item">
                            <h3>测试 API 是否可用</h3>
                            <pre class="code-block" style="margin:8px 0 0 0">curl -X POST "http://你的域名/api.php?action=get_status" \
  -H "X-API-Key: <?php echo htmlspecialchars(API_SECRET_KEY, ENT_QUOTES, 'UTF-8'); ?>"</pre>
                        </div>
                    </div>
                </div>

                <!-- PHP 调用示例 -->
                <div class="panel">
                    <div class="panel-header">
                        <h2><i class="fa-brands fa-php"></i> PHP 调用示例</h2>
                    </div>
                    <div class="doc-section">
                        <div class="doc-item">
                            <h3>创建邮箱（POST）</h3>
                            <pre class="code-block" style="margin:8px 0 0 0">&lt;?php

$api_key = '<?php echo htmlspecialchars(API_SECRET_KEY, ENT_QUOTES, 'UTF-8'); ?>';
$url = 'http://你的域名/api.php?action=create_email';
$data = ['domain' => 'bltiwd.com'];

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-API-Key: ' . $api_key]);
$response = curl_exec($ch);
curl_close($ch);

$result = json_decode($response, true);
if ($result && $result['success']) {
    echo '邮箱: ' . $result['email'] . "\n";
    echo '令牌: ' . $result['token'] . "\n";
}</pre>
                        </div>
                        <div class="doc-item">
                            <h3>获取邮件（GET）</h3>
                            <pre class="code-block" style="margin:8px 0 0 0">&lt;?php

$email = 'example@bltiwd.com';
$url = 'http://你的域名/api.php?action=get_messages&email=' . urlencode($email);

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$response = curl_exec($ch);
curl_close($ch);

$result = json_decode($response, true);
if ($result && $result['success']) {
    foreach ($result['messages'] as $msg) {
        echo '主题: ' . $msg['subject'] . "\n";
        echo '发件人: ' . $msg['from'] . "\n";
        echo "---\n";
    }
}</pre>
                        </div>
                        <div class="doc-item">
                            <h3>获取域名列表（GET）</h3>
                            <pre class="code-block" style="margin:8px 0 0 0">&lt;?php

$url = 'http://你的域名/api.php?action=get_domains';
$response = file_get_contents($url);
$result = json_decode($response, true);
if ($result && $result['success']) {
    foreach ($result['data'] as $domain) {
        echo $domain['domain'] . ' - ' . $domain['category'] . "\n";
    }
}</pre>
                        </div>
                        <div class="doc-item">
                            <h3>批量创建（POST）</h3>
                            <pre class="code-block" style="margin:8px 0 0 0">&lt;?php

$api_key = '<?php echo htmlspecialchars(API_SECRET_KEY, ENT_QUOTES, 'UTF-8'); ?>';
$url = 'http://你的域名/api.php?action=batch_create';
$data = ['count' => 3, 'domain' => 'bltiwd.com'];

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-API-Key: ' . $api_key]);
$response = curl_exec($ch);
curl_close($ch);

echo $response;</pre>
                        </div>
                    </div>
                </div>

                <!-- API Key 说明 -->
                <div class="panel">
                    <div class="panel-header">
                        <h2><i class="fa-solid fa-key"></i> API Key 获取与使用</h2>
                    </div>
                    <div class="doc-section">
                        <div class="doc-item">
                            <h3>什么是 API Key？</h3>
                            <p>API Key 是系统为脚本调用生成的安全密钥，用于绕过网页的 CSRF 验证。每个部署环境的 API Key 都不同。</p>
                        </div>
                        <div class="doc-item">
                            <h3>如何获取 API Key？</h3>
                            <p>API Key 基于部署路径自动生成，在本页面上方的 <b>认证方式</b> 区域可以看到您的 API Key：</p>
                            <div class="code-block" style="margin:12px 0 0 0">您的 API Key:
<?php echo htmlspecialchars(API_SECRET_KEY, ENT_QUOTES, 'UTF-8'); ?></div>
                            <p style="margin-top:12px">或直接查看 <code>config.php</code> 中的 <code>API_SECRET_KEY</code> 常量定义。</p>
                        </div>
                        <div class="doc-item">
                            <h3>如何使用？</h3>
                            <p>在 POST 请求的 HTTP Header 中添加：</p>
                            <div class="code-block" style="margin:12px 0 0 0">X-API-Key: <?php echo htmlspecialchars(API_SECRET_KEY, ENT_QUOTES, 'UTF-8'); ?></div>
                            <p style="margin-top:12px">以下操作需要 API Key：</p>
                            <ul style="margin:8px 0 0 24px; color: var(--text-secondary); line-height: 1.8;">
                                <li>创建邮箱 (create_email)</li>
                                <li>批量创建 (batch_create)</li>
                                <li>删除邮箱 (delete_email)</li>
                                <li>清除缓存 (clear_cache)</li>
                                <li>手动添加邮箱 (add_email)</li>
                                <li>清空邮箱列表 (clear_emails)</li>
                                <li>删除邮箱记录 (remove_email_record)</li>
                            </ul>
                            <p style="margin-top:12px">以下操作 <b>不需要</b> API Key（公开接口）：</p>
                            <ul style="margin:8px 0 0 24px; color: var(--text-secondary); line-height: 1.8;">
                                <li>获取域名列表 (get_domains)</li>
                                <li>获取邮件 (get_messages)</li>
                                <li>获取已保存邮箱 (get_stored_emails)</li>
                                <li>查看 API 状态 (get_status)</li>
                                <li>获取域名分类 (get_categories)</li>
                                <li>查看系统基本信息 (不带 action 的请求)</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </section>
        </main>
    </div>

    <!-- 模态框：快速创建结果 -->
    <div class="modal" id="quick-modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fa-solid fa-envelope-open"></i> 邮箱创建成功</h3>
                <button class="modal-close" onclick="closeModal('quick-modal')">&times;</button>
            </div>
            <div class="modal-body" id="quick-modal-body"></div>
        </div>
    </div>

    <!-- 模态框：邮件详情 -->
    <div class="modal" id="message-modal">
        <div class="modal-content modal-wide">
            <div class="modal-header">
                <h3><i class="fa-solid fa-envelope-open-text"></i> 邮件详情</h3>
                <button class="modal-close" onclick="closeModal('message-modal')">&times;</button>
            </div>
            <div class="modal-body" id="message-modal-body"></div>
        </div>
    </div>

    <!-- 通知容器 -->
    <div class="notification-container" id="notification-container"></div>

    <script>
        // CSRF token from PHP session
        window.CSRF_TOKEN = '<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>';
    </script>
    <script src="js/app.js"></script>
</body>
</html>
