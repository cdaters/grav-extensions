const TAG = window.__GRAV_FIELD_TAG;

class RevisionLedgerHistoryField extends HTMLElement {
  constructor() {
    super();
    this._field = null;
    this._value = null;
    this.context = null;
    this.revisions = [];
    this.busy = false;
    this.error = '';
    this.detail = null;
    this.opened = false;
    this.toolbarButton = null;
    this.portal = null;
  }

  set field(value) { this._field = value; }
  get field() { return this._field; }
  set value(value) { this._value = value; }
  get value() { return this._value; }

  connectedCallback() {
    this.style.display = 'none';
    this.adminPath = this.editorPath();
    if (!this.adminPath) return;
    this.installDocumentStyles();
    this.portal = document.createElement('div');
    this.portal.dataset.revisionLedgerPortal = 'true';
    this.portal.attachShadow({ mode: 'open' });
    document.body.appendChild(this.portal);
    this.observer = new MutationObserver(() => this.scheduleToolbar());
    this.observer.observe(document.body, { childList: true, subtree: true });
    this.escapeHandler = event => { if (event.key === 'Escape' && this.opened) this.close(); };
    this.saveHandler = event => {
      const button = event.target?.closest?.('button');
      if (!button || (button.textContent || '').trim().toLowerCase() !== 'save') return;
      clearTimeout(this.saveRefreshTimer);
      this.saveRefreshTimer = setTimeout(() => this.loadContext(), 1400);
    };
    document.addEventListener('keydown', this.escapeHandler);
    document.addEventListener('click', this.saveHandler, true);
    this.scheduleToolbar();
    this.loadContext();
  }

  disconnectedCallback() {
    this.observer?.disconnect();
    clearTimeout(this.toolbarTimer);
    clearTimeout(this.saveRefreshTimer);
    document.removeEventListener('keydown', this.escapeHandler);
    document.removeEventListener('click', this.saveHandler, true);
    this.toolbarButton?.remove();
    this.portal?.remove();
  }

  editorPath() {
    const marker = '/pages/edit/';
    const pathname = location.pathname || '';
    const index = pathname.indexOf(marker);
    if (index < 0) return '';
    const encoded = pathname.slice(index + marker.length).replace(/\/+$/, '');
    if (!encoded) return '';
    try {
      return '/' + encoded.split('/').map(part => decodeURIComponent(part)).join('/');
    } catch (_) {
      return '/' + encoded;
    }
  }

  headers() {
    let token = window.__GRAV_API_TOKEN || '', environment = '';
    try {
      const auth = JSON.parse(localStorage.getItem('grav_admin_auth') || '{}');
      token ||= auth.accessToken || '';
      environment = auth.environment || '';
    } catch (_) {}
    const headers = { 'Content-Type': 'application/json' };
    if (token) { headers.Authorization = `Bearer ${token}`; headers['X-API-Token'] = token; }
    if (environment) headers['X-Grav-Environment'] = environment;
    return headers;
  }

  apiUrl(path) { return `${window.__GRAV_API_SERVER_URL || ''}${window.__GRAV_API_PREFIX || '/api/v1'}${path}`; }
  async api(path, options = {}) {
    const response = await fetch(this.apiUrl(path), { ...options, headers: { ...this.headers(), ...(options.headers || {}) } });
    const raw = await response.text(); let payload = {};
    try { payload = raw ? JSON.parse(raw) : {}; } catch (_) { payload = { message: raw }; }
    if (!response.ok) throw new Error(payload.detail || payload.message || payload.title || response.statusText || `HTTP ${response.status}`);
    return payload.data ?? payload;
  }

  async loadContext() {
    try {
      this.context = await this.api(`/revision-ledger/editor-context?path=${encodeURIComponent(this.adminPath)}`);
      await this.loadRevisions();
    } catch (error) {
      this.error = error?.message || String(error);
      this.renderPanel();
    }
  }

  async loadRevisions() {
    if (!this.context?.route) return;
    const data = await this.api(`/revision-ledger/revisions?route=${encodeURIComponent(this.context.route)}`);
    this.revisions = data.revisions || [];
    this.context.count = this.revisions.length;
    this.updateBadge();
    this.renderPanel();
  }

  scheduleToolbar() {
    clearTimeout(this.toolbarTimer);
    this.toolbarTimer = setTimeout(() => this.installToolbarButton(), 80);
  }

