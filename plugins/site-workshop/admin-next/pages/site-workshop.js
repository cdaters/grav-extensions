const TAG = window.__GRAV_PAGE_TAG || 'site-workshop-page';

class SiteWorkshopPage extends HTMLElement {
  constructor() {
    super();
    this.attachShadow({ mode: 'open' });
    this.state = { status: null, result: null, query: '', pack: '', page: 1, busy: false, error: '', copied: '', theme: 'dark' };
  }

  connectedCallback() { this.observeTheme(); this.render(); this.load(); }
  disconnectedCallback() { this.observer?.disconnect(); this.media?.removeEventListener?.('change', this.themeListener); }

  observeTheme() {
    const update = () => { const theme = this.detectTheme(); if (theme !== this.state.theme) { this.state.theme = theme; this.render(); } };
    this.media = matchMedia('(prefers-color-scheme: dark)');
    this.themeListener = update;
    this.media.addEventListener?.('change', update);
    this.observer = new MutationObserver(update);
    this.observer.observe(document.documentElement, { attributes: true, attributeFilter: ['class', 'data-theme', 'data-mode', 'style'] });
    if (document.body) this.observer.observe(document.body, { attributes: true, attributeFilter: ['class', 'data-theme', 'data-mode', 'style'] });
    update();
  }

  detectTheme() {
    const source = `${document.documentElement.dataset.theme || ''} ${document.body?.dataset?.theme || ''} ${document.documentElement.className} ${document.body?.className}`.toLowerCase();
    if (source.includes('dark')) return 'dark';
    if (source.includes('light')) return 'light';
    const rgb = getComputedStyle(document.body || document.documentElement).backgroundColor.match(/\d+/g)?.map(Number);
    return rgb && ((rgb[0] * 299 + rgb[1] * 587 + rgb[2] * 114) / 1000) > 150 ? 'light' : (this.media.matches ? 'dark' : 'light');
  }

  headers() {
    let token = '', environment = '';
    try { const auth = JSON.parse(localStorage.getItem('grav_admin_auth') || '{}'); token = window.__GRAV_API_TOKEN || auth.accessToken || ''; environment = auth.environment || ''; } catch (_) {}
    const headers = { 'Content-Type': 'application/json' };
    if (token) { headers.Authorization = `Bearer ${token}`; headers['X-API-Token'] = token; }
    if (environment) headers['X-Grav-Environment'] = environment;
    return headers;
  }

  url(path) { return `${window.__GRAV_API_SERVER_URL || ''}${window.__GRAV_API_PREFIX || '/api/v1'}${path}`; }
  async api(path, options = {}) {
    const response = await fetch(this.url(path), { ...options, headers: { ...this.headers(), ...(options.headers || {}) } });
    const raw = await response.text();
    let payload = {};
    try { payload = raw ? JSON.parse(raw) : {}; } catch (_) { payload = { message: raw }; }
    if (!response.ok) throw new Error(payload.detail || payload.message || payload.title || `HTTP ${response.status}`);
    return payload.data ?? payload;
  }

  async load() {
    this.state.busy = true; this.state.error = ''; this.render();
    try {
      this.state.status = await this.api('/site-workshop/status');
      await this.loadIcons();
    } catch (error) { this.state.error = error?.message || String(error); }
    finally { this.state.busy = false; this.render(); }
  }

  async loadIcons() {
    const params = new URLSearchParams({ q: this.state.query, pack: this.state.pack, page: String(this.state.page), per_page: '48' });
    this.state.result = await this.api(`/site-workshop/icons?${params}`);
  }

  async search(resetPage = true) {
    if (resetPage) this.state.page = 1;
    this.state.busy = true; this.state.error = ''; this.render();
    try { await this.loadIcons(); }
    catch (error) { this.state.error = error?.message || String(error); }
    finally { this.state.busy = false; this.render(); }
  }

  async refresh() {
    this.state.busy = true; this.state.error = ''; this.render();
    try { await this.api('/site-workshop/icons/refresh', { method: 'POST', body: '{}' }); await this.load(); }
    catch (error) { this.state.error = error?.message || String(error); this.state.busy = false; this.render(); }
  }

