const TAG = window.__GRAV_PAGE_TAG || 'grav-jarvis-page';

class JarvisRequestError extends Error {
  constructor(message, code = 'jarvis_request_failed', status = 0) {
    super(message);
    this.code = code;
    this.status = status;
  }

  get retryable() {
    return new Set([
      'jarvis_rate_limited', 'jarvis_timeout', 'jarvis_provider_unavailable',
      'jarvis_request_failed', 'jarvis_network_error',
    ]).has(this.code);
  }
}

class GravJarvisPage extends HTMLElement {
  constructor() {
    super();
    this.attachShadow({ mode: 'open' });
    this.state = {
      bootstrap: null, provider: '', model: '', models: [], modelMessage: '', validation: null,
      prompt: '', response: '', usage: null, busy: false, checking: false, error: null,
      retryAction: null,
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
    let response;
    try {
      response = await fetch(this.apiUrl(path), {
        method: options.method || 'GET',
        headers: this.authHeaders(options.body !== undefined),
        body: options.body === undefined ? undefined : JSON.stringify(options.body),
        credentials: 'omit',
        cache: 'no-store',
      });
    } catch (_) {
      throw new JarvisRequestError('Jarvis could not reach the Grav API.', 'jarvis_network_error');
    }
    const raw = await response.text();
    let payload = {};
    try { payload = raw ? JSON.parse(raw) : {}; } catch (_) {}
    if (!response.ok) {
      const error = payload.error || payload;
      throw new JarvisRequestError(
        error.detail || error.message || error.title || `Jarvis request failed (${response.status}).`,
        error.code || 'jarvis_request_failed',
        response.status,
      );
    }
    return payload.data ?? payload;
  }

  async load() {
    this.state.busy = true; this.clearError(); this.render();
    try {
      this.state.bootstrap = await this.api('/grav-jarvis/bootstrap');
      const providers = this.state.bootstrap.providers || [];
      if (!providers.length) throw new JarvisRequestError('No Jarvis providers are registered. Ask an administrator to configure one.', 'jarvis_no_providers');
      if (!providers.some(provider => provider.id === this.state.provider)) this.state.provider = providers[0].id;
      await this.refreshProvider();
    } catch (error) {
      this.setError(error, 'load');
    } finally {
      this.state.busy = false; this.render();
    }
  }

  async refreshProvider() {
    if (!this.state.provider) return;
    this.state.checking = true; this.clearError(); this.render();
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
      this.setError(error, 'provider');
    } finally {
      this.state.checking = false; this.render();
    }
  }

  async ask() {
    if (this.state.busy || !this.canGenerate() || !this.state.prompt.trim()) return;
    this.state.busy = true; this.clearError(); this.render();
    try {
      const result = await this.api('/grav-jarvis/completions', {
        method: 'POST',
        body: { provider_id: this.state.provider, model: this.state.model || null, prompt: this.state.prompt },
      });
      this.state.response = result.response || '';
      this.state.usage = result.usage || null;
    } catch (error) {
      this.setError(error, error?.retryable ? 'ask' : null);
    } finally {
      this.state.busy = false; this.render();
    }
  }

  retry() {
    if (this.state.retryAction === 'ask') return this.ask();
    if (this.state.retryAction === 'provider') return this.refreshProvider();
    return this.load();
  }

  clearError() { this.state.error = null; this.state.retryAction = null; }
  setError(error, retryAction = null) {
    this.state.error = error instanceof JarvisRequestError
      ? error
      : new JarvisRequestError(error?.message || 'Jarvis is unavailable.');
    this.state.retryAction = retryAction;
  }