  installToolbarButton() {
    if (!this.isConnected || document.querySelector('[data-revision-ledger-trigger]')) return;
    const save = [...document.querySelectorAll('button')].find(button => {
      const text = (button.textContent || '').trim().toLowerCase();
      const label = `${button.getAttribute('aria-label') || ''} ${button.getAttribute('title') || ''}`.toLowerCase();
      return text === 'save' || /(^|\s)save(\s|$)/.test(label);
    });
    const group = save?.parentElement;
    if (!save || !group || group.querySelectorAll('button').length < 2) return;
    const neutral = [...group.querySelectorAll('button')].find(button => button !== save && !/delete|remove/.test(`${button.textContent} ${button.title} ${button.getAttribute('aria-label')}`.toLowerCase()));
    const button = document.createElement('button');
    button.type = 'button';
    button.className = neutral?.className || '';
    button.dataset.revisionLedgerTrigger = 'true';
    button.title = 'Revision history';
    button.setAttribute('aria-label', 'Open revision history');
    button.innerHTML = `${this.historyIcon()}<span data-revision-ledger-badge>${this.context?.count ?? 0}</span>`;
    button.addEventListener('click', event => { event.preventDefault(); event.stopPropagation(); this.toggle(); });
    group.insertBefore(button, group.firstElementChild);
    this.toolbarButton = button;
    this.updateBadge();
  }

  updateBadge() {
    const badge = this.toolbarButton?.querySelector('[data-revision-ledger-badge]');
    if (badge) badge.textContent = String(this.context?.count ?? this.revisions.length ?? 0);
  }

  toggle() { this.opened ? this.close() : this.open(); }
  async open() {
    this.opened = true;
    this.toolbarButton?.setAttribute('aria-expanded', 'true');
    this.renderPanel();
    try { await this.loadRevisions(); } catch (error) { this.error = error?.message || String(error); this.renderPanel(); }
  }
  close() {
    this.opened = false;
    this.detail = null;
    this.toolbarButton?.setAttribute('aria-expanded', 'false');
    this.renderPanel();
  }

  async checkpoint() {
    const reason = prompt('Checkpoint note:', 'Named checkpoint before editorial work');
    if (reason === null) return;
    await this.act(() => this.api('/revision-ledger/checkpoints', {
      method: 'POST', body: JSON.stringify({ route: this.context.route, reason })
    }));
  }

  async preview(id) {
    await this.act(async () => { this.detail = { type: 'preview', data: await this.api(`/revision-ledger/revisions/${encodeURIComponent(id)}`) }; }, false);
  }

  async compare(id) {
    await this.act(async () => { this.detail = { type: 'compare', data: await this.api(`/revision-ledger/revisions/${encodeURIComponent(id)}/compare`) }; }, false);
  }

  async restore(id) {
    const phrase = prompt('This replaces the current page after saving a safety checkpoint. Type RESTORE PAGE to continue:');
    if (phrase === null) return;
    await this.act(() => this.api(`/revision-ledger/revisions/${encodeURIComponent(id)}/restore`, {
      method: 'POST', body: JSON.stringify({ confirmation: phrase })
    }), false);
    location.reload();
  }

  async act(action, reload = true) {
    if (this.busy) return;
    this.busy = true; this.error = ''; this.renderPanel();
    try { await action(); if (reload) await this.loadRevisions(); }
    catch (error) { this.error = error?.message || String(error); }
    finally { this.busy = false; this.renderPanel(); }
  }

  fullLedgerUrl() {
    return `${this.adminBase()}/plugin/revision-ledger?route=${encodeURIComponent(this.context?.route || '')}`;
  }

  adminBase() {
    const path = location.pathname || '/admin';
    const marker = '/pages/edit/';
    const index = path.indexOf(marker);
    if (index >= 0) return path.slice(0, index) || '/admin';
    const admin = path.indexOf('/admin');
    return admin >= 0 ? path.slice(0, admin + 6) : '/admin';
  }

  renderPanel() {
    if (!this.portal?.shadowRoot) return;
    if (!this.opened) { this.portal.shadowRoot.innerHTML = ''; return; }
    const title = this.context?.title || 'Revision History';
    this.portal.shadowRoot.innerHTML = `<style>${this.panelStyles()}</style>
      <div class="veil" id="veil"></div>
      <aside class="panel ${this.themeClass()}" role="dialog" aria-modal="true" aria-label="Revision history">
        <header><div><span class="eyebrow">PAGE HISTORY</span><h2>Revision History</h2><p>${this.escape(title)} · ${this.escape(this.context?.route || this.adminPath)}</p></div><button class="icon close" id="close" aria-label="Close">×</button></header>
        <div class="toolbar"><button id="checkpoint" ${!this.context || this.busy ? 'disabled' : ''}>+ Checkpoint</button><a href="${this.escape(this.fullLedgerUrl())}">Open full ledger ↗</a></div>
        ${this.error ? `<div class="notice">${this.escape(this.error)}</div>` : ''}
        <div class="list">${this.busy && !this.revisions.length ? '<div class="empty">Reading the ledger…</div>' : this.revisions.map((item, index) => this.row(item, this.revisions.length - index)).join('') || '<div class="empty"><strong>No revisions yet.</strong><span>Save the page or create a named checkpoint to begin its history.</span></div>'}</div>
        ${this.detail ? this.detailView() : ''}
      </aside>`;
    this.bindPanel();
  }