  adminBase() {
    const path = location.pathname;
    for (const marker of ['/plugin/site-workshop', '/plugins/site-workshop']) {
      const index = path.indexOf(marker); if (index >= 0) return path.slice(0, index) || '/admin';
    }
    return '/admin';
  }

  settings() { location.href = `${this.adminBase()}/plugins/site-workshop`; }

  async copy(value, key) {
    try {
      await navigator.clipboard.writeText(value);
      this.state.copied = key; this.render();
      setTimeout(() => { if (this.state.copied === key) { this.state.copied = ''; this.render(); } }, 1300);
    } catch (_) { this.state.error = 'Clipboard access was blocked. Select and copy the example manually.'; this.render(); }
  }

  render() {
    const status = this.state.status || {};
    const bench = status.icon_bench || {};
    const result = this.state.result || {};
    const items = result.items || [];
    const pagination = result.pagination || { page: 1, pages: 1, total: 0 };
    const modules = Object.entries(status.modules || {});
    const dark = this.state.theme === 'dark';
    this.shadowRoot.innerHTML = `<style>${this.styles(dark)}</style>
      <main class="shell">
        <section class="hero">
          <div><span class="eyebrow">MODULAR SITE OPERATIONS</span><h1>Site Workshop</h1><p>Small, dependable tools for routine Grav work—each module independently useful and explicitly bounded.</p></div>
          <div class="actions"><button data-settings>Plugin settings</button><button class="primary" data-refresh ${this.state.busy ? 'disabled' : ''}>${this.state.busy ? 'Working…' : 'Refresh packs'}</button></div>
        </section>
        <section class="metrics">
          <div><span>Modules</span><strong>${modules.length || 4}</strong></div><div><span>Available now</span><strong>${modules.filter(([, item]) => item.status === 'available').length}</strong></div><div><span>Icon packs</span><strong>${Number(bench.pack_count || 0)}</strong></div><div><span>Safe icons</span><strong>${Number(bench.icon_count || 0)}</strong></div>
        </section>
        ${this.state.error ? `<div class="alert">${this.escape(this.state.error)}</div>` : ''}
        <section class="modules">${modules.map(([key, module]) => `<article class="module ${module.status}"><div><span class="module-state">${this.escape(module.status)}</span><h2>${this.escape(module.label)}</h2><p>${this.escape(module.description)}</p></div>${module.status === 'available' ? '<span class="ready">Ready</span>' : '<span class="roadmap-badge">Roadmap</span>'}</article>`).join('')}</section>
        <section class="panel">
          <header class="panel-head"><div><span class="eyebrow">ICON BENCH</span><h2>Safe SVG library</h2><p>Search the bundled originals and any discovered custom packs. Output is sanitized again every time it is rendered.</p></div><div class="integrations"><span>${bench.shortcode_available ? '✓' : '—'} Shortcode</span><span>✓ Twig</span><span>${bench.dom_sanitizer_available ? '✓' : '—'} DOM sanitizer</span></div></header>
          <div class="toolbar"><label><span>Find an icon</span><input data-query value="${this.escape(this.state.query)}" placeholder="Search names and packs…"></label><label><span>Icon pack</span><select data-pack><option value="">All packs</option>${(bench.packs || []).map(pack => `<option value="${this.escape(pack.slug)}" ${this.state.pack === pack.slug ? 'selected' : ''}>${this.escape(pack.label)} (${pack.count})</option>`).join('')}</select></label><button data-search>${this.state.busy ? 'Searching…' : 'Search'}</button></div>
          ${items.length ? `<div class="grid">${items.map(item => this.iconCard(item)).join('')}</div>` : `<div class="empty">${this.state.busy ? 'Reading icon packs…' : 'No icons match this view.'}</div>`}
          <footer class="pager"><span>${Number(pagination.total || 0)} matching icons</span><div><button data-prev ${pagination.page <= 1 || this.state.busy ? 'disabled' : ''}>Previous</button><span>Page ${pagination.page} of ${pagination.pages}</span><button data-next ${pagination.page >= pagination.pages || this.state.busy ? 'disabled' : ''}>Next</button></div></footer>
        </section>
        <section class="guide"><div><span class="eyebrow">CUSTOM PACKS</span><h2>Bring your own SVG set</h2></div><p>Place a folder of <code>kebab-case.svg</code> files inside <code>theme://images/icons</code> or <code>user://data/site-workshop/icons</code>. The immediate folder name becomes the pack name. Unsafe elements, event handlers, remote references, styles, and embedded data are removed before output.</p></section>
      </main>`;
    this.bind();
  }

