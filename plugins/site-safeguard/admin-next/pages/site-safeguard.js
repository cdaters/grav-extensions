const TAG = window.__GRAV_PAGE_TAG || 'site-safeguard-page';

class SiteSafeguardPage extends HTMLElement {
  constructor() {
    super();
    this.attachShadow({ mode: 'open' });
    this.state = {
      status: null,
      inspections: {},
      profile: 'portable_site',
      note: '',
      busy: false,
      message: '',
      error: '',
      theme: 'dark',
    };
    this.themeObserver = null;
  }

  connectedCallback() {
    this.syncTheme();
    this.render();
    this.load();
  }

  disconnectedCallback() {
    this.themeObserver?.disconnect();
  }

  syncTheme() {
    const update = () => {
      const tokens = `${document.documentElement.dataset.theme || ''} ${document.documentElement.className || ''} ${document.body?.className || ''}`.toLowerCase();
      let theme = tokens.includes('light') ? 'light' : (tokens.includes('dark') ? 'dark' : '');
      if (!theme) theme = matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
      if (theme !== this.state.theme) {
        this.state.theme = theme;
        this.render();
      }
    };
    this.themeObserver = new MutationObserver(update);
    this.themeObserver.observe(document.documentElement, { attributes: true, attributeFilter: ['class', 'data-theme'] });
    if (document.body) this.themeObserver.observe(document.body, { attributes: true, attributeFilter: ['class', 'data-theme'] });
    update();
  }

  getAuthHeaders(json = true) {
    let token = window.__GRAV_API_TOKEN || '';
    let environment = '';
    try {
      const auth = JSON.parse(localStorage.getItem('grav_admin_auth') || '{}');
      token ||= auth.accessToken || '';
      environment = auth.environment || '';
    } catch (_) {}
    const headers = {};
    if (json) headers['Content-Type'] = 'application/json';
    if (token) {
      headers.Authorization = `Bearer ${token}`;
      headers['X-API-Token'] = token;
    }
    if (environment) headers['X-Grav-Environment'] = environment;
    return headers;
  }

  apiUrl(path) {
    return `${window.__GRAV_API_SERVER_URL || ''}${window.__GRAV_API_PREFIX || '/api/v1'}${path}`;
  }

  async api(path, options = {}) {
    const isForm = options.body instanceof FormData;
    const response = await fetch(this.apiUrl(path), {
      ...options,
      headers: { ...this.getAuthHeaders(!isForm), ...(options.headers || {}) },
    });
    const raw = await response.text();
    let payload = {};
    try { payload = raw ? JSON.parse(raw) : {}; } catch (_) { payload = { message: raw }; }
    if (!response.ok) {
      throw new Error(payload.detail || payload.message || payload.title || response.statusText || `HTTP ${response.status}`);
    }
    return payload.data ?? payload;
  }

  async run(action, label = '') {
    if (this.state.busy) return;
    this.state.busy = true;
    this.state.message = label;
    this.state.error = '';
    this.render();
    try {
      await action();
    } catch (error) {
      this.state.error = error?.message || String(error);
      this.state.message = '';
    } finally {
      this.state.busy = false;
      this.render();
    }
  }

  async load() {
    await this.run(async () => {
      this.state.status = await this.api('/site-safeguard/status');
      const profiles = this.state.status?.profiles || [];
      if (!profiles.some(profile => profile.key === this.state.profile)) {
        this.state.profile = profiles[0]?.key || 'portable_site';
      }
      this.state.message = '';
    }, 'Reading safeguards…');
  }

  async createPackage() {
    await this.run(async () => {
      await this.api('/site-safeguard/packages', {
        method: 'POST',
        body: JSON.stringify({ profile: this.state.profile, note: this.state.note }),
      });
      this.state.note = '';
      this.state.status = await this.api('/site-safeguard/status');
      this.state.message = 'Package created. Inspect it before staging or transferring it.';
    }, 'Building and hashing package…');
  }

  async uploadPackage(file) {
    if (!file) return;
    await this.run(async () => {
      const body = new FormData();
      body.append('package', file);
      await this.api('/site-safeguard/packages/upload', { method: 'POST', body });
      this.state.status = await this.api('/site-safeguard/status');
      this.state.message = 'Package imported and validated.';
    }, 'Uploading and validating package…');
  }