  row(item, number) {
    return `<article><div class="number">${number}</div><div class="copy"><strong>${this.escape(item.reason || this.source(item.source))}</strong><span>${this.date(item.created_at)}</span><small>${this.escape(item.author || 'System')} · ${this.escape(this.source(item.source))}</small></div><div class="actions"><button class="icon" data-preview="${this.escape(item.id)}" title="Preview" aria-label="Preview revision">${this.eyeIcon()}</button><button class="icon" data-compare="${this.escape(item.id)}" title="Compare" aria-label="Compare revision">${this.compareIcon()}</button><button class="icon restore" data-restore="${this.escape(item.id)}" title="Restore" aria-label="Restore revision">${this.restoreIcon()}</button></div></article>`;
  }

  detailView() {
    const compare = this.detail.type === 'compare';
    const data = this.detail.data || {};
    const item = compare ? (data.revision || {}) : data;
    const content = compare ? data.unified : data.content;
    return `<div class="detail"><header><div><span class="eyebrow">${compare ? 'COMPARISON' : 'SNAPSHOT PREVIEW'}</span><h3>${this.escape(item.reason || item.id)}</h3></div><button class="icon" id="close-detail">×</button></header><pre>${this.escape(content || '')}</pre><footer>${compare ? `<span>${data.changed ? 'This snapshot differs from the current page.' : 'This snapshot matches the current page.'}</span>` : '<span>Read-only retained content.</span>'}<button class="restore-text" data-restore="${this.escape(item.id)}">Restore</button></footer></div>`;
  }

  bindPanel() {
    const root = this.portal.shadowRoot;
    root.getElementById('close')?.addEventListener('click', () => this.close());
    root.getElementById('veil')?.addEventListener('click', () => this.close());
    root.getElementById('checkpoint')?.addEventListener('click', () => this.checkpoint());
    root.getElementById('close-detail')?.addEventListener('click', () => { this.detail = null; this.renderPanel(); });
    root.querySelectorAll('[data-preview]').forEach(button => button.addEventListener('click', () => this.preview(button.dataset.preview)));
    root.querySelectorAll('[data-compare]').forEach(button => button.addEventListener('click', () => this.compare(button.dataset.compare)));
    root.querySelectorAll('[data-restore]').forEach(button => button.addEventListener('click', () => this.restore(button.dataset.restore)));
  }

  themeClass() {
    const classes = `${document.documentElement.className} ${document.body?.className || ''}`.toLowerCase();
    const explicit = `${document.documentElement.dataset.theme || ''} ${document.body?.dataset?.theme || ''}`.toLowerCase();
    if (/\blight\b/.test(`${classes} ${explicit}`)) return 'light';
    for (const node of [document.body, document.documentElement].filter(Boolean)) {
      const match = getComputedStyle(node).backgroundColor?.match(/rgba?\((\d+),\s*(\d+),\s*(\d+)/i);
      if (match) {
        const luminance = (+match[1] * 299 + +match[2] * 587 + +match[3] * 114) / 1000;
        return luminance >= 150 ? 'light' : 'dark';
      }
    }
    return 'dark';
  }

  source(value) { return ({ 'admin-auto':'Before Admin save', initial:'Initial version', manual:'Named checkpoint', 'pre-restore':'Safety checkpoint', plugin:'Plugin checkpoint' })[value] || value || 'Checkpoint'; }
  date(value) { try { return new Intl.DateTimeFormat(undefined, { dateStyle:'medium', timeStyle:'short' }).format(new Date(value)); } catch (_) { return value || '—'; } }
  escape(value) { return String(value ?? '').replace(/[&<>'"]/g, char => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', "'":'&#39;', '"':'&quot;' }[char])); }

