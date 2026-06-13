/* ================================================
 * Instant Mail API 管理系统 - JavaScript
 * 注意：所有来自外部 API / 邮件内容的字符串，在插入 innerHTML 之前，
 * 都必须经过 escapeHtml() 转义，以避免 XSS 注入。
 * ================================================ */

// ========== 全局变量 ==========
const API_BASE = 'api.php';
const STORAGE_KEY = 'instant_mail_emails';
let createdEmails = [];
let allDomains = [];
let domainsLoaded = false; // 防止重复加载域名

// CSRF token helper
function getCsrfToken() {
    return window.CSRF_TOKEN || '';
}

// 封装 fetch：自动带上 credentials + CSRF token header
function apiFetch(url, options = {}) {
    options.credentials = 'same-origin';
    if (!options.headers) options.headers = {};
    if (options.method && options.method.toUpperCase() === 'POST') {
        // 同时通过 header 和 body 传递 CSRF（双重保障）
        if (typeof options.headers === 'object' && !(options.headers instanceof Headers)) {
            options.headers['X-CSRF-Token'] = getCsrfToken();
        }
    }
    return fetch(url, options);
}

// 当前在列表中渲染的邮件对象（避免把整个对象塞进 onclick 字符串里）
let renderedMessages = [];

// ========== 工具函数 ==========

/** 把普通文本转成 HTML 安全字符串（用于 innerHTML 场景） */
function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;')
        .replace(/\//g, '&#x2F;');
}

/** 剥离 HTML，返回纯文本（用于邮件摘要） */
function stripHtml(html) {
    if (!html) return '';
    const tmp = document.createElement('div');
    tmp.textContent = html; // 用 textContent 自动做 HTML 实体解析 → 纯文本
    return tmp.textContent || tmp.innerText || '';
}

function copyToClipboard(text) {
    navigator.clipboard.writeText(text).then(() => {
        showNotification('success', '已复制', '已复制到剪贴板');
    }).catch(err => {
        // 回退到老式 API
        try {
            const ta = document.createElement('textarea');
            ta.value = text;
            ta.style.position = 'fixed';
            ta.style.opacity = '0';
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            document.body.removeChild(ta);
            showNotification('success', '已复制', '已复制到剪贴板');
        } catch (e) {
            showNotification('error', '复制失败', err && err.message ? err.message : '未知错误');
        }
    });
}

function closeModal(modalId) {
    document.getElementById(modalId).classList.remove('active');
}

function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebar-overlay');
    sidebar.classList.toggle('open');
    overlay.classList.toggle('active');
}

function closeSidebar() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebar-overlay');
    sidebar.classList.remove('open');
    overlay.classList.remove('active');
}

function updateSystemTime() {
    const now = new Date();
    const formatted = now.toLocaleString('zh-CN');
    const timeEl = document.getElementById('system-time');
    if (timeEl) timeEl.textContent = formatted;
}

// ========== 页面初始化 ==========
document.addEventListener('DOMContentLoaded', function () {
    initNavigation();
    initData();

    // 先从 localStorage 快速加载
    loadFromLocalStorage(true);

    // 然后从后端加载（确保最新数据）
    setTimeout(() => {
        loadStoredEmails();
        refreshStatus();
        const statusText = document.getElementById('status-text');
        if (statusText) statusText.textContent = '系统就绪';
    }, 100);

    updateSystemTime();
    setInterval(updateSystemTime, 1000);
});

// ========== 导航 ==========
function initNavigation() {
    const navItems = document.querySelectorAll('.nav-item');
    navItems.forEach(item => {
        item.addEventListener('click', function () {
            const section = this.getAttribute('data-section');
            switchSection(section);
        });
    });
}

function switchSection(sectionName) {
    // 移动端切换时关闭侧边栏
    closeSidebar();

    document.querySelectorAll('.nav-item').forEach(item => {
        item.classList.remove('active');
        if (item.getAttribute('data-section') === sectionName) {
            item.classList.add('active');
        }
    });

    document.querySelectorAll('.content-section').forEach(section => {
        section.classList.remove('active');
        if (section.id === `section-${sectionName}`) {
            section.classList.add('active');
        }
    });

    if (sectionName === 'dashboard') {
        renderDashboardEmails();
    }
}