  async inspectPackage(name) {
    await this.run(async () => {
      const result = await this.api(`/site-safeguard/packages/${encodeURIComponent(name)}/inspect`, { method: 'POST', body: '{}' });
      this.state.inspections[name] = result;
      this.state.message = result.valid ? 'Every archived file matches its SHA-256 manifest.' : 'Package validation failed.';
    }, 'Inspecting structure and checksums…');
  }

  async stagePackage(name) {
    if (!confirm('Validate this package again and extract it into an isolated directory outside the running site? The live site will not be changed.')) return;
    await this.run(async () => {
      await this.api(`/site-safeguard/packages/${encodeURIComponent(name)}/stage`, { method: 'POST', body: '{}' });
      this.state.status = await this.api('/site-safeguard/status');
      this.state.message = 'Verified stage created. The running site was not modified.';
    }, 'Validating, extracting, and re-verifying stage…');
  }

  async downloadPackage(name) {
    await this.run(async () => {
      const result = await this.api(`/site-safeguard/packages/${encodeURIComponent(name)}/download-token`, { method: 'POST', body: '{}' });
      this.state.message = 'The protected package download is starting…';
      window.location.assign(result.url);
    }, 'Preparing protected download…');
  }

  async deletePackage(name) {
    if (!confirm(`Permanently delete ${name}? This does not delete an existing stage or any live-site files.`)) return;
    await this.run(async () => {
      await this.api(`/site-safeguard/packages/${encodeURIComponent(name)}`, { method: 'DELETE' });
      delete this.state.inspections[name];
      this.state.status = await this.api('/site-safeguard/status');
      this.state.message = 'Package deleted.';
    }, 'Deleting package…');
  }

  async deleteStage(id) {
    if (!confirm(`Delete isolated stage ${id}? The running site will not be changed.`)) return;
    await this.run(async () => {
      await this.api(`/site-safeguard/stages/${encodeURIComponent(id)}`, { method: 'DELETE' });
      this.state.status = await this.api('/site-safeguard/status');
      this.state.message = 'Stage deleted.';
    }, 'Deleting stage…');
  }