  historyIcon() { return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5M12 7v5l3 2"/></svg>'; }
  eyeIcon() { return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"/><circle cx="12" cy="12" r="2.5"/></svg>'; }
  compareIcon() { return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 7h11l-3-3m3 3-3 3M17 17H6l3 3m-3-3 3-3"/></svg>'; }
  restoreIcon() { return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 12a8 8 0 1 0 2.3-5.7L4 8"/><path d="M4 4v4h4"/></svg>'; }

  installDocumentStyles() {
    if (document.getElementById('revision-ledger-toolbar-styles')) return;
    const style = document.createElement('style');
    style.id = 'revision-ledger-toolbar-styles';
    style.textContent = `[data-revision-ledger-trigger]{position:relative;display:inline-flex;align-items:center;justify-content:center;min-width:34px;min-height:34px}[data-revision-ledger-trigger]>svg{width:17px;height:17px;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}[data-revision-ledger-badge]{position:absolute;right:-5px;top:-7px;min-width:19px;height:19px;padding:0 5px;border:2px solid var(--background,#18181b);border-radius:999px;background:#a855f7;color:#fff;font:700 10px/15px system-ui,sans-serif;text-align:center}`;
    document.head.appendChild(style);
  }

  panelStyles() { return `
    *{box-sizing:border-box}.veil{position:fixed;inset:0;z-index:99990;background:#0005;backdrop-filter:blur(1px)}.panel{--bg:#101721;--panel:#171f2b;--panel2:#202938;--line:#313b4b;--text:#f1f5f9;--muted:#9ca9ba;--accent:#a855f7;--danger:#fb7185;position:fixed;z-index:99991;top:0;right:0;bottom:0;width:min(460px,96vw);display:flex;flex-direction:column;background:var(--bg);color:var(--text);border-left:1px solid var(--line);box-shadow:-20px 0 60px #0007;font:14px/1.4 Inter,system-ui,sans-serif}.panel.light{--bg:#fff;--panel:#f7f8fb;--panel2:#eef1f5;--line:#d8dee8;--text:#172033;--muted:#68768a;--accent:#7c3aed;--danger:#dc2626}.panel>header,.detail>header{display:flex;justify-content:space-between;align-items:flex-start;gap:15px;padding:18px;border-bottom:1px solid var(--line);background:var(--panel)}h2,h3{margin:2px 0;font-size:18px}header p{margin:3px 0 0;color:var(--muted);font:11px ui-monospace,SFMono-Regular,Menlo,monospace;overflow-wrap:anywhere}.eyebrow{color:var(--accent);font-size:9px;font-weight:850;letter-spacing:.15em}.toolbar{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:11px 16px;border-bottom:1px solid var(--line)}button,a{min-height:34px;border:1px solid var(--line);border-radius:7px;background:var(--panel2);color:var(--text);font:700 12px/1 system-ui,sans-serif}button{padding:0 12px;cursor:pointer}button:disabled{opacity:.5;cursor:not-allowed}a{display:inline-flex;align-items:center;padding:0 11px;color:var(--accent);text-decoration:none}.notice{margin:12px;padding:11px;border:1px solid color-mix(in srgb,var(--danger) 45%,var(--line));border-radius:8px;color:var(--danger);background:color-mix(in srgb,var(--danger) 8%,var(--panel))}.list{overflow:auto;flex:1}.list article{display:grid;grid-template-columns:34px minmax(0,1fr) auto;align-items:center;gap:10px;padding:13px 14px;border-bottom:1px solid var(--line)}.number{display:grid;place-items:center;width:31px;height:31px;border-radius:999px;background:var(--accent);color:#fff;font-size:11px;font-weight:800}.copy strong,.copy span,.copy small{display:block}.copy span{margin-top:2px;color:var(--text);font-size:12px}.copy small{margin-top:2px;color:var(--muted);font-size:10px}.actions{display:flex;gap:4px}.icon{width:32px;min-width:32px;padding:0;display:grid;place-items:center}.icon svg{width:15px;height:15px;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}.icon.restore,.restore-text{color:var(--danger)}.close{font-size:22px}.empty{padding:50px 24px;text-align:center;color:var(--muted)}.empty strong,.empty span{display:block}.empty span{margin-top:5px;font-size:12px}.detail{position:absolute;inset:64px 14px 14px;display:flex;flex-direction:column;background:var(--panel);border:1px solid var(--line);border-radius:10px;box-shadow:0 20px 60px #0008;overflow:hidden}.detail>header{padding:13px 15px}.detail pre{flex:1;overflow:auto;margin:0;padding:15px;background:var(--bg);white-space:pre-wrap;overflow-wrap:anywhere;font:11px/1.55 ui-monospace,SFMono-Regular,Menlo,monospace}.detail footer{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:11px 14px;border-top:1px solid var(--line);color:var(--muted);font-size:11px}@media(max-width:560px){.list article{grid-template-columns:34px 1fr}.actions{grid-column:2}.toolbar{align-items:stretch;flex-direction:column}.toolbar a{justify-content:center}}
  `; }
}

if (!customElements.get(TAG)) customElements.define(TAG, RevisionLedgerHistoryField);