  iconCard(item) {
    const shortKey = `${item.reference}:shortcode`;
    const twigKey = `${item.reference}:twig`;
    return `<article class="icon-card"><div class="preview">${item.svg || ''}</div><div class="identity"><strong>${this.escape(item.name)}</strong><span>${this.escape(item.pack_label)}</span><code>${this.escape(item.reference)}</code></div><div class="copy-actions"><button data-copy="${this.escape(item.shortcode)}" data-key="${this.escape(shortKey)}">${this.state.copied === shortKey ? 'Copied!' : 'Copy shortcode'}</button><button data-copy="${this.escape(item.twig)}" data-key="${this.escape(twigKey)}">${this.state.copied === twigKey ? 'Copied!' : 'Copy Twig'}</button></div></article>`;
  }

  bind() {
    this.shadowRoot.querySelector('[data-settings]')?.addEventListener('click', () => this.settings());
    this.shadowRoot.querySelector('[data-refresh]')?.addEventListener('click', () => this.refresh());
    const query = this.shadowRoot.querySelector('[data-query]');
    query?.addEventListener('input', event => { this.state.query = event.target.value; });
    query?.addEventListener('keydown', event => { if (event.key === 'Enter') this.search(); });
    this.shadowRoot.querySelector('[data-pack]')?.addEventListener('change', event => { this.state.pack = event.target.value; this.search(); });
    this.shadowRoot.querySelector('[data-search]')?.addEventListener('click', () => this.search());
    this.shadowRoot.querySelector('[data-prev]')?.addEventListener('click', () => { this.state.page--; this.search(false); });
    this.shadowRoot.querySelector('[data-next]')?.addEventListener('click', () => { this.state.page++; this.search(false); });
    this.shadowRoot.querySelectorAll('[data-copy]').forEach(button => button.addEventListener('click', () => this.copy(button.dataset.copy || '', button.dataset.key || '')));
  }