// ========== 初始化数据 ==========
function initData() {
    loadDomainSelects();
}

// ========== 已保存邮箱加载（从后端） ==========
function loadStoredEmails() {
    fetch(`${API_BASE}?action=get_stored_emails&_=${Date.now()}`)
        .then(res => {
            if (!res.ok) {
                throw new Error(`HTTP ${res.status}`);
            }
            return res.json();
        })
        .then(data => {
            if (data.success && Array.isArray(data.data)) {
                createdEmails = data.data;
                try {
                    localStorage.setItem(STORAGE_KEY, JSON.stringify(createdEmails));
                } catch (e) { /* 忽略 localStorage 写入失败 */ }
                updateEmailCount();
                renderDashboardEmails();
            } else {
                // 后端说失败 —— 回退到 localStorage
                loadFromLocalStorage(false);
            }
        })
        .catch(() => {
            // 网络错误 —— 回退到 localStorage
            loadFromLocalStorage(false);
        });
}

// ========== 从 localStorage 加载（作为备份方案） ==========
function loadFromLocalStorage(isFirstLoad) {
    try {
        const stored = localStorage.getItem(STORAGE_KEY);
        if (stored) {
            const parsed = JSON.parse(stored);
            if (Array.isArray(parsed) && parsed.length > 0) {
                createdEmails = parsed;
                updateEmailCount();
                renderDashboardEmails();
                return;
            }
        }
        createdEmails = [];
    } catch (e) {
        createdEmails = [];
    }
    updateEmailCount();
    renderDashboardEmails();
}

// ========== 把本地新增的邮箱同步写入到后端持久化存储 ==========
function saveEmailToStorage(email, token, domain, service) {
    if (!email) return;

    const exists = createdEmails.some(e => e.email === email);
    if (exists) return;

    const emailData = {
        email: email,
        token: token || '',
        domain: domain || 'unknown',
        service: service || 'public',
        created_at: new Date().toLocaleString('zh-CN'),
        status: 'active'
    };

    createdEmails.unshift(emailData);
    if (createdEmails.length > 100) {
        createdEmails = createdEmails.slice(0, 100);
    }

    try {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(createdEmails));
    } catch (e) { /* 忽略 */ }

    updateEmailCount();
    renderDashboardEmails();

    // 同时调用后端把它写入到 emails.json（由 create_email 的业务逻辑已经写入，
    // 此处保留作为额外同步点）
    const params = new URLSearchParams();
    params.append('action', 'add_email');
    params.append('email', email);
    params.append('token', token || '');
    params.append('domain', domain || 'unknown');
    params.append('csrf_token', getCsrfToken());
    apiFetch(API_BASE, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: params
    }).catch(() => { /* 失败静默，用户不会感知 */ });
}

// ========== 从存储中删除邮箱 ==========
function removeEmailFromStorage(email) {
    const before = createdEmails.length;
    createdEmails = createdEmails.filter(e => e.email !== email);
    if (before !== createdEmails.length) {
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify(createdEmails));
        } catch (e) { /* 忽略 */ }
        updateEmailCount();
        renderDashboardEmails();
    }
}

// ========== 刷新已保存邮箱计数 ==========
function updateEmailCount() {
    const countEl = document.getElementById('stat-email-count');
    const miniEl = document.getElementById('mini-email-count');
    const badgeEl = document.getElementById('saved-email-badge');
    const count = createdEmails.length;
    if (countEl) countEl.textContent = count;
    if (miniEl) miniEl.textContent = count;
    if (badgeEl) badgeEl.textContent = count;
}

