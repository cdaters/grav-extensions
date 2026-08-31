const TAG = window.__GRAV_PANEL_TAG || 'grav-jarvis-panel';

class JarvisRequestError extends Error {
  constructor(message, code = 'jarvis_request_failed', status = 0) {
    super(message);
    this.code = code;
    this.status = status;
  }

  get retryable() {
    return new Set([
      'jarvis_rate_limited', 'jarvis_timeout', 'jarvis_provider_unavailable',
      'jarvis_request_failed', 'jarvis_network_error', 'jarvis_proposal_conflict',
    ]).has(this.code);
  }
}

class GravJarvisPanel extends HTMLElement {
  constructor() {
    super();
    this.attachShadow({ mode: 'open' });
    this.state = {
      bootstrap: null, provider: '', model: '', models: [], modelMessage: '', validation: null,
      page: null, action: 'rewrite', custom: '', original: '', proposal: null,
      busy: false, checking: false, error: null, message: '', retryAction: null,
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
    let response;
    try {
      response = await fetch(this.apiUrl(path), {
        method: options.method || 'GET',
        headers: this.authHeaders(options.body !== undefined),
        body: options.body === undefined ? undefined : JSON.stringify(options.body),
        credentials: 'omit', cache: 'no-store',
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

  async editorSnapshot() {
    return new Promise((resolve, reject) => {
      const timeout = setTimeout(() => {
        window.removeEventListener('grav:editor:content-response', receive);
        reject(new JarvisRequestError('The current editor buffer is unavailable. Reopen Jarvis from a page editor.', 'jarvis_editor_unavailable'));
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
    this.state.busy = true; this.clearError(); this.render();
    try {
      if (!this.route) throw new JarvisRequestError('Jarvis needs an open page editor.', 'jarvis_editor_unavailable');
      const [bootstrap, page, snapshot] = await Promise.all([
        this.api('/grav-jarvis/bootstrap'),
        this.api(`/grav-jarvis/page-context?route=${encodeURIComponent(this.route)}`),
        this.editorSnapshot(),
      ]);
      this.state.bootstrap = bootstrap; this.state.page = page; this.state.original = snapshot.content;
      const providers = bootstrap.providers || [];
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
      this.setError(error, 'provider');
    } finally { this.state.checking = false; this.render(); }
  }

  async generate() {
    if (this.state.busy || !this.canGenerate()) return;
    if (this.state.action === 'custom' && !this.state.custom.trim()) {
      this.setError(new JarvisRequestError('Enter an instruction for Custom Prompt.', 'jarvis_invalid_request')); this.render(); return;
    }
    const replacedProposal = this.state.proposal;
    this.state.busy = true; this.clearError(); this.state.message = ''; this.render();
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
          replaces_proposal_id: replacedProposal?.proposal_id || null,
        },
      });
      this.state.original = snapshot.content; this.state.proposal = proposal;
    } catch (error) {
      this.setError(error, error?.retryable ? 'generate' : null);
    } finally { this.state.busy = false; this.render(); }
  }

  async reject() {
    const proposal = this.state.proposal;
    if (!proposal || this.state.busy) return;
    if (!proposal.proposal_id) {
      this.state.proposal = null;
      this.state.message = 'Proposal rejected. The editor buffer was not changed.';
      this.clearError(); this.render(); return;
    }
    this.state.busy = true; this.clearError(); this.render();
    try {
      await this.api(`/grav-jarvis/proposals/${encodeURIComponent(proposal.proposal_id)}/discard`, {
        method: 'POST', body: { route: this.route },
      });
      this.state.proposal = null;
      this.state.message = 'Proposal rejected and closed. The editor buffer was not changed.';
    } catch (error) {
      if (error?.code === 'jarvis_proposal_conflict') {
        this.state.proposal = null;
        this.state.message = 'The proposal was already closed. The editor buffer was not changed.';
      } else {
        this.setError(error, error?.retryable ? 'reject' : null);
      }
    } finally { this.state.busy = false; this.render(); }
  }

  async accept() {
    const proposal = this.state.proposal;
    if (!proposal?.proposal_id || !proposal.context?.accept_allowed || !this.state.bootstrap?.can_approve || this.state.busy) return;
    this.state.busy = true; this.clearError(); this.state.message = ''; this.render();
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
      this.setError(error, error?.code === 'jarvis_proposal_conflict' ? 'generate' : null);
    } finally { this.state.busy = false; this.render(); }
  }

  retry() {
    if (this.state.retryAction === 'generate') return this.generate();
    if (this.state.retryAction === 'provider') return this.refreshProvider();
    if (this.state.retryAction === 'reject') return this.reject();
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
    const retry = this.state.error && this.state.retryAction
      ? `<button id="retry" class="secondary">${this.state.retryAction === 'generate' ? 'Generate new proposal' : 'Retry'}</button>` : '';
    const context = proposal?.context;
    this.shadowRoot.innerHTML = `
      <style>${this.styles()}</style><main aria-busy="${this.state.busy || this.state.checking}">
        <section class="intro"><div><span class="eyebrow">CURRENT PAGE</span><h2>Jarvis</h2><p>${this.escape(this.state.page?.title || this.route || 'Page editor')}</p></div><span class="status ${statusClass}" role="status" aria-live="polite">${this.escape(status)}</span></section>
        ${this.state.error ? `<div class="notice error" role="alert"><span>${this.escape(this.state.error.message)}</span>${retry}</div>` : ''}
        ${this.state.message ? `<div class="notice success" role="status" aria-live="polite">${this.escape(this.state.message)}</div>` : ''}
        <section class="setup" aria-label="Provider and model">
          <label for="provider">Provider</label><select id="provider" ${this.state.busy ? 'disabled' : ''} aria-describedby="provider-note">${providers.map(item => `<option value="${this.escape(item.id)}" ${item.id === this.state.provider ? 'selected' : ''}>${this.escape(this.label(item.id))}</option>`).join('')}</select>
          <label for="model">Model</label><select id="model" ${this.state.busy ? 'disabled' : ''} aria-describedby="provider-note"><option value="">Configured default</option>${this.state.models.map(model => `<option value="${this.escape(model.id)}" ${model.id === this.state.model ? 'selected' : ''}>${this.escape(model.label || model.id)}</option>`).join('')}</select>
          <button id="check" class="secondary" ${this.state.busy || !this.state.provider ? 'disabled' : ''} aria-label="Check selected provider">${this.state.checking ? 'Checking…' : 'Check'}</button>
        </section>
        <p id="provider-note" class="provider-note" role="status" aria-live="polite">${this.escape(providerNote)}</p>
        <section class="actions" aria-labelledby="actions-label"><span id="actions-label" class="section-label">Choose an action</span><div class="action-grid">${actions.map(item => `<button data-action="${this.escape(item.id)}" class="action ${item.id === this.state.action ? 'active' : ''}" aria-pressed="${item.id === this.state.action}" ${this.state.busy ? 'disabled' : ''}>${this.escape(item.label)}</button>`).join('')}</div>
          ${this.state.action === 'custom' ? `<label class="custom" for="custom">Instruction</label><textarea id="custom" maxlength="4000" placeholder="Describe the change you want…" aria-describedby="custom-help">${this.escape(this.state.custom)}</textarea><small id="custom-help">Press Control/Command + Enter to create the proposal.</small>` : ''}
          <button id="generate" class="primary" ${this.state.busy || !this.canGenerate() ? 'disabled' : ''}>${this.state.busy ? 'Working…' : 'Create proposal'}</button>
        </section>
        ${proposal ? `<section class="proposal" aria-labelledby="proposal-heading">
          <div class="proposal-head"><div><span class="eyebrow">REVIEW REQUIRED</span><h3 id="proposal-heading">${this.escape(this.actionLabel(proposal.action))} proposal</h3></div><small>${this.escape(proposal.provider_id)} · ${this.escape(proposal.model)}</small></div>
          ${(truncated.any || cannotAccept) ? `<div class="notice warn" role="status">${this.escape(cannotAccept || 'Some page context was bounded. Review the proposal carefully.')}</div>` : ''}
          ${context ? `<dl class="context-summary"><div><dt>Whole buffer</dt><dd>${Number(context.included_content_bytes || 0).toLocaleString()} of ${Number(context.content_bytes || 0).toLocaleString()} bytes</dd></div><div><dt>Media metadata</dt><dd>${Number(context.media_items || 0).toLocaleString()} items</dd></div><div><dt>Usage and cost</dt><dd>${this.escape(this.usage(proposal.usage, proposal.cost, proposal.reliability))}</dd></div></dl>` : ''}
          <div class="compare"><article aria-labelledby="before-label"><b id="before-label">Before — current unsaved buffer</b><pre tabindex="0">${this.escape(this.state.original)}</pre></article><article aria-labelledby="proposed-label"><b id="proposed-label">Proposed — not yet applied</b><pre tabindex="0">${this.escape(proposal.proposed_content)}</pre></article></div>
          <div class="review-actions"><button id="reject" class="secondary" ${this.state.busy ? 'disabled' : ''}>Reject and close</button><button id="accept" class="primary" ${this.state.busy || !acceptAllowed ? 'disabled' : ''}>Accept into editor</button></div>
          <p class="unsaved">Accept replaces only the current unsaved editor buffer. It never saves or publishes the page.</p>
        </section>` : ''}
        <aside>Whole-buffer editing remains review-first. Large-context execution is limited to safe, bounded summarization. Selection-aware editing remains deferred until Admin2 exposes a stable selection contract.</aside>
      </main>`;
    this.bind();
  }

  usage(usage, cost = null, reliability = null) {
    if (!usage) return 'Unavailable';
    const total = usage.total ?? null;
    const count = total === null ? 'usage unknown' : `${Number(total).toLocaleString()} ${usage.unit || 'units'}`;
    const amount = cost?.estimated_amount;
    const estimate = amount == null ? 'cost unknown' : `est. ${cost.currency || 'USD'} ${amount}`;
    const attempts = Number(usage.request_count ?? reliability?.attempts ?? 1);
    const retries = Number(usage.retry_count ?? reliability?.retry_count ?? 0);
    const request = (usage.cache_hit ?? reliability?.cache_hit)
      ? 'cache hit'
      : `${attempts} request${attempts === 1 ? '' : 's'}${retries ? `, ${retries} retr${retries === 1 ? 'y' : 'ies'}` : ''}`;
    return `${count} · ${estimate} · ${request}`;
  }

  actionLabel(action) {
    return this.state.bootstrap?.actions?.find(item => item.id === action)?.label || this.label(action);
  }

  bind() {
    this.shadowRoot.getElementById('provider')?.addEventListener('change', event => { this.state.provider = event.target.value; this.state.model = ''; this.refreshProvider(); });
    this.shadowRoot.getElementById('model')?.addEventListener('change', event => { this.state.model = event.target.value; });
    this.shadowRoot.getElementById('check')?.addEventListener('click', () => this.refreshProvider());
    this.shadowRoot.getElementById('retry')?.addEventListener('click', () => this.retry());
    this.shadowRoot.querySelectorAll('[data-action]').forEach(button => button.addEventListener('click', () => {
      this.state.action = button.dataset.action; this.clearError(); this.render();
      [...this.shadowRoot.querySelectorAll('[data-action]')].find(item => item.dataset.action === this.state.action)?.focus();
    }));
    this.shadowRoot.getElementById('custom')?.addEventListener('input', event => { this.state.custom = event.target.value; });
    this.shadowRoot.getElementById('custom')?.addEventListener('keydown', event => {
      if ((event.metaKey || event.ctrlKey) && event.key === 'Enter') { event.preventDefault(); this.generate(); }
    });
    this.shadowRoot.getElementById('generate')?.addEventListener('click', () => this.generate());
    this.shadowRoot.getElementById('reject')?.addEventListener('click', () => this.reject());
    this.shadowRoot.getElementById('accept')?.addEventListener('click', () => this.accept());
  }

  label(value) { return String(value ?? '').replaceAll('_', ' ').replaceAll('-', ' ').replace(/\b\w/g, letter => letter.toUpperCase()); }
  escape(value) { return String(value ?? '').replace(/[&<>'"]/g, char => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', "'":'&#39;', '"':'&quot;' }[char])); }
  styles() { return `
    :host{display:block;height:100%;color-scheme:light dark}*{box-sizing:border-box}main{--bg:var(--background,#fff);--panel:var(--card,#fff);--panel2:var(--secondary,#f4f4f5);--line:var(--border,#e4e4e7);--text:var(--foreground,#09090b);--muted:var(--muted-foreground,#71717a);--accent:var(--primary,#2463eb);--accentText:var(--primary-foreground,#fff);--good:var(--success,#1eae53);--warn:var(--warning,#eb980a);--danger:var(--destructive,#ef4444);min-height:100%;padding-bottom:18px;background:var(--bg);color:var(--text);font:13px/1.45 var(--font-sans,Inter,system-ui,sans-serif)}.intro{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:18px 20px;background:var(--panel);border-bottom:1px solid var(--line)}h2{font-size:24px;margin:1px 0}.intro p{margin:0;color:var(--muted);overflow-wrap:anywhere}.eyebrow,.section-label{font-size:9px;font-weight:800;letter-spacing:.14em;color:var(--accent)}.status{flex:none;padding:6px 9px;border:1px solid var(--line);border-radius:999px;background:var(--panel2);font-size:10px;font-weight:800}.status.ready{color:var(--good)}.status.warn,.status.checking{color:var(--warn)}.status.off{color:var(--muted)}.setup{display:grid;grid-template-columns:1fr 1fr auto;grid-template-areas:"pl ml ." "ps ms check";gap:5px 9px;padding:14px 16px;border-bottom:1px solid var(--line);background:var(--panel)}.setup label[for=provider]{grid-area:pl}.setup label[for=model]{grid-area:ml}#provider{grid-area:ps}#model{grid-area:ms}#check{grid-area:check}label{font-size:10px;font-weight:750}select,textarea,button{border:1px solid var(--line);border-radius:8px;background:var(--panel2);color:var(--text);font:inherit}select,button{min-height:38px;padding:0 10px}button{cursor:pointer;font-weight:750}button:disabled{opacity:.46;cursor:not-allowed}button:focus-visible,select:focus-visible,textarea:focus-visible,pre:focus-visible{outline:3px solid color-mix(in srgb,var(--accent) 60%,transparent);outline-offset:2px}.actions,.proposal{margin:14px 16px;padding:15px;border:1px solid var(--line);border-radius:10px;background:var(--panel)}.action-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:7px;margin:10px 0}.action{min-height:40px;overflow-wrap:anywhere}.action.active{border-color:var(--accent);background:color-mix(in srgb,var(--accent) 16%,var(--panel2));color:var(--accent)}textarea{width:100%;min-height:82px;margin-top:5px;padding:10px;resize:vertical}.custom+textarea+small{display:block;margin-top:4px}.primary{border-color:transparent;background:var(--accent);color:var(--accentText)}.actions>.primary{width:100%;margin-top:10px}.secondary{background:var(--panel2)}.notice{margin:12px 16px 0;padding:10px 11px;border-radius:8px;overflow-wrap:anywhere}.notice.error{border:1px solid color-mix(in srgb,var(--danger) 50%,var(--line));color:var(--danger);display:flex;justify-content:space-between;align-items:center;gap:8px}.notice.success{border:1px solid color-mix(in srgb,var(--good) 45%,var(--line));color:var(--good)}.notice.warn{margin:12px 0;border:1px solid color-mix(in srgb,var(--warn) 45%,var(--line));color:var(--warn)}.notice button{flex:none}.provider-note{min-height:1.2em;margin:10px 16px 0;color:var(--muted);font-size:11px}.provider-note:empty{display:none}.proposal-head{display:flex;justify-content:space-between;align-items:end;gap:10px}.proposal h3{margin:2px 0;font-size:17px}.proposal small{color:var(--muted)}.context-summary{display:grid;grid-template-columns:repeat(3,1fr);gap:7px;margin:12px 0}.context-summary div{min-width:0;padding:8px;border:1px solid var(--line);border-radius:7px}.context-summary dt{color:var(--muted);font-size:9px;text-transform:uppercase;letter-spacing:.06em}.context-summary dd{margin:3px 0 0;overflow-wrap:anywhere}.compare{display:grid;grid-template-columns:1fr 1fr;gap:9px;margin-top:12px}.compare article{min-width:0;border:1px solid var(--line);border-radius:8px;overflow:hidden}.compare b{display:block;padding:8px 10px;background:var(--panel2);font-size:10px}.compare pre{max-width:100%;max-height:330px;margin:0;padding:10px;overflow:auto;white-space:pre-wrap;overflow-wrap:anywhere;word-break:break-word;font:11px/1.45 var(--font-mono,ui-monospace,SFMono-Regular,Menlo,monospace)}.review-actions{display:flex;justify-content:flex-end;flex-wrap:wrap;gap:8px;margin-top:12px}.unsaved,aside,small{color:var(--muted);font-size:11px}.unsaved{text-align:end;margin:8px 0 0}aside{margin:14px 16px;padding:11px 13px;border:1px dashed var(--line);border-radius:8px}@media(max-width:520px){.intro{align-items:flex-start}.setup{grid-template-columns:1fr;grid-template-areas:"pl" "ps" "ml" "ms" "check"}.action-grid,.compare,.context-summary{grid-template-columns:1fr}.proposal-head{align-items:flex-start;flex-direction:column}.review-actions{display:grid;grid-template-columns:1fr}.review-actions button{width:100%}.unsaved{text-align:start}.notice.error{align-items:flex-start;flex-direction:column}}@media(prefers-reduced-motion:reduce){*{scroll-behavior:auto!important;transition:none!important}}
  `; }
}

if (!customElements.get(TAG)) customElements.define(TAG, GravJarvisPanel);