  render() {
    const status = this.state.status || {};
    const profiles = status.profiles || [];
    const packages = status.packages || [];
    const stages = status.stages || [];
    const disabled = this.state.busy ? 'disabled' : '';

    this.shadowRoot.innerHTML = `
      <style>${this.styles()}</style>
      <main class="shell ${this.state.theme}">
        <section class="hero">
          <div>
            <span class="eyebrow">VERIFIED SITE RECOVERY</span>
            <h1>Site Safeguard</h1>
            <p>Build portable Grav packages, verify every file, and recover through an automatically verified rollback.</p>
          </div>
          <div class="hero-state"><span>v${this.escape(status.version || '0.2.1')}</span><strong>${status.restore_enabled ? 'Restore armed' : 'Restore disabled'}</strong></div>
        </section>

        <section class="metrics">
          <div><span>Packages</span><strong>${packages.length}</strong></div>
          <div><span>Protected storage</span><strong>${this.formatBytes(status.stored_bytes || 0)}</strong></div>
          <div><span>Verified stages</span><strong>${stages.filter(stage => stage.verified).length}</strong></div>
          <div class="path"><span>Package directory</span><code>${this.escape(status.package_path || '—')}</code></div>
        </section>

        ${this.state.error ? `<div class="notice error">${this.escape(this.state.error)}</div>` : ''}
        ${this.state.message ? `<div class="notice">${this.escape(this.state.message)}</div>` : ''}

        <section class="safety">
          <div class="shield">✓</div>
          <div><strong>Browser operations never replace the running site.</strong><p>${this.escape(status.safety_message || 'Packages are validated and staged outside the running site; restore is a separate CLI operation.')}</p></div>
        </section>

        <section class="workspace">
          <div class="create panel">
            <header><span class="eyebrow">CREATE</span><h2>New recovery package</h2></header>
            <label>Package profile
              <select id="profile" ${disabled}>${profiles.map(profile => `<option value="${this.escape(profile.key)}" ${profile.key === this.state.profile ? 'selected' : ''}>${this.escape(profile.label)}</option>`).join('')}</select>
            </label>
            <p class="profile-help">${this.escape(profiles.find(profile => profile.key === this.state.profile)?.description || '')}</p>
            <label>Operator note
              <textarea id="note" rows="3" maxlength="1000" placeholder="Why are you creating this package?" ${disabled}>${this.escape(this.state.note)}</textarea>
            </label>
            <button class="primary" id="create" ${disabled || !status.zip_available ? 'disabled' : ''}>Create checksummed package</button>
          </div>

          <div class="import panel">
            <header><span class="eyebrow">IMPORT</span><h2>Validate another package</h2></header>
            <label class="drop" id="drop">
              <span class="upload-icon">↑</span>
              <strong>Drop a Site Safeguard ZIP here</strong>
              <small>or choose a package from this computer</small>
              <input id="upload" type="file" accept=".zip,application/zip" ${disabled || !status.allow_uploads ? 'disabled' : ''}>
            </label>
            <p class="fine">Imports remain in protected storage. A structurally unsafe package is rejected before it is retained.</p>
          </div>
        </section>

        <section class="panel packages">
          <header class="section-head"><div><span class="eyebrow">PACKAGE LIBRARY</span><h2>Recovery packages</h2></div><button class="quiet" id="refresh" ${disabled}>Refresh</button></header>
          ${packages.length ? packages.map(item => this.packageCard(item, disabled)).join('') : '<div class="empty">No packages yet. Create a portable package or import one for validation.</div>'}
        </section>

        <section class="panel stages">
          <header class="section-head"><div><span class="eyebrow">ISOLATED STAGING</span><h2>Verified stages</h2></div><code>${this.escape(status.stage_path || '')}</code></header>
          ${stages.length ? stages.map(stage => `
            <article class="stage-row">
              <div><strong>${this.escape(stage.id)}</strong><small>${this.escape(stage.record?.package || 'Unknown package')} · ${this.formatDate(stage.modified * 1000)}</small>${stage.verified && status.restore_enabled ? `<code class="restore-command">bin/plugin site-safeguard restore ${this.escape(stage.id)} --confirm="RESTORE THIS SITE"</code>` : ''}</div>
              <span class="badge good">${stage.verified ? 'Verified' : 'Unknown'}</span>
              <div class="stage-actions">
                <span class="cli-ready">${status.restore_enabled ? 'CLI restore ready' : 'Restore disabled'}</span>
                <button class="danger delete-stage" data-id="${this.escape(stage.id)}" ${disabled}>Delete stage</button>
              </div>
            </article>`).join('') : '<div class="empty compact">No isolated stages.</div>'}
        </section>
      </main>
    `;

    this.bind();
  }

  packageCard(item, disabled) {
    const manifest = item.manifest || {};
    const profile = manifest.profile || {};
    const inspection = this.state.inspections[item.name];
    const canStage = inspection?.valid && profile.deployable;
    return `
      <article class="package-card">
        <div class="package-main">
          <div class="file-icon">ZIP</div>
          <div class="package-copy">
            <strong>${this.escape(item.name)}</strong>
            <small>${this.escape(profile.label || 'Unknown profile')} · ${this.formatBytes(item.size)} · ${this.formatDate(item.modified * 1000)}</small>
            <code>SHA-256 ${this.escape(item.sha256 || '')}</code>
          </div>
          <span class="badge ${inspection ? (inspection.valid ? 'good' : 'bad') : ''}">${inspection ? (inspection.valid ? 'Verified' : 'Invalid') : 'Unverified'}</span>
        </div>
        <div class="actions">
          <button class="inspect" data-name="${this.escape(item.name)}" ${disabled}>Inspect</button>
          <button class="stage" data-name="${this.escape(item.name)}" ${disabled || !canStage ? 'disabled' : ''}>Create stage</button>
          <button class="download" data-name="${this.escape(item.name)}" ${disabled}>Download</button>
          <button class="danger delete-package" data-name="${this.escape(item.name)}" ${disabled}>Delete</button>
        </div>
        ${inspection ? this.inspectionPanel(inspection) : ''}
      </article>`;
  }

