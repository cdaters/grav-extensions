const TAG = window.__GRAV_PAGE_TAG || 'image-foundry-page';

class ImageFoundryPage extends HTMLElement {
  constructor() {
    super();
    this.attachShadow({ mode: 'open' });
    this.state = { status: null, busy: false, message: '', error: '', filter: '', theme: 'dark' };
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

  async api(path, options = {}) {
    const response = await fetch(this.apiUrl(path), { ...options, headers: { ...this.getAuthHeaders(), ...(options.headers || {}) } });
    const raw = await response.text();
    let payload = {};
    try { payload = raw ? JSON.parse(raw) : {}; } catch (_) { payload = { message: raw }; }
    if (!response.ok) throw new Error(payload.detail || payload.message || payload.title || response.statusText || `HTTP ${response.status}`);
    return payload.data ?? payload;
  }

  async run(action, label) {
    if (this.state.busy) return;
    this.state.busy = true; this.state.message = label || ''; this.state.error = ''; this.render();
    try { await action(); }
    catch (error) { this.state.error = error?.message || String(error); this.state.message = ''; }
    finally { this.state.busy = false; this.render(); }
  }

  async refresh(message = '') {
    this.state.status = await this.api('/image-foundry/status');
    this.state.message = message;
  }

  async load() { await this.run(() => this.refresh(''), 'Reading the derivative catalog…'); }

  async scan() {
    await this.run(async () => {
      const result = await this.api('/image-foundry/scan', { method: 'POST', body: '{}' });
      const errors = result.errors?.length ? ` ${result.errors.length} files could not be inspected.` : '';
      await this.refresh(`${result.scanned} sources cataloged; ${result.stale} need derivatives.${errors}`);
    }, 'Scanning configured source roots…');
  }

  async build(source = null, all = false) {
    await this.run(async () => {
      const result = await this.api('/image-foundry/build', { method: 'POST', body: JSON.stringify({ source, all }) });
      const failures = result.failed?.length ? ` ${result.failed.length} failed.` : '';
      await this.refresh(`${result.created_derivatives} derivatives built from ${result.built_sources} sources.${failures}`);
    }, source ? 'Building this image’s responsive set…' : 'Building responsive derivative sets…');
  }

  async purge() {
    if (!confirm('Delete every generated Image Foundry derivative and reset its catalog? Original images will not be changed.')) return;
    await this.run(async () => {
      const result = await this.api('/image-foundry/derivatives', { method: 'DELETE' });
      await this.refresh(`${result.removed_derivatives} generated files removed. Originals were not touched.`);
    }, 'Removing generated derivatives…');
  }

  render() {
    const status = this.state.status || {};
    const sources = (status.sources || []).filter(item => !this.state.filter || item.path.toLowerCase().includes(this.state.filter.toLowerCase()));
    const disabled = this.state.busy ? 'disabled' : '';
    this.shadowRoot.innerHTML = `
      <style>${this.styles()}</style>
      <main class="shell ${this.state.theme}">
        <section class="hero">
          <div><span class="eyebrow">RESPONSIVE IMAGE WORKSHOP</span><h1>Image Foundry</h1><p>Forge modern derivatives while every original remains intact and authoritative.</p></div>
          <div class="hero-actions"><button class="quiet" id="scan" ${disabled}>Scan sources</button><button class="primary" id="build" ${disabled || !status.gd_available ? 'disabled' : ''}>Build stale</button></div>
        </section>
        <section class="metrics">
          <div><span>Sources</span><strong>${status.source_count || 0}</strong></div>
          <div><span>Need build</span><strong>${status.stale_count || 0}</strong></div>
          <div><span>Derivatives</span><strong>${status.derivative_count || 0}</strong></div>
          <div><span>Generated data</span><strong>${this.bytes(status.generated_bytes || 0)}</strong></div>
        </section>
        ${this.state.error ? `<div class="notice error">${this.escape(this.state.error)}</div>` : ''}
        ${this.state.message ? `<div class="notice">${this.escape(this.state.message)}</div>` : ''}
        <section class="safety"><span>✓</span><div><strong>Original-preserving by design</strong><p>${this.escape(status.safety_message || 'Original images are never overwritten.')}</p></div></section>
        <section class="capabilities panel">
          <div><span class="eyebrow">SERVER CAPABILITIES</span><h2>Local image engine</h2></div>
          <div class="badges">
            ${this.badge('GD', status.gd_available)}${this.badge('WebP', status.webp_available)}${this.badge('AVIF', status.avif_available)}${this.badge('EXIF orientation', status.exif_available)}
          </div>
          <code>${this.escape(status.storage_path || 'Protected storage not initialized')}</code>
        </section>
        <section class="panel library">
          <header class="section-head">
            <div><span class="eyebrow">SOURCE CATALOG</span><h2>Responsive candidates</h2></div>
            <div class="tools"><input id="filter" value="${this.escape(this.state.filter)}" placeholder="Filter source paths…"><button class="quiet" id="all" ${disabled || !sources.length ? 'disabled' : ''}>Rebuild all</button><button class="danger" id="purge" ${disabled || !status.derivative_count ? 'disabled' : ''}>Purge generated</button></div>
          </header>
          ${sources.length ? `<div class="source-list">${sources.map(item => this.sourceRow(item, disabled)).join('')}</div>` : '<div class="empty">No cataloged images yet. Scan the configured source roots to begin.</div>'}
        </section>
      </main>`;
    this.bind();
  }

  sourceRow(item, disabled) {
    const count = Object.values(item.derivatives || {}).reduce((sum, variants) => sum + variants.length, 0);
    const generated = Object.values(item.derivatives || {}).flat().reduce((sum, variant) => sum + (variant.bytes || 0), 0);
    return `<article class="source-row">
      <div class="type">${this.escape((item.mime || 'image').replace('image/', '').toUpperCase())}</div>
      <div class="source-main"><strong>${this.escape(item.path)}</strong><small>${item.width} × ${item.height} · ${this.bytes(item.bytes || 0)}</small>${item.error ? `<em>${this.escape(item.error)}</em>` : ''}</div>
      <div class="source-stat"><span>Output</span><strong>${count ? `${count} files · ${this.bytes(generated)}` : 'Not built'}</strong></div>
      <span class="state ${item.stale ? 'stale' : 'ready'}">${item.stale ? 'Needs build' : 'Current'}</span>
      <button class="quiet build-one" data-source="${this.escape(item.path)}" ${disabled}>${item.stale ? 'Build' : 'Rebuild'}</button>
    </article>`;
  }

  badge(label, available) { return `<span class="badge ${available ? 'good' : 'bad'}">${available ? '✓' : '×'} ${label}</span>`; }

  bind() {
    this.shadowRoot.getElementById('scan')?.addEventListener('click', () => this.scan());
    this.shadowRoot.getElementById('build')?.addEventListener('click', () => this.build());
    this.shadowRoot.getElementById('all')?.addEventListener('click', () => this.build(null, true));
    this.shadowRoot.getElementById('purge')?.addEventListener('click', () => this.purge());
    this.shadowRoot.getElementById('filter')?.addEventListener('input', event => { this.state.filter = event.target.value; this.render(); this.shadowRoot.getElementById('filter')?.focus(); });
    this.shadowRoot.querySelectorAll('.build-one').forEach(button => button.addEventListener('click', () => this.build(button.dataset.source)));
  }

  bytes(value) {
    if (!value) return '0 B';
    const units = ['B', 'KB', 'MB', 'GB']; let size = Number(value); let unit = 0;
    while (size >= 1024 && unit < units.length - 1) { size /= 1024; unit++; }
    return `${size >= 10 || unit === 0 ? size.toFixed(0) : size.toFixed(1)} ${units[unit]}`;
  }

  escape(value) { return String(value ?? '').replace(/[&<>'"]/g, char => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', "'":'&#39;', '"':'&quot;' }[char])); }

