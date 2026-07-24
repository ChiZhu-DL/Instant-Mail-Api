/**
 * BeatMail frontend
 * All API calls go through /api/index.php
 *
 * EMAIL @ 铁律（前后端一致）:
 *   - localStorage / UI / 业务变量: 永远是 user@domain.com（字面量 @）
 *   - 发 HTTP 请求时: URLSearchParams 会把 @ 编成 %40（仅传输层，正常）
 *   - 禁止: 手动把邮箱改成 user%40domain 再保存或再 encode 一次
 *   - 服务端会 normalize，即使误传 %40 也能纠正；但前端不要依赖这个
 */
(function () {
  'use strict';

  const API = 'api/index.php';
  const STORAGE_KEY = 'beatmail_session_v1';
  const POLL_MS = 8000;
  const SEEN_KEY = 'beatmail_seen_v1';

  const $ = (sel, root = document) => root.querySelector(sel);
  const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

  /**
   * 把可能被错误编码的邮箱还原成字面量 @。
   * 处理: user@x / user%40x / user%2540x
   */
  function literalEmail(addr) {
    if (!addr || typeof addr !== 'string') return '';
    let s = addr.trim();
    for (let i = 0; i < 5; i++) {
      if (s.indexOf('%') === -1) break;
      try {
        const d = decodeURIComponent(s);
        if (d === s) break;
        s = d;
      } catch (e) {
        break;
      }
    }
    // 业务层禁止残留 %40
    if (/%40/i.test(s)) {
      s = s.replace(/%40/gi, '@');
    }
    return s.trim();
  }

  const state = {
    domains: [],
    session: null, // { mailbox, token, service }  mailbox 必须字面量 @
    messages: [],
    seenIds: loadSeen(),
    pollTimer: null,
    countdownTimer: null,
    countdown: 0,
    sound: localStorage.getItem('beatmail_sound') !== '0',
    reducedMotion: localStorage.getItem('beatmail_motion') === '1',
    loading: false,
  };

  // ── DOM ──────────────────────────────────────────
  const el = {
    screenStart: $('#screen-start'),
    screenBoard: $('#screen-board'),
    selectDomain: $('#select-domain'),
    inputName: $('#input-name'),
    btnCreate: $('#btn-create'),
    mailboxAddr: $('#mailbox-addr'),
    mailboxService: $('#mailbox-service'),
    btnCopy: $('#btn-copy'),
    btnRefresh: $('#btn-refresh'),
    btnNew: $('#btn-new'),
    inboxEmpty: $('#inbox-empty'),
    inboxList: $('#inbox-list'),
    detailPanel: $('#detail-panel'),
    boardLayout: $('.board-layout'),
    detailSubject: $('#detail-subject'),
    detailFrom: $('#detail-from'),
    detailTo: $('#detail-to'),
    detailTime: $('#detail-time'),
    detailFrame: $('#detail-frame'),
    detailText: $('#detail-text'),
    btnCloseDetail: $('#btn-close-detail'),
    chkAuto: $('#chk-auto'),
    metricCount: $('#metric-count'),
    metricTimer: $('#metric-timer'),
    statusDot: $('#status-dot'),
    statusText: $('#status-text'),
    btnSound: $('#btn-sound'),
    btnMotion: $('#btn-motion'),
    toast: $('#toast'),
    loading: $('#loading'),
    loadingText: $('#loading-text'),
  };

  // ── Boot ─────────────────────────────────────────
  document.addEventListener('DOMContentLoaded', init);

  async function init() {
    applyPrefs();
    bindEvents();
    setStatus('就绪', 'ok');
    await loadDomains();

    const saved = loadSession();
    if (saved && saved.mailbox && saved.token) {
      state.session = saved;
      enterBoard();
      await refreshInbox({ silent: true });
      startPolling();
    }
  }

  function bindEvents() {
    el.btnCreate.addEventListener('click', onCreate);
    el.btnCopy.addEventListener('click', onCopy);
    el.btnRefresh.addEventListener('click', () => refreshInbox({ force: true }));
    el.btnNew.addEventListener('click', onNewMailbox);
    el.btnCloseDetail.addEventListener('click', closeDetail);
    el.chkAuto.addEventListener('change', () => {
      if (el.chkAuto.checked) startPolling();
      else stopPolling();
    });
    el.btnSound.addEventListener('click', toggleSound);
    el.btnMotion.addEventListener('click', toggleMotion);
    el.inputName.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') onCreate();
    });
  }

  // ── Prefs ────────────────────────────────────────
  function applyPrefs() {
    el.btnSound.setAttribute('aria-pressed', state.sound ? 'true' : 'false');
    el.btnSound.textContent = state.sound ? '🔊' : '🔇';
    el.btnMotion.setAttribute('aria-pressed', state.reducedMotion ? 'true' : 'false');
    document.body.classList.toggle('reduced-motion', state.reducedMotion);
  }

  function toggleSound() {
    state.sound = !state.sound;
    localStorage.setItem('beatmail_sound', state.sound ? '1' : '0');
    applyPrefs();
    toast(state.sound ? '音效已开' : '音效已关');
  }

  function toggleMotion() {
    state.reducedMotion = !state.reducedMotion;
    localStorage.setItem('beatmail_motion', state.reducedMotion ? '1' : '0');
    applyPrefs();
    toast(state.reducedMotion ? '已减少动效' : '动效已开');
  }

  // ── API ──────────────────────────────────────────
  /**
   * 调用本站 API。
   * - query/body 里的 email 必须是字面量 @（我们会先 literalEmail）
   * - URLSearchParams / JSON 由浏览器传输；@ 在 query 中会变成 %40（仅一次，正确）
   * - 不要对 email 再手动 encodeURIComponent 后塞进 params（会双重编码）
   */
  async function api(action, opts = {}) {
    const method = (opts.method || 'GET').toUpperCase();
    const query = Object.assign({ action }, opts.query || {});

    if (query.email) {
      query.email = literalEmail(query.email);
    }

    let url = API + '?' + new URLSearchParams(query).toString();

    const init = {
      method,
      headers: {},
      cache: 'no-store',
    };

    if (method === 'POST') {
      const body = Object.assign({ action }, opts.body || {});
      if (body.email) {
        body.email = literalEmail(body.email);
      }
      init.headers['Content-Type'] = 'application/json';
      init.body = JSON.stringify(body);
      url = API + '?action=' + encodeURIComponent(action);
    }

    let res;
    try {
      res = await fetch(url, init);
    } catch (e) {
      throw new Error('网络请求失败（无法连接 API）');
    }

    const rawText = await res.text();
    // 兼容主机在 JSON 后注入 HTML 广告：截取第一个 { 到最后一个 }
    let text = rawText.trim();
    const firstBrace = text.indexOf('{');
    const lastBrace = text.lastIndexOf('}');
    if (firstBrace !== -1 && lastBrace > firstBrace) {
      text = text.slice(firstBrace, lastBrace + 1);
    }

    let data;
    try {
      data = JSON.parse(text);
    } catch (e) {
      const snip = (rawText || '').replace(/\s+/g, ' ').slice(0, 120);
      throw new Error(
        'API 返回非 JSON (HTTP ' + res.status + ')' +
        (snip ? ' · ' + snip : '') +
        ' · 请打开 /api/index.php?action=health 检查'
      );
    }
    if (!res.ok || data.ok === false) {
      const msg = (data && data.error) || ('请求失败 HTTP ' + res.status);
      const err = new Error(msg);
      err.payload = data;
      throw err;
    }
    return data;
  }

  async function loadDomains() {
    try {
      const res = await api('domains');
      const flat = (res.data && res.data.flat) || [];
      state.domains = flat;
      renderDomainSelect(flat);
    } catch (e) {
      el.selectDomain.innerHTML = '<option value="">域名加载失败</option>';
      setStatus('域名失败', 'bad');
      toast(e.message, 'bad');
    }
  }

  function renderDomainSelect(list) {
    const groups = {
      'temp-mail-io': '标准域名',
      'hd-premium': '高级域名 ★',
      'hd-gmail': 'Gmail 点别名',
      'hd-random': '随机',
    };
    const by = {};
    list.forEach((item) => {
      (by[item.service] = by[item.service] || []).push(item);
    });

    const frag = document.createDocumentFragment();
    Object.keys(groups).forEach((svc) => {
      const items = by[svc];
      if (!items || !items.length) return;
      const og = document.createElement('optgroup');
      og.label = groups[svc];
      items.forEach((item) => {
        const opt = document.createElement('option');
        opt.value = JSON.stringify({ domain: item.domain, service: item.service });
        opt.textContent = item.label || item.domain;
        og.appendChild(opt);
      });
      frag.appendChild(og);
    });
    el.selectDomain.innerHTML = '';
    el.selectDomain.appendChild(frag);

    // 默认：第一个实时标准域（勿写死已下线的 wnbaldwy.com）
    if (list.length) {
      const prefer =
        list.find((x) => x.service === 'temp-mail-io' && x.domain === 'bltiwd.com') ||
        list.find((x) => x.service === 'temp-mail-io') ||
        list[0];
      el.selectDomain.value = JSON.stringify({ domain: prefer.domain, service: prefer.service });
    }
  }

  // ── Create mailbox ───────────────────────────────
  async function onCreate() {
    if (state.loading) return;

    let domain = null;
    let service = 'temp-mail-io';
    try {
      const picked = JSON.parse(el.selectDomain.value || '{}');
      domain = picked.domain || null;
      service = picked.service || 'temp-mail-io';
    } catch (e) {
      toast('请选择域名', 'bad');
      return;
    }

    if (!domain && service !== 'hd-random') {
      toast('请选择域名', 'bad');
      return;
    }

    let name = (el.inputName.value || '').trim() || null;
    if (name) {
      name = name.replace(/[^a-zA-Z0-9._+-]/g, '');
      if (!name) name = null;
    }

    showLoading(service === 'hd-gmail' ? '生成 Gmail 别名…' : '生成邮箱中…');
    setStatus('创建中', 'warn');

    try {
      // 与 Python CLI 对齐：
      //   temp-mail-io / hd-premium → 始终传完整 email=name@所选域名
      //   禁止只传 service 导致服务端随机域
      const body = { service: service };
      if (service === 'hd-random' || domain === 'random') {
        body.service = 'hd-random';
      } else if (service === 'hd-gmail') {
        body.domain = domain;
        body.email = 'random@' + domain;
      } else {
        // temp-mail-io / hd-premium：强制 name@domain
        const local = name || randomLocal();
        body.domain = domain;
        body.name = local;
        body.email = local + '@' + domain; // 字面量 @
      }

      const res = await api('create', { method: 'POST', body: body });
      const data = res.data || {};
      if (!data.mailbox || !data.token) {
        throw new Error('上游未返回完整邮箱/Token，请换域名重试');
      }

      const mailbox = literalEmail(data.mailbox || data.email || '');
      if (!mailbox || mailbox.indexOf('@') === -1) {
        throw new Error('返回的邮箱不是合法字面量地址: ' + (data.mailbox || ''));
      }
      if (/%40/i.test(mailbox)) {
        throw new Error('邮箱含 %40，已拒绝保存（应是字面量 @）');
      }

      // 前端也校验：标准/高级域必须落在所选域名
      if (domain && service !== 'hd-gmail' && service !== 'hd-random') {
        const gotDomain = mailbox.split('@')[1] || '';
        if (gotDomain.toLowerCase() !== String(domain).toLowerCase()) {
          throw new Error('域名不一致：选择 ' + domain + '，得到 ' + mailbox);
        }
      }

      state.session = {
        mailbox: mailbox,
        token: data.token,
        service: data.service || service,
        domain: domain || null,
      };
      saveSession(state.session);
      state.seenIds = {};
      saveSeen();
      playBlip(880);

      enterBoard();
      toast('邮箱已就绪 · ' + mailbox, 'good');
      setStatus('在线', 'ok');
      await refreshInbox({ silent: true });
      startPolling();
    } catch (e) {
      setStatus('创建失败', 'bad');
      toast(e.message || '创建失败', 'bad');
      playBlip(220);
    } finally {
      hideLoading();
    }
  }

  function randomLocal() {
    const chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
    let out = '';
    for (let i = 0; i < 10; i++) {
      out += chars[Math.floor(Math.random() * chars.length)];
    }
    return out;
  }

  function enterBoard() {
    el.screenStart.classList.remove('is-active');
    el.screenBoard.classList.add('is-active');
    renderMailboxCard();
  }

  function renderMailboxCard() {
    const s = state.session;
    if (!s) return;
    el.mailboxAddr.textContent = s.mailbox;
    el.mailboxService.textContent = s.service || 'mail';
  }

  async function onNewMailbox() {
    if (!confirm('换一个新邮箱？当前收件箱会话会清除。')) return;
    stopPolling();
    closeDetail();
    state.session = null;
    state.messages = [];
    clearSession();
    el.screenBoard.classList.remove('is-active');
    el.screenStart.classList.add('is-active');
    el.metricCount.textContent = '0';
    el.metricTimer.textContent = '—';
    setStatus('就绪', 'ok');
    toast('选个域名，再开一把');
  }

  async function onCopy() {
    if (!state.session) return;
    const text = state.session.mailbox;
    try {
      if (navigator.clipboard && navigator.clipboard.writeText) {
        await navigator.clipboard.writeText(text);
      } else {
        const ta = document.createElement('textarea');
        ta.value = text;
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        ta.remove();
      }
      toast('已复制：' + text, 'good');
      playBlip(660);
    } catch (e) {
      toast('复制失败，请手动选中', 'bad');
    }
  }

  // ── Inbox ────────────────────────────────────────
  async function refreshInbox(opts = {}) {
    if (!state.session) return;
    if (state.loading && !opts.force) return;

    if (!opts.silent) {
      setStatus('刷新中', 'warn');
    }

    try {
      // email stays with "@"; URLSearchParams / encodeURIComponent → %40
      const res = await api('messages', {
        query: {
          email: state.session.mailbox,
          token: state.session.token,
        },
      });

      const list = (res.data && res.data.messages) || [];
      const prevCount = state.messages.length;
      const newOnes = list.filter((m) => m.id && !state.seenIds[m.id]);

      // mark all currently present as seen after first paint of new
      state.messages = list;
      el.metricCount.textContent = String(list.length);
      renderInbox(list, newOnes.map((m) => m.id));

      newOnes.forEach((m) => {
        if (m.id) state.seenIds[m.id] = 1;
      });
      // also mark existing
      list.forEach((m) => {
        if (m.id) state.seenIds[m.id] = 1;
      });
      saveSeen();

      if (newOnes.length && prevCount > 0) {
        toast('新邮件 ×' + newOnes.length + '！', 'good');
        playBlip(1046);
      } else if (!opts.silent) {
        toast(list.length ? '已刷新 · ' + list.length + ' 封' : '收件箱空空');
      }

      setStatus('在线', 'ok');
      resetCountdown();
    } catch (e) {
      setStatus('同步失败', 'bad');
      if (!opts.silent) toast(e.message || '刷新失败', 'bad');
    }
  }

  function renderInbox(list, newIds = []) {
    const newSet = new Set(newIds || []);
    if (!list.length) {
      el.inboxEmpty.hidden = false;
      el.inboxList.hidden = true;
      el.inboxList.innerHTML = '';
      return;
    }
    el.inboxEmpty.hidden = true;
    el.inboxList.hidden = false;

    el.inboxList.innerHTML = list
      .map((m) => {
        const id = escapeHtml(m.id || '');
        const from = escapeHtml(shortFrom(m.from || ''));
        const subject = escapeHtml(m.subject || '(无主题)');
        const preview = escapeHtml(m.preview || '');
        const time = escapeHtml(formatTime(m.createdAt));
        const isNew = m.id && newSet.has(m.id) ? ' is-new' : '';
        return (
          '<li class="mail-item' + isNew + '" data-id="' + id + '" role="button" tabindex="0">' +
            '<span class="mail-dot"></span>' +
            '<div class="mail-main">' +
              '<div class="mail-from">' + from + '</div>' +
              '<div class="mail-subject">' + subject + '</div>' +
              (preview ? '<div class="mail-preview">' + preview + '</div>' : '') +
            '</div>' +
            '<div class="mail-time">' + time + '</div>' +
          '</li>'
        );
      })
      .join('');

    $$('.mail-item', el.inboxList).forEach((node) => {
      const open = () => openMessage(node.getAttribute('data-id'));
      node.addEventListener('click', open);
      node.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault();
          open();
        }
      });
    });
  }

  async function openMessage(id) {
    if (!id || !state.session) return;
    $$('.mail-item', el.inboxList).forEach((n) => {
      n.classList.toggle('is-active', n.getAttribute('data-id') === id);
      if (n.getAttribute('data-id') === id) n.classList.add('read');
    });

    showLoading('打开邮件…');
    try {
      const res = await api('message', {
        query: {
          email: state.session.mailbox,
          token: state.session.token,
          id: id,
        },
      });
      const d = res.data || {};
      el.detailSubject.textContent = d.subject || '(无主题)';
      el.detailFrom.textContent = d.from || '—';
      el.detailTo.textContent = d.to || state.session.mailbox;
      el.detailTime.textContent = formatTime(d.createdAt, true);

      const html = d.html;
      const text = d.text;
      if (html && String(html).trim()) {
        el.detailFrame.hidden = false;
        el.detailText.hidden = true;
        // sandboxed iframe — write sanitized-ish html
        const doc = el.detailFrame.contentDocument;
        if (doc) {
          doc.open();
          doc.write(
            '<!DOCTYPE html><html><head><meta charset="utf-8">' +
            '<base target="_blank" rel="noopener">' +
            '<style>body{font-family:system-ui,sans-serif;font-size:14px;color:#1a237e;margin:12px;word-break:break-word;line-height:1.5}img{max-width:100%;height:auto}a{color:#1565c0}</style>' +
            '</head><body>' + String(html) + '</body></html>'
          );
          doc.close();
        }
      } else {
        el.detailFrame.hidden = true;
        el.detailText.hidden = false;
        el.detailText.textContent = text || '(无正文内容)';
      }

      el.detailPanel.hidden = false;
      el.boardLayout.classList.add('has-detail');
      document.body.classList.add('detail-open');
      playBlip(523);
    } catch (e) {
      toast(e.message || '打开失败', 'bad');
    } finally {
      hideLoading();
    }
  }

  function closeDetail() {
    el.detailPanel.hidden = true;
    el.boardLayout.classList.remove('has-detail');
    document.body.classList.remove('detail-open');
    $$('.mail-item', el.inboxList).forEach((n) => n.classList.remove('is-active'));
    try {
      const doc = el.detailFrame.contentDocument;
      if (doc) {
        doc.open();
        doc.write('');
        doc.close();
      }
    } catch (e) { /* ignore */ }
  }

  // ── Polling ──────────────────────────────────────
  function startPolling() {
    stopPolling();
    if (!el.chkAuto.checked || !state.session) return;
    resetCountdown();
    state.pollTimer = setInterval(() => {
      refreshInbox({ silent: true });
    }, POLL_MS);
  }

  function stopPolling() {
    if (state.pollTimer) {
      clearInterval(state.pollTimer);
      state.pollTimer = null;
    }
    if (state.countdownTimer) {
      clearInterval(state.countdownTimer);
      state.countdownTimer = null;
    }
    el.metricTimer.textContent = '—';
  }

  function resetCountdown() {
    if (state.countdownTimer) clearInterval(state.countdownTimer);
    if (!el.chkAuto.checked) {
      el.metricTimer.textContent = '—';
      return;
    }
    state.countdown = Math.round(POLL_MS / 1000);
    el.metricTimer.textContent = state.countdown + 's';
    state.countdownTimer = setInterval(() => {
      state.countdown -= 1;
      if (state.countdown <= 0) {
        el.metricTimer.textContent = '…';
      } else {
        el.metricTimer.textContent = state.countdown + 's';
      }
    }, 1000);
  }

  // ── UI helpers ───────────────────────────────────
  function setStatus(text, kind) {
    el.statusText.textContent = text;
    el.statusDot.classList.remove('warn', 'bad');
    if (kind === 'warn') el.statusDot.classList.add('warn');
    if (kind === 'bad') el.statusDot.classList.add('bad');
  }

  let toastTimer = null;
  function toast(msg, kind) {
    el.toast.hidden = false;
    el.toast.textContent = msg;
    el.toast.classList.remove('good', 'bad', 'is-show');
    if (kind === 'good') el.toast.classList.add('good');
    if (kind === 'bad') el.toast.classList.add('bad');
    // force reflow
    void el.toast.offsetWidth;
    el.toast.classList.add('is-show');
    if (toastTimer) clearTimeout(toastTimer);
    toastTimer = setTimeout(() => {
      el.toast.classList.remove('is-show');
    }, 2600);
  }

  function showLoading(text) {
    state.loading = true;
    el.loadingText.textContent = text || '加载中…';
    el.loading.hidden = false;
  }

  function hideLoading() {
    state.loading = false;
    el.loading.hidden = true;
  }

  // tiny WebAudio blip (no asset files)
  let audioCtx = null;
  function playBlip(freq) {
    if (!state.sound) return;
    try {
      audioCtx = audioCtx || new (window.AudioContext || window.webkitAudioContext)();
      const o = audioCtx.createOscillator();
      const g = audioCtx.createGain();
      o.type = 'square';
      o.frequency.value = freq || 660;
      g.gain.value = 0.04;
      o.connect(g);
      g.connect(audioCtx.destination);
      o.start();
      g.gain.exponentialRampToValueAtTime(0.0001, audioCtx.currentTime + 0.12);
      o.stop(audioCtx.currentTime + 0.14);
    } catch (e) { /* ignore */ }
  }

  function shortFrom(from) {
    // "Name <a@b.com>" → prefer email or name
    const m = String(from).match(/<([^>]+)>/);
    if (m) return m[1];
    return from;
  }

  function formatTime(ts, full) {
    if (!ts) return '—';
    let n = Number(ts);
    if (!n) return '—';
    if (n < 1e12) n *= 1000;
    const d = new Date(n);
    if (Number.isNaN(d.getTime())) return '—';
    if (full) {
      return d.toLocaleString();
    }
    const now = Date.now();
    const diff = Math.max(0, now - d.getTime());
    if (diff < 60_000) return '刚刚';
    if (diff < 3600_000) return Math.floor(diff / 60_000) + ' 分钟前';
    if (diff < 86400_000) return Math.floor(diff / 3600_000) + ' 小时前';
    return d.toLocaleDateString();
  }

  function escapeHtml(s) {
    return String(s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  // ── Session storage ──────────────────────────────
  function saveSession(s) {
    try {
      if (s && s.mailbox) {
        s = Object.assign({}, s, { mailbox: literalEmail(s.mailbox) });
      }
      localStorage.setItem(STORAGE_KEY, JSON.stringify(s));
    } catch (e) { /* ignore */ }
  }

  function loadSession() {
    try {
      const raw = localStorage.getItem(STORAGE_KEY);
      if (!raw) return null;
      const s = JSON.parse(raw);
      if (s && s.mailbox) {
        s.mailbox = literalEmail(s.mailbox);
        // 旧版本若误存了 %40，读出时纠正并写回
        if (s.mailbox.indexOf('@') !== -1) {
          localStorage.setItem(STORAGE_KEY, JSON.stringify(s));
        }
      }
      return s;
    } catch (e) {
      return null;
    }
  }

  function clearSession() {
    try {
      localStorage.removeItem(STORAGE_KEY);
    } catch (e) { /* ignore */ }
  }

  function loadSeen() {
    try {
      return JSON.parse(localStorage.getItem(SEEN_KEY) || '{}') || {};
    } catch (e) {
      return {};
    }
  }

  function saveSeen() {
    try {
      // keep last 200 ids
      const ids = Object.keys(state.seenIds);
      if (ids.length > 200) {
        const slim = {};
        ids.slice(-200).forEach((k) => {
          slim[k] = 1;
        });
        state.seenIds = slim;
      }
      localStorage.setItem(SEEN_KEY, JSON.stringify(state.seenIds));
    } catch (e) { /* ignore */ }
  }
})();
