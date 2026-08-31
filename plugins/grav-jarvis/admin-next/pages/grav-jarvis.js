const TAG = window.__GRAV_PAGE_TAG || 'grav-jarvis-page';

class GravJarvisPage extends HTMLElement {
  constructor() {
    super();
    this.attachShadow({ mode: 'open' });
    this.state = {
      bootstrap: null, provider: '', model: '', models: [], modelMessage: '', validation: null,
      prompt: '', response: '', usage: null, busy: false, checking: false, error: '',
    };
  }

  connectedCallback() { this.render(); this.load(); }

  authHeaders(json = false) {
    let token = window.__GRAV_API_TOKEN || '';
    let environment = '';
    try {
      const auth = JSON.parse(localStorage.getItem('grav_admin_auth') || '{}');
      token ||= auth.accessToken || '';
      environment = auth.environment || '';
    } catch (_) {}
    const headers = { Accept: 'application/json' };
    if (json) headers['Content-Type'] = 'application/json';
    if (token) headers['X-API-Token'] = token;
    if (environment) headers['X-Grav-Environment'] = environment;
    return headers;
  }

  apiUrl(path) {
    return `${window.__GRAV_API_SERVER_URL || ''}${window.__GRAV_API_PREFIX || '/api/v1'}${path}`;
  }

  async api(path, options = {}) {
    const response = await fetch(this.apiUrl(path), {
      method: options.method || 'GET',
      headers: this.authHeaders(options.body !== undefined),
      body: options.body === undefined ? undefined : JSON.stringify(options.body),
      credentials: 'omit',
      cache: 'no-store',
    });
    const raw = await response.text();
    let payload = {};
    try { payload = raw ? JSON.parse(raw) : {}; } catch (_) {}
    if (!response.ok) {
      const error = payload.error || payload;
      throw new Error(error.detail || error.message || error.title || `Jarvis request failed (${response.status}).`);
    }
    return payload.data ?? payload;
  }

  async load() {
    this.state.busy = true; this.state.error = ''; this.render();
    try {
      this.state.bootstrap = await this.api('/grav-jarvis/bootstrap');
      const providers = this.state.bootstrap.providers || [];
      if (!providers.length) throw new Error('No Jarvis providers are registered. Ask an administrator to configure one.');
      this.state.provider = providers[0].id;
      await this.refreshProvider();
    } catch (error) {
      this.state.error = error?.message || 'Jarvis is unavailable.';
    } finally {
      this.state.busy = false; this.render();
    }
  }

  async refreshProvider() {
    if (!this.state.provider) return;
    this.state.checking = true; this.state.error = ''; this.render();
    try {
      const provider = encodeURIComponent(this.state.provider);
      const [validation, catalog] = await Promise.all([
        this.api(`/grav-jarvis/providers/${provider}/validate`, { method: 'POST', body: {} }),
        this.api(`/grav-jarvis/providers/${provider}/models`),
      ]);
      this.state.validation = validation;
      this.state.models = (catalog.models || []).filter(model => model.available !== false);
      this.state.modelMessage = catalog.message || '';
      if (!this.state.models.some(model => model.id === this.state.model)) this.state.model = '';
    } catch (error) {
      this.state.validation = null; this.state.models = []; this.state.modelMessage = '';
      this.state.error = error?.message || 'Provider status could not be loaded.';
    } finally {
      this.state.checking = false; this.render();
    }
  }

  async ask() {
    if (this.state.busy || !this.state.provider || !this.state.prompt.trim()) return;
    this.state.busy = true; this.state.error = ''; this.state.response = ''; this.state.usage = null; this.render();
    try {
      const result = await this.api('/grav-jarvis/completions', {
        method: 'POST',
        body: { provider_id: this.state.provider, model: this.state.model || null, prompt: this.state.prompt },
      });
      this.state.response = result.response || '';
      this.state.usage = result.usage || null;
    } catch (error) {
      this.state.error = error?.message || 'Jarvis could not complete the prompt.';
    } finally {
      this.state.busy = false; this.render();
    }
  }

