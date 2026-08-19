const TAG = window.__GRAV_PAGE_TAG || 'meta-pilot-page';

class MetaPilotPage extends HTMLElement {
  constructor() {
    super();
    this.attachShadow({ mode: 'open' });
    this.state = { status: null, report: null, busy: false, error: '', filter: 'issues', query: '', theme: 'dark' };
    this.themeObserver = null;
  }

  connectedCallback() {
    this.syncTheme();
    this.render();
    this.load();
  }

  disconnectedCallback() { this.themeObserver?.disconnect(); }

  syncTheme() {
    const update = () => {
      const tokens = `${document.documentElement.dataset.theme || ''} ${document.documentElement.className || ''} ${document.body?.className || ''}`.toLowerCase();
      let theme = tokens.includes('light') ? 'light' : (tokens.includes('dark') ? 'dark' : '');
      if (!theme) theme = matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
      if (theme !== this.state.theme) { this.state.theme = theme; this.render(); }
    };
    this.themeObserver = new MutationObserver(update);
    this.themeObserver.observe(document.documentElement, { attributes: true, attributeFilter: ['class', 'data-theme'] });
    if (document.body) this.themeObserver.observe(document.body, { attributes: true, attributeFilter: ['class', 'data-theme'] });
    update();
  }

  getAuthHeaders() {
    let token = window.__GRAV_API_TOKEN || '';
    let environment = '';
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

  async api(path) {
    const response = await fetch(this.apiUrl(path), { headers: this.getAuthHeaders() });
    const raw = await response.text();
    let payload = {};
    try { payload = raw ? JSON.parse(raw) : {}; } catch (_) { payload = { message: raw }; }
    if (!response.ok) throw new Error(payload.detail || payload.message || payload.title || response.statusText || `HTTP ${response.status}`);
    return payload.data ?? payload;
  }

  async load() {
    if (this.state.busy) return;
    this.state.busy = true; this.state.error = ''; this.render();
    try {
      [this.state.status, this.state.report] = await Promise.all([
        this.api('/meta-pilot/status'),
        this.api('/meta-pilot/report'),
      ]);
    } catch (error) {
      this.state.error = error?.message || String(error);
    } finally {
      this.state.busy = false; this.render();
    }
  }

  render() {
    const status = this.state.status || {};
    const report = this.state.report || {};
    const summary = report.summary || status.summary || {};
    const pages = (report.pages || []).filter(page => {
      if (this.state.filter === 'issues' && !page.issues?.length) return false;
      if (this.state.filter === 'errors' && !page.issues?.some(issue => issue.severity === 'error')) return false;
      const needle = this.state.query.trim().toLowerCase();
      return !needle || `${page.route} ${page.title} ${page.description}`.toLowerCase().includes(needle);
    });
    this.shadowRoot.innerHTML = `
      <style>${this.styles()}</style>
      <main class="shell ${this.state.theme}">
        <section class="hero">
          <div><span class="eyebrow">SEARCH &amp; SOCIAL CONTROL CENTER</span><h1>Meta Pilot</h1><p>Guide every public page with consistent metadata, structured data, and crawl-ready discovery files.</p></div>
          <div class="score"><strong>${Number(summary.score ?? 0)}</strong><span>site score</span></div>
        </section>
        <section class="metrics">
          <div><span>Public pages</span><strong>${summary.pages || 0}</strong></div>
          <div><span>Sitemap URLs</span><strong>${summary.sitemap_pages || 0}</strong></div>
          <div><span>Errors</span><strong class="error-text">${summary.errors || 0}</strong></div>
          <div><span>Warnings</span><strong class="warning-text">${summary.warnings || 0}</strong></div>
        </section>
        ${this.state.error ? `<div class="notice error">${this.escape(this.state.error)}</div>` : ''}
        <section class="panel capability">
          <header><div><span class="eyebrow">OUTPUT STATUS</span><h2>Metadata flight systems</h2></div><button id="refresh" ${this.state.busy ? 'disabled' : ''}>${this.state.busy ? 'Scanning…' : 'Refresh report'}</button></header>
          <div class="badges">${Object.entries(status.features || {}).map(([key, value]) => this.badge(this.label(key), value)).join('')}</div>
          <div class="endpoints">
            ${status.sitemap_enabled ? `<a href="${this.escape(status.sitemap_url)}" target="_blank" rel="noopener">Open XML sitemap ↗</a>` : '<span>Sitemap disabled</span>'}
            ${status.robots_enabled ? `<a href="${this.escape(status.robots_url)}" target="_blank" rel="noopener">Open robots.txt ↗</a>` : '<span>robots.txt disabled</span>'}
          </div>
        </section>
        <section class="panel report">
          <header class="report-head">
            <div><span class="eyebrow">PAGE DIAGNOSTICS</span><h2>Metadata manifest</h2></div>
            <div class="tools">
              <input id="query" value="${this.escape(this.state.query)}" placeholder="Find a route or page…">
              <select id="filter">
                <option value="issues" ${this.state.filter === 'issues' ? 'selected' : ''}>Needs attention</option>
                <option value="errors" ${this.state.filter === 'errors' ? 'selected' : ''}>Errors only</option>
                <option value="all" ${this.state.filter === 'all' ? 'selected' : ''}>All pages</option>
              </select>
            </div>
          </header>
          ${pages.length ? `<div class="page-list">${pages.map(page => this.pageRow(page)).join('')}</div>` : `<div class="empty">${this.state.busy ? 'Reading public pages…' : 'No pages match this view.'}</div>`}
        </section>
        <section class="guide">
          <strong>Page-level overrides</strong>
          <p>Add a <code>meta_pilot:</code> block to page frontmatter for title, description, canonical, image, robots, schema type, or sitemap exclusions. Existing Grav <code>metadata:</code> descriptions and social fields remain valid fallbacks.</p>
        </section>
      </main>`;
    this.bind();
  }

  pageRow(page) {
    const issues = page.issues || [];
    return `<details class="page-row" ${issues.some(issue => issue.severity === 'error') ? 'open' : ''}>
      <summary>
        <span class="route">${this.escape(page.route)}</span>
        <span class="title">${this.escape(page.title || 'Untitled page')}</span>
        <span class="issue-count ${issues.length ? 'has-issues' : 'clear'}">${issues.length ? `${issues.length} issue${issues.length === 1 ? '' : 's'}` : 'Clear'}</span>
        <strong class="page-score">${page.score}</strong>
      </summary>
      <div class="page-detail">
        <dl>
          <div><dt>Description (${page.description_length})</dt><dd>${this.escape(page.description || '—')}</dd></div>
          <div><dt>Canonical</dt><dd><a href="${this.escape(page.canonical)}" target="_blank" rel="noopener">${this.escape(page.canonical)}</a></dd></div>
          <div><dt>Robots / schema</dt><dd>${this.escape(page.robots)} · ${this.escape(page.schema_type)}</dd></div>
          <div><dt>Social image</dt><dd>${page.image ? `<a href="${this.escape(page.image)}" target="_blank" rel="noopener">${this.escape(page.image)}</a>` : 'None'}</dd></div>
        </dl>
        <div class="issues">${issues.length ? issues.map(issue => `<div class="issue ${issue.severity}"><b>${this.escape(issue.severity)}</b><span>${this.escape(issue.message)}</span></div>`).join('') : '<div class="issue clear"><b>clear</b><span>No metadata issues detected.</span></div>'}</div>
      </div>
    </details>`;
  }

  badge(label, enabled) { return `<span class="badge ${enabled ? 'good' : 'off'}">${enabled ? '✓' : '×'} ${this.escape(label)}</span>`; }
  label(value) { return String(value).replaceAll('_', ' ').replace(/\b\w/g, letter => letter.toUpperCase()); }

  bind() {
    this.shadowRoot.getElementById('refresh')?.addEventListener('click', () => this.load());
    this.shadowRoot.getElementById('filter')?.addEventListener('change', event => { this.state.filter = event.target.value; this.render(); });
    this.shadowRoot.getElementById('query')?.addEventListener('input', event => {
      this.state.query = event.target.value; this.render();
      const input = this.shadowRoot.getElementById('query'); input?.focus(); input?.setSelectionRange(this.state.query.length, this.state.query.length);
    });
  }

  escape(value) { return String(value ?? '').replace(/[&<>'"]/g, char => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', "'":'&#39;', '"':'&quot;' }[char])); }

