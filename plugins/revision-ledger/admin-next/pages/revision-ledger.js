const TAG = window.__GRAV_PAGE_TAG || 'revision-ledger-page';

class RevisionLedgerPage extends HTMLElement {
  constructor() {
    super();
    this.attachShadow({ mode: 'open' });
    this.state = { status: null, pages: [], revisions: [], route: '', query: '', busy: false, error: '', compare: null, theme: 'dark' };
  }

  connectedCallback() { this.syncTheme(); this.render(); this.load(); }
  disconnectedCallback() { this.themeObserver?.disconnect(); this.themeMedia?.removeEventListener?.('change', this.themeListener); }

  syncTheme() {
    const update = () => { const theme = this.detectTheme(); if (theme !== this.state.theme) { this.state.theme = theme; this.render(); } };
    this.themeMedia = window.matchMedia?.('(prefers-color-scheme: dark)') || null;
    this.themeListener = update; this.themeMedia?.addEventListener?.('change', update);
    this.themeObserver = new MutationObserver(update);
    for (const node of [document.documentElement, document.body].filter(Boolean)) this.themeObserver.observe(node, { attributes: true, attributeFilter: ['class', 'data-theme', 'data-mode', 'style'] });
    update();
  }

  detectTheme() {
    const explicit = [document.documentElement?.dataset?.theme, document.documentElement?.dataset?.mode, document.body?.dataset?.theme, document.body?.dataset?.mode].join(' ').toLowerCase();
    if (/\bdark\b/.test(explicit)) return 'dark'; if (/\blight\b/.test(explicit)) return 'light';
    const classes = [...(document.documentElement?.classList || []), ...(document.body?.classList || [])].map(v => String(v).toLowerCase());
    if (classes.includes('dark')) return 'dark'; if (classes.includes('light')) return 'light';
    for (const node of [document.body, document.documentElement, this.parentElement].filter(Boolean)) {
      const value = getComputedStyle(node).backgroundColor; const match = value?.match(/rgba?\((\d+),\s*(\d+),\s*(\d+)/i);
      if (match) return ((+match[1] * 299 + +match[2] * 587 + +match[3] * 114) / 1000) < 150 ? 'dark' : 'light';
    }
    return this.themeMedia?.matches ? 'dark' : 'light';
  }

  headers() {
    let token = window.__GRAV_API_TOKEN || '', environment = '';
    try { const auth = JSON.parse(localStorage.getItem('grav_admin_auth') || '{}'); token ||= auth.accessToken || ''; environment = auth.environment || ''; } catch (_) {}
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

  async load(preserveRoute = true) {
    if (this.state.busy) return;
    this.state.busy = true; this.state.error = ''; this.render();
    try {
      const [status, catalog] = await Promise.all([this.api('/revision-ledger/status'), this.api('/revision-ledger/pages')]);
      this.state.status = status; this.state.pages = catalog.pages || [];
      if (!preserveRoute || !this.state.route) this.state.route = this.state.pages[0]?.route || '';
      await this.loadRevisions();
    } catch (error) { this.state.error = error?.message || String(error); }
    finally { this.state.busy = false; this.render(); }
  }

  async loadRevisions() {
    const suffix = this.state.route ? `?route=${encodeURIComponent(this.state.route)}` : '';
    this.state.revisions = (await this.api(`/revision-ledger/revisions${suffix}`)).revisions || [];
  }

  async chooseRoute(route) {
    this.state.route = route; this.state.busy = true; this.render();
    try { await this.loadRevisions(); this.state.error = ''; } catch (error) { this.state.error = error?.message || String(error); }
    finally { this.state.busy = false; this.render(); }
  }

  async checkpoint() {
    if (!this.state.route) return;
    const reason = prompt('Checkpoint note:', 'Named checkpoint before editorial work');
    if (reason === null) return;
    await this.act(() => this.api('/revision-ledger/checkpoints', { method: 'POST', body: JSON.stringify({ route: this.state.route, reason }) }));
  }

  async compare(id) {
    await this.act(async () => { this.state.compare = await this.api(`/revision-ledger/revisions/${encodeURIComponent(id)}/compare`); }, false);
  }

  async restore(id) {
    const phrase = prompt('This replaces the current page after saving a safety checkpoint. Type RESTORE PAGE to continue:');
    if (phrase === null) return;
    await this.act(() => this.api(`/revision-ledger/revisions/${encodeURIComponent(id)}/restore`, { method: 'POST', body: JSON.stringify({ confirmation: phrase }) }));
  }

  async prune() {
    if (!confirm(`Apply configured retention to ${this.state.route || 'all recorded pages'}? Manual and pre-restore checkpoints remain protected when configured.`)) return;
    await this.act(() => this.api('/revision-ledger/prune', { method: 'POST', body: JSON.stringify({ route: this.state.route || null, execute: true }) }));
  }

  async act(action, reload = true) {
    if (this.state.busy) return;
    this.state.busy = true; this.state.error = ''; this.render();
    try { await action(); if (reload) await this.loadRevisions(); }
    catch (error) { this.state.error = error?.message || String(error); }
    finally { this.state.busy = false; this.render(); }
  }

  adminBase() {
    const path = location.pathname || '/admin';
    for (const marker of ['/plugin/revision-ledger', '/plugins/revision-ledger']) { const i = path.indexOf(marker); if (i >= 0) return path.slice(0, i) || '/admin'; }
    const i = path.indexOf('/admin'); return i >= 0 ? path.slice(0, i + 6) : '/admin';
  }

  render() {
    const status = this.state.status || {};
    const pages = this.state.pages.filter(page => !this.state.query || `${page.route} ${page.title}`.toLowerCase().includes(this.state.query.toLowerCase()));
    const selected = this.state.pages.find(page => page.route === this.state.route);
    this.shadowRoot.innerHTML = `<style>${this.styles()}</style><main class="shell ${this.state.theme}">
      <section class="hero"><div><span class="eyebrow">DURABLE CONTENT HISTORY</span><h1>Revision Ledger</h1><p>Know what changed, why it changed, and exactly what a rollback will restore.</p></div><div class="hero-actions"><button id="settings">Plugin settings</button><button class="primary" id="checkpoint" ${!this.state.route || this.state.busy ? 'disabled' : ''}>Create checkpoint</button></div></section>
      <section class="metrics"><div><span>Protected pages</span><strong>${status.pages || 0}</strong></div><div><span>Revisions</span><strong>${status.revisions || 0}</strong></div><div><span>Stored content</span><strong>${this.bytes(status.bytes || 0)}</strong></div><div><span>Latest checkpoint</span><strong class="small">${status.latest ? this.date(status.latest.created_at) : 'None yet'}</strong></div></section>
      ${this.state.error ? `<div class="notice">${this.escape(this.state.error)}</div>` : ''}
      <section class="workspace">
        <aside><header><div><span class="eyebrow">PAGES</span><h2>History catalog</h2></div><button id="refresh" ${this.state.busy ? 'disabled' : ''}>↻</button></header><input id="query" placeholder="Find a page…" value="${this.escape(this.state.query)}"><nav>${pages.map(page => `<button class="page ${page.route === this.state.route ? 'active' : ''}" data-route="${this.escape(page.route)}"><span>${this.escape(page.title || page.route)}</span><small>${this.escape(page.route)} · ${page.count}</small></button>`).join('') || '<p class="empty">No page revisions yet.</p>'}</nav></aside>
        <section class="timeline"><header><div><span class="eyebrow">REVISION TIMELINE</span><h2>${this.escape(selected?.title || 'Choose a page')}</h2><p>${this.escape(this.state.route)}</p></div><button id="prune" ${!this.state.revisions.length || this.state.busy ? 'disabled' : ''}>Apply retention</button></header>
          <div class="revisions">${this.state.revisions.map(item => this.revisionRow(item)).join('') || `<div class="empty">${this.state.busy ? 'Reading the ledger…' : 'No revisions for this page.'}</div>`}</div>
        </section>
      </section>
      <footer><strong>Protected storage</strong><code>${this.escape(status.storage || 'Not initialized')}</code><span>Automatic saves deduplicate identical content. Restore always creates a pre-restore safety checkpoint.</span></footer>
      ${this.state.compare ? this.compareModal(this.state.compare) : ''}
    </main>`;
    this.bind();
  }

  revisionRow(item) {
    return `<article class="revision"><div class="dot"></div><div class="revision-main"><div class="revision-title"><strong>${this.escape(item.reason || this.source(item.source))}</strong><span class="source">${this.escape(this.source(item.source))}</span></div><p>${this.date(item.created_at)} · ${this.escape(item.author || 'System')} · ${this.bytes(item.bytes)}</p><code>${this.escape(item.id)} · ${this.escape(String(item.sha256 || '').slice(0, 16))}…</code></div><div class="revision-actions"><button data-compare="${this.escape(item.id)}">Compare</button><button class="danger" data-restore="${this.escape(item.id)}">Restore</button></div></article>`;
  }

  compareModal(result) {
    const item = result.revision || {};
    return `<div class="backdrop" id="backdrop"><section class="modal"><header><div><span class="eyebrow">READABLE COMPARISON</span><h2>${this.escape(item.title || item.route)}</h2><p>${this.escape(item.id)} ${result.changed ? '· differs from current' : '· matches current'}</p></div><button id="close">×</button></header><div class="diff-tabs"><span>Snapshot</span><span>Current page</span></div><div class="side-by-side"><pre>${this.escape(result.snapshot)}</pre><pre>${this.escape(result.current)}</pre></div><details><summary>Unified diff</summary><pre class="unified">${this.escape(result.unified)}</pre></details><footer><button id="close2">Done</button><button class="danger" data-restore="${this.escape(item.id)}">Restore this revision</button></footer></section></div>`;
  }

  bind() {
    this.shadowRoot.getElementById('settings')?.addEventListener('click', () => location.href = `${this.adminBase()}/plugins/revision-ledger`);
    this.shadowRoot.getElementById('checkpoint')?.addEventListener('click', () => this.checkpoint());
    this.shadowRoot.getElementById('refresh')?.addEventListener('click', () => this.load());
    this.shadowRoot.getElementById('prune')?.addEventListener('click', () => this.prune());
    this.shadowRoot.querySelectorAll('[data-route]').forEach(button => button.addEventListener('click', () => this.chooseRoute(button.dataset.route)));
    this.shadowRoot.querySelectorAll('[data-compare]').forEach(button => button.addEventListener('click', () => this.compare(button.dataset.compare)));
    this.shadowRoot.querySelectorAll('[data-restore]').forEach(button => button.addEventListener('click', () => this.restore(button.dataset.restore)));
    for (const id of ['close', 'close2']) this.shadowRoot.getElementById(id)?.addEventListener('click', () => { this.state.compare = null; this.render(); });
    this.shadowRoot.getElementById('backdrop')?.addEventListener('click', event => { if (event.target.id === 'backdrop') { this.state.compare = null; this.render(); } });
    this.shadowRoot.getElementById('query')?.addEventListener('input', event => { this.state.query = event.target.value; this.render(); const input = this.shadowRoot.getElementById('query'); input?.focus(); input?.setSelectionRange(this.state.query.length, this.state.query.length); });
  }

  source(value) { return ({ 'admin-auto':'Before Admin save', initial:'Initial version', manual:'Named checkpoint', 'pre-restore':'Safety checkpoint', plugin:'Plugin checkpoint' })[value] || value || 'Checkpoint'; }
  date(value) { if (!value) return '—'; try { return new Intl.DateTimeFormat(undefined, { dateStyle:'medium', timeStyle:'short' }).format(new Date(value)); } catch (_) { return value; } }
  bytes(value) { const n = Number(value || 0); if (n < 1024) return `${n} B`; if (n < 1048576) return `${(n / 1024).toFixed(1)} KB`; return `${(n / 1048576).toFixed(1)} MB`; }
  escape(value) { return String(value ?? '').replace(/[&<>'"]/g, char => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', "'":'&#39;', '"':'&quot;' }[char])); }

  styles() { return `
    :host{display:block}*{box-sizing:border-box}.shell{--bg:#0d1622;--panel:#121e2c;--panel2:#192638;--line:#2a394d;--text:#edf4ff;--muted:#99a8bc;--accent:#a855f7;--good:#34d399;--danger:#fb7185;color:var(--text);background:var(--bg);border-radius:14px;overflow:hidden;font:14px/1.45 Inter,system-ui,sans-serif}.shell.light{--bg:#f6f7fb;--panel:#fff;--panel2:#f1f4f8;--line:#dce2ea;--text:#182131;--muted:#68768a;--accent:#7c3aed;--good:#059669;--danger:#dc2626}.hero{padding:30px;display:flex;align-items:center;justify-content:space-between;gap:24px;background:radial-gradient(circle at 86% 10%,color-mix(in srgb,var(--accent) 24%,transparent),transparent 38%),var(--panel);border-bottom:1px solid var(--line)}h1{font-size:31px;margin:4px 0}h2{font-size:19px;margin:3px 0}.hero p,.timeline header p,footer span{color:var(--muted);margin:0}.eyebrow{color:var(--accent);font-size:10px;font-weight:850;letter-spacing:.15em}.hero-actions,.revision-actions{display:flex;gap:10px}.metrics{display:grid;grid-template-columns:repeat(4,1fr);background:var(--panel);border-bottom:1px solid var(--line)}.metrics>div{padding:17px 20px;border-right:1px solid var(--line)}.metrics span{display:block;color:var(--muted);font-size:9px;font-weight:850;text-transform:uppercase;letter-spacing:.1em}.metrics strong{font-size:21px}.metrics .small{font-size:12px}.notice{margin:16px 20px 0;padding:13px 16px;border:1px solid color-mix(in srgb,var(--danger) 50%,var(--line));border-radius:9px;background:color-mix(in srgb,var(--danger) 8%,var(--panel));color:var(--danger)}button,input{min-height:40px;border:1px solid var(--line);border-radius:8px;background:var(--panel2);color:var(--text);font:inherit}button{padding:0 14px;font-weight:750;cursor:pointer}button.primary{background:var(--accent);border-color:var(--accent);color:white}button.danger{color:var(--danger)}button:disabled{opacity:.45;cursor:not-allowed}.workspace{display:grid;grid-template-columns:minmax(245px,30%) 1fr;margin:18px 20px;border:1px solid var(--line);border-radius:12px;overflow:hidden;background:var(--panel)}aside{border-right:1px solid var(--line)}aside header,.timeline>header,.modal>header{display:flex;justify-content:space-between;align-items:center;gap:14px;padding:18px;border-bottom:1px solid var(--line)}aside input{margin:14px;width:calc(100% - 28px);padding:0 12px}nav{max-height:680px;overflow:auto}.page{display:block;width:100%;height:auto;text-align:left;border:0;border-radius:0;border-top:1px solid var(--line);padding:13px 16px;background:transparent}.page.active{background:color-mix(in srgb,var(--accent) 13%,var(--panel));box-shadow:inset 3px 0 var(--accent)}.page span{display:block}.page small{display:block;color:var(--muted);font:11px ui-monospace,SFMono-Regular,Menlo,monospace;margin-top:3px;overflow-wrap:anywhere}.revisions{max-height:760px;overflow:auto}.revision{position:relative;display:grid;grid-template-columns:16px minmax(0,1fr) auto;gap:12px;padding:18px;border-bottom:1px solid var(--line)}.dot{width:10px;height:10px;border:2px solid var(--accent);border-radius:50%;margin-top:6px}.revision-title{display:flex;gap:9px;align-items:center;flex-wrap:wrap}.source{padding:3px 7px;border-radius:999px;background:var(--panel2);color:var(--muted);font-size:9px;font-weight:800;text-transform:uppercase}.revision p{margin:5px 0;color:var(--muted)}code{font:11px ui-monospace,SFMono-Regular,Menlo,monospace;color:var(--accent);overflow-wrap:anywhere}.empty{padding:55px 20px;text-align:center;color:var(--muted)}.shell>footer{display:grid;grid-template-columns:auto 1fr;gap:6px 15px;margin:0 20px 22px;padding:15px 18px;border:1px dashed var(--line);border-radius:9px}.shell>footer span{grid-column:1/-1}.backdrop{position:fixed;inset:0;z-index:99999;background:#000a;display:grid;place-items:center;padding:25px}.modal{width:min(1200px,96vw);max-height:92vh;overflow:auto;background:var(--panel);border:1px solid var(--line);border-radius:14px;box-shadow:0 25px 80px #0008}.modal header p{margin:3px 0;color:var(--muted)}.diff-tabs{display:grid;grid-template-columns:1fr 1fr;border-bottom:1px solid var(--line)}.diff-tabs span{padding:9px 17px;color:var(--muted);font-size:10px;font-weight:800;text-transform:uppercase}.side-by-side{display:grid;grid-template-columns:1fr 1fr;max-height:52vh;overflow:auto}.side-by-side pre{margin:0;padding:17px;white-space:pre-wrap;overflow-wrap:anywhere;font:12px/1.55 ui-monospace,SFMono-Regular,Menlo,monospace}.side-by-side pre+pre{border-left:1px solid var(--line)}details{border-top:1px solid var(--line)}details summary{padding:13px 18px;cursor:pointer}.unified{margin:0;padding:17px;background:var(--panel2);white-space:pre-wrap}.modal footer{padding:14px 18px;display:flex;justify-content:flex-end;gap:10px;border-top:1px solid var(--line)}@media(max-width:850px){.hero{align-items:flex-start;flex-direction:column}.metrics{grid-template-columns:repeat(2,1fr)}.workspace{grid-template-columns:1fr}aside{border-right:0;border-bottom:1px solid var(--line)}.revision{grid-template-columns:16px 1fr}.revision-actions{grid-column:2}.side-by-side{grid-template-columns:1fr}.side-by-side pre+pre{border-left:0;border-top:1px solid var(--line)}}@media(max-width:520px){.metrics{grid-template-columns:1fr}.workspace,.notice,.shell>footer{margin-left:10px;margin-right:10px}.revision-actions,.hero-actions{flex-wrap:wrap}}
  `; }
}

if (!customElements.get(TAG)) customElements.define(TAG, RevisionLedgerPage);