  styles() { return `
    :host{display:block}*{box-sizing:border-box}.shell{--bg:#0e1724;--panel:#121e2d;--panel2:#172537;--line:#29384c;--text:#edf4ff;--muted:#97a6ba;--accent:#a855f7;--accent2:#7c3aed;--good:#34d399;--danger:#fb7185;color:var(--text);background:var(--bg);border-radius:14px;overflow:hidden;font:14px/1.45 Inter,system-ui,sans-serif}.shell.light{--bg:#f6f7fb;--panel:#fff;--panel2:#f0f3f8;--line:#dce2eb;--text:#182131;--muted:#667286;--accent:#7c3aed;--accent2:#6d28d9;--good:#059669;--danger:#dc2626}.hero{padding:30px;background:radial-gradient(circle at 85% 10%,color-mix(in srgb,var(--accent) 24%,transparent),transparent 36%),var(--panel);display:flex;gap:24px;align-items:center;justify-content:space-between;border-bottom:1px solid var(--line)}h1{font-size:30px;margin:4px 0}h2{font-size:19px;margin:3px 0}.hero p,.safety p{color:var(--muted);margin:0}.eyebrow{color:var(--accent);font-size:11px;font-weight:800;letter-spacing:.16em}.hero-actions,.tools,.badges{display:flex;gap:10px;align-items:center;flex-wrap:wrap}button,input{font:inherit;border-radius:9px;border:1px solid var(--line);min-height:42px}button{padding:0 16px;color:var(--text);background:var(--panel2);font-weight:700;cursor:pointer}button:disabled{opacity:.45;cursor:not-allowed}.primary{background:var(--accent);border-color:var(--accent);color:white}.danger{color:var(--danger)}.metrics{display:grid;grid-template-columns:repeat(4,1fr);background:var(--panel);border-bottom:1px solid var(--line)}.metrics>div{padding:18px 22px;border-right:1px solid var(--line)}.metrics span,.source-stat span{display:block;color:var(--muted);font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.1em}.metrics strong{font-size:22px}.notice,.safety{margin:16px 20px 0;padding:14px 18px;border:1px solid color-mix(in srgb,var(--good) 45%,var(--line));background:color-mix(in srgb,var(--good) 10%,var(--panel));border-radius:10px}.notice.error{border-color:color-mix(in srgb,var(--danger) 55%,var(--line));color:var(--danger);background:color-mix(in srgb,var(--danger) 8%,var(--panel))}.safety{display:flex;gap:14px;align-items:center}.safety>span{display:grid;place-items:center;width:38px;height:38px;border-radius:10px;background:var(--good);color:white;font-weight:900}.panel{margin:16px 20px;background:var(--panel);border:1px solid var(--line);border-radius:12px}.capabilities{padding:20px;display:grid;grid-template-columns:1fr auto;gap:16px;align-items:center}.capabilities code{grid-column:1/-1;color:var(--muted);overflow-wrap:anywhere}.badge,.state{border-radius:999px;padding:6px 10px;font-size:11px;font-weight:800}.badge.good,.state.ready{background:color-mix(in srgb,var(--good) 16%,var(--panel));color:var(--good)}.badge.bad,.state.stale{background:color-mix(in srgb,var(--danger) 12%,var(--panel));color:var(--danger)}.section-head{padding:18px 20px;display:flex;align-items:center;justify-content:space-between;gap:18px;border-bottom:1px solid var(--line)}input{width:min(300px,40vw);padding:0 13px;background:var(--panel2);color:var(--text)}.source-row{display:grid;grid-template-columns:56px minmax(220px,1fr) 180px auto auto;gap:16px;align-items:center;padding:14px 18px;border-bottom:1px solid var(--line)}.source-row:last-child{border:0}.type{width:52px;height:52px;display:grid;place-items:center;border:1px solid var(--line);border-radius:10px;color:var(--accent);font-size:10px;font-weight:900}.source-main{min-width:0}.source-main strong{display:block;white-space:nowrap;text-overflow:ellipsis;overflow:hidden}.source-main small{color:var(--muted)}.source-main em{display:block;color:var(--danger);font-size:12px}.source-stat strong{font-size:12px}.empty{padding:56px;text-align:center;color:var(--muted)}@media(max-width:900px){.hero,.section-head{align-items:flex-start;flex-direction:column}.metrics{grid-template-columns:repeat(2,1fr)}.capabilities{grid-template-columns:1fr}.source-row{grid-template-columns:48px 1fr auto}.source-stat{grid-column:2}.state{grid-column:3;grid-row:1}.build-one{grid-column:3;grid-row:2}.tools{width:100%}input{width:100%}}@media(max-width:560px){.metrics{grid-template-columns:1fr}.source-row{grid-template-columns:44px 1fr}.state,.build-one{grid-column:2;grid-row:auto}.capabilities,.panel,.notice,.safety{margin-left:10px;margin-right:10px}}
  `; }
}

if (!customElements.get(TAG)) customElements.define(TAG, ImageFoundryPage);