  styles() { return `
    :host{display:block}*{box-sizing:border-box}.shell{--bg:#0d1622;--panel:#121e2c;--panel2:#192638;--line:#2a394d;--text:#edf4ff;--muted:#99a8bc;--accent:#a855f7;--good:#34d399;--warn:#fbbf24;--danger:#fb7185;color:var(--text);background:var(--bg);border-radius:14px;overflow:hidden;font:14px/1.45 Inter,system-ui,sans-serif}.shell.light{--bg:#f6f7fb;--panel:#fff;--panel2:#f1f4f8;--line:#dce2ea;--text:#182131;--muted:#68768a;--accent:#7c3aed;--good:#059669;--warn:#b45309;--danger:#dc2626}.hero{padding:30px;display:flex;align-items:center;justify-content:space-between;gap:24px;background:radial-gradient(circle at 86% 10%,color-mix(in srgb,var(--accent) 24%,transparent),transparent 38%),var(--panel);border-bottom:1px solid var(--line)}h1{font-size:31px;margin:4px 0}h2{font-size:19px;margin:3px 0}.hero p,.guide p{color:var(--muted);margin:0}.eyebrow{color:var(--accent);font-size:11px;font-weight:850;letter-spacing:.15em}.score{width:112px;height:82px;border:1px solid var(--line);border-radius:12px;display:grid;place-content:center;text-align:center;background:color-mix(in srgb,var(--panel) 80%,transparent)}.score strong{font-size:32px;line-height:1}.score span{color:var(--muted);text-transform:uppercase;font-size:9px;font-weight:800;letter-spacing:.11em}.metrics{display:grid;grid-template-columns:repeat(4,1fr);background:var(--panel);border-bottom:1px solid var(--line)}.metrics>div{padding:18px 22px;border-right:1px solid var(--line)}.metrics span{display:block;color:var(--muted);font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.1em}.metrics strong{font-size:22px}.error-text{color:var(--danger)}.warning-text{color:var(--warn)}.panel{margin:16px 20px;background:var(--panel);border:1px solid var(--line);border-radius:12px}.capability{padding:20px}.capability header,.report-head{display:flex;align-items:center;justify-content:space-between;gap:18px}.badges,.endpoints,.tools{display:flex;gap:10px;align-items:center;flex-wrap:wrap}.badges{margin:18px 0}.badge{border-radius:999px;padding:6px 10px;font-size:11px;font-weight:800}.badge.good{background:color-mix(in srgb,var(--good) 15%,var(--panel));color:var(--good)}.badge.off{background:var(--panel2);color:var(--muted)}a{color:var(--accent);text-decoration:none}.endpoints span{color:var(--muted)}button,input,select{min-height:42px;border:1px solid var(--line);border-radius:9px;font:inherit;background:var(--panel2);color:var(--text)}button{padding:0 16px;font-weight:750;cursor:pointer}button:disabled{opacity:.5}.notice{margin:16px 20px 0;padding:14px 18px;border-radius:10px}.notice.error{border:1px solid color-mix(in srgb,var(--danger) 50%,var(--line));background:color-mix(in srgb,var(--danger) 8%,var(--panel));color:var(--danger)}.report-head{padding:18px 20px;border-bottom:1px solid var(--line)}input{width:min(310px,36vw);padding:0 13px}select{padding:0 34px 0 12px}.page-row{border-bottom:1px solid var(--line)}.page-row:last-child{border:0}.page-row summary{list-style:none;display:grid;grid-template-columns:180px minmax(240px,1fr) auto 48px;align-items:center;gap:16px;padding:14px 18px;cursor:pointer}.page-row summary::-webkit-details-marker{display:none}.route{font:12px ui-monospace,SFMono-Regular,Menlo,monospace;color:var(--accent);overflow-wrap:anywhere}.title{font-weight:750}.issue-count{border-radius:999px;padding:5px 9px;font-size:10px;font-weight:850}.issue-count.has-issues{color:var(--warn);background:color-mix(in srgb,var(--warn) 12%,var(--panel))}.issue-count.clear{color:var(--good);background:color-mix(in srgb,var(--good) 12%,var(--panel))}.page-score{width:38px;height:38px;border-radius:50%;display:grid;place-content:center;background:var(--panel2)}.page-detail{padding:4px 18px 18px;display:grid;grid-template-columns:1.35fr 1fr;gap:18px}.page-detail dl{margin:0;background:var(--panel2);border-radius:9px;padding:12px 15px}.page-detail dl>div+div{margin-top:10px}.page-detail dt{color:var(--muted);font-size:10px;font-weight:850;text-transform:uppercase;letter-spacing:.08em}.page-detail dd{margin:2px 0;overflow-wrap:anywhere}.issues{display:flex;flex-direction:column;gap:8px}.issue{display:grid;grid-template-columns:62px 1fr;gap:9px;padding:9px 11px;border-radius:8px;background:var(--panel2)}.issue b{text-transform:uppercase;font-size:9px;letter-spacing:.08em}.issue.error b{color:var(--danger)}.issue.warning b{color:var(--warn)}.issue.info b,.issue.clear b{color:var(--good)}.empty{padding:60px;text-align:center;color:var(--muted)}.guide{margin:16px 20px 22px;padding:16px 18px;border:1px dashed var(--line);border-radius:10px}.guide code{color:var(--accent)}@media(max-width:850px){.metrics{grid-template-columns:repeat(2,1fr)}.capability header,.report-head,.hero{align-items:flex-start;flex-direction:column}.tools{width:100%}input{width:100%}.page-row summary{grid-template-columns:1fr auto}.route,.title{grid-column:1}.issue-count,.page-score{grid-column:2}.page-detail{grid-template-columns:1fr}}@media(max-width:520px){.metrics{grid-template-columns:1fr}.panel,.guide,.notice{margin-left:10px;margin-right:10px}}
  `; }
}

if (!customElements.get(TAG)) customElements.define(TAG, MetaPilotPage);