  canGenerate() {
    return Boolean(this.state.provider && this.state.validation?.usable && !this.state.checking);
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
    const retry = this.state.error && this.state.retryAction
      ? `<button id="retry" class="secondary">${this.state.retryAction === 'ask' ? 'Retry request' : 'Retry'}</button>` : '';
    const generateDisabled = this.state.busy || !this.canGenerate() || !this.state.prompt.trim();
    this.shadowRoot.innerHTML = `
      <style>${this.styles()}</style>
      <main aria-busy="${this.state.busy || this.state.checking}">
        <header><div><span class="eyebrow">GRAV 2 ASSISTANT</span><h1>Jarvis</h1><p>Ask for help through a server-side, provider-neutral AI service.</p></div><span class="status ${statusClass}" role="status" aria-live="polite">${this.escape(status)}</span></header>
        ${this.state.error ? `<div class="notice error" role="alert"><span>${this.escape(this.state.error.message)}</span>${retry}</div>` : ''}
        <section class="controls" aria-label="Provider and model">
          <label for="provider">Provider</label><select id="provider" ${this.state.busy ? 'disabled' : ''} aria-describedby="provider-note">${providers.map(provider => `<option value="${this.escape(provider.id)}" ${provider.id === this.state.provider ? 'selected' : ''}>${this.escape(this.label(provider.id))}</option>`).join('')}</select>
          <label for="model">Model</label><select id="model" ${this.state.busy ? 'disabled' : ''} aria-describedby="provider-note"><option value="">Configured default</option>${this.state.models.map(model => `<option value="${this.escape(model.id)}" ${model.id === this.state.model ? 'selected' : ''}>${this.escape(model.label || model.id)}</option>`).join('')}</select>
          <button id="check" class="secondary" ${this.state.busy || !this.state.provider ? 'disabled' : ''} aria-label="Check selected provider">${this.state.checking ? 'Checking…' : 'Check provider'}</button>
        </section>
        <div id="provider-note" class="provider-note" role="status" aria-live="polite">${issue ? `<p>${this.escape(issue)}</p>` : ''}${this.state.modelMessage ? `<p>${this.escape(this.state.modelMessage)}</p>` : ''}${capabilities.length ? `<div class="capabilities" aria-label="Provider capabilities">${capabilities.map(capability => `<span>${this.escape(this.label(capability))}</span>`).join('')}</div>` : ''}</div>
        <section class="assistant" aria-labelledby="prompt-label">
          <label id="prompt-label" for="prompt">What can Jarvis help with?</label>
          <textarea id="prompt" maxlength="8000" placeholder="Ask a question or draft some text…" aria-describedby="prompt-count">${this.escape(this.state.prompt)}</textarea>
          <div class="submit"><span id="prompt-count">${this.state.prompt.length.toLocaleString()} / 8,000</span><button id="ask" ${generateDisabled ? 'disabled' : ''}>${this.state.busy ? 'Working…' : 'Ask Jarvis'}</button></div>
        </section>
        ${this.state.response ? `<section class="response" aria-labelledby="response-heading"><div><span id="response-heading" class="eyebrow">RESPONSE</span>${this.usage()}</div><pre tabindex="0">${this.escape(this.state.response)}</pre></section>` : ''}
        <aside>Jarvis sends requests from the server. Credentials never enter this page, and this assistant cannot save or publish content.</aside>
      </main>`;
    this.bind();
  }

  usage() {
    if (!this.state.usage) return '';
    const count = this.state.usage.total ?? null;
    const unit = this.state.usage.unit || 'units';
    return `<small>${count === null ? 'Usage unavailable' : `${Number(count).toLocaleString()} ${this.escape(unit)}`}</small>`;
  }

  bind() {
    this.shadowRoot.getElementById('provider')?.addEventListener('change', event => {
      this.state.provider = event.target.value; this.state.model = ''; this.refreshProvider();
    });
    this.shadowRoot.getElementById('model')?.addEventListener('change', event => { this.state.model = event.target.value; });
    this.shadowRoot.getElementById('check')?.addEventListener('click', () => this.refreshProvider());
    this.shadowRoot.getElementById('retry')?.addEventListener('click', () => this.retry());
    this.shadowRoot.getElementById('prompt')?.addEventListener('input', event => {
      this.state.prompt = event.target.value;
      const button = this.shadowRoot.getElementById('ask'); if (button) button.disabled = !this.canGenerate() || !this.state.prompt.trim();
      const count = this.shadowRoot.getElementById('prompt-count'); if (count) count.textContent = `${this.state.prompt.length.toLocaleString()} / 8,000`;
    });
    this.shadowRoot.getElementById('prompt')?.addEventListener('keydown', event => {
      if ((event.metaKey || event.ctrlKey) && event.key === 'Enter') { event.preventDefault(); this.ask(); }
    });
    this.shadowRoot.getElementById('ask')?.addEventListener('click', () => this.ask());
  }

