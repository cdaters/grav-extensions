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
      validations: {}, defaultModel: '', defaultAvailable: null,
      prompt: '', response: '', usage: null, cost: null, reliability: null, busy: false, checking: false, error: null,
      retryAction: null, message: '', credentialBusy: '',
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
      const preferred = this.state.bootstrap.default_provider || providers[0].id;
      if (!providers.some(provider => provider.id === this.state.provider)) this.state.provider = preferred;
      this.state.defaultModel = this.setup(this.state.provider)?.default_model || '';
      this.state.modelMessage = 'Select Validate / Test connection to check access and discover models.';
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
      this.state.validations = { ...this.state.validations, [this.state.provider]: validation };
      this.state.models = catalog.models || [];
      this.state.modelMessage = catalog.message || '';
      this.state.defaultModel = catalog.configured_default_model || this.setup(this.state.provider)?.default_model || '';
      this.state.defaultAvailable = catalog.configured_default_available ?? null;
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
      this.state.cost = result.cost || null;
      this.state.reliability = result.reliability || null;
    } catch (error) {
      this.setError(error, error?.retryable ? 'ask' : null);
    } finally {
      this.state.busy = false; this.render();
    }
  }

  async saveCredential(providerId) {
    const input = this.shadowRoot.getElementById(`credential-${providerId}`);
    const credential = input?.value || '';
    if (!credential.trim() || this.state.credentialBusy) return;
    input.value = '';
    this.state.credentialBusy = providerId; this.state.message = ''; this.clearError(); this.render();
    try {
      const result = await this.api(`/grav-jarvis/providers/${encodeURIComponent(providerId)}/credential`, {
        method: 'POST', body: { credential },
      });
      this.applyCredentialStatus(providerId, result.credential);
      this.state.validation = result.validation;
      this.state.validations = { ...this.state.validations, [providerId]: result.validation };
      if (result.models) this.applyCatalog(result.models);
      this.state.message = result.message || 'Credential saved securely.';
    } catch (error) {
      this.setError(error);
    } finally {
      this.state.credentialBusy = ''; this.render();
    }
  }

  async removeCredential(providerId) {
    if (this.state.credentialBusy) return;
    this.state.credentialBusy = providerId; this.state.message = ''; this.clearError(); this.render();
    try {
      const result = await this.api(`/grav-jarvis/providers/${encodeURIComponent(providerId)}/credential/remove`, {
        method: 'POST', body: {},
      });
      this.applyCredentialStatus(providerId, result.credential);
      delete this.state.validations[providerId];
      if (this.state.provider === providerId) {
        this.state.validation = null; this.state.models = [];
      }
      this.state.message = result.message || 'Encrypted local credential removed.';
    } catch (error) {
      this.setError(error);
    } finally {
      this.state.credentialBusy = ''; this.render();
    }
  }

  applyCredentialStatus(providerId, credential) {
    const setup = this.setup(providerId);
    if (!setup || !credential) return;
    setup.credential_status = credential.status;
    setup.credential_source = credential.source;
    setup.credential_backend = credential.backend;
    setup.master_key_source = credential.master_key_source;
    setup.stored_credential_present = credential.stored_credential_present;
    setup.stored_credential_inactive = credential.stored_credential_inactive;
  }

  applyCatalog(catalog) {
    this.state.models = catalog.models || [];
    this.state.modelMessage = catalog.message || '';
    this.state.defaultModel = catalog.configured_default_model || this.setup()?.default_model || '';
    this.state.defaultAvailable = catalog.configured_default_available ?? null;
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

  setup(providerId = this.state.provider) {
    return (this.state.bootstrap?.provider_setups || []).find(item => item.id === providerId) || null;
  }

  credentialStatus(setup) {
    const validation = this.state.validations[setup.id];
    const value = validation?.credential_status || setup.credential_status || 'unknown';
    return ({ configured: 'Configured', missing: 'Missing', invalid: 'Invalid', managed: 'Provider-managed', unknown: 'Unknown' })[value] || 'Unknown';
  }

  validationStatus(setup) {
    if (!setup.enabled) return 'Disabled';
    if (!setup.registered || setup.configuration_status === 'invalid') return 'Configuration error';
    const validation = this.state.validations[setup.id];
    if (!validation) return 'Not tested';
    if (validation.usable) return 'Valid';
    if (validation.state === 'retryable') return 'Temporarily unavailable';
    return 'Invalid';
  }

  status() {
    const validation = this.state.validation;
    if (this.state.checking) return ['Checking…', 'checking'];
    if (!validation) {
      const setup = this.setup();
      return setup && ['missing', 'invalid'].includes(setup.credential_status)
        ? ['Needs configuration', 'warn'] : ['Not tested', 'off'];
    }
    if (validation.usable) return ['Ready', 'ready'];
    if (validation.state === 'misconfigured') return ['Needs configuration', 'warn'];
    if (validation.state === 'retryable') return ['Temporarily unavailable', 'warn'];
    return ['Unavailable', 'off'];
  }

  render() {
    const providers = this.state.bootstrap?.providers || [];
    const setups = this.state.bootstrap?.provider_setups || [];
    const [status, statusClass] = this.status();
    const issue = this.state.validation?.issues?.[0]?.message || '';
    const capabilities = providers.find(provider => provider.id === this.state.provider)?.capabilities || [];
    const retry = this.state.error && this.state.retryAction
      ? `<button id="retry" class="secondary">${this.state.retryAction === 'ask' ? 'Retry request' : 'Retry'}</button>` : '';
    const generateDisabled = this.state.busy || !this.canGenerate() || !this.state.prompt.trim();
    this.shadowRoot.innerHTML = `
      <style>${this.styles()}</style>
      <main aria-busy="${this.state.busy || this.state.checking}">
        <header><div><span class="eyebrow">GRAV 2 ASSISTANT</span><h1>Jarvis</h1><p>Configure providers securely, validate access, choose a model, then ask for help.</p></div><div class="hero-actions">${this.state.bootstrap?.can_configure ? `<button id="settings" class="secondary" title="Open Jarvis plugin settings"><span aria-hidden="true">⚙</span> Settings</button>` : ''}<span class="status ${statusClass}" role="status" aria-live="polite">${this.escape(status)}</span></div></header>
        ${this.state.error ? `<div class="notice error" role="alert"><span>${this.escape(this.state.error.message)}</span>${retry}</div>` : ''}
        ${this.state.message ? `<div class="notice success" role="status"><span>${this.escape(this.state.message)}</span></div>` : ''}
        <section class="provider-setup" aria-labelledby="provider-setup-heading">
          <div class="setup-heading"><div><span class="eyebrow">PROVIDER SETUP</span><h2 id="provider-setup-heading">Providers</h2><p>Paste a first-party API key once, or keep using an environment variable. Jarvis never returns a saved secret.</p></div></div>
          <div class="provider-cards">${setups.map(setup => this.providerCard(setup)).join('')}</div>
        </section>
        ${this.readiness()}
        <section class="controls" aria-label="Provider and model">
          <label for="provider">Provider</label><select id="provider" ${this.state.busy ? 'disabled' : ''} aria-describedby="provider-note">${providers.map(provider => `<option value="${this.escape(provider.id)}" ${provider.id === this.state.provider ? 'selected' : ''}>${this.escape(this.label(provider.id))}</option>`).join('')}</select>
          <label for="model">Model</label><select id="model" ${this.state.busy ? 'disabled' : ''} aria-describedby="provider-note"><option value="">${this.escape(this.state.defaultModel ? `Configured default — ${this.state.defaultModel}${this.state.defaultAvailable === false ? ' (not discovered)' : ''}` : 'Configured default')}</option>${this.state.models.map(model => `<option value="${this.escape(model.id)}" ${model.id === this.state.model ? 'selected' : ''}>${this.escape(`${model.label || model.id}${model.available === false ? ' (unavailable)' : ''}`)}</option>`).join('')}</select>
          <button id="check" class="secondary" ${this.state.busy || !this.state.provider ? 'disabled' : ''} aria-label="Validate selected provider and discover models">${this.state.checking ? 'Validating…' : 'Validate / Test connection'}</button>
        </section>
        <div id="provider-note" class="provider-note" role="status" aria-live="polite">${issue ? `<p>${this.escape(issue)}</p>` : ''}${this.state.modelMessage ? `<p>${this.escape(this.state.modelMessage)}</p>` : ''}${capabilities.length ? `<div class="capabilities" aria-label="Provider capabilities">${capabilities.map(capability => `<span>${this.escape(this.label(capability))}</span>`).join('')}</div>` : ''}</div>
        <section class="assistant" aria-labelledby="prompt-label">
          <label id="prompt-label" for="prompt">What can Jarvis help with?</label>
          <textarea id="prompt" maxlength="8000" placeholder="Ask a question or draft some text…" aria-describedby="prompt-count">${this.escape(this.state.prompt)}</textarea>
          <div class="submit"><span id="prompt-count">${this.state.prompt.length.toLocaleString()} / 8,000</span><button id="ask" ${generateDisabled ? 'disabled' : ''}>${this.state.busy ? 'Working…' : 'Ask Jarvis'}</button></div>
        </section>
        ${this.state.response ? `<section class="response" aria-labelledby="response-heading"><div><span id="response-heading" class="eyebrow">RESPONSE</span>${this.usage()}</div><pre tabindex="0">${this.escape(this.state.response)}</pre></section>` : ''}
        <aside>Jarvis sends provider requests from the server. An Admin-entered key is sent once for saving, encrypted outside plugin YAML, cleared from the form, and never returned. This assistant cannot save or publish content.</aside>
      </main>`;
    this.bind();
  }

  usage() {
    if (!this.state.usage) return '';
    const count = this.state.usage.total ?? null;
    const unit = this.state.usage.unit || 'units';
    const attempts = Number(this.state.usage.request_count ?? this.state.reliability?.attempts ?? 1);
    const retries = Number(this.state.usage.retry_count ?? this.state.reliability?.retry_count ?? 0);
    const cache = Boolean(this.state.usage.cache_hit ?? this.state.reliability?.cache_hit);
    const amount = this.state.cost?.estimated_amount;
    const cost = amount == null ? 'cost unknown' : `est. ${this.state.cost.currency || 'USD'} ${amount}`;
    const request = cache ? 'cache hit' : `${attempts} request${attempts === 1 ? '' : 's'}${retries ? `, ${retries} retr${retries === 1 ? 'y' : 'ies'}` : ''}`;
    return `<small>${count === null ? 'Usage unavailable' : `${Number(count).toLocaleString()} ${this.escape(unit)}`} · ${this.escape(cost)} · ${this.escape(request)}</small>`;
  }

  providerCard(setup) {
    const selected = setup.id === this.state.provider;
    const env = setup.credential_environment_variable
      ? `<div><dt>Environment variable</dt><dd><code>${this.escape(setup.credential_environment_variable)}</code></dd></div>` : '';
    const model = setup.default_model
      ? `<div><dt>Configured default</dt><dd><code>${this.escape(setup.default_model)}</code></dd></div>` : '';
    const base = setup.base_uri
      ? `<div><dt>API base</dt><dd><code>${this.escape(setup.base_uri)}</code></dd></div>` : '';
    const link = setup.official_setup_url
      ? `<a href="${this.escape(setup.official_setup_url)}" target="_blank" rel="noopener noreferrer">Open official key setup</a>` : '';
    const canValidate = setup.enabled && setup.registered;
    const source = setup.credential_source === 'environment' ? 'Environment variable'
      : setup.credential_source === 'encrypted_local' ? `Encrypted local store · ${setup.credential_backend === 'sodium' ? 'Sodium' : 'OpenSSL fallback'}`
      : 'None';
    const override = setup.stored_credential_inactive
      ? '<p class="inline-note">An environment-provided credential is active. The stored local credential is not being used.</p>' : '';
    const localReady = Boolean(this.state.bootstrap?.environment_readiness?.local_storage_available);
    const entry = setup.kind === 'official' && setup.admin_credential_supported && this.state.bootstrap?.can_manage
      ? `<div class="credential-entry"><label for="credential-${this.escape(setup.id)}">API key</label><input id="credential-${this.escape(setup.id)}" type="password" autocomplete="new-password" spellcheck="false" maxlength="8192" placeholder="Paste a new key (never pre-filled)"><small>Empty means no replacement. The key is never returned after submission.</small><div class="credential-actions"><button data-save-credential="${this.escape(setup.id)}" ${!localReady || this.state.credentialBusy ? 'disabled' : ''}>${this.state.credentialBusy === setup.id ? 'Saving…' : 'Save & Validate'}</button>${setup.stored_credential_present ? `<button class="danger secondary" data-remove-credential="${this.escape(setup.id)}" ${this.state.credentialBusy ? 'disabled' : ''}>Remove stored credential</button>` : ''}</div>${!localReady ? '<p class="inline-note">Encrypted Admin entry is unavailable on this host. Use the environment variable shown above.</p>' : ''}</div>` : '';
    return `<article class="provider-card ${selected ? 'selected' : ''}">
      <div class="card-title"><h3>${this.escape(setup.label || this.label(setup.id))}</h3><span>${setup.enabled ? 'Enabled' : 'Disabled'}</span></div>
      <dl><div><dt>Credential</dt><dd>${this.escape(this.credentialStatus(setup))}</dd></div><div><dt>Source</dt><dd>${this.escape(source)}</dd></div><div><dt>Validation</dt><dd>${this.escape(this.validationStatus(setup))}</dd></div>${env}${model}${base}</dl>
      <p>${this.escape(setup.guidance || '')}</p>
      ${override}${entry}
      <div class="card-actions">${link}<button class="secondary" data-validate-provider="${this.escape(setup.id)}" ${canValidate ? '' : 'disabled'}>${selected && this.state.checking ? 'Validating…' : 'Select and validate'}</button></div>
    </article>`;
  }

  readiness() {
    const readiness = this.state.bootstrap?.environment_readiness;
    if (!readiness?.requirements) return '';
    const summary = readiness.best_backend === 'sodium' ? 'Best available local storage: Sodium encrypted.'
      : readiness.best_backend === 'openssl' ? 'Sodium is unavailable. Jarvis will use authenticated OpenSSL encryption.'
      : 'No supported authenticated encryption backend is available. Admin-entered keys are disabled; environment credentials still work.';
    const group = value => ({ required: 'Required', required_local: 'Required for Admin keys', recommended: 'Recommended', fallback: 'Fallback', optional: 'Optional / Advanced' })[value] || this.label(value);
    return `<details class="readiness"><summary><span><strong>Environment readiness</strong><small>${this.escape(summary)}</small></span><span>${readiness.local_storage_available ? 'Ready' : 'Environment-only'}</span></summary><div class="readiness-grid">${readiness.requirements.map(item => `<div><span class="requirement-group">${this.escape(group(item.group))}</span><strong>${this.escape(item.label)}</strong><span class="availability ${item.available ? 'yes' : 'no'}">${item.available ? 'Available' : 'Unavailable'}</span><small>${this.escape(item.detail)}</small></div>`).join('')}</div><p>Optional and recommended items may be absent without disabling Jarvis. Local encrypted storage requires a safe path and either Sodium or authenticated OpenSSL.</p></details>`;
  }

  adminBasePath() {
    const path = window.location.pathname || '/admin';
    for (const marker of ['/plugin/grav-jarvis', '/plugins/grav-jarvis']) {
      const index = path.indexOf(marker);
      if (index >= 0) return path.slice(0, index) || '/admin';
    }
    const index = path.indexOf('/admin');
    return index >= 0 ? path.slice(0, index + 6) : '/admin';
  }

  bind() {
    this.shadowRoot.getElementById('provider')?.addEventListener('change', event => {
      this.state.provider = event.target.value; this.state.model = ''; this.refreshProvider();
    });
    this.shadowRoot.getElementById('model')?.addEventListener('change', event => { this.state.model = event.target.value; });
    this.shadowRoot.getElementById('check')?.addEventListener('click', () => this.refreshProvider());
    this.shadowRoot.getElementById('settings')?.addEventListener('click', () => { window.location.href = `${this.adminBasePath()}${this.state.bootstrap?.settings_path || '/plugins/grav-jarvis'}`; });
    this.shadowRoot.querySelectorAll('[data-validate-provider]').forEach(button => button.addEventListener('click', () => {
      this.state.provider = button.dataset.validateProvider; this.state.model = ''; this.refreshProvider();
    }));
    this.shadowRoot.querySelectorAll('[data-save-credential]').forEach(button => button.addEventListener('click', () => this.saveCredential(button.dataset.saveCredential)));
    this.shadowRoot.querySelectorAll('[data-remove-credential]').forEach(button => button.addEventListener('click', () => this.removeCredential(button.dataset.removeCredential)));
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
    :host{display:block;color-scheme:light dark}*{box-sizing:border-box}main{--bg:var(--background,#fff);--panel:var(--card,#fff);--panel2:var(--secondary,#f4f4f5);--line:var(--border,#e4e4e7);--text:var(--foreground,#09090b);--muted:var(--muted-foreground,#71717a);--accent:var(--primary,#2463eb);--accentText:var(--primary-foreground,#fff);--good:var(--success,#1eae53);--warn:var(--warning,#eb980a);--danger:var(--destructive,#ef4444);max-width:1080px;margin:auto;border:1px solid var(--line);border-radius:14px;overflow:hidden;background:var(--bg);color:var(--text);font:14px/1.5 var(--font-sans,Inter,system-ui,sans-serif)}header{display:flex;justify-content:space-between;align-items:center;gap:16px;padding:28px;background:var(--panel);border-bottom:1px solid var(--line)}h1{font-size:30px;margin:2px 0}h2{margin:2px 0;font-size:21px}header p,.setup-heading p{margin:0;color:var(--muted)}.hero-actions{display:flex;align-items:center;gap:9px;flex-wrap:wrap;justify-content:flex-end}.eyebrow{font-size:10px;font-weight:800;letter-spacing:.14em;color:var(--accent)}.status{flex:none;border:1px solid var(--line);border-radius:999px;padding:7px 11px;font-size:11px;font-weight:800;background:var(--panel2)}.status.ready{color:var(--good)}.status.warn,.status.checking{color:var(--warn)}.status.off{color:var(--muted)}.provider-setup{padding:20px 24px;border-bottom:1px solid var(--line);background:var(--bg)}.setup-heading,.card-title,.card-actions{display:flex;justify-content:space-between;align-items:center;gap:12px}.provider-cards{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin-top:15px}.provider-card{min-width:0;padding:15px;border:1px solid var(--line);border-radius:10px;background:var(--panel)}.provider-card.selected{border-color:var(--accent)}.card-title h3{margin:0;font-size:16px}.card-title span{font-size:10px;font-weight:800;color:var(--muted)}.provider-card dl{display:grid;grid-template-columns:1fr 1fr;gap:7px;margin:12px 0}.provider-card dl div{min-width:0;padding:7px;border-radius:7px;background:var(--panel2)}.provider-card dt{font-size:9px;text-transform:uppercase;letter-spacing:.06em;color:var(--muted)}.provider-card dd{margin:2px 0 0;overflow-wrap:anywhere}.provider-card code{font:10px/1.35 var(--font-mono,ui-monospace,monospace)}.provider-card>p{min-height:3em;color:var(--muted);font-size:11px}.inline-note{min-height:0!important;color:var(--warn)!important}.credential-entry{display:grid;gap:7px;margin:12px 0;padding:12px;border:1px solid var(--line);border-radius:8px;background:var(--panel2)}.credential-entry input{width:100%;min-height:42px;padding:0 11px;border:1px solid var(--line);border-radius:8px;background:var(--panel);color:var(--text);font:inherit}.credential-entry small{color:var(--muted)}.credential-actions{display:flex;gap:7px;flex-wrap:wrap}.credential-actions .danger{color:var(--danger)}.card-actions{align-items:flex-end;flex-wrap:wrap}.card-actions a{color:var(--accent);font-size:11px}.readiness{padding:17px 24px;border-bottom:1px solid var(--line);background:var(--panel)}.readiness summary{display:flex;justify-content:space-between;align-items:center;gap:12px;cursor:pointer}.readiness summary span:first-child{display:grid}.readiness summary small{color:var(--muted)}.readiness-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:9px;margin-top:14px}.readiness-grid>div{display:grid;gap:3px;padding:10px;border:1px solid var(--line);border-radius:8px;background:var(--bg)}.requirement-group{color:var(--muted);font-size:9px;font-weight:800;text-transform:uppercase}.availability{font-size:11px;font-weight:800}.availability.yes{color:var(--good)}.availability.no{color:var(--warn)}.readiness>p{margin-bottom:0;color:var(--muted);font-size:11px}.controls{display:grid;grid-template-columns:1fr 1fr auto;grid-template-areas:"pl ml ." "ps ms check";gap:6px 14px;padding:20px 24px;background:var(--panel);border-bottom:1px solid var(--line);align-items:end}.controls label[for=provider]{grid-area:pl}.controls label[for=model]{grid-area:ml}#provider{grid-area:ps}#model{grid-area:ms}#check{grid-area:check}label{font-size:12px;font-weight:700}select,textarea,button{border:1px solid var(--line);border-radius:8px;background:var(--panel2);color:var(--text);font:inherit}select,button{min-height:42px;padding:0 13px}button{cursor:pointer;font-weight:750;background:var(--accent);color:var(--accentText);border-color:transparent}button.secondary,.notice button{background:var(--panel2);color:var(--text);border-color:var(--line)}button:disabled{opacity:.48;cursor:not-allowed}button:focus-visible,select:focus-visible,input:focus-visible,textarea:focus-visible,pre:focus-visible,a:focus-visible,summary:focus-visible{outline:3px solid color-mix(in srgb,var(--accent) 60%,transparent);outline-offset:2px}.provider-note{min-height:1.5em;margin:14px 24px 0;color:var(--muted)}.provider-note:empty{display:none}.provider-note p{margin:4px 0}.capabilities{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px}.capabilities span{padding:4px 7px;border-radius:999px;background:var(--panel2);font-size:10px}.assistant,.response{margin:20px 24px;padding:20px;border:1px solid var(--line);border-radius:11px;background:var(--panel)}.assistant{display:grid;gap:8px}textarea{width:100%;min-height:180px;resize:vertical;padding:14px;line-height:1.55}.submit{display:flex;justify-content:space-between;align-items:center;gap:12px}.submit span,small{color:var(--muted);font-size:11px}.response>div{display:flex;justify-content:space-between;gap:12px}.response pre{max-width:100%;margin:14px 0 0;white-space:pre-wrap;overflow-wrap:anywhere;font:14px/1.6 var(--font-sans,Inter,system-ui,sans-serif)}.notice{margin:18px 24px 0;padding:12px 14px;border:1px solid color-mix(in srgb,var(--danger) 45%,var(--line));border-radius:8px;color:var(--danger);display:flex;justify-content:space-between;align-items:center;gap:12px}.notice.success{color:var(--good);border-color:color-mix(in srgb,var(--good) 45%,var(--line))}.notice span{min-width:0;overflow-wrap:anywhere}aside{margin:20px 24px 24px;padding:14px 16px;border:1px dashed var(--line);border-radius:8px;color:var(--muted)}@media(max-width:700px){header,.setup-heading{align-items:flex-start;padding:20px}header{flex-direction:column}.hero-actions{width:100%;justify-content:space-between}.provider-setup{padding:16px 12px}.provider-cards,.readiness-grid{grid-template-columns:1fr}.readiness{padding:15px 12px}.controls{grid-template-columns:1fr;grid-template-areas:"pl" "ps" "ml" "ms" "check"}.assistant,.response,.notice,aside{margin-left:12px;margin-right:12px}.submit{align-items:flex-end}.submit button{max-width:60%}}@media(prefers-reduced-motion:reduce){*{scroll-behavior:auto!important;transition:none!important}}
  `; }
}

if (!customElements.get(TAG)) customElements.define(TAG, GravJarvisPage);
