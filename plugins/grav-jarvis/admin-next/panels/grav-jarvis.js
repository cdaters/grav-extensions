const TAG = window.__GRAV_PANEL_TAG || 'grav-jarvis-panel';

class GravJarvisPanel extends HTMLElement {
  constructor() {
    super();
    this.attachShadow({ mode: 'open' });
    this.state = {
      bootstrap: null, provider: '', model: '', models: [], modelMessage: '', validation: null,
      page: null, action: 'rewrite', custom: '', original: '', proposal: null,
      busy: false, checking: false, error: '', message: '',
    };
  }

  connectedCallback() { this.render(); this.load(); }

  get route() { return this.getAttribute('route') || window.__GRAV_PAGE_ROUTE || ''; }
  get language() { return this.getAttribute('lang') || window.__GRAV_CONTENT_LANG || ''; }

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

  apiUrl(path) { return `${window.__GRAV_API_SERVER_URL || ''}${window.__GRAV_API_PREFIX || '/api/v1'}${path}`; }

  async api(path, options = {}) {
    const response = await fetch(this.apiUrl(path), {
      method: options.method || 'GET',
      headers: this.authHeaders(options.body !== undefined),
      body: options.body === undefined ? undefined : JSON.stringify(options.body),
      credentials: 'omit', cache: 'no-store',
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

  async editorSnapshot() {
    return new Promise((resolve, reject) => {
      const timeout = setTimeout(() => {
        window.removeEventListener('grav:editor:content-response', receive);
        reject(new Error('The current editor buffer is unavailable. Reopen Jarvis from a page editor.'));
      }, 1800);
      const receive = event => {
        const detail = event.detail || {};
        if (this.route && detail.route && detail.route !== this.route) return;
        clearTimeout(timeout);
        window.removeEventListener('grav:editor:content-response', receive);
        resolve({
          content: typeof detail.content === 'string' ? detail.content : '',
          route: detail.route || this.route,
          title: detail.title || this.state.page?.title || '',
          template: detail.template || this.state.page?.template || '',
          language: this.language || this.state.page?.language || '',
        });
      };
      window.addEventListener('grav:editor:content-response', receive);
      window.dispatchEvent(new CustomEvent('grav:editor:get-content'));
    });
  }

  async load() {
    this.state.busy = true; this.state.error = ''; this.render();
    try {
      if (!this.route) throw new Error('Jarvis needs an open page editor.');
      const [bootstrap, page, snapshot] = await Promise.all([
        this.api('/grav-jarvis/bootstrap'),
        this.api(`/grav-jarvis/page-context?route=${encodeURIComponent(this.route)}`),
        this.editorSnapshot(),
      ]);
      this.state.bootstrap = bootstrap; this.state.page = page; this.state.original = snapshot.content;
      const providers = bootstrap.providers || [];
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
      const id = encodeURIComponent(this.state.provider);
      const [validation, catalog] = await Promise.all([
        this.api(`/grav-jarvis/providers/${id}/validate`, { method: 'POST', body: {} }),
        this.api(`/grav-jarvis/providers/${id}/models`),
      ]);
      this.state.validation = validation;
      this.state.models = (catalog.models || []).filter(model => model.available !== false);
      this.state.modelMessage = catalog.message || '';
      if (!this.state.models.some(model => model.id === this.state.model)) this.state.model = '';
    } catch (error) {
      this.state.validation = null; this.state.models = []; this.state.modelMessage = '';
      this.state.error = error?.message || 'Provider status could not be loaded.';
    } finally { this.state.checking = false; this.render(); }
  }

  async generate() {
    if (this.state.busy || !this.state.provider) return;
    if (this.state.action === 'custom' && !this.state.custom.trim()) {
      this.state.error = 'Enter an instruction for Custom Prompt.'; this.render(); return;
    }
    this.state.busy = true; this.state.error = ''; this.state.message = ''; this.state.proposal = null; this.render();
    try {
      const snapshot = await this.editorSnapshot();
      const proposal = await this.api('/grav-jarvis/proposals', {
        method: 'POST',
        body: {
          provider_id: this.state.provider, model: this.state.model || null,
          action: this.state.action,
          custom_instruction: this.state.action === 'custom' ? this.state.custom : null,
          route: snapshot.route || this.route, content: snapshot.content, title: snapshot.title,
          template: snapshot.template, language: snapshot.language,
        },
      });
      this.state.original = snapshot.content; this.state.proposal = proposal;
    } catch (error) {
      this.state.error = error?.message || 'Jarvis could not create a proposal.';
    } finally { this.state.busy = false; this.render(); }
  }

  reject() {
    this.state.proposal = null; this.state.message = 'Proposal rejected. The editor buffer was not changed.'; this.render();
  }

  async accept() {
    const proposal = this.state.proposal;
    if (!proposal?.proposal_id || !proposal.context?.accept_allowed || !this.state.bootstrap?.can_approve) return;
    this.state.busy = true; this.state.error = ''; this.state.message = ''; this.render();
    try {
      const snapshot = await this.editorSnapshot();
      const result = await this.api(`/grav-jarvis/proposals/${encodeURIComponent(proposal.proposal_id)}/accept`, {
        method: 'POST',
        body: { route: snapshot.route || this.route, current_content: snapshot.content, proposed_content: proposal.proposed_content },
      });
      window.dispatchEvent(new CustomEvent('grav:editor:insert-content', { detail: { content: result.content, mode: 'replace' } }));
      this.state.original = result.content; this.state.proposal = null;
      this.state.message = 'Accepted into the unsaved editor buffer. Review the page and save only when ready.';
    } catch (error) {
      this.state.error = error?.message || 'The proposal could not be accepted.';
    } finally { this.state.busy = false; this.render(); }
  }

  status() {
    if (this.state.checking) return ['Checking…', 'checking'];
    if (this.state.validation?.usable) return ['Ready', 'ready'];
    if (this.state.validation?.state === 'misconfigured') return ['Needs configuration', 'warn'];
    if (this.state.validation?.state === 'retryable') return ['Try again', 'warn'];
    return ['Unavailable', 'off'];
  }

  render() {
    const providers = this.state.bootstrap?.providers || [];
    const actions = this.state.bootstrap?.actions || [];
    const proposal = this.state.proposal;
    const [status, statusClass] = this.status();
    const acceptAllowed = Boolean(proposal?.context?.accept_allowed && this.state.bootstrap?.can_approve);
    const truncated = proposal?.context?.truncated || {};
    const providerNote = this.state.validation?.issues?.[0]?.message || this.state.modelMessage || '';
    const cannotAccept = proposal && !proposal.context?.accept_allowed
      ? (truncated.content ? 'This page exceeded the bounded content window. The proposal is preview-only.' : 'This proposal exceeded the reviewable output limit. It is preview-only.')
      : (proposal && !this.state.bootstrap?.can_approve ? 'You can review this proposal but do not have permission to accept it.' : '');
    this.shadowRoot.innerHTML = `
      <style>${this.styles()}</style><main>
        <section class="intro"><div><span class="eyebrow">CURRENT PAGE</span><h2>Jarvis</h2><p>${this.escape(this.state.page?.title || this.route || 'Page editor')}</p></div><span class="status ${statusClass}">${this.escape(status)}</span></section>
        ${this.state.error ? `<div class="notice error" role="alert">${this.escape(this.state.error)} <button id="retry">Retry</button></div>` : ''}
        ${this.state.message ? `<div class="notice success" role="status">${this.escape(this.state.message)}</div>` : ''}
        <section class="setup">
          <label>Provider<select id="provider" ${this.state.busy ? 'disabled' : ''}>${providers.map(item => `<option value="${this.escape(item.id)}" ${item.id === this.state.provider ? 'selected' : ''}>${this.escape(this.label(item.id))}</option>`).join('')}</select></label>
          <label>Model<select id="model" ${this.state.busy ? 'disabled' : ''}><option value="">Configured default</option>${this.state.models.map(model => `<option value="${this.escape(model.id)}" ${model.id === this.state.model ? 'selected' : ''}>${this.escape(model.label || model.id)}</option>`).join('')}</select></label>
          <button id="check" class="secondary" ${this.state.busy || !this.state.provider ? 'disabled' : ''}>${this.state.checking ? 'Checking…' : 'Check'}</button>
        </section>
        ${providerNote ? `<p class="provider-note">${this.escape(providerNote)}</p>` : ''}
        <section class="actions"><span class="section-label">Choose an action</span><div class="action-grid">${actions.map(item => `<button data-action="${this.escape(item.id)}" class="action ${item.id === this.state.action ? 'active' : ''}" ${this.state.busy ? 'disabled' : ''}>${this.escape(item.label)}</button>`).join('')}</div>
          ${this.state.action === 'custom' ? `<label class="custom">Instruction<textarea id="custom" maxlength="4000" placeholder="Describe the change you want…">${this.escape(this.state.custom)}</textarea></label>` : ''}
          <button id="generate" class="primary" ${this.state.busy || !this.state.provider ? 'disabled' : ''}>${this.state.busy ? 'Creating proposal…' : 'Create proposal'}</button>
        </section>
        ${proposal ? `<section class="proposal">
          <div class="proposal-head"><div><span class="eyebrow">REVIEW REQUIRED</span><h3>${this.escape(this.label(proposal.action))} proposal</h3></div><small>${this.escape(proposal.provider_id)} · ${this.escape(proposal.model)}</small></div>
          ${(truncated.any || cannotAccept) ? `<div class="notice warn">${this.escape(cannotAccept || 'Some page context was bounded. Review the proposal carefully.')}</div>` : ''}
          <div class="compare"><article><b>Before</b><pre>${this.escape(this.state.original)}</pre></article><article><b>Proposed</b><pre>${this.escape(proposal.proposed_content)}</pre></article></div>
          <div class="review-actions"><button id="reject" class="secondary" ${this.state.busy ? 'disabled' : ''}>Reject</button><button id="accept" class="primary" ${this.state.busy || !acceptAllowed ? 'disabled' : ''}>Accept into editor</button></div>
          <p class="unsaved">Accept replaces only the current unsaved editor buffer. It never saves or publishes the page.</p>
        </section>` : ''}
        <aside>Whole-buffer editing is used in 0.2.0. Selection-aware editing is deferred until Admin2 exposes a stable selection contract.</aside>
      </main>`;
    this.bind();
  }

  bind() {
    this.shadowRoot.getElementById('provider')?.addEventListener('change', event => { this.state.provider = event.target.value; this.state.model = ''; this.refreshProvider(); });
    this.shadowRoot.getElementById('model')?.addEventListener('change', event => { this.state.model = event.target.value; });
    this.shadowRoot.getElementById('check')?.addEventListener('click', () => this.refreshProvider());
    this.shadowRoot.getElementById('retry')?.addEventListener('click', () => this.load());
    this.shadowRoot.querySelectorAll('[data-action]').forEach(button => button.addEventListener('click', () => { this.state.action = button.dataset.action; this.state.error = ''; this.render(); }));
    this.shadowRoot.getElementById('custom')?.addEventListener('input', event => { this.state.custom = event.target.value; });
    this.shadowRoot.getElementById('generate')?.addEventListener('click', () => this.generate());
    this.shadowRoot.getElementById('reject')?.addEventListener('click', () => this.reject());
    this.shadowRoot.getElementById('accept')?.addEventListener('click', () => this.accept());
  }

  label(value) { return String(value ?? '').replaceAll('_', ' ').replaceAll('-', ' ').replace(/\b\w/g, letter => letter.toUpperCase()); }
  escape(value) { return String(value ?? '').replace(/[&<>'"]/g, char => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', "'":'&#39;', '"':'&quot;' }[char])); }
  styles() { return `
    .provider-note{margin:10px 16px 0;color:var(--muted);font-size:11px}
    :host{display:block;height:100%}*{box-sizing:border-box}main{--bg:#0c1420;--panel:#121d2b;--panel2:#192638;--line:#2a3a4e;--text:#edf4ff;--muted:#9cacbf;--accent:#a78bfa;--good:#34d399;--warn:#fbbf24;--danger:#fb7185;min-height:100%;padding-bottom:18px;background:var(--bg);color:var(--text);font:13px/1.45 Inter,system-ui,sans-serif}.intro{display:flex;justify-content:space-between;align-items:center;padding:18px 20px;background:var(--panel);border-bottom:1px solid var(--line)}h2{font-size:24px;margin:1px 0}.intro p{margin:0;color:var(--muted)}.eyebrow,.section-label{font-size:9px;font-weight:850;letter-spacing:.15em;color:var(--accent)}.status{padding:6px 9px;border-radius:999px;background:var(--panel2);font-size:10px;font-weight:850}.status.ready{color:var(--good)}.status.warn,.status.checking{color:var(--warn)}.status.off{color:var(--muted)}.setup{display:grid;grid-template-columns:1fr 1fr auto;gap:9px;padding:14px 16px;border-bottom:1px solid var(--line);background:var(--panel)}label{display:grid;gap:5px;font-size:10px;font-weight:800}select,textarea,button{border:1px solid var(--line);border-radius:8px;background:var(--panel2);color:var(--text);font:inherit}select,button{min-height:37px;padding:0 10px}button{cursor:pointer;font-weight:800}button:disabled{opacity:.42;cursor:not-allowed}.actions,.proposal{margin:14px 16px;padding:15px;border:1px solid var(--line);border-radius:11px;background:var(--panel)}.action-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:7px;margin:10px 0}.action{min-height:39px}.action.active{border-color:var(--accent);background:color-mix(in srgb,var(--accent) 20%,var(--panel2));color:var(--accent)}textarea{width:100%;min-height:82px;padding:10px;resize:vertical}.primary{border-color:transparent;background:var(--accent);color:#140d26}.actions>.primary{width:100%;margin-top:10px}.secondary{background:var(--panel2)}.notice{margin:12px 16px 0;padding:10px 11px;border-radius:8px}.notice.error{border:1px solid color-mix(in srgb,var(--danger) 50%,var(--line));color:var(--danger)}.notice.success{border:1px solid color-mix(in srgb,var(--good) 45%,var(--line));color:var(--good)}.notice.warn{margin:12px 0;border:1px solid color-mix(in srgb,var(--warn) 45%,var(--line));color:var(--warn)}.notice button{float:right;min-height:26px}.proposal-head{display:flex;justify-content:space-between;align-items:end;gap:10px}.proposal h3{margin:2px 0;font-size:17px}.proposal small{color:var(--muted)}.compare{display:grid;grid-template-columns:1fr 1fr;gap:9px;margin-top:12px}.compare article{min-width:0;border:1px solid var(--line);border-radius:8px;overflow:hidden}.compare b{display:block;padding:8px 10px;background:var(--panel2);font-size:10px}.compare pre{max-height:330px;margin:0;padding:10px;overflow:auto;white-space:pre-wrap;overflow-wrap:anywhere;font:11px/1.45 ui-monospace,SFMono-Regular,Menlo,monospace}.review-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:12px}.unsaved,aside{color:var(--muted);font-size:11px}.unsaved{text-align:right;margin:8px 0 0}aside{margin:14px 16px;padding:11px 13px;border:1px dashed var(--line);border-radius:8px}@media(prefers-color-scheme:light){main{--bg:#f5f7fb;--panel:#fff;--panel2:#f0f3f8;--line:#dbe1e9;--text:#182131;--muted:#66758a;--accent:#7c3aed;--good:#047857;--warn:#a16207;--danger:#be123c}}@media(max-width:520px){.setup{grid-template-columns:1fr}.action-grid,.compare{grid-template-columns:1fr}}
  `; }
}

if (!customElements.get(TAG)) customElements.define(TAG, GravJarvisPanel);