  escape(value) { return String(value ?? '').replace(/[&<>'"]/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' }[char])); }

  styles(dark) { return `
    :host{display:block;--bg:${dark ? '#0d1724' : '#f6f8fb'};--panel:${dark ? '#121f2f' : '#fff'};--panel2:${dark ? '#18273a' : '#f0f4f8'};--text:${dark ? '#edf3fc' : '#182131'};--muted:${dark ? '#98a8bc' : '#67758a'};--line:${dark ? '#2a3b51' : '#dce3eb'};--accent:#a855f7;--good:#34d399;--warn:#fbbf24;color:var(--text);font:14px/1.48 Inter,system-ui,sans-serif}*{box-sizing:border-box}.shell{overflow:hidden;border:1px solid var(--line);border-radius:15px;background:var(--bg)}h1,h2,p{margin:0}.hero{display:flex;justify-content:space-between;align-items:center;gap:2rem;padding:2rem;background:radial-gradient(circle at 85% 0,color-mix(in srgb,var(--accent) 24%,transparent),transparent 38%),var(--panel)}h1{margin:.18rem 0;font-size:2rem}.hero p,.panel-head p,.module p,.guide p{color:var(--muted)}.eyebrow{color:var(--accent);font-size:.7rem;font-weight:850;letter-spacing:.17em}.actions,.integrations,.pager>div,.copy-actions{display:flex;gap:.6rem;align-items:center;flex-wrap:wrap}button,input,select{min-height:42px;border:1px solid var(--line);border-radius:9px;color:var(--text);background:var(--panel2);font:inherit}button{padding:.6rem .9rem;font-weight:750;cursor:pointer}button:disabled{opacity:.48;cursor:not-allowed}.primary{color:#fff;background:var(--accent);border-color:transparent}.metrics{display:grid;grid-template-columns:repeat(4,1fr);background:var(--panel);border-top:1px solid var(--line);border-bottom:1px solid var(--line)}.metrics>div{padding:1rem 1.35rem;border-right:1px solid var(--line)}.metrics>div:last-child{border:0}.metrics span{display:block;color:var(--muted);font-size:.67rem;font-weight:850;letter-spacing:.12em;text-transform:uppercase}.metrics strong{font-size:1.4rem}.alert{margin:1rem 1.2rem 0;padding:.8rem 1rem;border:1px solid #7b3047;border-radius:9px;color:#ff8ba0;background:#381b27}.modules{display:grid;grid-template-columns:repeat(4,1fr);gap:.8rem;padding:1rem 1.2rem}.module{display:flex;justify-content:space-between;gap:1rem;min-height:142px;padding:1rem;border:1px solid var(--line);border-radius:11px;background:var(--panel)}.module h2{margin:.2rem 0;font-size:1rem}.module-state{color:var(--muted);font-size:.65rem;font-weight:850;letter-spacing:.12em;text-transform:uppercase}.ready,.roadmap-badge{align-self:flex-start;padding:.25rem .5rem;border-radius:999px;font-size:.62rem;font-weight:850;text-transform:uppercase}.ready{color:var(--good);background:color-mix(in srgb,var(--good) 13%,var(--panel))}.roadmap-badge{color:var(--warn);background:color-mix(in srgb,var(--warn) 12%,var(--panel))}.panel,.guide{margin:0 1.2rem 1.2rem;border:1px solid var(--line);border-radius:12px;background:var(--panel)}.panel-head{display:flex;justify-content:space-between;align-items:center;gap:2rem;padding:1.2rem;border-bottom:1px solid var(--line)}.panel-head h2,.guide h2{font-size:1.2rem}.integrations span{padding:.35rem .58rem;border-radius:999px;color:var(--good);background:color-mix(in srgb,var(--good) 12%,var(--panel))}.toolbar{display:grid;grid-template-columns:minmax(240px,1fr) minmax(180px,280px) auto;gap:.7rem;align-items:end;padding:1rem 1.2rem;border-bottom:1px solid var(--line)}label span{display:block;margin-bottom:.3rem;color:var(--muted);font-size:.68rem;font-weight:800;text-transform:uppercase;letter-spacing:.1em}input,select{width:100%;padding:0 .8rem}.grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:.8rem;padding:1rem 1.2rem}.icon-card{display:grid;grid-template-columns:54px minmax(0,1fr);gap:.8rem;padding:.8rem;border:1px solid var(--line);border-radius:10px;background:var(--panel2)}.preview{width:54px;height:54px;display:grid;place-items:center;border:1px solid var(--line);border-radius:9px;background:var(--panel)}.preview svg{width:25px;height:25px;color:var(--accent)}.identity{min-width:0}.identity>*{display:block}.identity span{color:var(--muted);font-size:.76rem}.identity code{margin-top:.25rem;color:var(--accent);font-size:.7rem;overflow-wrap:anywhere}.copy-actions{grid-column:1/-1}.copy-actions button{min-height:34px;flex:1;padding:.38rem .55rem;font-size:.72rem}.pager{display:flex;justify-content:space-between;align-items:center;gap:1rem;padding:.85rem 1.2rem;border-top:1px solid var(--line);color:var(--muted)}.empty{padding:4rem;text-align:center;color:var(--muted)}.guide{display:grid;grid-template-columns:220px 1fr;gap:1.4rem;padding:1rem 1.2rem;border-style:dashed}.guide code{color:var(--accent)}@media(max-width:1100px){.modules,.grid{grid-template-columns:repeat(2,1fr)}}@media(max-width:760px){.hero,.panel-head,.pager{align-items:stretch;flex-direction:column}.metrics{grid-template-columns:repeat(2,1fr)}.modules,.grid{grid-template-columns:1fr}.toolbar{grid-template-columns:1fr}.guide{grid-template-columns:1fr}}@media(max-width:480px){.metrics{grid-template-columns:1fr}.panel,.guide{margin-left:.65rem;margin-right:.65rem}.modules{padding-left:.65rem;padding-right:.65rem}}
    .toolbar input,.toolbar select,.toolbar button{height:42px;min-height:42px;margin:0}.toolbar button{display:inline-flex;align-items:center;justify-content:center}
  `; }
}

if (!customElements.get(TAG)) customElements.define(TAG, SiteWorkshopPage);