  status() {
    const validation = this.state.validation;
    if (this.state.checking) return ['Checking…', 'checking'];
    if (!validation) return ['Unknown', 'off'];
    if (validation.usable) return ['Ready', 'ready'];
    if (validation.state === 'misconfigured') return ['Needs configuration', 'warn'];
    if (validation.state === 'retryable') return ['Temporarily unavailable', 'warn'];
    return ['Unavailable', 'off'];
  }

  render() {
    const providers = this.state.bootstrap?.providers || [];
    const [status, statusClass] = this.status();
    const issue = this.state.validation?.issues?.[0]?.message || '';
    const capabilities = providers.find(provider => provider.id === this.state.provider)?.capabilities || [];
    this.shadowRoot.innerHTML = `
      <style>${this.styles()}</style>
      <main>
        <header><div><span class="eyebrow">GRAV 2 ASSISTANT</span><h1>Jarvis</h1><p>Ask for help through a server-side, provider-neutral AI service.</p></div><span class="status ${statusClass}">${this.escape(status)}</span></header>
        ${this.state.error ? `<div class="notice error" role="alert">${this.escape(this.state.error)} <button id="retry">Retry</button></div>` : ''}
        <section class="controls">
          <label>Provider<select id="provider" ${this.state.busy ? 'disabled' : ''}>${providers.map(provider => `<option value="${this.escape(provider.id)}" ${provider.id === this.state.provider ? 'selected' : ''}>${this.escape(this.label(provider.id))}</option>`).join('')}</select></label>
          <label>Model<select id="model" ${this.state.busy ? 'disabled' : ''}><option value="">Configured default</option>${this.state.models.map(model => `<option value="${this.escape(model.id)}" ${model.id === this.state.model ? 'selected' : ''}>${this.escape(model.label || model.id)}</option>`).join('')}</select></label>
          <button id="check" class="secondary" ${this.state.busy || !this.state.provider ? 'disabled' : ''}>${this.state.checking ? 'Checking…' : 'Check provider'}</button>
        </section>
        ${(issue || this.state.modelMessage || capabilities.length) ? `<div class="provider-note">${issue ? `<p>${this.escape(issue)}</p>` : ''}${this.state.modelMessage ? `<p>${this.escape(this.state.modelMessage)}</p>` : ''}${capabilities.length ? `<div class="capabilities">${capabilities.map(capability => `<span>${this.escape(this.label(capability))}</span>`).join('')}</div>` : ''}</div>` : ''}
        <section class="assistant">
          <label for="prompt">What can Jarvis help with?</label>
          <textarea id="prompt" maxlength="8000" placeholder="Ask a question or draft some text…">${this.escape(this.state.prompt)}</textarea>
          <div class="submit"><span>${this.state.prompt.length.toLocaleString()} / 8,000</span><button id="ask" ${this.state.busy || !this.state.provider || !this.state.prompt.trim() ? 'disabled' : ''}>${this.state.busy ? 'Working…' : 'Ask Jarvis'}</button></div>
        </section>
        ${this.state.response ? `<section class="response"><div><span class="eyebrow">RESPONSE</span>${this.usage()}</div><pre>${this.escape(this.state.response)}</pre></section>` : ''}
        <aside>Jarvis sends requests from the server. Credentials never enter this page, and this assistant cannot save or publish content.</aside>
      </main>`;
    this.bind();
  }

  usage() {
    if (!this.state.usage) return '';
    const count = this.state.usage.total ?? null;
    const unit = this.state.usage.unit || 'units';
    return `<small>${count === null ? '' : `${Number(count).toLocaleString()} ${this.escape(unit)}`}</small>`;
  }

  bind() {
    this.shadowRoot.getElementById('provider')?.addEventListener('change', event => {
      this.state.provider = event.target.value; this.state.model = ''; this.refreshProvider();
    });
    this.shadowRoot.getElementById('model')?.addEventListener('change', event => { this.state.model = event.target.value; });
    this.shadowRoot.getElementById('check')?.addEventListener('click', () => this.refreshProvider());
    this.shadowRoot.getElementById('retry')?.addEventListener('click', () => this.load());
    this.shadowRoot.getElementById('prompt')?.addEventListener('input', event => {
      this.state.prompt = event.target.value;
      const button = this.shadowRoot.getElementById('ask'); if (button) button.disabled = !this.state.prompt.trim();
    });
    this.shadowRoot.getElementById('ask')?.addEventListener('click', () => this.ask());
  }