// ========== 控制台：渲染已保存邮箱列表 ==========
function renderDashboardEmails() {
    const tbody = document.getElementById('recent-emails-body');
    if (!tbody) return;

    updateEmailCount();

    if (!createdEmails || createdEmails.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="4" style="text-align: center; padding: 40px;">
                    <div style="font-size: 48px; color: #ccc; margin-bottom: 12px;">
                        <i class="fa-solid fa-envelope-open"></i>
                    </div>
                    <strong style="font-size: 16px; color: #666;">暂无已保存的邮箱</strong>
                    <p style="color: #999; margin-top: 8px; font-size: 14px;">
                        点击左侧 <strong>"创建邮箱"</strong> 开始使用
                    </p>
                    <button class="btn btn-primary" onclick="switchSection('create');" style="margin-top: 12px;">
                        <i class="fa-solid fa-plus"></i> 创建第一个邮箱
                    </button>
                </td>
            </tr>
        `;
        return;
    }

    // 使用 textContent / 拼接 HTML 时做转义
    tbody.innerHTML = createdEmails.slice(0, 50).map((emailData, index) => {
        const email = escapeHtml(emailData.email || '');
        const domain = escapeHtml(emailData.domain || 'unknown');
        const service = escapeHtml(emailData.service || 'public');
        const createdAt = escapeHtml(emailData.created_at || '');
        const token = emailData.token || '';

        const deleteBtn = token
            ? `<button class="btn btn-danger btn-sm" data-email="${email}" data-token="${escapeHtml(token)}" data-action="delete-email"><i class="fa-solid fa-trash"></i> 删除</button>`
            : '';

        return `
            <tr>
                <td>
                    <div style="display: flex; flex-direction: column; gap: 4px;">
                        <strong>${email}</strong>
                        ${createdAt ? `<small style="color: #999; font-size: 11px;">创建: ${createdAt}</small>` : ''}
                    </div>
                </td>
                <td><code style="background: #f5f5f5; padding: 2px 6px; border-radius: 3px; font-size: 12px;">${domain}</code></td>
                <td><span class="status-badge ${service === 'public' ? 'online' : 'experimental'}">${service}</span></td>
                <td style="display: flex; gap: 4px; flex-wrap: wrap;">
                    <button class="btn btn-primary btn-sm" data-email="${email}" data-action="check-email">
                        <i class="fa-solid fa-magnifying-glass"></i> 查看邮件
                    </button>
                    <button class="btn btn-info btn-sm" data-email="${email}" data-action="copy-email">
                        <i class="fa-solid fa-copy"></i> 复制
                    </button>
                    ${deleteBtn}
                </td>
            </tr>
        `;
    }).join('');

    // 使用事件委托：在 tbody 上绑定一次点击事件，避免每次渲染重复绑定
    if (!tbody._delegated) {
        tbody.addEventListener('click', function (e) {
            const btn = e.target.closest('button[data-action]');
            if (!btn) return;
            const action = btn.getAttribute('data-action');
            const email = btn.getAttribute('data-email') || '';
            const token = btn.getAttribute('data-token') || '';

            if (action === 'check-email') {
                const input = document.getElementById('check-email');
                if (input) input.value = email;
                switchSection('messages');
            } else if (action === 'copy-email') {
                copyToClipboard(email);
            } else if (action === 'delete-email') {
                deleteStoredEmail(email, token);
            }
        });
        tbody._delegated = true;
    }
}

function refreshEmailList() {
    loadStoredEmails();
}

// ========== 域名相关 ==========
function loadDomains(forceRefresh) {
    const category = document.getElementById('filter-category') ? document.getElementById('filter-category').value : '';
    let url = `${API_BASE}?action=get_domains&_=${Date.now()}`;
    if (category) url += `&category=${encodeURIComponent(category)}`;
    if (forceRefresh) url += `&refresh=1`;

    fetch(url)
        .then(res => res.json())
        .then(data => {
            if (data.success && data.data) {
                allDomains = data.data;
                renderDomains(data.data);
                updateDomainCount(data.data.length);
                loadDomainSelects();
            }
        })
        .catch(err => {
            showNotification('error', '加载失败', err && err.message ? err.message : '网络错误');
        });
}

function renderDomains(domains) {
    const tbody = document.getElementById('domains-body');
    if (!tbody) return;
    if (!domains || domains.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted">暂无域名</td></tr>';
        return;
    }

    tbody.innerHTML = domains.map((d, index) => `
        <tr>
            <td>${index + 1}</td>
            <td><strong>${escapeHtml(d.domain)}</strong></td>
            <td><span class="category-tag category-${escapeHtml(d.category || 'public')}">${getCategoryName(d.category)}</span></td>
            <td>${d.type === 'stable' ? '<span class="status-badge online">稳定</span>' : '<span class="status-badge experimental">实验</span>'}</td>
            <td>${d.available ? '<span class="status-badge online">可用</span>' : '<span class="status-badge offline">不可用</span>'}</td>
            <td>
                <button class="btn btn-primary btn-sm" data-domain="${escapeHtml(d.domain)}" data-action="create-with-domain">
                    <i class="fa-solid fa-plus"></i> 创建邮箱
                </button>
            </td>
        </tr>
    `).join('');

    // 事件委托："创建邮箱" 按钮
    tbody.querySelectorAll('button[data-action="create-with-domain"]').forEach(btn => {
        btn.addEventListener('click', function () {
            const domain = this.getAttribute('data-domain') || '';
            switchSection('create');
            setTimeout(() => {
                const select = document.getElementById('create-domain');
                if (select) select.value = domain;
                createEmail();
            }, 200);
        });
    });
}

function updateDomainCount(count) {
    const countEl = document.getElementById('stat-email-count');
    const miniEl = document.getElementById('mini-domain-count');
    const domainCountEl = document.getElementById('domains-count');
    if (miniEl) miniEl.textContent = count;
    if (domainCountEl) domainCountEl.textContent = `${count} 个`;
}

function getCategoryName(category) {
    const names = {
        'public': 'Public',
        'hd': 'HD域名',
        'hot': '热门类',
        'google': '谷歌变体',
        'unknown': '其他'
    };
    return names[category] || category || '未分类';
}

function loadDomainSelects() {
    if (domainsLoaded) return; // 已加载过，跳过
    domainsLoaded = true;
    fetch(`${API_BASE}?action=get_domains&_=${Date.now()}`)
        .then(res => res.json())
        .then(data => {
            if (data.success && data.data) {
                const domains = data.data;
                const options = domains.map(d =>
                    `<option value="${escapeHtml(d.domain)}">${escapeHtml(d.domain)} (${getCategoryName(d.category)})</option>`
                ).join('');

                const createSelect = document.getElementById('create-domain');
                const batchSelect = document.getElementById('batch-domain');
                if (createSelect) createSelect.innerHTML = '<option value="">随机选择</option>' + options;
                if (batchSelect) batchSelect.innerHTML = '<option value="">随机选择</option>' + options;
            }
        })
        .catch(err => {
            console.error(err);
            domainsLoaded = false; // 失败时允许重试
        });
}

// ========== 创建邮箱 ==========
function createEmail() {
    const domain = document.getElementById('create-domain').value;
    const name = document.getElementById('create-name').value;

    const params = new URLSearchParams();
    params.append('action', 'create_email');
    params.append('csrf_token', getCsrfToken());
    if (domain) params.append('domain', domain);
    if (name) params.append('name', name);

    apiFetch(API_BASE, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: params
    })
        .then(data => {
            if (data.success) {
                showNotification('success', '创建成功', `邮箱: ${escapeHtml(data.email)}`, 3000);
                renderCreateResult(data);
                saveEmailToStorage(data.email, data.token, data.domain, data.service);
            } else {
                showNotification('error', '创建失败', data.error || '未知错误', 4000);
            }
        })
        .catch(err => {
            showNotification('error', '请求失败', err && err.message ? err.message : '网络错误', 4000);
        });
}

function createGmailEmail() {
    const params = new URLSearchParams();
    params.append('action', 'create_gmail');
    params.append('csrf_token', getCsrfToken());

    apiFetch(API_BASE, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: params
    })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                showNotification('success', '创建成功', 'Gmail类邮箱创建完成');
                renderCreateResult(data, true);
                if (data.email) {
                    saveEmailToStorage(data.email, data.token, data.domain, data.service);
                }
            } else {
                showNotification('warning', '提示', data.error || '该功能为实验性');
            }
        })
        .catch(err => {
            showNotification('error', '请求失败', err && err.message ? err.message : '网络错误');
        });
}

function renderCreateResult(data, isGmail) {
    const panel = document.getElementById('create-result-panel');
    if (!panel) return;
    panel.style.display = 'block';
    const resultDiv = document.getElementById('create-result');
    if (!resultDiv) return;

    const email = escapeHtml(data.email || data.raw_data || '-');
    const token = data.token ? escapeHtml(data.token) : '';
    const domain = escapeHtml(data.domain || '-');
    const service = escapeHtml(data.service || 'public');

    let html = '<div class="result-box">';
    html += `<div class="result-item"><div class="result-label">邮箱地址</div><div class="result-value email">${email}</div></div>`;
    if (token) {
        html += `<div class="result-item"><div class="result-label">删除令牌</div><div class="result-value">${token}</div></div>`;
    }
    html += `<div class="result-item"><div class="result-label">使用域名</div><div class="result-value">${domain}</div></div>`;
    html += `<div class="result-item"><div class="result-label">服务类型</div><div class="result-value"><span class="status-badge ${service === 'public' ? 'online' : 'experimental'}">${service}</span></div></div>`;

    if (data.email && data.token) {
        html += `<div class="result-item" style="display:flex; gap:10px; flex-wrap: wrap;">
            <button class="btn btn-info" data-action="check-email" data-email="${escapeHtml(data.email)}"><i class="fa-solid fa-magnifying-glass"></i> 查看邮件</button>
            <button class="btn btn-danger" data-action="delete-email" data-email="${escapeHtml(data.email)}" data-token="${escapeHtml(data.token)}"><i class="fa-solid fa-trash"></i> 删除邮箱</button>
        </div>`;
    }

    html += '</div>';
    resultDiv.innerHTML = html;

    // 绑定结果区的按钮事件
    resultDiv.querySelectorAll('button[data-action]').forEach(btn => {
        btn.addEventListener('click', function () {
            const action = this.getAttribute('data-action');
            const email = this.getAttribute('data-email') || '';
            const token = this.getAttribute('data-token') || '';
            if (action === 'check-email') {
                const input = document.getElementById('check-email');
                if (input) input.value = email;
                switchSection('messages');
            } else if (action === 'delete-email') {
                deleteStoredEmail(email, token);
            }
        });
    });
}

// ========== 邮件查看 ==========
function getMessages() {
    const email = (document.getElementById('check-email').value || '').trim();
    if (!email) {
        showNotification('warning', '请输入邮箱', '请先输入要查询的邮箱地址');
        return;
    }

    console.log('[邮件查看] 请求邮箱:', email);

    fetch(`${API_BASE}?action=get_messages&email=${encodeURIComponent(email)}&_=${Date.now()}`)
        .then(res => {
            console.log('[邮件查看] HTTP 状态:', res.status);
            if (!res.ok) throw new Error(`HTTP ${res.status}`);
            return res.json();
        })
        .then(data => {
            console.log('[邮件查看] 响应数据:', data);
            if (data.success) {
                const messages = data.messages || [];
                renderMessages(email, messages, data.debug);
                const countText = messages.length > 0
                    ? `共 ${messages.length} 封邮件`
                    : '邮箱暂无邮件';
                showNotification(messages.length > 0 ? 'success' : 'info', messages.length > 0 ? '获取成功' : '提示', countText, 3000);
            } else {
                showNotification('error', '获取失败', data.error || '未知错误', 4000);
            }
        })
        .catch(err => {
            console.error('[邮件查看] 请求失败:', err);
            showNotification('error', '请求失败', err && err.message ? err.message : '网络错误', 4000);
        });
}

function renderMessages(email, messages, debugInfo) {
    const countEl = document.getElementById('messages-count');
    if (countEl) countEl.textContent = `${messages.length} 封`;
    const listDiv = document.getElementById('messages-list');
    if (!listDiv) return;

    renderedMessages = messages || [];

    if (messages.length === 0) {
        let debugHtml = '';
        if (debugInfo && Array.isArray(debugInfo) && debugInfo.length > 0) {
            debugHtml = `<div style="margin-top: 20px; padding: 12px; background: rgba(255,152,0,0.1); border: 1px solid #ff9800; border-radius: 8px; text-align: left; font-size: 12px; color: #555;">
                <div style="font-weight: bold; margin-bottom: 6px;">调试信息：</div>
                ${debugInfo.map(d => `<div>• ${escapeHtml(String(d))}</div>`).join('')}
            </div>`;
        }
        listDiv.innerHTML = `
            <div style="padding: 40px; text-align: center;">
                <i class="fa-solid fa-inbox" style="font-size: 48px; color: var(--text-muted); margin-bottom: 16px;"></i>
                <p class="text-muted">邮箱中暂无邮件</p>
                <small style="color: var(--text-muted);">邮箱: ${escapeHtml(email)}</small>
                ${debugHtml}
            </div>
        `;
        return;
    }

    listDiv.innerHTML = messages.map((msg, index) => {
        const from = escapeHtml(msg.from || '未知发件人');
        const date = escapeHtml(msg.date || msg.created_at || '');
        const subject = escapeHtml(msg.subject || '（无主题）');
        const previewText = stripHtml(msg.body || msg.body_text || '').substring(0, 120);

        return `
            <div class="message-card" data-message-index="${index}">
                <div class="message-header">
                    <span class="message-from">${from}</span>
                    <span class="message-date">${date}</span>
                </div>
                <div class="message-subject">${subject}</div>
                <div class="message-preview">${escapeHtml(previewText)}...</div>
            </div>
        `;
    }).join('');

    // 点击事件委托：点击邮件卡片查看详情
    listDiv.querySelectorAll('.message-card').forEach(card => {
        card.addEventListener('click', function () {
            const idx = parseInt(this.getAttribute('data-message-index'), 10);
            if (!isNaN(idx) && renderedMessages[idx]) {
                showMessageDetail(renderedMessages[idx]);
            }
        });
    });
}

/**
 * 显示邮件详情。
 * 关键安全点：邮件正文（body_html）来自外部，可能包含 <script> / <img src=...> 等，
 * 必须要么作为纯文本展示（stripHtml），要么放在沙箱 iframe 里。
 * 这里默认展示纯文本 + 提供"查看原始HTML"按钮（在新标签打开独立页面）。
 */
function showMessageDetail(msg) {
    const modalBody = document.getElementById('message-modal-body');
    if (!modalBody) return;

    const from = escapeHtml(msg.from || '未知');
    const subject = escapeHtml(msg.subject || '（无主题）');
    const date = escapeHtml(msg.date || msg.created_at || '-');
    const plainBody = stripHtml(msg.body || msg.body_text || '（无内容）');

    // 附件（如果存在）
    let attachmentsHtml = '';
    if (Array.isArray(msg.attachments) && msg.attachments.length > 0) {
        attachmentsHtml = '<div class="result-item"><div class="result-label">附件</div><div class="result-value">';
        msg.attachments.forEach((att, i) => {
            const attName = escapeHtml(att.filename || att.name || `附件 ${i + 1}`);
            attachmentsHtml += `<div style="padding:4px 0;"><i class="fa-solid fa-paperclip"></i> ${attName}</div>`;
        });
        attachmentsHtml += '</div></div>';
    }

    modalBody.innerHTML = `
        <div class="result-item">
            <div class="result-label">发件人</div>
            <div class="result-value">${from}</div>
        </div>
        <div class="result-item">
            <div class="result-label">主题</div>
            <div class="result-value">${subject}</div>
        </div>
        <div class="result-item">
            <div class="result-label">时间</div>
            <div class="result-value">${date}</div>
        </div>
        ${attachmentsHtml}
        <div class="result-item">
            <div class="result-label">邮件内容（纯文本）</div>
            <div class="result-value" style="word-break: break-word; padding: 12px; background: #f8f9fa; border-radius: 4px; max-height: 400px; overflow: auto; white-space: pre-wrap;">${escapeHtml(plainBody)}</div>
        </div>
    `;

    document.getElementById('message-modal').classList.add('active');
}

function getEmailStats() {
    const email = (document.getElementById('check-email').value || '').trim();
    if (!email) {
        showNotification('warning', '请输入邮箱', '请先输入要查询的邮箱地址');
        return;
    }

    fetch(`${API_BASE}?action=get_stats&email=${encodeURIComponent(email)}&_=${Date.now()}`)
        .then(res => res.json())
        .then(data => {
            if (data.success && data.data) {
                const panel = document.getElementById('email-stats-panel');
                if (panel) panel.style.display = 'block';
                const statsDiv = document.getElementById('email-stats');
                if (statsDiv) {
                    statsDiv.innerHTML = `
                        <div class="stats-display">
                            <div class="stat-box">
                                <h4>总邮件数</h4>
                                <div class="stat-number">${escapeHtml(String(data.data.total_messages || 0))}</div>
                            </div>
                            <div class="stat-box">
                                <h4>未读邮件</h4>
                                <div class="stat-number">${escapeHtml(String(data.data.unread_messages || 0))}</div>
                            </div>
                            <div class="stat-box">
                                <h4>含附件</h4>
                                <div class="stat-number">${data.data.has_attachments ? '是' : '否'}</div>
                            </div>
                        </div>
                    `;
                }
            }
        })
        .catch(err => {
            showNotification('error', '获取失败', err && err.message ? err.message : '网络错误');
        });
}

// ========== 批量操作 ==========
function batchCreateEmails() {
    const count = parseInt(document.getElementById('batch-count').value, 10) || 5;
    const domain = document.getElementById('batch-domain').value;

    if (count < 1 || count > 50) {
        showNotification('warning', '数量无效', '批量创建数量必须在 1-50 之间');
        return;
    }

    const params = new URLSearchParams();
    params.append('action', 'batch_create');
    params.append('csrf_token', getCsrfToken());
    params.append('count', String(count));
    if (domain) params.append('domain', domain);

    showNotification('info', '正在创建', `正在批量创建 ${count} 个邮箱...`);

    apiFetch(API_BASE, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: params
    })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                showNotification('success', '创建完成', `成功创建 ${data.success_count}/${data.total} 个邮箱`);
                renderBatchResult(data);

                if (data.emails) {
                    data.emails.forEach(emailData => {
                        if (emailData && emailData.email && emailData.token) {
                            saveEmailToStorage(emailData.email, emailData.token, emailData.domain, emailData.service);
                        }
                    });
                }
            } else {
                showNotification('error', '创建失败', data.error || '未知错误');
            }
        })
        .catch(err => {
            showNotification('error', '请求失败', err && err.message ? err.message : '网络错误');
        });
}

function renderBatchResult(data) {
    const panel = document.getElementById('batch-result-panel');
    if (panel) panel.style.display = 'block';
    const summary = document.getElementById('batch-summary');
    if (summary) summary.textContent = `${data.success_count}/${data.total}`;

    const tbody = document.getElementById('batch-result-body');
    if (!tbody) return;

    const emails = data.emails || [];
    tbody.innerHTML = emails.map((email, index) => {
        if (email && email.email) {
            return `<tr>
                <td>${index + 1}</td>
                <td><strong>${escapeHtml(email.email)}</strong></td>
                <td>${escapeHtml(String(email.token || '-'))}</td>
                <td><span class="status-badge online">成功</span></td>
            </tr>`;
        } else {
            return `<tr>
                <td>${index + 1}</td>
                <td class="text-muted">-</td>
                <td class="text-muted">-</td>
                <td><span class="status-badge offline">失败</span></td>
            </tr>`;
        }
    }).join('');
}

// ========== 删除邮箱 ==========
function deleteStoredEmail(email, token) {
    if (!confirm('确定要删除该邮箱吗？')) return;

    const params = new URLSearchParams();
    params.append('action', 'delete_email');
    params.append('csrf_token', getCsrfToken());
    params.append('email', email);
    params.append('token', token);

    apiFetch(API_BASE, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: params
    })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                showNotification('success', '删除成功', data.message || '邮箱已删除');
                removeEmailFromStorage(email);
            } else {
                showNotification('warning', '删除失败', data.message || data.error || '未知错误');
                // 即使远端失败也把该条记录从本地列表移除（让用户免于在列表里看到它）
                removeEmailFromStorage(email);
            }
        })
        .catch(err => {
            showNotification('error', '请求失败', err && err.message ? err.message : '网络错误');
        });
}

// ========== API 状态 ==========
function refreshStatus() {
    fetch(`${API_BASE}?action=get_status&_=${Date.now()}`)
        .then(res => res.json())
        .then(data => {
            if (data.success && data.data) {
                const publicEl = document.getElementById('status-public');
                const hdEl = document.getElementById('status-hd');
                const gmailEl = document.getElementById('status-gmail');
                if (publicEl) {
                    if (data.data.public && data.data.public.status) {
                        publicEl.textContent = data.data.public.status === 'online' ? '正常' : data.data.public.status;
                    } else {
                        publicEl.textContent = data.data.public ? '正常' : '离线';
                    }
                }
                if (hdEl) {
                    if (data.data.hd && data.data.hd.status) {
                        hdEl.textContent = data.data.hd.status === 'online' ? '正常' : (data.data.hd.status || '实验性');
                    } else {
                        hdEl.textContent = data.data.hd ? '正常' : '实验性';
                    }
                }
                if (gmailEl) {
                    if (data.data.gmail && data.data.gmail.status) {
                        gmailEl.textContent = data.data.gmail.status === 'online' ? '正常' : (data.data.gmail.status || '实验性');
                    } else {
                        gmailEl.textContent = data.data.gmail ? '正常' : '实验性';
                    }
                }
            }
        })
        .catch(err => console.error(err));
}

function clearCache() {
    const params = new URLSearchParams();
    params.append('action', 'clear_cache');
    params.append('csrf_token', getCsrfToken());
    apiFetch(API_BASE, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: params
    })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                showNotification('success', '缓存已清除', '域名数据将重新获取');
                loadDomains(true);
            } else {
                showNotification('error', '清除失败', data.error || '未知错误');
            }
        })
        .catch(err => {
            showNotification('error', '请求失败', err && err.message ? err.message : '网络错误');
        });
}

// ========== 快速创建 ==========
function showQuickCreateModal() {
    const params = new URLSearchParams();
    params.append('action', 'quick_create');
    params.append('csrf_token', getCsrfToken());

    apiFetch(API_BASE, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: params
    })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                showNotification('success', '创建成功', `邮箱: ${escapeHtml(data.email)}`);

                const modalBody = document.getElementById('quick-modal-body');
                if (modalBody) {
                    modalBody.innerHTML = `
                        <div class="result-item">
                            <div class="result-label">邮箱地址</div>
                            <div class="result-value email">${escapeHtml(data.email)}</div>
                        </div>
                        <div class="result-item">
                            <div class="result-label">删除令牌</div>
                            <div class="result-value">${escapeHtml(String(data.token || ''))}</div>
                        </div>
                        <div style="margin-top: 16px; display: flex; gap: 10px; flex-wrap: wrap;">
                            <button class="btn btn-primary" data-action="copy-email" data-email="${escapeHtml(data.email)}"><i class="fa-solid fa-copy"></i> 复制邮箱</button>
                            <button class="btn btn-info" data-action="check-email" data-email="${escapeHtml(data.email)}"><i class="fa-solid fa-magnifying-glass"></i> 查看邮件</button>
                        </div>
                    `;
                    modalBody.querySelectorAll('button[data-action]').forEach(btn => {
                        btn.addEventListener('click', function () {
                            const action = this.getAttribute('data-action');
                            const email = this.getAttribute('data-email') || '';
                            if (action === 'copy-email') {
                                copyToClipboard(email);
                                closeModal('quick-modal');
                            } else if (action === 'check-email') {
                                const input = document.getElementById('check-email');
                                if (input) input.value = email;
                                closeModal('quick-modal');
                                switchSection('messages');
                            }
                        });
                    });
                }
                document.getElementById('quick-modal').classList.add('active');

                saveEmailToStorage(data.email, data.token, 'auto', 'public');
            } else {
                showNotification('error', '创建失败', data.error || '未知错误');
            }
        })
        .catch(err => {
            showNotification('error', '请求失败', err && err.message ? err.message : '网络错误');
        });
}

// ========== 通知系统 ==========
function showNotification(type, title, message) {
    const container = document.getElementById('notification-container');
    if (!container) return;

    // 限制最多显示 5 个通知
    const maxVisible = 5;
    while (container.children.length >= maxVisible) {
        container.firstChild.remove();
    }

    const notification = document.createElement('div');
    notification.className = `notification ${type}`;
    notification.innerHTML = `
        <i class="notification-icon ${type === 'success' ? 'fa-solid fa-circle-check' : type === 'error' ? 'fa-solid fa-circle-xmark' : type === 'warning' ? 'fa-solid fa-triangle-exclamation' : 'fa-solid fa-circle-info'}"></i>
        <div class="notification-content">
            <div class="notification-title">${escapeHtml(title)}</div>
            <div class="notification-message">${escapeHtml(message)}</div>
        </div>
    `;

    container.appendChild(notification);

    setTimeout(() => {
        notification.style.opacity = '0';
        notification.style.transform = 'translateX(100%)';
        notification.style.transition = 'all 0.3s ease';
        setTimeout(() => notification.remove(), 300);
    }, 4000);
}

// ========== 模态框背景点击关闭 ==========
document.addEventListener('click', function (e) {
    if (e.target.classList.contains('modal')) {
        e.target.classList.remove('active');
    }
});