  label(value) { return String(value).replaceAll('_', ' ').replaceAll('-', ' ').replace(/\b\w/g, letter => letter.toUpperCase()); }
  escape(value) { return String(value ?? '').replace(/[&<>'"]/g, char => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', "'":'&#39;', '"':'&quot;' }[char])); }
  styles() { return `
    :host{display:block;color-scheme:light dark}*{box-sizing:border-box}main{--bg:var(--background,#fff);--panel:var(--card,#fff);--panel2:var(--secondary,#f4f4f5);--line:var(--border,#e4e4e7);--text:var(--foreground,#09090b);--muted:var(--muted-foreground,#71717a);--accent:var(--primary,#2463eb);--accentText:var(--primary-foreground,#fff);--good:var(--success,#1eae53);--warn:var(--warning,#eb980a);--danger:var(--destructive,#ef4444);max-width:1080px;margin:auto;border:1px solid var(--line);border-radius:14px;overflow:hidden;background:var(--bg);color:var(--text);font:14px/1.5 var(--font-sans,Inter,system-ui,sans-serif)}header{display:flex;justify-content:space-between;align-items:center;gap:16px;padding:28px;background:var(--panel);border-bottom:1px solid var(--line)}h1{font-size:30px;margin:2px 0}header p{margin:0;color:var(--muted)}.eyebrow{font-size:10px;font-weight:800;letter-spacing:.14em;color:var(--accent)}.status{flex:none;border:1px solid var(--line);border-radius:999px;padding:7px 11px;font-size:11px;font-weight:800;background:var(--panel2)}.status.ready{color:var(--good)}.status.warn,.status.checking{color:var(--warn)}.status.off{color:var(--muted)}.controls{display:grid;grid-template-columns:1fr 1fr auto;grid-template-areas:"pl ml ." "ps ms check";gap:6px 14px;padding:20px 24px;background:var(--panel);border-bottom:1px solid var(--line);align-items:end}.controls label[for=provider]{grid-area:pl}.controls label[for=model]{grid-area:ml}#provider{grid-area:ps}#model{grid-area:ms}#check{grid-area:check}label{font-size:12px;font-weight:700}select,textarea,button{border:1px solid var(--line);border-radius:8px;background:var(--panel2);color:var(--text);font:inherit}select,button{min-height:42px;padding:0 13px}button{cursor:pointer;font-weight:750;background:var(--accent);color:var(--accentText);border-color:transparent}button.secondary,.notice button{background:var(--panel2);color:var(--text);border-color:var(--line)}button:disabled{opacity:.48;cursor:not-allowed}button:focus-visible,select:focus-visible,textarea:focus-visible,pre:focus-visible{outline:3px solid color-mix(in srgb,var(--accent) 60%,transparent);outline-offset:2px}.provider-note{min-height:1.5em;margin:14px 24px 0;color:var(--muted)}.provider-note:empty{display:none}.provider-note p{margin:4px 0}.capabilities{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px}.capabilities span{padding:4px 7px;border-radius:999px;background:var(--panel2);font-size:10px}.assistant,.response{margin:20px 24px;padding:20px;border:1px solid var(--line);border-radius:11px;background:var(--panel)}.assistant{display:grid;gap:8px}textarea{width:100%;min-height:180px;resize:vertical;padding:14px;line-height:1.55}.submit{display:flex;justify-content:space-between;align-items:center;gap:12px}.submit span,small{color:var(--muted);font-size:11px}.response>div{display:flex;justify-content:space-between;gap:12px}.response pre{max-width:100%;margin:14px 0 0;white-space:pre-wrap;overflow-wrap:anywhere;font:14px/1.6 var(--font-sans,Inter,system-ui,sans-serif)}.notice{margin:18px 24px 0;padding:12px 14px;border:1px solid color-mix(in srgb,var(--danger) 45%,var(--line));border-radius:8px;color:var(--danger);display:flex;justify-content:space-between;align-items:center;gap:12px}.notice span{min-width:0;overflow-wrap:anywhere}aside{margin:20px 24px 24px;padding:14px 16px;border:1px dashed var(--line);border-radius:8px;color:var(--muted)}@media(max-width:700px){header{align-items:flex-start;padding:20px}.controls{grid-template-columns:1fr;grid-template-areas:"pl" "ps" "ml" "ms" "check"}.assistant,.response,.notice,aside{margin-left:12px;margin-right:12px}.submit{align-items:flex-end}.submit button{max-width:60%}}@media(prefers-reduced-motion:reduce){*{scroll-behavior:auto!important;transition:none!important}}
  `; }
}

if (!customElements.get(TAG)) customElements.define(TAG, GravJarvisPage);