  label(value) { return String(value).replaceAll('_', ' ').replaceAll('-', ' ').replace(/\b\w/g, letter => letter.toUpperCase()); }
  escape(value) { return String(value ?? '').replace(/[&<>'"]/g, char => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', "'":'&#39;', '"':'&quot;' }[char])); }
  styles() { return `
    :host{display:block}*{box-sizing:border-box}main{--bg:#0c1420;--panel:#121d2b;--panel2:#182536;--line:#2b3a4e;--text:#edf4ff;--muted:#9cabc0;--accent:#a78bfa;--good:#34d399;--warn:#fbbf24;--danger:#fb7185;max-width:1080px;margin:auto;border-radius:16px;overflow:hidden;background:var(--bg);color:var(--text);font:14px/1.5 Inter,system-ui,sans-serif}header{display:flex;justify-content:space-between;align-items:center;padding:30px;background:radial-gradient(circle at 90% 0,color-mix(in srgb,var(--accent) 24%,transparent),transparent 42%),var(--panel);border-bottom:1px solid var(--line)}h1{font-size:32px;margin:2px 0}header p{margin:0;color:var(--muted)}.eyebrow{font-size:10px;font-weight:850;letter-spacing:.16em;color:var(--accent)}.status{border-radius:999px;padding:8px 12px;font-size:11px;font-weight:800;background:var(--panel2)}.status.ready{color:var(--good)}.status.warn,.status.checking{color:var(--warn)}.status.off{color:var(--muted)}.controls{display:grid;grid-template-columns:1fr 1fr auto;gap:14px;padding:20px 24px;background:var(--panel);border-bottom:1px solid var(--line);align-items:end}label{display:grid;gap:7px;font-size:12px;font-weight:750}select,textarea,button{border:1px solid var(--line);border-radius:9px;background:var(--panel2);color:var(--text);font:inherit}select,button{min-height:43px;padding:0 13px}button{cursor:pointer;font-weight:800;background:var(--accent);color:#120b24;border-color:transparent}button.secondary,.notice button{background:var(--panel2);color:var(--text);border-color:var(--line)}button:disabled{opacity:.45;cursor:not-allowed}.provider-note{margin:14px 24px 0;color:var(--muted)}.provider-note p{margin:4px 0}.capabilities{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px}.capabilities span{padding:4px 7px;border-radius:999px;background:var(--panel2);font-size:10px}.assistant,.response{margin:20px 24px;padding:20px;border:1px solid var(--line);border-radius:12px;background:var(--panel)}textarea{width:100%;min-height:180px;resize:vertical;padding:14px;line-height:1.55}.submit{display:flex;justify-content:space-between;align-items:center;margin-top:12px}.submit span,small{color:var(--muted);font-size:11px}.response>div{display:flex;justify-content:space-between}.response pre{margin:14px 0 0;white-space:pre-wrap;overflow-wrap:anywhere;font:14px/1.6 Inter,system-ui,sans-serif}.notice{margin:18px 24px 0;padding:12px 14px;border:1px solid color-mix(in srgb,var(--danger) 45%,var(--line));border-radius:9px;color:var(--danger);display:flex;justify-content:space-between;align-items:center;gap:12px}aside{margin:20px 24px 24px;padding:14px 16px;border:1px dashed var(--line);border-radius:9px;color:var(--muted)}@media(prefers-color-scheme:light){main{--bg:#f5f7fb;--panel:#fff;--panel2:#f0f3f8;--line:#dbe1e9;--text:#182131;--muted:#66758a;--accent:#7c3aed;--good:#047857;--warn:#a16207;--danger:#be123c}}@media(max-width:700px){header{align-items:flex-start;gap:14px}.controls{grid-template-columns:1fr}.assistant,.response,.notice,aside{margin-left:12px;margin-right:12px}}
  `; }
}

if (!customElements.get(TAG)) customElements.define(TAG, GravJarvisPage);
