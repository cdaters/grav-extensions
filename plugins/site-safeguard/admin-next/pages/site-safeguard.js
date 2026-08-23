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
      restoreOperation: '',
      armedAction: '',
      restorePhrase: '',
      disclosures: this.loadDisclosurePreferences(),
    };
    this.themeObserver = null;
    this.themeMedia = null;
    this.themeListener = null;
    this.restorePollTimer = null;
  }

  connectedCallback() {
    this.syncTheme();
    this.render();
    this.load();
  }

  disconnectedCallback() {
    this.themeObserver?.disconnect();
    this.themeMedia?.removeEventListener?.('change', this.themeListener);
    if (this.restorePollTimer) clearTimeout(this.restorePollTimer);
  }

  syncTheme() {
    const update = () => {
      const theme = this.detectTheme();
      if (theme !== this.state.theme) {
        this.state.theme = theme;
        this.render();
      }
    };
    this.themeMedia = window.matchMedia?.('(prefers-color-scheme: dark)') || null;
    this.themeListener = update;
    this.themeMedia?.addEventListener?.('change', update);
    this.themeObserver = new MutationObserver(update);
    this.themeObserver.observe(document.documentElement, { attributes: true, attributeFilter: ['class', 'data-theme', 'data-mode', 'style'] });
    if (document.body) this.themeObserver.observe(document.body, { attributes: true, attributeFilter: ['class', 'data-theme', 'data-mode', 'style'] });
    update();
  }

  detectTheme() {
    const explicit = [document.documentElement?.dataset?.theme, document.documentElement?.dataset?.mode, document.body?.dataset?.theme, document.body?.dataset?.mode].join(' ').toLowerCase();
    if (/\bdark\b/.test(explicit)) return 'dark';
    if (/\blight\b/.test(explicit)) return 'light';
    const classes = [...(document.documentElement?.classList || []), ...(document.body?.classList || [])].map(value => String(value).toLowerCase());
    if (classes.includes('dark')) return 'dark';
    if (classes.includes('light')) return 'light';
    for (const node of [document.body, document.documentElement, this.parentElement].filter(Boolean)) {
      const style = window.getComputedStyle(node);
      const parsed = this.parseRgb(style.backgroundColor || style.getPropertyValue('--admin-bg') || style.getPropertyValue('--background'));
      if (!parsed) continue;
      const [red, green, blue] = parsed;
      return ((red * 299 + green * 587 + blue * 114) / 1000) < 150 ? 'dark' : 'light';
    }
    return this.themeMedia?.matches ? 'dark' : 'light';
  }

  parseRgb(value) {
    if (!value || value === 'transparent') return null;
    const match = String(value).match(/rgba?\((\d+),\s*(\d+),\s*(\d+)(?:,\s*([\d.]+))?/i);
    if (!match || (match[4] !== undefined && Number(match[4]) === 0)) return null;
    return [Number(match[1]), Number(match[2]), Number(match[3])];
  }

  adminBasePath() {
    const path = window.location.pathname || '/admin';
    for (const marker of ['/plugin/site-safeguard', '/plugins/site-safeguard']) {
      const index = path.indexOf(marker);
      if (index >= 0) return path.slice(0, index) || '/admin';
    }
    const index = path.indexOf('/admin');
    return index >= 0 ? path.slice(0, index + '/admin'.length) : '/admin';
  }

  openPluginSettings() { window.location.href = `${this.adminBasePath()}/plugins/site-safeguard`; }

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
    const method = String(options.method || 'GET').toUpperCase();
    const url = this.apiUrl(path);
    const request = {
      ...options,
      headers: { ...this.getAuthHeaders(!isForm), ...(options.headers || {}) },
    };
    let response = await fetch(url, request);
    if ([403, 405, 501].includes(response.status) && ['DELETE', 'PATCH', 'PUT'].includes(method)) {
      response = await fetch(url, {
        ...request,
        method: 'POST',
        headers: { ...request.headers, 'X-HTTP-Method-Override': method },
      });
    }
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
      if (this.state.error) {
        requestAnimationFrame(() => {
          const notice = this.shadowRoot.querySelector('.notice.error');
          notice?.focus({ preventScroll: true });
          notice?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        });
      }
    }
  }

  async load() {
    await this.run(async () => {
      this.state.status = await this.api('/site-safeguard/status');
      const active = (this.state.status?.restore_history || []).find(operation => this.restoreStateIsActive(operation.state));
      if (active) {
        this.state.restoreOperation = active.id;
        this.scheduleRestorePoll();
      }
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
    const key = `create-stage:${name}`;
    if (!this.armAction(key, `Click Confirm stage to validate ${name} again and extract it outside the running site. The live site will not be changed.`)) return;
    this.state.armedAction = '';
    await this.run(async () => {
      await this.api(`/site-safeguard/packages/${encodeURIComponent(name)}/stage`, { method: 'POST', body: '{}' });
      this.state.status = await this.api('/site-safeguard/status');
      this.state.message = 'Verified stage created. The running site was not modified.';
    }, 'Validating, extracting, and re-verifying stage…');
  }

  async downloadPackage(name) {
    await this.run(async () => {
      const result = await this.api(`/site-safeguard/packages/${encodeURIComponent(name)}/download-token`, { method: 'POST', body: '{}' });
      const downloadUrl = new URL(result.path || result.url, window.location.origin);
      downloadUrl.protocol = window.location.protocol;
      downloadUrl.host = window.location.host;
      const link = document.createElement('a');
      link.href = downloadUrl.toString();
      link.download = name;
      link.rel = 'noreferrer';
      link.style.display = 'none';
      document.body.append(link);
      link.click();
      link.remove();
      this.state.message = 'The protected package download is starting. You can retry it briefly if the browser or connection interrupts the transfer.';
    }, 'Preparing protected download…');
  }

  async deletePackage(name) {
    const key = `package:${name}`;
    if (!this.armAction(key, `Click Confirm delete to permanently remove ${name}. Existing stages and live-site files will not be changed.`)) return;
    this.state.armedAction = '';
    await this.run(async () => {
      const encodedName = encodeURIComponent(name);
      await this.deleteResource(
        `/site-safeguard/packages/${encodedName}/delete`,
        `/site-safeguard/packages/${encodedName}`
      );
      delete this.state.inspections[name];
      this.state.status = await this.api('/site-safeguard/status');
      this.state.message = 'Package deleted.';
    }, 'Deleting package…');
  }

  async deleteStage(id, recognized = true) {
    const key = `stage:${id}`;
    const prompt = recognized
      ? `Click Confirm delete to remove isolated stage ${id}. The running site will not be changed.`
      : `Click Confirm removal to remove ${id} from Site Safeguard's isolated staging area. The running site and external plugin data will not be changed.`;
    if (!this.armAction(key, prompt)) return;
    this.state.armedAction = '';
    await this.run(async () => {
      const encodedId = encodeURIComponent(id);
      await this.deleteResource(
        `/site-safeguard/stages/${encodedId}/delete`,
        `/site-safeguard/stages/${encodedId}`
      );
      this.state.status = await this.api('/site-safeguard/status');
      this.state.message = recognized ? 'Stage deleted.' : 'Unrecognized staging directory removed.';
    }, recognized ? 'Deleting stage…' : 'Removing unrecognized staging directory…');
  }

  async deleteResource(actionPath, compatiblePath) {
    try {
      return await this.api(actionPath, { method: 'POST', body: '{}' });
    } catch (error) {
      if (!/no route matches|route[^.]*not found/i.test(error?.message || '')) throw error;
      return this.api(compatiblePath, {
        method: 'POST',
        headers: { 'X-HTTP-Method-Override': 'DELETE' },
        body: '{}',
      });
    }
  }

  armAction(key, message) {
    if (this.state.armedAction === key) return true;
    this.state.armedAction = key;
    this.state.error = '';
    this.state.message = message;
    this.render();
    return false;
  }

  cancelArmedAction() {
    const action = this.state.armedAction;
    this.state.armedAction = '';
    this.state.restorePhrase = '';
    this.state.message = action.startsWith('create-stage:')
      ? 'Stage creation cancelled. The package and running site were unchanged.'
      : (action.startsWith('restore:')
        ? 'Restore cancelled. The running site was unchanged.'
        : 'Deletion cancelled. Nothing was removed.');
    this.state.error = '';
    this.render();
  }

  loadDisclosurePreferences() {
    try {
      const preferences = JSON.parse(localStorage.getItem('site_safeguard_disclosures') || '{}');
      return preferences && typeof preferences === 'object' ? preferences : {};
    } catch (_) {
      return {};
    }
  }

  disclosureOpen(section, defaultOpen) {
    const preference = this.state.disclosures?.[section];
    return typeof preference === 'boolean' ? preference : Boolean(defaultOpen);
  }

  toggleDisclosure(section, defaultOpen) {
    const disclosures = {
      ...(this.state.disclosures || {}),
      [section]: !this.disclosureOpen(section, defaultOpen),
    };
    this.state.disclosures = disclosures;
    try {
      localStorage.setItem('site_safeguard_disclosures', JSON.stringify(disclosures));
    } catch (_) {}
    this.render();
  }

  async restoreStage(id) {
    const phrase = this.state.status?.restore_confirmation || 'RESTORE THIS SITE';
    const key = `restore:${id}`;
    if (this.state.armedAction !== key) {
      this.state.armedAction = key;
      this.state.restorePhrase = '';
      this.state.error = '';
      this.state.message = `Restore is not running. Type ${phrase} in the stage row, then select Confirm restore.`;
      this.render();
      requestAnimationFrame(() => this.shadowRoot.querySelector('.restore-phrase')?.focus());
      return;
    }
    const entered = this.state.restorePhrase.trim();
    if (entered !== phrase) {
      this.state.error = `Restore not launched: type ${phrase} exactly.`;
      this.state.message = '';
      this.render();
      return;
    }

    this.state.armedAction = '';
    this.state.restorePhrase = '';
    await this.run(async () => {
      const result = await this.api(`/site-safeguard/stages/${encodeURIComponent(id)}/restore`, {
        method: 'POST',
        body: JSON.stringify({ confirmation: entered.trim() }),
      });
      this.state.restoreOperation = result.operation?.id || '';
      this.state.status = await this.api('/site-safeguard/status');
      this.state.message = 'Restore launched. This page will follow the protected recovery journal.';
      this.scheduleRestorePoll();
    }, 'Launching detached restore worker…');
  }

  scheduleRestorePoll(delay = 1800) {
    if (this.restorePollTimer) clearTimeout(this.restorePollTimer);
    if (!this.state.restoreOperation) return;
    this.restorePollTimer = setTimeout(() => this.pollRestore(), delay);
  }

  async pollRestore() {
    if (!this.state.restoreOperation) return;
    try {
      this.state.status = await this.api('/site-safeguard/status');
      const operation = (this.state.status?.restore_history || []).find(item => item.id === this.state.restoreOperation);
      if (operation && !this.restoreStateIsActive(operation.state)) {
        this.state.message = operation.state === 'completed'
          ? 'Restore completed and the restored site passed verification.'
          : `Restore finished with state: ${this.restoreStateLabel(operation.state)}.`;
        this.state.restoreOperation = '';
      }
      this.state.error = '';
      this.render();
    } catch (_) {
      // Maintenance mode or a cache rebuild can briefly interrupt the API while
      // the detached worker replaces files. Keep following the same operation.
    }
    if (this.state.restoreOperation) this.scheduleRestorePoll(2200);
  }

  restoreStateIsActive(state) {
    return ['queued', 'preparing', 'rollback-verified', 'restoring', 'restore-failed'].includes(String(state || ''));
  }

  restoreStateLabel(state) {
    return ({
      queued: 'Queued',
      preparing: 'Preparing source',
      'rollback-verified': 'Rollback verified',
      restoring: 'Restoring files',
      completed: 'Completed',
      'restore-failed': 'Restore failed; rolling back',
      'rolled-back': 'Automatically rolled back',
      'rollback-failed': 'Rollback failed',
      'launch-failed': 'Launch failed',
      'worker-failed': 'Worker failed',
    })[state] || String(state || 'Unknown');
  }

  render() {
    const status = this.state.status || {};
    const profiles = status.profiles || [];
    const packages = status.packages || [];
    const stages = status.stages || [];
    const history = status.restore_history || [];
    const requirements = status.environment_requirements || [];
    const disabled = (this.state.busy || this.state.restoreOperation) ? 'disabled' : '';
    const missingRequired = requirements.filter(item => item.group === 'required' && !item.available);
    const missingAdvisory = requirements.filter(item => ['recommended', 'restore'].includes(item.group) && !item.available);
    const readinessTone = missingRequired.length ? 'error' : (missingAdvisory.length ? 'warning' : 'ready');
    const readinessLabel = missingRequired.length
      ? `${missingRequired.length} required ${missingRequired.length === 1 ? 'check needs' : 'checks need'} attention`
      : (missingAdvisory.length
        ? `${missingAdvisory.length} ${missingAdvisory.length === 1 ? 'capability is' : 'capabilities are'} unavailable`
        : 'All checks passed');
    const readinessOpen = this.disclosureOpen('readiness', readinessTone !== 'ready');
    const packagesOpen = this.disclosureOpen('packages', true);
    const stagesNeedAttention = stages.some(stage => !stage.verified || !stage.recognized);
    const stagesOpen = this.disclosureOpen('stages', stages.length > 0 || stagesNeedAttention);
    const historyNeedsAttention = history.some(operation => operation.state !== 'completed');
    const historyOpen = this.disclosureOpen('history', Boolean(this.state.restoreOperation) || historyNeedsAttention);
    const verifiedStageCount = stages.filter(stage => stage.verified).length;
    const unrecognizedStageCount = stages.filter(stage => !stage.recognized).length;

    this.shadowRoot.innerHTML = `
      <style>${this.styles()}</style>
      <main class="shell ${this.state.theme}">
        <section class="hero">
          <div>
            <span class="eyebrow">VERIFIED SITE RECOVERY</span>
            <h1>Site Safeguard</h1>
            <p>Build portable Grav packages, verify every file, and recover through an automatically verified rollback.</p>
          </div>
          <div class="hero-actions"><button class="quiet" id="settings">Plugin settings</button><div class="hero-state"><span>v${this.escape(status.version || '0.3.10')}</span><strong>${status.restore_enabled ? (status.admin_restore_enabled && status.restore_launcher_available ? 'Restore ready' : 'CLI restore only') : 'Restore disabled'}</strong></div></div>
        </section>

        <section class="metrics">
          <div><span>Packages</span><strong>${packages.length}</strong></div>
          <div><span>Protected storage</span><strong>${this.formatBytes(status.stored_bytes || 0)}</strong></div>
          <div><span>Verified stages</span><strong>${stages.filter(stage => stage.verified).length}</strong></div>
          <div class="path"><span>Package directory</span><code>${this.escape(status.package_path || '—')}</code></div>
        </section>

        ${this.state.error ? `<div class="notice error" role="alert" tabindex="-1">${this.escape(this.state.error)}</div>` : ''}
        ${this.state.message ? `<div class="notice">${this.escape(this.state.message)}</div>` : ''}

        <section class="safety">
          <div class="shield">✓</div>
          <div><strong>The initiating browser request never performs the replacement.</strong><p>${this.escape(status.safety_message || 'Restore runs in a detached, rollback-first PHP CLI worker.')}</p></div>
        </section>

        ${requirements.length ? `
        <section class="panel readiness readiness-${readinessTone}">
          ${this.disclosureHeader('readiness', 'ENVIRONMENT READINESS', 'Host capabilities', readinessLabel, readinessOpen, readinessTone)}
          <div class="section-body" id="readiness-panel" ${readinessOpen ? '' : 'hidden'}>
            <div class="requirement-grid">
              ${requirements.map(item => this.requirementCard(item)).join('')}
            </div>
            <p class="requirement-note"><strong>Required</strong> capabilities power the current ZIP workflow. <strong>Recommended</strong> capabilities prepare this host for SSA/SSS. Missing optional capabilities never cause a silent cryptographic downgrade.</p>
          </div>
        </section>` : ''}

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
          ${this.disclosureHeader('packages', 'PACKAGE LIBRARY', 'Recovery packages', `${packages.length} ${packages.length === 1 ? 'package' : 'packages'}`, packagesOpen, '', `<button class="quiet" id="refresh" ${disabled}>Refresh</button>`)}
          <div class="section-body" id="packages-panel" ${packagesOpen ? '' : 'hidden'}>
            ${packages.length ? packages.map(item => this.packageCard(item, disabled)).join('') : '<div class="empty">No packages yet. Create a portable package or import one for validation.</div>'}
          </div>
        </section>

        <section class="panel stages">
          ${this.disclosureHeader('stages', 'ISOLATED STAGING', 'Verified stages', `${verifiedStageCount} verified${unrecognizedStageCount ? ` · ${unrecognizedStageCount} unrecognized` : ''}`, stagesOpen, stagesNeedAttention ? 'warning' : '', `<code>${this.escape(status.stage_path || '')}</code>`)}
          <div class="section-body" id="stages-panel" ${stagesOpen ? '' : 'hidden'}>
            ${stages.length ? stages.map(stage => this.stageRow(stage, status, disabled)).join('') : '<div class="empty compact">No isolated stages.</div>'}
          </div>
        </section>

        <section class="panel history">
          ${this.disclosureHeader('history', 'RECOVERY JOURNAL', 'Restore operations', `${history.length} ${history.length === 1 ? 'operation' : 'operations'}`, historyOpen, historyNeedsAttention ? 'warning' : '', this.state.restoreOperation ? '<span class="working-dot">Following active restore…</span>' : '')}
          <div class="section-body" id="history-panel" ${historyOpen ? '' : 'hidden'}>
            ${history.length ? history.map(operation => this.restoreCard(operation)).join('') : '<div class="empty compact">No restore operations recorded.</div>'}
          </div>
        </section>
      </main>
    `;

    this.bind();
  }

  disclosureHeader(section, eyebrow, title, summary, open, tone = '', extra = '') {
    const panelId = `${section}-panel`;
    const stateLabel = open ? 'Collapse' : 'Expand';
    const statusIcon = ({ ready: '✓', warning: '!', error: '×' })[tone] || '';
    return `
      <header class="section-head ${tone ? `status-${tone}` : ''}">
        <div><span class="eyebrow">${this.escape(eyebrow)}</span><h2>${this.escape(title)}</h2></div>
        <div class="section-head-actions">
          <span class="section-summary">${statusIcon ? `<span class="status-icon" aria-hidden="true">${statusIcon}</span>` : ''}${this.escape(summary)}</span>
          ${extra}
          <button type="button" class="disclosure-toggle" data-section="${this.escape(section)}" data-default-open="${open ? 'true' : 'false'}" aria-expanded="${open ? 'true' : 'false'}" aria-controls="${panelId}" aria-label="${stateLabel} ${this.escape(title)}" title="${stateLabel} ${this.escape(title)}">
            <span aria-hidden="true">${open ? '⌃' : '⌄'}</span>
          </button>
        </div>
      </header>`;
  }

  stageRow(stage, status, disabled) {
    const deleteArmed = this.state.armedAction === `stage:${stage.id}`;
    const restoreArmed = this.state.armedAction === `restore:${stage.id}`;
    const canRestore = stage.verified && status.restore_enabled && status.admin_restore_enabled && status.restore_launcher_available;
    const phrase = status.restore_confirmation || 'RESTORE THIS SITE';
    const restoreReady = this.state.restorePhrase.trim() === phrase;
    const restoreState = stage.recognized
      ? (status.restore_enabled
        ? (status.admin_restore_enabled ? this.escape(status.restore_launcher_message || 'CLI restore ready') : 'Admin Restore disabled')
        : 'Restore disabled')
      : 'Not restorable';
    return `
      <article class="stage-row ${stage.recognized ? '' : 'unrecognized'}">
        <div><strong>${this.escape(stage.id)}</strong><small>${this.escape(stage.recognized ? (stage.record?.package || 'Recognized stage') : 'Unrecognized directory — not a recovery stage')} · ${this.formatDate(stage.modified * 1000)}</small>${stage.verified && status.restore_enabled ? `<code class="restore-command">CLI fallback: bin/plugin site-safeguard restore ${this.escape(stage.id)} --confirm="RESTORE THIS SITE"</code>` : ''}</div>
        <span class="badge ${stage.verified ? 'good' : 'warn'}">${stage.verified ? 'Verified' : (stage.recognized ? 'Unverified' : 'Unrecognized')}</span>
        <div class="stage-actions">
          ${canRestore && !restoreArmed ? `<button class="primary restore-stage" data-id="${this.escape(stage.id)}" ${disabled}>Restore</button>` : (!restoreArmed ? `<span class="cli-ready">${restoreState}</span>` : '<span class="cli-ready">Confirmation required below</span>')}
          ${deleteArmed ? `<button class="quiet cancel-action" ${disabled}>Cancel</button>` : ''}
          <button class="danger delete-stage ${deleteArmed ? 'armed' : ''}" data-id="${this.escape(stage.id)}" data-recognized="${stage.recognized ? 'true' : 'false'}" ${(disabled || restoreArmed) ? 'disabled' : ''}>${deleteArmed ? (stage.recognized ? 'Confirm delete' : 'Confirm removal') : (stage.recognized ? 'Delete stage' : 'Remove directory')}</button>
        </div>
        ${restoreArmed ? `
          <div class="restore-confirmation" role="group" aria-label="Confirm full-site restore">
            <div><strong>Confirm full-site restore</strong><p>Site Safeguard will verify the stage, create and verify a rollback package, then launch the detached restore worker. The running site is not changed until that protected preparation succeeds.</p></div>
            <label>Type <code>${this.escape(phrase)}</code> exactly
              <input class="restore-phrase" data-id="${this.escape(stage.id)}" type="text" autocomplete="off" spellcheck="false" value="${this.escape(this.state.restorePhrase)}">
            </label>
            <div class="restore-confirm-actions">
              <button class="quiet cancel-action">Cancel</button>
              <button class="primary confirm-restore" data-id="${this.escape(stage.id)}" ${restoreReady ? '' : 'disabled'}>Confirm restore</button>
            </div>
          </div>` : ''}
      </article>`;
  }

  restoreCard(operation) {
    const state = String(operation.state || 'unknown');
    const active = this.restoreStateIsActive(state);
    const good = state === 'completed';
    const warning = state === 'rolled-back';
    const when = operation.completed_at || operation.updated_at || operation.started_at || operation.requested_at;
    const log = String(operation.worker_log_tail || '').trim();
    return `
      <article class="restore-card ${active ? 'active' : ''}">
        <div class="restore-summary">
          <div>
            <strong>${this.escape(operation.id || 'Unknown operation')}</strong>
            <small>${this.escape(operation.source_stage || 'Unknown stage')} · ${this.formatDate(when)}</small>
          </div>
          <span class="badge ${good ? 'good' : ((!active && !warning) ? 'bad' : '')}">${this.escape(this.restoreStateLabel(state))}</span>
        </div>
        <div class="restore-progress" aria-label="Restore progress"><span style="width:${this.restoreProgress(state)}%"></span></div>
        <div class="restore-facts">
          <span>Verified files <strong>${operation.verified_files ?? '—'}</strong></span>
          <span>Rollback package <strong>${this.escape(operation.rollback_package || 'Pending')}</strong></span>
          ${operation.error ? `<span class="restore-error">${this.escape(operation.error)}</span>` : ''}
        </div>
        ${log ? `<details><summary>Worker output</summary><pre>${this.escape(log)}</pre></details>` : ''}
      </article>`;
  }

  restoreProgress(state) {
    return ({ queued: 8, preparing: 22, 'rollback-verified': 45, restoring: 72, 'restore-failed': 82, completed: 100, 'rolled-back': 100, 'rollback-failed': 100, 'launch-failed': 100, 'worker-failed': 100 })[state] || 0;
  }

  requirementCard(item) {
    const group = ({ required: 'Required', recommended: 'Recommended', optional: 'Optional', restore: 'Restore only' })[item.group] || 'Capability';
    return `
      <article class="requirement-card ${item.available ? 'available' : 'missing'}">
        <div><strong>${this.escape(item.label || item.key)}</strong><span>${this.escape(group)}</span></div>
        <span class="capability-state">${item.available ? 'Available' : 'Unavailable'}</span>
        <p>${this.escape(item.detail || '')}</p>
      </article>`;
  }

  packageCard(item, disabled) {
    const manifest = item.manifest || {};
    const profile = manifest.profile || {};
    const note = String(manifest.note || '').trim();
    const inspection = this.state.inspections[item.name];
    const canStage = inspection?.valid && profile.deployable;
    const stageArmed = this.state.armedAction === `create-stage:${item.name}`;
    const deleteArmed = this.state.armedAction === `package:${item.name}`;
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
        <div class="package-note ${note ? '' : 'empty-note'}">
          <span>Operator note</span>
          <p>${note ? this.escape(note) : 'No operator note was attached to this package.'}</p>
        </div>
        <div class="actions">
          <button class="inspect" data-name="${this.escape(item.name)}" ${disabled}>Inspect</button>
          ${stageArmed ? `<button class="quiet cancel-action" ${disabled}>Cancel</button>` : ''}
          <button class="stage ${stageArmed ? 'armed-safe' : ''}" data-name="${this.escape(item.name)}" ${disabled || !canStage ? 'disabled' : ''}>${stageArmed ? 'Confirm stage' : 'Create stage'}</button>
          <button class="download" data-name="${this.escape(item.name)}" ${disabled}>Download</button>
          ${deleteArmed ? `<button class="quiet cancel-action" ${disabled}>Cancel</button>` : ''}
          <button class="danger delete-package ${deleteArmed ? 'armed' : ''}" data-name="${this.escape(item.name)}" ${(disabled || stageArmed) ? 'disabled' : ''}>${deleteArmed ? 'Confirm delete' : 'Delete'}</button>
        </div>
        ${inspection ? this.inspectionPanel(inspection) : ''}
      </article>`;
  }

  inspectionPanel(result) {
    const manifest = result.manifest || {};
    const stats = manifest.stats || {};
    const note = String(manifest.note || '').trim();
    return `
      <div class="inspection ${result.valid ? 'valid' : 'invalid'}">
        <div class="inspection-grid">
          <div><span>Result</span><strong>${result.valid ? 'All checks passed' : 'Validation failed'}</strong></div>
          <div><span>Files checked</span><strong>${result.archive_files ?? 0}</strong></div>
          <div><span>Expanded size</span><strong>${this.formatBytes(result.uncompressed_bytes || 0)}</strong></div>
          <div><span>Source warnings</span><strong>${(result.warnings || []).length}</strong></div>
        </div>
        <div class="inspection-note"><span>Operator note</span><p>${note ? this.escape(note) : 'No operator note was attached to this package.'}</p></div>
        ${(result.errors || []).length ? `<ul class="issues errors">${result.errors.map(error => `<li>${this.escape(error)}</li>`).join('')}</ul>` : ''}
        ${(result.warnings || []).length ? `<ul class="issues warnings">${result.warnings.map(warning => `<li>${this.escape(warning)}</li>`).join('')}</ul>` : ''}
        ${stats.symlinks_skipped ? `<p class="fine">${stats.symlinks_skipped} symbolic link(s) were deliberately omitted when this package was created.</p>` : ''}
      </div>`;
  }

  bind() {
    this.shadowRoot.querySelector('#settings')?.addEventListener('click', () => this.openPluginSettings());
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
    this.shadowRoot.querySelectorAll('.restore-stage').forEach(button => button.addEventListener('click', () => this.restoreStage(button.dataset.id)));
    this.shadowRoot.querySelectorAll('.confirm-restore').forEach(button => button.addEventListener('click', () => this.restoreStage(button.dataset.id)));
    this.shadowRoot.querySelectorAll('.delete-stage').forEach(button => button.addEventListener('click', () => this.deleteStage(button.dataset.id, button.dataset.recognized === 'true')));
    this.shadowRoot.querySelectorAll('.cancel-action').forEach(button => button.addEventListener('click', () => this.cancelArmedAction()));
    this.shadowRoot.querySelectorAll('.restore-phrase').forEach(input => input.addEventListener('input', event => {
      this.state.restorePhrase = event.target.value;
      const button = event.target.closest('.restore-confirmation')?.querySelector('.confirm-restore');
      if (button) button.disabled = event.target.value.trim() !== (this.state.status?.restore_confirmation || 'RESTORE THIS SITE');
    }));
    this.shadowRoot.querySelectorAll('.disclosure-toggle').forEach(button => button.addEventListener('click', () => this.toggleDisclosure(button.dataset.section, button.dataset.defaultOpen === 'true')));
  }

  styles() {
    return `
      :host { display:block; }
      * { box-sizing:border-box; }
      button,input,select,textarea { font:inherit; }
      .shell { --bg:#10151d; --panel:#151c26; --panel-2:#1b2430; --text:#edf3fa; --muted:#93a2b5; --line:#2a3544; --accent:#9a4cff; --accent-2:#bd85ff; --good:#38ca8b; --warn:#e7aa46; --bad:#ff6262; min-height:calc(100vh - 120px); padding:24px; border-radius:12px; background:var(--bg); color:var(--text); font:14px/1.5 system-ui,-apple-system,sans-serif; }
      .shell.light { --bg:#f6f7fa; --panel:#fff; --panel-2:#f0f2f6; --text:#20252d; --muted:#687383; --line:#dce0e7; --accent:#7428d8; --accent-2:#7428d8; --good:#087d52; --bad:#b62d2d; }
      .hero { display:flex; align-items:center; justify-content:space-between; gap:20px; padding:28px; border:1px solid var(--line); border-radius:12px 12px 0 0; background:linear-gradient(120deg,var(--panel),color-mix(in srgb,var(--accent) 12%,var(--panel))); }
      h1,h2,p { margin:0; } h1 { margin:.2rem 0 .35rem; font-size:30px; } h2 { margin:.15rem 0 0; font-size:19px; }
      .hero p,.profile-help,.fine { color:var(--muted); }
      .eyebrow { color:var(--accent-2); font-size:10px; font-weight:850; letter-spacing:.14em; }
      .hero-actions { display:flex; align-items:center; gap:10px; }.hero-state { display:grid; gap:3px; min-width:160px; padding:14px 16px; border:1px solid var(--line); border-radius:9px; background:var(--panel); }
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
      .readiness { margin-bottom:16px; }.readiness-summary { color:var(--muted); font-size:11px; font-weight:800; }.requirement-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); }.requirement-card { display:grid; grid-template-columns:1fr auto; gap:5px 14px; padding:14px 18px; border-right:1px solid var(--line); border-bottom:1px solid var(--line); }.requirement-card:nth-child(2n) { border-right:0; }.requirement-card > div { display:grid; }.requirement-card > div span { color:var(--muted); font-size:9px; font-weight:800; letter-spacing:.08em; text-transform:uppercase; }.requirement-card p { grid-column:1/-1; color:var(--muted); font-size:11px; }.capability-state { align-self:start; padding:3px 7px; border-radius:999px; color:var(--good); background:color-mix(in srgb,var(--good) 12%,transparent); font-size:9px; font-weight:850; text-transform:uppercase; }.requirement-card.missing .capability-state { color:var(--bad); background:color-mix(in srgb,var(--bad) 10%,transparent); }.requirement-note { padding:12px 18px; color:var(--muted); font-size:11px; }.requirement-note strong { color:var(--text); }
      .workspace { display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:16px; }
      .panel { border:1px solid var(--line); border-radius:10px; background:var(--panel); }
      .create,.import { display:grid; align-content:start; gap:13px; padding:20px; }
      label { display:grid; gap:6px; color:var(--muted); font-size:11px; font-weight:750; letter-spacing:.05em; text-transform:uppercase; }
      select,textarea { width:100%; min-height:42px; padding:9px 11px; border:1px solid var(--line); border-radius:7px; outline:none; background:var(--panel-2); color:var(--text); text-transform:none; }
      textarea { resize:vertical; }.profile-help { min-height:42px; font-size:12px; }
      button { min-height:38px; padding:8px 12px; border:1px solid var(--line); border-radius:7px; background:var(--panel-2); color:var(--text); cursor:pointer; font-weight:750; }
      button:hover:not(:disabled) { border-color:var(--accent); } button:disabled { cursor:not-allowed; opacity:.45; }
      .primary { border-color:var(--accent); background:var(--accent); color:#fff; }.armed-safe { border-color:var(--good); background:color-mix(in srgb,var(--good) 15%,var(--panel-2)); color:var(--good); }.danger { color:var(--bad); }.danger.armed { border-color:var(--bad); background:var(--bad); color:#fff; }.quiet { background:transparent; }
      .drop { min-height:147px; place-content:center; place-items:center; gap:4px; padding:20px; border:1px dashed var(--accent); border-radius:10px; background:color-mix(in srgb,var(--accent) 7%,var(--panel-2)); cursor:pointer; text-align:center; text-transform:none; }
      .drop.over { background:color-mix(in srgb,var(--accent) 16%,var(--panel-2)); }.drop input { max-width:230px; margin-top:9px; color:var(--muted); }.drop small { color:var(--muted); font-weight:500; }.upload-icon { display:grid; width:34px; height:34px; place-items:center; border-radius:9px; background:var(--accent); color:#fff; font-size:21px; }
      .section-head { display:flex; align-items:center; justify-content:space-between; gap:16px; padding:16px 18px; border-bottom:1px solid var(--line); transition:background-color .2s ease,border-color .2s ease; }.section-head code { max-width:420px; overflow:hidden; color:var(--muted); font-size:11px; text-overflow:ellipsis; white-space:nowrap; }.section-head.status-ready { border-bottom-color:color-mix(in srgb,var(--good) 38%,var(--line)); background:color-mix(in srgb,var(--good) 7%,var(--panel)); }.section-head.status-warning { border-bottom-color:color-mix(in srgb,var(--warn) 42%,var(--line)); background:color-mix(in srgb,var(--warn) 7%,var(--panel)); }.section-head.status-error { border-bottom-color:color-mix(in srgb,var(--bad) 42%,var(--line)); background:color-mix(in srgb,var(--bad) 7%,var(--panel)); }.section-head-actions { display:flex; align-items:center; justify-content:flex-end; gap:10px; min-width:0; }.section-summary { display:inline-flex; align-items:center; gap:6px; color:var(--muted); font-size:11px; font-weight:800; }.status-icon { display:inline-grid; width:18px; height:18px; place-items:center; border:1px solid currentColor; border-radius:999px; font-size:11px; line-height:1; }.status-ready .section-summary { color:var(--good); }.status-warning .section-summary { color:var(--warn); }.status-error .section-summary { color:var(--bad); }.disclosure-toggle { display:grid; width:38px; min-width:38px; padding:0; place-items:center; border-radius:999px; background:transparent; font-size:18px; line-height:1; }.disclosure-toggle:focus-visible { outline:2px solid var(--accent); outline-offset:2px; }.section-body[hidden] { display:none!important; }
      .package-card { border-bottom:1px solid var(--line); }.package-card:last-child { border-bottom:0; }.package-main { display:flex; align-items:center; gap:13px; padding:14px 18px 8px; }.file-icon { display:grid; flex:0 0 48px; height:48px; place-items:center; border:1px solid var(--line); border-radius:9px; color:var(--accent-2); font:800 11px ui-monospace,monospace; background:var(--panel-2); }
      .package-copy { display:grid; flex:1; min-width:0; gap:2px; }.package-copy strong { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }.package-copy small { color:var(--muted); }.package-copy code { overflow:hidden; color:var(--muted); font-size:10px; text-overflow:ellipsis; white-space:nowrap; }
      .badge { padding:4px 8px; border:1px solid var(--line); border-radius:999px; color:var(--muted); font-size:10px; font-weight:800; }.badge.good { border-color:color-mix(in srgb,var(--good) 40%,var(--line)); color:var(--good); background:color-mix(in srgb,var(--good) 10%,transparent); }.badge.warn { border-color:color-mix(in srgb,var(--warn) 45%,var(--line)); color:var(--warn); background:color-mix(in srgb,var(--warn) 9%,transparent); }.badge.bad { color:var(--bad); }
      .package-note { margin:0 18px 13px 83px; padding:10px 12px; border-left:2px solid var(--accent); background:var(--panel-2); }.package-note span,.inspection-note span { color:var(--accent-2); font-size:9px; font-weight:800; letter-spacing:.1em; text-transform:uppercase; }.package-note p,.inspection-note p { margin:3px 0 0; color:var(--text); font-size:12px; line-height:1.5; white-space:pre-wrap; }.package-note.empty-note p { color:var(--muted); font-style:italic; }
      .actions { display:flex; justify-content:flex-end; gap:7px; padding:0 18px 14px; }
      .inspection { margin:0 18px 16px; padding:13px; border:1px solid var(--line); border-radius:8px; background:var(--panel-2); }.inspection.valid { border-color:color-mix(in srgb,var(--good) 45%,var(--line)); }.inspection.invalid { border-color:color-mix(in srgb,var(--bad) 45%,var(--line)); }.inspection-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:10px; }.inspection-grid > div { display:grid; gap:4px; }.inspection-note { margin-top:12px; padding-top:10px; border-top:1px solid var(--line); }.issues { margin:10px 0 0; padding-left:20px; }.issues.errors { color:var(--bad); }.issues.warnings { color:#d89a2b; }
      .stages { margin-top:16px; }.stage-row { display:grid; grid-template-columns:1fr auto auto; align-items:center; gap:12px; padding:13px 18px; border-bottom:1px solid var(--line); }.stage-row.unrecognized { background:color-mix(in srgb,var(--warn) 5%,transparent); }.stage-row:last-child { border-bottom:0; }.stage-row > div { display:grid; }.stage-row small { color:var(--muted); }.restore-command { margin-top:5px; overflow:auto; color:var(--accent-2); font-size:10px; white-space:nowrap; }.stage-actions { display:flex!important; align-items:center; gap:10px; }.cli-ready { color:var(--muted); font-size:11px; font-weight:750; }.restore-confirmation { grid-column:1/-1; display:grid!important; grid-template-columns:minmax(240px,1.5fr) minmax(260px,1fr) auto; align-items:end; gap:14px; margin-top:3px; padding:14px; border:1px solid color-mix(in srgb,var(--bad) 50%,var(--line)); border-radius:9px; background:color-mix(in srgb,var(--bad) 6%,var(--panel-2)); }.restore-confirmation p { margin-top:4px; color:var(--muted); font-size:11px; }.restore-confirmation label { color:var(--text); text-transform:none; }.restore-confirmation label code { color:var(--bad); font-size:11px; }.restore-phrase { width:100%; min-height:40px; padding:8px 10px; border:1px solid var(--line); border-radius:7px; outline:none; background:var(--panel); color:var(--text); font:12px ui-monospace,monospace; }.restore-phrase:focus { border-color:var(--bad); box-shadow:0 0 0 2px color-mix(in srgb,var(--bad) 18%,transparent); }.restore-confirm-actions { display:flex!important; gap:8px; }.empty { padding:38px 20px; color:var(--muted); text-align:center; }.empty.compact { padding:22px; }
      .history { margin-top:16px; }.working-dot { color:var(--accent-2); font-size:11px; font-weight:800; }.restore-card { padding:15px 18px; border-bottom:1px solid var(--line); }.restore-card:last-child { border-bottom:0; }.restore-card.active { background:color-mix(in srgb,var(--accent) 6%,var(--panel)); }.restore-summary { display:flex; align-items:center; justify-content:space-between; gap:15px; }.restore-summary > div { display:grid; }.restore-summary small { color:var(--muted); }.restore-progress { height:5px; margin:11px 0; overflow:hidden; border-radius:999px; background:var(--panel-2); }.restore-progress span { display:block; height:100%; border-radius:inherit; background:linear-gradient(90deg,var(--accent),var(--good)); transition:width .35s ease; }.restore-facts { display:flex; flex-wrap:wrap; gap:9px 20px; color:var(--muted); font-size:11px; }.restore-facts strong { color:var(--text); }.restore-error { flex-basis:100%; color:var(--bad); }.restore-card details { margin-top:10px; color:var(--muted); }.restore-card summary { cursor:pointer; font-size:11px; font-weight:750; }.restore-card pre { max-height:180px; overflow:auto; padding:10px; border:1px solid var(--line); border-radius:7px; background:var(--panel-2); color:var(--text); font:10px/1.5 ui-monospace,monospace; white-space:pre-wrap; }
      @media (max-width:950px) { .metrics { grid-template-columns:1fr 1fr; }.metrics > div:nth-child(2) { border-right:0; }.metrics .path { grid-column:1/-1; border-top:1px solid var(--line); }.workspace { grid-template-columns:1fr; }.inspection-grid { grid-template-columns:1fr 1fr; }.restore-confirmation { grid-template-columns:1fr; align-items:stretch; }.restore-confirm-actions { justify-content:flex-end; } }
      @media (max-width:620px) { .shell { padding:12px; }.hero { align-items:flex-start; flex-direction:column; }.hero-state { width:100%; }.metrics { grid-template-columns:1fr; }.metrics > div { border-right:0; border-bottom:1px solid var(--line); }.metrics > div:last-child { border-bottom:0; }.metrics .path { grid-column:auto; }.section-head { align-items:flex-start; flex-wrap:wrap; }.section-head-actions { width:100%; justify-content:space-between; }.section-head code { max-width:55vw; }.requirement-grid { grid-template-columns:1fr; }.requirement-card { border-right:0; }.package-main { align-items:flex-start; flex-wrap:wrap; }.package-copy { flex-basis:calc(100% - 65px); }.package-note { margin-left:18px; }.actions { justify-content:stretch; flex-wrap:wrap; }.actions button { flex:1; }.inspection-grid { grid-template-columns:1fr; }.stage-row { grid-template-columns:1fr auto; }.stage-actions { grid-column:1/-1; flex-wrap:wrap; }.stage-actions button { flex:1; } }
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