  inspectionPanel(result) {
    const manifest = result.manifest || {};
    const stats = manifest.stats || {};
    return `
      <div class="inspection ${result.valid ? 'valid' : 'invalid'}">
        <div class="inspection-grid">
          <div><span>Result</span><strong>${result.valid ? 'All checks passed' : 'Validation failed'}</strong></div>
          <div><span>Files checked</span><strong>${result.archive_files ?? 0}</strong></div>
          <div><span>Expanded size</span><strong>${this.formatBytes(result.uncompressed_bytes || 0)}</strong></div>
          <div><span>Source warnings</span><strong>${(result.warnings || []).length}</strong></div>
        </div>
        ${(result.errors || []).length ? `<ul class="issues errors">${result.errors.map(error => `<li>${this.escape(error)}</li>`).join('')}</ul>` : ''}
        ${(result.warnings || []).length ? `<ul class="issues warnings">${result.warnings.map(warning => `<li>${this.escape(warning)}</li>`).join('')}</ul>` : ''}
        ${stats.symlinks_skipped ? `<p class="fine">${stats.symlinks_skipped} symbolic link(s) were deliberately omitted when this package was created.</p>` : ''}
      </div>`;
  }

  bind() {
    this.shadowRoot.querySelector('#profile')?.addEventListener('change', event => {
      this.state.profile = event.target.value;
      this.render();
    });
    this.shadowRoot.querySelector('#note')?.addEventListener('input', event => { this.state.note = event.target.value; });
    this.shadowRoot.querySelector('#create')?.addEventListener('click', () => this.createPackage());
    this.shadowRoot.querySelector('#refresh')?.addEventListener('click', () => this.load());
    this.shadowRoot.querySelector('#upload')?.addEventListener('change', event => this.uploadPackage(event.target.files?.[0]));
    const drop = this.shadowRoot.querySelector('#drop');
    drop?.addEventListener('dragover', event => { event.preventDefault(); drop.classList.add('over'); });
    drop?.addEventListener('dragleave', () => drop.classList.remove('over'));
    drop?.addEventListener('drop', event => {
      event.preventDefault();
      drop.classList.remove('over');
      this.uploadPackage(event.dataTransfer?.files?.[0]);
    });
    this.shadowRoot.querySelectorAll('.inspect').forEach(button => button.addEventListener('click', () => this.inspectPackage(button.dataset.name)));
    this.shadowRoot.querySelectorAll('.stage').forEach(button => button.addEventListener('click', () => this.stagePackage(button.dataset.name)));
    this.shadowRoot.querySelectorAll('.download').forEach(button => button.addEventListener('click', () => this.downloadPackage(button.dataset.name)));
    this.shadowRoot.querySelectorAll('.delete-package').forEach(button => button.addEventListener('click', () => this.deletePackage(button.dataset.name)));
    this.shadowRoot.querySelectorAll('.delete-stage').forEach(button => button.addEventListener('click', () => this.deleteStage(button.dataset.id)));
  }

  styles() {
    return `
      :host { display:block; }
      * { box-sizing:border-box; }
      button,input,select,textarea { font:inherit; }
      .shell { --bg:#10151d; --panel:#151c26; --panel-2:#1b2430; --text:#edf3fa; --muted:#93a2b5; --line:#2a3544; --accent:#9a4cff; --accent-2:#bd85ff; --good:#38ca8b; --bad:#ff6262; min-height:calc(100vh - 120px); padding:24px; border-radius:12px; background:var(--bg); color:var(--text); font:14px/1.5 system-ui,-apple-system,sans-serif; }
      .shell.light { --bg:#f6f7fa; --panel:#fff; --panel-2:#f0f2f6; --text:#20252d; --muted:#687383; --line:#dce0e7; --accent:#7428d8; --accent-2:#7428d8; --good:#087d52; --bad:#b62d2d; }
      .hero { display:flex; align-items:center; justify-content:space-between; gap:20px; padding:28px; border:1px solid var(--line); border-radius:12px 12px 0 0; background:linear-gradient(120deg,var(--panel),color-mix(in srgb,var(--accent) 12%,var(--panel))); }
      h1,h2,p { margin:0; } h1 { margin:.2rem 0 .35rem; font-size:30px; } h2 { margin:.15rem 0 0; font-size:19px; }
      .hero p,.profile-help,.fine { color:var(--muted); }
      .eyebrow { color:var(--accent-2); font-size:10px; font-weight:850; letter-spacing:.14em; }
      .hero-state { display:grid; gap:3px; min-width:160px; padding:14px 16px; border:1px solid var(--line); border-radius:9px; background:var(--panel); }
      .hero-state span { color:var(--muted); font:12px ui-monospace,monospace; }
      .hero-state strong { color:var(--bad); }
      .metrics { display:grid; grid-template-columns:repeat(3,minmax(120px,1fr)) minmax(260px,1.5fr); border:1px solid var(--line); border-top:0; background:var(--panel); }
      .metrics > div { display:grid; gap:5px; min-width:0; padding:16px 18px; border-right:1px solid var(--line); }
      .metrics > div:last-child { border-right:0; }
      .metrics span,.inspection span { color:var(--muted); font-size:10px; font-weight:750; letter-spacing:.08em; text-transform:uppercase; }
      .metrics strong { font-size:19px; }.metrics code { overflow:hidden; text-overflow:ellipsis; color:var(--muted); font:12px ui-monospace,monospace; }
      .notice { margin-top:14px; padding:11px 14px; border:1px solid color-mix(in srgb,var(--accent) 45%,var(--line)); border-radius:8px; background:color-mix(in srgb,var(--accent) 8%,var(--panel)); }
      .notice.error { border-color:color-mix(in srgb,var(--bad) 55%,var(--line)); color:var(--bad); background:color-mix(in srgb,var(--bad) 8%,var(--panel)); }
      .safety { display:flex; align-items:center; gap:14px; margin:16px 0; padding:15px 18px; border:1px solid color-mix(in srgb,var(--good) 45%,var(--line)); border-radius:10px; background:color-mix(in srgb,var(--good) 7%,var(--panel)); }
      .safety p { color:var(--muted); }.shield { display:grid; flex:0 0 38px; height:38px; place-items:center; border-radius:12px; background:var(--good); color:white; font-weight:900; }
      .workspace { display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:16px; }
      .panel { border:1px solid var(--line); border-radius:10px; background:var(--panel); }
      .create,.import { display:grid; align-content:start; gap:13px; padding:20px; }
      label { display:grid; gap:6px; color:var(--muted); font-size:11px; font-weight:750; letter-spacing:.05em; text-transform:uppercase; }
      select,textarea { width:100%; min-height:42px; padding:9px 11px; border:1px solid var(--line); border-radius:7px; outline:none; background:var(--panel-2); color:var(--text); text-transform:none; }
      textarea { resize:vertical; }.profile-help { min-height:42px; font-size:12px; }
      button { min-height:38px; padding:8px 12px; border:1px solid var(--line); border-radius:7px; background:var(--panel-2); color:var(--text); cursor:pointer; font-weight:750; }
      button:hover:not(:disabled) { border-color:var(--accent); } button:disabled { cursor:not-allowed; opacity:.45; }
      .primary { border-color:var(--accent); background:var(--accent); color:#fff; }.danger { color:var(--bad); }.quiet { background:transparent; }
      .drop { min-height:147px; place-content:center; place-items:center; gap:4px; padding:20px; border:1px dashed var(--accent); border-radius:10px; background:color-mix(in srgb,var(--accent) 7%,var(--panel-2)); cursor:pointer; text-align:center; text-transform:none; }
      .drop.over { background:color-mix(in srgb,var(--accent) 16%,var(--panel-2)); }.drop input { max-width:230px; margin-top:9px; color:var(--muted); }.drop small { color:var(--muted); font-weight:500; }.upload-icon { display:grid; width:34px; height:34px; place-items:center; border-radius:9px; background:var(--accent); color:#fff; font-size:21px; }
      .section-head { display:flex; align-items:center; justify-content:space-between; gap:16px; padding:16px 18px; border-bottom:1px solid var(--line); }.section-head code { color:var(--muted); font-size:11px; }
      .package-card { border-bottom:1px solid var(--line); }.package-card:last-child { border-bottom:0; }.package-main { display:flex; align-items:center; gap:13px; padding:14px 18px 8px; }.file-icon { display:grid; flex:0 0 48px; height:48px; place-items:center; border:1px solid var(--line); border-radius:9px; color:var(--accent-2); font:800 11px ui-monospace,monospace; background:var(--panel-2); }
      .package-copy { display:grid; flex:1; min-width:0; gap:2px; }.package-copy strong { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }.package-copy small { color:var(--muted); }.package-copy code { overflow:hidden; color:var(--muted); font-size:10px; text-overflow:ellipsis; white-space:nowrap; }
      .badge { padding:4px 8px; border:1px solid var(--line); border-radius:999px; color:var(--muted); font-size:10px; font-weight:800; }.badge.good { border-color:color-mix(in srgb,var(--good) 40%,var(--line)); color:var(--good); background:color-mix(in srgb,var(--good) 10%,transparent); }.badge.bad { color:var(--bad); }
      .actions { display:flex; justify-content:flex-end; gap:7px; padding:0 18px 14px; }
      .inspection { margin:0 18px 16px; padding:13px; border:1px solid var(--line); border-radius:8px; background:var(--panel-2); }.inspection.valid { border-color:color-mix(in srgb,var(--good) 45%,var(--line)); }.inspection.invalid { border-color:color-mix(in srgb,var(--bad) 45%,var(--line)); }.inspection-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:10px; }.inspection-grid > div { display:grid; gap:4px; }.issues { margin:10px 0 0; padding-left:20px; }.issues.errors { color:var(--bad); }.issues.warnings { color:#d89a2b; }
      .stages { margin-top:16px; }.stage-row { display:grid; grid-template-columns:1fr auto auto; align-items:center; gap:12px; padding:13px 18px; border-bottom:1px solid var(--line); }.stage-row:last-child { border-bottom:0; }.stage-row > div { display:grid; }.stage-row small { color:var(--muted); }.restore-command { margin-top:5px; overflow:auto; color:var(--accent-2); font-size:10px; white-space:nowrap; }.stage-actions { display:flex!important; align-items:center; gap:10px; }.cli-ready { color:var(--muted); font-size:11px; font-weight:750; }.empty { padding:38px 20px; color:var(--muted); text-align:center; }.empty.compact { padding:22px; }
      @media (max-width:950px) { .metrics { grid-template-columns:1fr 1fr; }.metrics > div:nth-child(2) { border-right:0; }.metrics .path { grid-column:1/-1; border-top:1px solid var(--line); }.workspace { grid-template-columns:1fr; }.inspection-grid { grid-template-columns:1fr 1fr; } }
      @media (max-width:620px) { .shell { padding:12px; }.hero { align-items:flex-start; flex-direction:column; }.hero-state { width:100%; }.metrics { grid-template-columns:1fr; }.metrics > div { border-right:0; border-bottom:1px solid var(--line); }.metrics > div:last-child { border-bottom:0; }.metrics .path { grid-column:auto; }.package-main { align-items:flex-start; flex-wrap:wrap; }.package-copy { flex-basis:calc(100% - 65px); }.actions { justify-content:stretch; flex-wrap:wrap; }.actions button { flex:1; }.inspection-grid { grid-template-columns:1fr; }.stage-row { grid-template-columns:1fr auto; }.stage-actions { grid-column:1/-1; flex-wrap:wrap; }.stage-actions button { flex:1; } }
    `;
  }

  formatBytes(bytes) {
    if (!Number.isFinite(Number(bytes)) || Number(bytes) <= 0) return '0 B';
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    const index = Math.min(Math.floor(Math.log(Number(bytes)) / Math.log(1024)), units.length - 1);
    const value = Number(bytes) / Math.pow(1024, index);
    return `${value.toFixed(index === 0 ? 0 : (value >= 10 ? 1 : 2))} ${units[index]}`;
  }

  formatDate(value) {
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? 'Unknown date' : new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(date);
  }

  escape(value) {
    return String(value ?? '').replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;').replaceAll("'", '&#039;');
  }
}

if (!customElements.get(TAG)) {
  customElements.define(TAG, SiteSafeguardPage);
}
