const TAG = window.__GRAV_PAGE_TAG || 'file-vault-page';

class FileVaultPage extends HTMLElement {
  constructor() {
    super();
    this.attachShadow({ mode: 'open' });
    this.state = {
      status: null,
      selectedId: null,
      query: '',
      busy: false,
      message: '',
      error: '',
      theme: 'dark',
      selectedCategory: 'all',
      categoryModal: null,
      publicModal: false,
      vaultModal: false,
      urlModal: false,
      activityModal: false,
      activityData: null,
      uploadQueue: [],
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
      if (!theme) {
        for (const element of [document.body, document.documentElement]) {
          if (!element) continue;
          const color = getComputedStyle(element).backgroundColor.match(/[\d.]+/g)?.slice(0, 3).map(Number);
          if (color?.length === 3 && color.some(value => value > 0)) {
            theme = ((color[0] * 299 + color[1] * 587 + color[2] * 114) / 1000) > 145 ? 'light' : 'dark';
            break;
          }
        }
      }
      theme ||= matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
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
      token = token || auth.accessToken || '';
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
    this.state.busy = true;
    this.state.error = '';
    this.state.message = label;
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

  async load(preferredId = this.state.selectedId) {
    await this.run(async () => {
      this.state.status = await this.api('/file-vault/status');
      const categories = this.state.status?.categories || [];
      if (!['all', 'unlisted'].includes(this.state.selectedCategory) && !categories.some(category => category.id === this.state.selectedCategory)) {
        this.state.selectedCategory = 'all';
      }
      const items = this.filteredItems();
      this.state.selectedId = items.some(item => item.id === preferredId) ? preferredId : (items[0]?.id || null);
      this.state.message = '';
    }, 'Loading vault…');
  }

  selected() {
    return (this.state.status?.items || []).find(item => item.id === this.state.selectedId) || null;
  }

  filteredItems() {
    const query = this.state.query.trim().toLowerCase();
    const category = (this.state.status?.categories || []).find(entry => entry.id === this.state.selectedCategory);
    return (this.state.status?.items || []).filter(item => {
      if (this.state.selectedCategory === 'unlisted' && item.listed !== false) return false;
      if (category && item.category !== category.name) return false;
      return !query || [item.display_name, item.filename, item.description, item.category, ...(item.tags || [])].join(' ').toLowerCase().includes(query);
    });
  }

  async uploadFiles(fileList) {
    const files = Array.from(fileList || []);
    if (!files.length || this.state.busy) return;
    const listed = this.state.selectedCategory !== 'unlisted';
    const category = listed ? ((this.state.status?.categories || []).find(entry => entry.id === this.state.selectedCategory)?.name || '') : '';
    this.state.uploadQueue = files.map((file, index) => ({ id: `${Date.now()}-${index}`, file, name: file.name, status: 'Queued', error: '' }));
    this.state.busy = true;
    this.state.error = '';
    this.state.message = `Uploading ${files.length} ${files.length === 1 ? 'file' : 'files'} to ${listed ? (category || 'Uncategorized') : 'Unlisted assets'}…`;
    this.render();

    let lastId = null;
    let completed = 0;
    for (const entry of this.state.uploadQueue) {
      entry.status = 'Uploading';
      this.render();
      try {
        const form = new FormData();
        form.append('file', entry.file);
        const query = new URLSearchParams({ listed: String(listed) });
        if (category) query.set('category', category);
        const result = await this.api(`/file-vault/upload?${query.toString()}`, { method: 'POST', body: form });
        entry.status = 'Added';
        lastId = result.item?.id || lastId;
        completed += 1;
      } catch (error) {
        entry.status = 'Failed';
        entry.error = error?.message || String(error);
      }
      entry.file = null;
      this.render();
    }

    this.state.busy = false;
    await this.load(lastId);
    const failed = files.length - completed;
    this.state.message = `${completed} ${completed === 1 ? 'file' : 'files'} added${failed ? `; ${failed} failed` : ''}.`;
    if (failed) this.state.error = 'One or more files could not be uploaded. Check the allowlist and file-size limit.';
    this.render();
  }

  formData() {
    const root = this.shadowRoot;
    return {
      display_name: root.querySelector('#fv-name')?.value.trim() || '',
      download_name: root.querySelector('#fv-download-name')?.value.trim() || '',
      version: root.querySelector('#fv-version')?.value.trim() || '',
      description: root.querySelector('#fv-description')?.value.trim() || '',
      category: root.querySelector('#fv-category')?.value.trim() || '',
      tags: (root.querySelector('#fv-tags')?.value || '').split(',').map(value => value.trim()).filter(Boolean),
      published_at: root.querySelector('#fv-published')?.value || '',
      access: root.querySelector('#fv-access')?.value.trim() || '',
      external_url: root.querySelector('#fv-external-url')?.value.trim() || '',
      download_password: root.querySelector('#fv-password')?.value || '',
      clear_password: Boolean(root.querySelector('#fv-clear-password')?.checked),
      download_limit: Math.max(0, Number(root.querySelector('#fv-download-limit')?.value || 0)),
      sort_order: Number(root.querySelector('#fv-order')?.value || 0),
      featured: Boolean(root.querySelector('#fv-featured')?.checked),
      enabled: Boolean(root.querySelector('#fv-enabled')?.checked),
      listed: Boolean(root.querySelector('#fv-listed')?.checked),
    };
  }

  async save() {
    const item = this.selected();
    if (!item) return;
    const body = this.formData();
    await this.run(async () => {
      const result = await this.api(`/file-vault/items/${encodeURIComponent(item.id)}`, {
        method: 'PATCH',
        body: JSON.stringify(body),
      });
      await this.load(item.id);
      this.state.message = result.message || 'Metadata saved.';
    }, 'Saving metadata…');
  }

  async copyShortcode() {
    const item = this.selected();
    if (!item) return;
    const shortcode = `[file-download id="${item.id}" /]`;
    try {
      await navigator.clipboard.writeText(shortcode);
      this.state.message = 'Inline download shortcode copied.';
      this.state.error = '';
    } catch (_) {
      this.state.error = 'The browser could not copy automatically. Select and copy the shortcode manually.';
    }
    this.render();
  }

  async remove(deleteFile) {
    const item = this.selected();
    if (!item) return;
    const warning = deleteFile
      ? `Permanently delete ${item.filename} and remove it from the catalog? This cannot be undone.`
      : (item.source_type === 'url' ? `Remove the secure URL entry ${item.display_name}?` : `Remove ${item.display_name} from the catalog? The stored file will be kept.`);
    if (!confirm(warning)) return;
    await this.run(async () => {
      const result = await this.api(`/file-vault/items/${encodeURIComponent(item.id)}?delete_file=${deleteFile ? 'true' : 'false'}`, { method: 'DELETE' });
      await this.load(null);
      this.state.message = result.message || 'Item removed.';
    }, deleteFile ? 'Deleting file…' : 'Removing item…');
  }

  async resetCount() {
    const item = this.selected();
    if (!item || !confirm(`Reset the download count for ${item.display_name}?`)) return;
    await this.run(async () => {
      const result = await this.api(`/file-vault/items/${encodeURIComponent(item.id)}/reset-count`, { method: 'POST' });
      await this.load(item.id);
      this.state.message = result.message || 'Count reset.';
    }, 'Resetting count…');
  }

  selectCategory(id) {
    this.state.selectedCategory = id;
    this.state.query = '';
    this.state.selectedId = this.filteredItems()[0]?.id || null;
    this.render();
  }

  openCategoryModal(category = null) {
    this.state.categoryModal = category
      ? { mode: 'edit', id: category.id, name: category.name, description: category.description || '', sort_order: category.sort_order || 0 }
      : { mode: 'create', id: '', name: '', description: '', sort_order: (this.state.status?.categories || []).length + 1 };
    this.render();
  }

  closeCategoryModal() {
    this.state.categoryModal = null;
    this.render();
  }

  async saveCategory() {
    const modal = this.state.categoryModal;
    if (!modal) return;
    const body = {
      name: this.shadowRoot.querySelector('#fv-category-name')?.value.trim() || '',
      description: this.shadowRoot.querySelector('#fv-category-description')?.value.trim() || '',
      sort_order: Number(this.shadowRoot.querySelector('#fv-category-order')?.value || 0),
    };
    if (!body.name) {
      this.state.error = 'Category name is required.';
      this.render();
      return;
    }
    await this.run(async () => {
      const result = await this.api(modal.mode === 'edit' ? `/file-vault/categories/${encodeURIComponent(modal.id)}` : '/file-vault/categories', {
        method: modal.mode === 'edit' ? 'PATCH' : 'POST',
        body: JSON.stringify(body),
      });
      this.state.categoryModal = null;
      this.state.selectedCategory = result.category?.id || modal.id || 'all';
      await this.load();
      this.state.message = result.message || 'Category saved.';
    }, modal.mode === 'edit' ? 'Saving category…' : 'Creating category…');
  }

  async deleteCategory() {
    const modal = this.state.categoryModal;
    if (!modal?.id) return;
    const category = (this.state.status?.categories || []).find(entry => entry.id === modal.id);
    if (!category || !confirm(`Delete the “${category.name}” category? Files will be moved, not deleted.`)) return;
    const moveTo = this.shadowRoot.querySelector('#fv-category-move')?.value || '';
    await this.run(async () => {
      const result = await this.api(`/file-vault/categories/${encodeURIComponent(modal.id)}?move_to=${encodeURIComponent(moveTo)}`, { method: 'DELETE' });
      this.state.categoryModal = null;
      this.state.selectedCategory = moveTo || 'all';
      await this.load(null);
      this.state.message = result.message || 'Category deleted.';
    }, 'Deleting category…');
  }

  openPublicSettings() {
    this.state.publicModal = true;
    this.render();
  }

  closePublicSettings() {
    this.state.publicModal = false;
    this.render();
  }

  async savePublicSettings() {
    const root = this.shadowRoot;
    const body = {
      hero_kicker: root.querySelector('#fv-public-kicker')?.value.trim() || '',
      hero_title: root.querySelector('#fv-public-title')?.value.trim() || '',
      hero_intro: root.querySelector('#fv-public-intro')?.value.trim() || '',
      show_intro: Boolean(root.querySelector('#fv-public-show-intro')?.checked),
      show_file_count: Boolean(root.querySelector('#fv-public-show-files')?.checked),
      show_download_count: Boolean(root.querySelector('#fv-public-show-downloads')?.checked),
      default_view: root.querySelector('#fv-public-view')?.value || 'list',
      default_category: root.querySelector('#fv-public-category')?.value || '',
      search_placeholder: root.querySelector('#fv-public-search')?.value.trim() || '',
    };
    await this.run(async () => {
      const result = await this.api('/file-vault/settings/public', { method: 'PATCH', body: JSON.stringify(body) });
      this.state.publicModal = false;
      await this.load();
      this.state.message = result.message || 'Public Downloads settings saved.';
    }, 'Saving public settings…');
  }

  openVaultSettings() {
    this.state.vaultModal = true;
    this.render();
  }

  closeVaultSettings() {
    this.state.vaultModal = false;
    this.render();
  }

  async saveVaultSettings() {
    const extensions = (this.shadowRoot.querySelector('#fv-allowed-extensions')?.value || '')
      .split(/[\s,]+/).map(value => value.trim().replace(/^\./, '').toLowerCase()).filter(Boolean);
    const activity = {
      enabled: Boolean(this.shadowRoot.querySelector('#fv-activity-enabled')?.checked),
      ip_mode: this.shadowRoot.querySelector('#fv-activity-ip-mode')?.value || 'hash',
      retention_days: Number(this.shadowRoot.querySelector('#fv-activity-retention')?.value || 30),
      store_user_agent: Boolean(this.shadowRoot.querySelector('#fv-activity-user-agent')?.checked),
      trust_proxy_headers: Boolean(this.shadowRoot.querySelector('#fv-activity-proxy')?.checked),
    };
    await this.run(async () => {
      const result = await this.api('/file-vault/settings/vault', { method: 'PATCH', body: JSON.stringify({ allowed_extensions: extensions, activity }) });
      this.state.vaultModal = false;
      await this.load();
      this.state.message = result.message || 'Vault settings saved.';
    }, 'Saving vault settings…');
  }

  async openActivity() {
    await this.run(async () => {
      this.state.activityData = await this.api('/file-vault/activity?limit=200');
      this.state.activityModal = true;
      this.state.message = '';
    }, 'Loading activity…');
  }

  closeActivity() {
    this.state.activityModal = false;
    this.render();
  }

  async purgeActivity() {
    if (!confirm('Clear the entire File Vault download activity log? This cannot be undone.')) return;
    await this.run(async () => {
      const result = await this.api('/file-vault/activity', { method: 'DELETE' });
      this.state.activityData = { ...(this.state.activityData || {}), events: [], count: 0 };
      this.state.message = result.message || 'Download activity log cleared.';
    }, 'Clearing activity…');
  }

  openUrlModal() {
    this.state.urlModal = true;
    this.render();
  }

  closeUrlModal() {
    this.state.urlModal = false;
    this.render();
  }

  async createUrlItem() {
    const root = this.shadowRoot;
    const body = {
      display_name: root.querySelector('#fv-url-name')?.value.trim() || '',
      external_url: root.querySelector('#fv-url-destination')?.value.trim() || '',
      download_name: root.querySelector('#fv-url-download-name')?.value.trim() || '',
      category: root.querySelector('#fv-url-category')?.value || '',
      description: root.querySelector('#fv-url-description')?.value.trim() || '',
      listed: Boolean(root.querySelector('#fv-url-listed')?.checked),
    };
    await this.run(async () => {
      const result = await this.api('/file-vault/items/url', { method: 'POST', body: JSON.stringify(body) });
      this.state.urlModal = false;
      await this.load(result.item?.id || null);
      this.state.message = result.message || 'Secure URL download added.';
    }, 'Adding secure URL…');
  }

  render() {
    const status = this.state.status;
    const item = this.selected();
    const totals = status?.totals || { files: 0, bytes: 0, downloads: 0 };
    const items = this.filteredItems();
    const categories = status?.categories || [];
    const activeCategory = categories.find(category => category.id === this.state.selectedCategory) || null;
    const activeLabel = this.state.selectedCategory === 'unlisted' ? 'Unlisted assets' : (activeCategory?.name || 'All files');
    const uploadLabel = this.state.selectedCategory === 'unlisted' ? 'Unlisted assets' : (activeCategory?.name || 'Uncategorized');
    const extensionText = (status?.allowed_extensions || []).map(value => `.${value}`).join(', ');
    this.setAttribute('data-theme', this.state.theme);
    this.shadowRoot.innerHTML = `
      <style>${this.styles()}</style>
      <div class="shell">
        <section class="hero">
          <div>
            <span class="eyebrow">FILE VAULT ADMIN</span>
            <h2>File Vault</h2>
            <p>Protected binaries, polished metadata, and download telemetry in one place.</p>
          </div>
          <div class="hero-actions">
            <button class="button secondary" id="fv-public">View public vault ↗</button>
            <button class="button secondary" id="fv-public-settings">Public view settings</button>
            <button class="button secondary" id="fv-vault-settings">Vault settings</button>
            <button class="button secondary" id="fv-activity">Activity${Number(status?.activity_count || 0) ? ` · ${Number(status.activity_count).toLocaleString()}` : ''}</button>
            <button class="button secondary" id="fv-add-url">Add URL</button>
            <label class="button primary ${this.state.busy ? 'disabled' : ''}">
              Upload files
              <input id="fv-upload" type="file" multiple accept="${(status?.allowed_extensions || []).map(value => `.${this.escape(value)}`).join(',')}" hidden ${this.state.busy ? 'disabled' : ''}>
            </label>
          </div>
        </section>

        ${this.state.error ? `<div class="notice error">${this.escape(this.state.error)}</div>` : ''}
        ${this.state.message ? `<div class="notice">${this.escape(this.state.message)}</div>` : ''}

        <section class="metrics">
          <article><span>Archive files</span><strong>${Number(totals.files).toLocaleString()}</strong></article>
          <article><span>Stored data</span><strong>${this.formatBytes(Number(totals.bytes))}</strong></article>
          <article><span>Downloads</span><strong>${Number(totals.downloads).toLocaleString()}</strong></article>
          <article class="storage"><span>Protected storage</span><strong title="${this.escape(status?.storage_path || '')}">${this.escape(status?.storage_path || '—')}</strong></article>
        </section>

        <section class="workspace">
          <aside class="catalog">
            <div class="panel-head">
              <div><span class="label">CATEGORIES</span><b>${this.escape(activeLabel)} · ${items.length} ${items.length === 1 ? 'file' : 'files'}</b></div>
              <div class="panel-actions"><button class="small-button" id="fv-add-category">＋ Category</button><button class="icon-button" id="fv-refresh" title="Refresh" ${this.state.busy ? 'disabled' : ''}>↻</button></div>
            </div>
            <div class="cabinet-layout">
              <nav class="drawers" aria-label="Catalog categories">
                <button class="drawer ${this.state.selectedCategory === 'all' ? 'active' : ''}" data-category="all">
                  <span class="drawer-icon">▤</span><span><b>All files</b><small>${status?.items?.length || 0} items</small></span>
                </button>
                <button class="drawer ${this.state.selectedCategory === 'unlisted' ? 'active' : ''}" data-category="unlisted">
                  <span class="drawer-icon">◈</span><span><b>Unlisted assets</b><small>${(status?.items || []).filter(entry => entry.listed === false).length} items</small></span>
                </button>
                ${categories.map(category => `
                  <div class="drawer-row ${category.id === this.state.selectedCategory ? 'active' : ''}">
                    <button class="drawer" data-category="${this.escape(category.id)}">
                      <span class="drawer-icon">▰</span><span><b>${this.escape(category.name)}</b><small>${Number(category.file_count || 0)} ${Number(category.file_count || 0) === 1 ? 'item' : 'items'}</small></span>
                    </button>
                    <button class="drawer-edit" data-edit-category="${this.escape(category.id)}" title="Edit ${this.escape(category.name)}">•••</button>
                  </div>`).join('')}
              </nav>
              <div class="drawer-contents">
                <div class="drop-zone" id="fv-drop-zone" role="button" tabindex="0" aria-label="Upload files to ${this.escape(uploadLabel)}">
                  <span class="drop-icon">⇧</span>
                  <span><strong>Drop files into ${this.escape(uploadLabel)}</strong><small>${this.state.selectedCategory === 'unlisted' ? 'inline links only; hidden from public catalogs · ' : 'or click to browse · '}${this.escape(extensionText || 'configured file types')}</small></span>
                </div>
                ${this.state.uploadQueue.length ? `<div class="upload-queue" aria-live="polite">${this.state.uploadQueue.map(entry => `
                  <div class="queue-item ${entry.status.toLowerCase()}"><span>${this.escape(entry.name)}</span><strong>${this.escape(entry.status)}</strong>${entry.error ? `<small title="${this.escape(entry.error)}">${this.escape(entry.error)}</small>` : ''}</div>`).join('')}</div>` : ''}
                <label class="drawer-search-label"><span>Search files</span><span class="search"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="6"></circle><path d="m16 16 4 4"></path></svg><input id="fv-search" type="search" value="${this.escape(this.state.query)}" placeholder="Search selected category…"></span></label>
                <div class="file-list">
                  ${!status ? '<div class="empty">Loading catalog…</div>' : ''}
                  ${status && !items.length ? '<div class="empty">This drawer is empty.</div>' : ''}
                  ${items.map(entry => `
                    <button class="file ${entry.id === this.state.selectedId ? 'active' : ''}" data-id="${this.escape(entry.id)}">
                      <span class="file-icon">${entry.source_type === 'url' ? 'URL' : this.escape((entry.filename.split('.').pop() || 'FILE').toUpperCase())}</span>
                      <span class="file-copy">
                        <strong>${this.escape(entry.display_name)}</strong>
                        <small>${this.escape(entry.filename)} · ${this.escape(entry.size)}</small>
                        <span class="badges">
                          ${entry.category ? `<em>${this.escape(entry.category)}</em>` : ''}
                          ${entry.listed === false ? '<em class="unlisted">Unlisted</em>' : ''}
                          ${entry.featured ? '<em class="featured">Featured</em>' : ''}
                          ${entry.password_protected ? '<em>Password</em>' : ''}
                          ${entry.download_limit > 0 ? `<em>${Number(entry.downloads_remaining)} left</em>` : ''}
                          ${entry.exhausted ? '<em class="danger">Exhausted</em>' : ''}
                          ${entry.missing ? '<em class="danger">Missing</em>' : ''}
                        </span>
                      </span>
                      <span class="count">${Number(entry.download_count).toLocaleString()}<small>↓</small></span>
                    </button>`).join('')}
                </div>
              </div>
            </div>
          </aside>

          <main class="editor">
            ${item ? this.editorMarkup(item) : `
              <div class="empty editor-empty">
                <div class="vault-mark">FV</div>
                <h3>${status?.items?.length ? 'This category is empty' : 'Your vault is empty'}</h3>
                <p>${status?.items?.length ? 'Upload a file here or assign an existing file to this category.' : 'Upload a supported file to create the first catalog entry.'}</p>
                <small>Allowed: ${this.escape(extensionText || 'configured extensions')}</small>
              </div>`}
          </main>
        </section>
      </div>
      ${this.categoryModalMarkup()}
      ${this.publicSettingsModalMarkup()}
      ${this.vaultSettingsModalMarkup()}
      ${this.activityModalMarkup()}
      ${this.urlModalMarkup()}`;
    this.bindEvents();
  }

  editorMarkup(item) {
    const categories = this.state.status?.categories || [];
    return `
      <div class="panel-head editor-head">
        <div>
          <span class="label">METADATA</span>
          <b>${this.escape(item.display_name)}</b>
        </div>
        <div class="status ${item.enabled ? 'on' : 'off'}">${item.enabled ? (item.listed === false ? 'Unlisted' : 'Published') : 'Disabled'}</div>
      </div>
      <div class="editor-body">
        <div class="identity">
          <span class="large-icon">${item.source_type === 'url' ? 'URL' : this.escape((item.filename.split('.').pop() || 'FILE').toUpperCase())}</span>
          <div><h3>${this.escape(item.filename)}</h3><p>${item.source_type === 'url' ? 'Secure external redirect · destination hidden from catalog output' : `${this.escape(item.size)} · SHA-256 ${this.escape((item.checksum_sha256 || 'not calculated').slice(0, 16))}${item.checksum_sha256 ? '…' : ''}`}</p></div>
        </div>
        <div class="form-grid">
          ${this.field('Display name', 'fv-name', item.display_name, 'wide')}
          ${this.field('Served filename', 'fv-download-name', item.download_name)}
          ${this.field('Version', 'fv-version', item.version, '', 'e.g. 3.7')}
          ${item.source_type === 'url' ? this.field('Destination URL', 'fv-external-url', item.external_url, 'wide', 'https://example.com/download') : ''}
          <label class="field"><span>Category</span><select id="fv-category">
            <option value="">Uncategorized</option>
            ${categories.map(category => `<option value="${this.escape(category.name)}" ${category.name === item.category ? 'selected' : ''}>${this.escape(category.name)}</option>`).join('')}
          </select></label>
          ${this.field('Published date', 'fv-published', item.published_at, '', '', 'date')}
          ${this.field('Tags (comma-separated)', 'fv-tags', (item.tags || []).join(', '), 'wide')}
          <label class="field wide"><span>Description</span><textarea id="fv-description" rows="4">${this.escape(item.description)}</textarea></label>
          ${this.field('Required ACL (blank = public)', 'fv-access', item.access, 'wide', 'site.login')}
          <label class="field"><span>New download password</span><input id="fv-password" type="password" value="" placeholder="${item.password_protected ? 'Password is set; enter to replace' : 'Optional, at least 8 characters'}" autocomplete="new-password"></label>
          <label class="field"><span>Download limit (0 = unlimited)</span><input id="fv-download-limit" type="number" min="0" step="1" value="${Number(item.download_limit || 0)}"></label>
          <div class="field wide security-toggles">
            <label class="check-control"><input id="fv-clear-password" type="checkbox" ${item.password_protected ? '' : 'disabled'}><span>${item.password_protected ? 'Remove the current download password' : 'No download password is currently set'}</span></label>
            <small>Passwords are stored as one-way hashes. Limited items are automatically delisted when their successful download count reaches the limit.</small>
          </div>
          ${this.field('Sort order', 'fv-order', item.sort_order, '', '', 'number')}
          <div class="field toggles">
            <label><input id="fv-enabled" type="checkbox" ${item.enabled ? 'checked' : ''}><span>Enabled</span></label>
            <label><input id="fv-listed" type="checkbox" ${item.listed !== false ? 'checked' : ''}><span>Listed</span></label>
            <label><input id="fv-featured" type="checkbox" ${item.featured ? 'checked' : ''}><span>Featured</span></label>
          </div>
          <div class="field wide settings-note"><strong>Catalog visibility</strong><p>Uncheck <b>Listed</b> for a protected one-off asset. It remains available through its inline shortcode but never appears in public File Vault catalogs or category collections.</p></div>
        </div>
        <div class="shortcode-box">
          <div><span>Inline download shortcode</span><code>[file-download id="${this.escape(item.id)}" /]</code><small>Paste this into any Shortcode Core-enabled page. Add <code>label="Download now"</code> or <code>layout="card"</code> when desired.</small></div>
          <button class="button secondary" id="fv-copy-shortcode">Copy</button>
        </div>
        <div class="insights">
          <div><span>Downloads</span><strong>${Number(item.download_count).toLocaleString()}</strong></div>
          <div><span>${item.download_limit > 0 ? 'Remaining' : 'Last modified'}</span><strong>${item.download_limit > 0 ? Number(item.downloads_remaining).toLocaleString() : this.escape(item.modified_at || '—')}</strong></div>
          <div><span>Source</span><strong>${item.source_type === 'url' ? 'Secure URL' : 'Stored file'}</strong></div>
          <button class="link-button" id="fv-reset">Reset count</button>
        </div>
      </div>
      <footer class="editor-footer">
        <div class="danger-zone">
          <button class="button danger-quiet" id="fv-remove">Remove entry</button>
          ${item.source_type === 'file' ? '<button class="button danger-quiet" id="fv-delete">Delete stored file</button>' : ''}
        </div>
        <button class="button primary" id="fv-save" ${this.state.busy ? 'disabled' : ''}>Save metadata</button>
      </footer>`;
  }

  categoryModalMarkup() {
    const modal = this.state.categoryModal;
    if (!modal) return '';
    const categories = (this.state.status?.categories || []).filter(category => category.id !== modal.id);
    return `
      <div class="modal-backdrop" id="fv-modal-backdrop">
        <section class="modal" role="dialog" aria-modal="true" aria-labelledby="fv-category-title">
          <header>
            <div><span class="label">CATALOG DRAWER</span><h3 id="fv-category-title">${modal.mode === 'edit' ? 'Edit category' : 'New category'}</h3></div>
            <button class="modal-close" id="fv-category-close" aria-label="Close">×</button>
          </header>
          <div class="modal-body">
            ${this.field('Category name', 'fv-category-name', modal.name, 'wide', 'e.g. Core Releases')}
            <label class="field wide"><span>Description</span><textarea id="fv-category-description" rows="3" placeholder="What belongs in this drawer?">${this.escape(modal.description)}</textarea></label>
            ${this.field('Sort order', 'fv-category-order', modal.sort_order, '', '', 'number')}
            ${modal.mode === 'edit' ? `
              <div class="move-box field wide">
                <span>If this category is deleted</span>
                <select id="fv-category-move">
                  <option value="">Move files to Uncategorized</option>
                  ${categories.map(category => `<option value="${this.escape(category.id)}">Move files to ${this.escape(category.name)}</option>`).join('')}
                </select>
                <button class="button danger-quiet" id="fv-category-delete">Delete category</button>
                <small>The category is removed; its stored files are never deleted.</small>
              </div>` : ''}
          </div>
          <footer>
            <button class="button secondary" id="fv-category-cancel">Cancel</button>
            <button class="button primary" id="fv-category-save">${modal.mode === 'edit' ? 'Save category' : 'Create category'}</button>
          </footer>
        </section>
      </div>`;
  }

  publicSettingsModalMarkup() {
    if (!this.state.publicModal) return '';
    const settings = this.state.status?.public_settings || {};
    const categories = this.state.status?.categories || [];
    const exampleCategory = categories[0]?.id || 'category-id';
    const exampleItem = this.state.status?.items?.[0]?.id || 'download-id';
    return `
      <div class="modal-backdrop" id="fv-public-backdrop">
        <section class="modal public-modal" role="dialog" aria-modal="true" aria-labelledby="fv-public-settings-title">
          <header>
            <div><span class="label">PUBLIC DOWNLOADS</span><h3 id="fv-public-settings-title">Public view settings</h3></div>
            <button class="modal-close" id="fv-public-close" aria-label="Close">×</button>
          </header>
          <div class="modal-body public-modal-body">
            ${this.field('Eyebrow', 'fv-public-kicker', settings.hero_kicker || '', '', 'FILE VAULT')}
            ${this.field('Heading', 'fv-public-title', settings.hero_title || '', '', 'Downloads')}
            <label class="field wide"><span>Introduction</span><textarea id="fv-public-intro" rows="4">${this.escape(settings.hero_intro || '')}</textarea></label>
            <label class="field"><span>Default category</span><select id="fv-public-category"><option value="">All categories</option>${categories.map(category => `<option value="${this.escape(category.id)}" ${category.id === settings.default_category ? 'selected' : ''}>${this.escape(category.name)}</option>`).join('')}</select></label>
            <label class="field"><span>Default display</span><select id="fv-public-view"><option value="list" ${settings.default_view !== 'grid' ? 'selected' : ''}>List</option><option value="grid" ${settings.default_view === 'grid' ? 'selected' : ''}>Grid</option></select></label>
            ${this.field('Search placeholder', 'fv-public-search', settings.search_placeholder || '', 'wide', 'Search files, descriptions, and tags…')}
            <div class="field wide public-toggles">
              <span>Visible hero elements</span>
              <label><input id="fv-public-show-intro" type="checkbox" ${settings.show_intro !== false ? 'checked' : ''}><span>Introduction</span></label>
              <label><input id="fv-public-show-files" type="checkbox" ${settings.show_file_count !== false ? 'checked' : ''}><span>Archive file count</span></label>
              <label><input id="fv-public-show-downloads" type="checkbox" ${settings.show_download_count !== false ? 'checked' : ''}><span>Download count</span></label>
            </div>
            <div class="field wide shortcode-help"><span>Embed in another page</span><code>[file-vault category="${this.escape(exampleCategory)}" view="grid" limit="6" /]</code><code>[file-download id="${this.escape(exampleItem)}" layout="card" /]</code><small>Examples use the first category and item in this vault. Requires Shortcode Core; file-level ACLs always remain enforced.</small></div>
          </div>
          <footer><button class="button secondary" id="fv-public-cancel">Cancel</button><button class="button primary" id="fv-public-save">Save public settings</button></footer>
        </section>
      </div>`;
  }

  vaultSettingsModalMarkup() {
    if (!this.state.vaultModal) return '';
    const settings = this.state.status?.vault_settings || {};
    const activity = settings.activity || this.state.status?.activity_settings || {};
    return `
      <div class="modal-backdrop" id="fv-vault-backdrop">
        <section class="modal" role="dialog" aria-modal="true" aria-labelledby="fv-vault-settings-title">
          <header><div><span class="label">UPLOAD SECURITY</span><h3 id="fv-vault-settings-title">Vault settings</h3></div><button class="modal-close" id="fv-vault-close" aria-label="Close">×</button></header>
          <div class="modal-body">
            ${this.field('Allowed file types', 'fv-allowed-extensions', (settings.allowed_extensions || []).join(', '), 'wide', 'zip, pdf, txt')}
            <div class="field wide settings-note"><strong>Upload allowlist</strong><p>Enter extensions separated by commas. File Vault validates the extension again on the server; URL entries are not uploads and are limited to http/https destinations.</p><small>Maximum upload size: ${this.formatBytes(Number(settings.max_upload_size || 0))}</small></div>
            <div class="field wide settings-section">
              <span>Download activity</span>
              <label class="check-control"><input id="fv-activity-enabled" type="checkbox" ${activity.enabled ? 'checked' : ''}><span>Record successful downloads</span></label>
              <small>Disabled by default. Enable only after your public privacy notice describes this collection.</small>
            </div>
            <label class="field"><span>IP storage</span><select id="fv-activity-ip-mode"><option value="none" ${activity.ip_mode === 'none' ? 'selected' : ''}>Do not store</option><option value="hash" ${activity.ip_mode !== 'none' && activity.ip_mode !== 'full' ? 'selected' : ''}>Pseudonymous hash</option><option value="full" ${activity.ip_mode === 'full' ? 'selected' : ''}>Full IP address</option></select></label>
            ${this.field('Retention (days)', 'fv-activity-retention', Number(activity.retention_days || 30), '', '', 'number')}
            <div class="field wide settings-section compact">
              <label class="check-control"><input id="fv-activity-user-agent" type="checkbox" ${activity.store_user_agent ? 'checked' : ''}><span>Store browser user agent</span></label>
              <label class="check-control"><input id="fv-activity-proxy" type="checkbox" ${activity.trust_proxy_headers ? 'checked' : ''}><span>Trust X-Forwarded-For from my reverse proxy</span></label>
              <small>Only trust proxy headers when a controlled proxy replaces them. User-agent capture is off by default because it adds identifying detail.</small>
            </div>
          </div>
          <footer><button class="button secondary" id="fv-vault-cancel">Cancel</button><button class="button primary" id="fv-vault-save">Save vault settings</button></footer>
        </section>
      </div>`;
  }

  activityModalMarkup() {
    if (!this.state.activityModal) return '';
    const data = this.state.activityData || { events: [], count: 0, settings: {} };
    const events = data.events || [];
    const settings = data.settings || {};
    return `
      <div class="modal-backdrop" id="fv-activity-backdrop">
        <section class="modal activity-modal" role="dialog" aria-modal="true" aria-labelledby="fv-activity-title">
          <header><div><span class="label">DOWNLOAD ACTIVITY</span><h3 id="fv-activity-title">Successful downloads</h3></div><button class="modal-close" id="fv-activity-close" aria-label="Close">×</button></header>
          <div class="activity-summary"><span><strong>${Number(data.count || 0).toLocaleString()}</strong> retained events</span><span>${settings.enabled ? `On · ${this.escape(settings.ip_mode || 'none')} IP · ${Number(settings.retention_days || 30)} days` : 'Recording is off'}</span></div>
          <div class="activity-table-wrap">
            ${events.length ? `<table class="activity-table"><thead><tr><th>When</th><th>Download</th><th>Visitor</th><th>IP</th><th>Details</th></tr></thead><tbody>${events.map(event => `<tr><td>${this.escape(this.formatDateTime(event.at))}</td><td><strong>${this.escape(event.item_name || event.item_id)}</strong><small>${this.escape(event.source_type || 'file')}</small></td><td>${this.escape(event.user || 'Guest')}</td><td><code>${this.escape(event.ip || 'Not stored')}</code><small>${this.escape(event.ip_mode || 'none')}</small></td><td class="agent" title="${this.escape(event.user_agent || '')}">${this.escape(event.user_agent || '—')}</td></tr>`).join('')}</tbody></table>` : '<div class="empty">No successful downloads have been recorded.</div>'}
          </div>
          <footer><button class="button danger-quiet" id="fv-activity-purge" ${events.length ? '' : 'disabled'}>Clear activity log</button><button class="button secondary" id="fv-activity-done">Done</button></footer>
        </section>
      </div>`;
  }

  urlModalMarkup() {
    if (!this.state.urlModal) return '';
    const categories = this.state.status?.categories || [];
    return `
      <div class="modal-backdrop" id="fv-url-backdrop">
        <section class="modal" role="dialog" aria-modal="true" aria-labelledby="fv-url-title">
          <header><div><span class="label">SECURE REDIRECT</span><h3 id="fv-url-title">Add URL download</h3></div><button class="modal-close" id="fv-url-close" aria-label="Close">×</button></header>
          <div class="modal-body">
            ${this.field('Display name', 'fv-url-name', '', 'wide', 'External archive or resource')}
            ${this.field('Destination URL', 'fv-url-destination', '', 'wide', 'https://example.com/download')}
            ${this.field('Served filename', 'fv-url-download-name', '', '', 'Optional label or filename')}
            <label class="field"><span>Category</span><select id="fv-url-category"><option value="">Uncategorized</option>${categories.map(category => `<option value="${this.escape(category.id)}">${this.escape(category.name)}</option>`).join('')}</select></label>
            <label class="field wide"><span>Description</span><textarea id="fv-url-description" rows="3" placeholder="Describe what the visitor will receive."></textarea></label>
            <label class="field wide check-control"><input id="fv-url-listed" type="checkbox" checked><span>List this URL in public download catalogs</span></label>
            <div class="field wide settings-note"><strong>How protection works</strong><p>Visitors receive only an expiring File Vault link. ACL, password, and download-limit checks run before a no-referrer redirect. The destination URL becomes visible to the visitor after redirect, as required for browser navigation.</p></div>
          </div>
          <footer><button class="button secondary" id="fv-url-cancel">Cancel</button><button class="button primary" id="fv-url-save">Add secure URL</button></footer>
        </section>
      </div>`;
  }

  field(label, id, value, classes = '', placeholder = '', type = 'text') {
    return `<label class="field ${classes}"><span>${label}</span><input id="${id}" type="${type}" value="${this.escape(value)}" placeholder="${this.escape(placeholder)}"></label>`;
  }

  bindEvents() {
    this.shadowRoot.querySelector('#fv-upload')?.addEventListener('change', event => {
      this.uploadFiles(event.target.files);
      event.target.value = '';
    });
    const dropZone = this.shadowRoot.querySelector('#fv-drop-zone');
    const browse = () => {
      if (!this.state.busy) this.shadowRoot.querySelector('#fv-upload')?.click();
    };
    dropZone?.addEventListener('click', browse);
    dropZone?.addEventListener('keydown', event => {
      if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        browse();
      }
    });
    for (const eventName of ['dragenter', 'dragover']) {
      dropZone?.addEventListener(eventName, event => {
        event.preventDefault();
        if (!this.state.busy) dropZone.classList.add('dragging');
      });
    }
    for (const eventName of ['dragleave', 'drop']) {
      dropZone?.addEventListener(eventName, event => {
        event.preventDefault();
        dropZone.classList.remove('dragging');
      });
    }
    dropZone?.addEventListener('drop', event => {
      if (!this.state.busy) this.uploadFiles(event.dataTransfer?.files);
    });
    this.shadowRoot.querySelector('#fv-public')?.addEventListener('click', () => window.open(this.state.status?.public_route || '/downloads', '_blank', 'noopener'));
    this.shadowRoot.querySelector('#fv-public-settings')?.addEventListener('click', () => this.openPublicSettings());
    this.shadowRoot.querySelector('#fv-vault-settings')?.addEventListener('click', () => this.openVaultSettings());
    this.shadowRoot.querySelector('#fv-activity')?.addEventListener('click', () => this.openActivity());
    this.shadowRoot.querySelector('#fv-add-url')?.addEventListener('click', () => this.openUrlModal());
    this.shadowRoot.querySelector('#fv-refresh')?.addEventListener('click', () => this.load());
    this.shadowRoot.querySelector('#fv-search')?.addEventListener('input', event => {
      const cursor = event.target.selectionStart;
      this.state.query = event.target.value;
      this.render();
      const input = this.shadowRoot.querySelector('#fv-search');
      input?.focus();
      input?.setSelectionRange(cursor, cursor);
    });
    this.shadowRoot.querySelectorAll('[data-category]').forEach(button => button.addEventListener('click', () => this.selectCategory(button.dataset.category)));
    this.shadowRoot.querySelector('#fv-add-category')?.addEventListener('click', () => this.openCategoryModal());
    this.shadowRoot.querySelectorAll('[data-edit-category]').forEach(button => button.addEventListener('click', () => {
      const category = (this.state.status?.categories || []).find(entry => entry.id === button.dataset.editCategory);
      if (category) this.openCategoryModal(category);
    }));
    this.shadowRoot.querySelectorAll('[data-id]').forEach(button => button.addEventListener('click', () => {
      this.state.selectedId = button.dataset.id;
      this.render();
    }));
    this.shadowRoot.querySelector('#fv-save')?.addEventListener('click', () => this.save());
    this.shadowRoot.querySelector('#fv-copy-shortcode')?.addEventListener('click', () => this.copyShortcode());
    this.shadowRoot.querySelector('#fv-remove')?.addEventListener('click', () => this.remove(false));
    this.shadowRoot.querySelector('#fv-delete')?.addEventListener('click', () => this.remove(true));
    this.shadowRoot.querySelector('#fv-reset')?.addEventListener('click', () => this.resetCount());
    this.shadowRoot.querySelector('#fv-category-save')?.addEventListener('click', () => this.saveCategory());
    this.shadowRoot.querySelector('#fv-category-delete')?.addEventListener('click', () => this.deleteCategory());
    this.shadowRoot.querySelector('#fv-category-close')?.addEventListener('click', () => this.closeCategoryModal());
    this.shadowRoot.querySelector('#fv-category-cancel')?.addEventListener('click', () => this.closeCategoryModal());
    this.shadowRoot.querySelector('#fv-modal-backdrop')?.addEventListener('click', event => {
      if (event.target.id === 'fv-modal-backdrop') this.closeCategoryModal();
    });
    this.shadowRoot.querySelector('#fv-public-save')?.addEventListener('click', () => this.savePublicSettings());
    this.shadowRoot.querySelector('#fv-public-close')?.addEventListener('click', () => this.closePublicSettings());
    this.shadowRoot.querySelector('#fv-public-cancel')?.addEventListener('click', () => this.closePublicSettings());
    this.shadowRoot.querySelector('#fv-public-backdrop')?.addEventListener('click', event => {
      if (event.target.id === 'fv-public-backdrop') this.closePublicSettings();
    });
    this.shadowRoot.querySelector('#fv-vault-save')?.addEventListener('click', () => this.saveVaultSettings());
    this.shadowRoot.querySelector('#fv-vault-close')?.addEventListener('click', () => this.closeVaultSettings());
    this.shadowRoot.querySelector('#fv-vault-cancel')?.addEventListener('click', () => this.closeVaultSettings());
    this.shadowRoot.querySelector('#fv-vault-backdrop')?.addEventListener('click', event => {
      if (event.target.id === 'fv-vault-backdrop') this.closeVaultSettings();
    });
    this.shadowRoot.querySelector('#fv-activity-close')?.addEventListener('click', () => this.closeActivity());
    this.shadowRoot.querySelector('#fv-activity-done')?.addEventListener('click', () => this.closeActivity());
    this.shadowRoot.querySelector('#fv-activity-purge')?.addEventListener('click', () => this.purgeActivity());
    this.shadowRoot.querySelector('#fv-activity-backdrop')?.addEventListener('click', event => {
      if (event.target.id === 'fv-activity-backdrop') this.closeActivity();
    });
    this.shadowRoot.querySelector('#fv-url-save')?.addEventListener('click', () => this.createUrlItem());
    this.shadowRoot.querySelector('#fv-url-close')?.addEventListener('click', () => this.closeUrlModal());
    this.shadowRoot.querySelector('#fv-url-cancel')?.addEventListener('click', () => this.closeUrlModal());
    this.shadowRoot.querySelector('#fv-url-backdrop')?.addEventListener('click', event => {
      if (event.target.id === 'fv-url-backdrop') this.closeUrlModal();
    });
  }

  styles() {
    return `
      :host { --bg:var(--background,#19191d); --panel:var(--card,#202023); --panel-2:var(--secondary,#27272a); --line:var(--border,#313135); --text:var(--foreground,#fafafa); --muted-text:var(--muted-foreground,#a1a1aa); --accent:var(--primary,#3c83f6); --accent-2:var(--ring,var(--primary,#3c83f6)); --danger:var(--destructive,#7f1d1d); --green:var(--success,#2eb860); display:block; color:var(--text); font:14px/1.5 Inter,ui-sans-serif,system-ui,sans-serif; }
      :host([data-theme="light"]) { --bg:var(--background,#fff); --panel:var(--card,#fff); --panel-2:var(--secondary,#f4f4f5); --line:var(--border,#e4e4e7); --text:var(--foreground,#09090b); --muted-text:var(--muted-foreground,#71717a); --accent:var(--primary,#2463eb); --accent-2:var(--ring,var(--primary,#2463eb)); --danger:var(--destructive,#ef4444); --green:var(--success,#1eae53); }
      * { box-sizing:border-box; }
      button,input,textarea,select { font:inherit; }
      button { color:inherit; }
      .shell { min-height:calc(100vh - 130px); background:var(--bg); border:1px solid var(--line); border-radius:14px; overflow:hidden; }
      .hero { display:flex; flex-wrap:wrap; align-items:center; justify-content:space-between; gap:24px; padding:26px 30px; background:linear-gradient(135deg,var(--panel),var(--panel-2)); border-bottom:1px solid var(--line); }
      .hero > div:first-child { flex:1 1 280px; }
      .eyebrow,.label { color:var(--accent-2); font-size:11px; font-weight:800; letter-spacing:.15em; }
      h2 { margin:3px 0 2px; font-size:28px; letter-spacing:-.03em; }
      .hero p { margin:0; color:var(--muted-text); }
      .hero-actions,.danger-zone { display:flex; gap:9px; flex-wrap:wrap; }
      .hero-actions { flex:1 1 580px; justify-content:flex-end; }
      .button { border:1px solid var(--line); border-radius:8px; padding:9px 14px; background:var(--panel-2); cursor:pointer; font-weight:700; transition:.16s ease; }
      .button:hover { transform:translateY(-1px); border-color:var(--muted-text); }
      .button.primary { color:white; background:var(--accent); border-color:var(--accent); }
      .button.primary:hover { background:var(--accent-2); }
      .button:disabled,.button.disabled { opacity:.5; cursor:not-allowed; transform:none; }
      .danger-quiet { color:var(--danger); background:transparent; }
      .notice { margin:16px 20px 0; padding:10px 13px; border:1px solid color-mix(in srgb,var(--green) 45%,var(--line)); border-radius:8px; background:color-mix(in srgb,var(--green) 10%,var(--panel)); }
      .notice.error { border-color:color-mix(in srgb,var(--accent) 50%,var(--line)); background:color-mix(in srgb,var(--accent) 10%,var(--panel)); color:#ff8a82; }
      .metrics { display:grid; grid-template-columns:repeat(3,minmax(140px,1fr)) minmax(260px,2fr); gap:1px; background:var(--line); border-bottom:1px solid var(--line); }
      .metrics article { padding:16px 20px; background:var(--panel); }
      .metrics span { display:block; color:var(--muted-text); font-size:11px; text-transform:uppercase; letter-spacing:.08em; }
      .metrics strong { display:block; margin-top:4px; font-size:20px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
      .metrics .storage strong { font-size:13px; font-family:ui-monospace,monospace; color:var(--muted-text); }
      .workspace { display:grid; grid-template-columns:minmax(540px,48%) minmax(450px,1fr); min-height:590px; }
      .catalog { min-width:0; background:var(--panel); border-right:1px solid var(--line); }
      .panel-head { min-height:64px; display:flex; align-items:center; justify-content:space-between; gap:12px; padding:12px 18px; border-bottom:1px solid var(--line); }
      .panel-head .label,.panel-head b { display:block; }
      .panel-head b { margin-top:2px; }
      .panel-actions { display:flex; align-items:center; gap:7px; }
      .small-button { height:34px; padding:0 10px; border:1px solid var(--line); border-radius:8px; background:var(--panel-2); color:var(--accent); cursor:pointer; font-size:11px; font-weight:750; }
      .icon-button { width:34px; height:34px; border:1px solid var(--line); border-radius:8px; background:var(--panel-2); cursor:pointer; font-size:19px; }
      .cabinet-layout { display:grid; grid-template-columns:170px minmax(0,1fr); min-height:610px; }
      .drawers { padding:10px 8px; border-right:1px solid var(--line); background:color-mix(in srgb,var(--panel-2) 60%,var(--panel)); }
      .drawer-row { position:relative; display:flex; align-items:stretch; border:1px solid transparent; border-radius:8px; }
      .drawer-row.active,.drawer.active { border-color:color-mix(in srgb,var(--accent) 45%,var(--line)); background:color-mix(in srgb,var(--accent) 12%,var(--panel)); }
      .drawer { min-width:0; width:100%; display:flex; align-items:center; gap:8px; padding:8px; border:1px solid transparent; border-radius:8px; background:transparent; text-align:left; cursor:pointer; }
      .drawer-row .drawer { padding-right:29px; }
      .drawer:hover,.drawer-row:hover { background:var(--panel-2); }
      .drawer-icon { color:var(--accent); font-size:12px; }
      .drawer span:last-child { min-width:0; }
      .drawer b,.drawer small { display:block; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
      .drawer b { font-size:12px; }
      .drawer small { color:var(--muted-text); font-size:10px; }
      .drawer-edit { position:absolute; top:50%; right:4px; width:26px; height:26px; padding:0; border:0; border-radius:6px; transform:translateY(-50%); background:transparent; color:var(--muted-text); cursor:pointer; }
      .drawer-edit:hover { background:var(--panel); color:var(--text); }
      .drawer-contents { min-width:0; }
      .drop-zone { display:flex; align-items:center; gap:10px; margin:14px 14px 8px; padding:11px 12px; border:1px dashed color-mix(in srgb,var(--accent) 55%,var(--line)); border-radius:9px; background:color-mix(in srgb,var(--accent) 6%,var(--panel)); cursor:pointer; transition:.16s ease; }
      .drop-zone:hover,.drop-zone.dragging { border-style:solid; border-color:var(--accent); background:color-mix(in srgb,var(--accent) 14%,var(--panel)); box-shadow:0 0 0 3px color-mix(in srgb,var(--accent) 12%,transparent); }
      .drop-zone:focus-visible { outline:2px solid var(--accent); outline-offset:2px; }
      .drop-icon { display:grid; flex:0 0 auto; width:32px; height:32px; place-items:center; border-radius:7px; background:var(--accent); color:white; font-size:18px; font-weight:850; }
      .drop-zone strong,.drop-zone small { display:block; }
      .drop-zone strong { font-size:12px; }
      .drop-zone small { color:var(--muted-text); font-size:10px; }
      .upload-queue { display:grid; gap:5px; max-height:180px; margin:0 14px 10px; padding:8px; overflow:auto; border:1px solid var(--line); border-radius:8px; background:var(--panel-2); }
      .queue-item { display:grid; grid-template-columns:minmax(0,1fr) auto; gap:2px 10px; padding:5px 7px; border-radius:6px; background:var(--panel); font-size:10px; }
      .queue-item span { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
      .queue-item strong { color:var(--muted-text); }
      .queue-item.added strong { color:var(--green); }
      .queue-item.failed strong,.queue-item.failed small { color:#ff766e; }
      .queue-item small { grid-column:1/-1; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; color:var(--muted-text); }
      .drawer-search-label { display:block; margin:14px; }
      .drawer-search-label > span:first-child { display:block; margin:0 0 5px 2px; color:var(--muted-text); font-size:10px; font-weight:750; letter-spacing:.07em; text-transform:uppercase; }
      .search { display:flex; align-items:center; gap:8px; min-height:42px; padding:0 11px; border:1px solid var(--line); border-radius:8px; background:var(--panel-2); color:var(--muted-text); }
      .search svg { flex:0 0 auto; width:15px; height:15px; fill:none; stroke:currentColor; stroke-width:1.8; stroke-linecap:round; }
      .search input { width:100%; min-width:0; min-height:40px; margin:0; padding:0; border:0; border-radius:0; outline:0; color:var(--text); background:transparent; box-shadow:none; line-height:1.25; }
      .file-list { max-height:610px; overflow:auto; padding:0 8px 12px; }
      .file { width:100%; display:grid; grid-template-columns:44px minmax(0,1fr) auto; align-items:center; gap:11px; padding:12px 10px; border:1px solid transparent; border-radius:9px; background:transparent; text-align:left; cursor:pointer; }
      .file:hover { background:var(--panel-2); }
      .file.active { border-color:color-mix(in srgb,var(--accent) 55%,var(--line)); background:color-mix(in srgb,var(--accent) 10%,var(--panel)); }
      .file-icon,.large-icon { display:grid; place-items:center; border:1px solid var(--line); border-radius:8px; background:linear-gradient(145deg,var(--panel-2),var(--panel)); color:var(--accent-2); font-size:9px; font-weight:900; letter-spacing:.04em; }
      .file-icon { width:44px; height:48px; }
      .file-copy { min-width:0; }
      .file-copy strong,.file-copy small { display:block; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
      .file-copy small { color:var(--muted-text); font-size:11px; }
      .badges { display:flex; gap:4px; margin-top:5px; }
      .badges em { padding:1px 5px; border-radius:4px; background:var(--panel-2); color:var(--muted-text); font-size:9px; font-style:normal; text-transform:uppercase; }
      .badges .featured { color:#ffc96b; }
      .badges .unlisted { color:var(--accent-2); }
      .badges .danger { color:#ff766e; }
      .count { color:var(--muted-text); font-variant-numeric:tabular-nums; }
      .count small { margin-left:2px; }
      .editor { display:flex; min-width:0; flex-direction:column; background:var(--panel-2); }
      .editor-head .status { padding:4px 8px; border-radius:99px; font-size:11px; font-weight:800; }
      .status.on { color:var(--green); background:color-mix(in srgb,var(--green) 12%,transparent); }
      .status.off { color:var(--muted-text); background:var(--panel); }
      .editor-body { flex:1; padding:22px; overflow:auto; }
      .identity { display:flex; align-items:center; gap:15px; padding-bottom:20px; border-bottom:1px solid var(--line); }
      .large-icon { width:60px; height:66px; font-size:12px; }
      .identity h3 { margin:0; font-family:ui-monospace,monospace; font-size:17px; }
      .identity p { margin:4px 0 0; color:var(--muted-text); font-size:11px; }
      .form-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:15px; padding:22px 0; }
      .field { min-width:0; }
      .field.wide { grid-column:1/-1; }
      .field > span { display:block; margin-bottom:5px; color:var(--muted-text); font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; }
      .field input,.field textarea,.field select { width:100%; border:1px solid var(--line); border-radius:7px; padding:9px 10px; outline:none; background:var(--panel); color:var(--text); }
      .field input:not([type="checkbox"]),.field select { height:42px; min-height:42px; padding-top:0; padding-bottom:0; line-height:1.25; }
      .field input[type="checkbox"] { flex:0 0 auto; width:16px; height:16px; margin:0; padding:0; border:0; accent-color:var(--accent); box-shadow:none; }
      .field input:focus,.field textarea:focus,.field select:focus { border-color:var(--accent); box-shadow:0 0 0 3px color-mix(in srgb,var(--accent) 15%,transparent); }
      .field textarea { resize:vertical; }
      .toggles { display:flex; align-items:end; gap:16px; padding-bottom:9px; }
      .toggles label { display:flex; align-items:center; gap:7px; font-weight:700; }
      .toggles input { accent-color:var(--accent); }
      .shortcode-box { display:flex; align-items:center; justify-content:space-between; gap:14px; margin-bottom:16px; padding:13px 15px; border:1px solid var(--line); border-radius:9px; background:var(--panel); }
      .shortcode-box > div { min-width:0; }
      .shortcode-box span,.shortcode-box small { display:block; color:var(--muted-text); }
      .shortcode-box span { margin-bottom:5px; font-size:10px; font-weight:750; letter-spacing:.07em; text-transform:uppercase; }
      .shortcode-box code { display:block; overflow:auto; color:var(--accent-2); font-size:12px; white-space:nowrap; }
      .shortcode-box small { margin-top:5px; font-size:10px; }
      .shortcode-box small code { display:inline; overflow:visible; font-size:inherit; white-space:normal; }
      .insights { display:flex; align-items:center; gap:24px; flex-wrap:wrap; padding:15px; border:1px solid var(--line); border-radius:9px; background:var(--panel); }
      .insights span { display:block; color:var(--muted-text); font-size:10px; text-transform:uppercase; }
      .insights strong { font-size:16px; }
      .link-button { margin-left:auto; border:0; background:transparent; color:var(--accent-2); cursor:pointer; font-weight:700; }
      .editor-footer { display:flex; align-items:center; justify-content:space-between; gap:15px; padding:14px 18px; border-top:1px solid var(--line); background:var(--panel); }
      .empty { padding:40px 20px; color:var(--muted-text); text-align:center; }
      .editor-empty { margin:auto; }
      .editor-empty h3 { color:var(--text); }
      .vault-mark { width:72px; height:72px; display:grid; place-items:center; margin:0 auto; border:1px solid var(--line); border-radius:18px; color:var(--accent-2); font-size:22px; font-weight:900; background:var(--panel); }
      .modal-backdrop { position:fixed; z-index:10000; inset:0; display:grid; place-items:center; padding:20px; background:#0009; }
      .modal { display:flex; flex-direction:column; width:min(560px,100%); max-height:calc(100vh - 40px); overflow:hidden; border:1px solid var(--line); border-radius:12px; background:var(--panel); color:var(--text); box-shadow:0 24px 80px #0008; }
      .modal.public-modal { width:min(720px,100%); }
      .modal header,.modal footer { display:flex; align-items:center; justify-content:space-between; gap:16px; padding:16px 18px; }
      .modal header { border-bottom:1px solid var(--line); }
      .modal footer { justify-content:flex-end; border-top:1px solid var(--line); }
      .modal h3 { margin:2px 0 0; font-size:20px; }
      .modal-close { width:34px; height:34px; border:0; border-radius:8px; background:var(--panel-2); cursor:pointer; font-size:24px; }
      .modal-body { display:grid; grid-template-columns:1fr 140px; gap:15px; min-height:0; overflow:auto; padding:18px; }
      .move-box { padding:14px; border:1px solid color-mix(in srgb,var(--danger) 40%,var(--line)); border-radius:9px; background:color-mix(in srgb,var(--danger) 6%,var(--panel)); }
      .move-box select { margin-bottom:10px; }
      .move-box .danger-quiet { margin-right:8px; }
      .move-box small { color:var(--muted-text); }
      .public-toggles { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); align-items:center; gap:12px; padding:14px; border:1px solid var(--line); border-radius:9px; background:var(--panel-2); }
      .public-toggles > span { grid-column:1/-1; width:100%; margin:0; }
      .public-toggles label,.check-control { display:flex; align-items:center; gap:8px; min-width:0; font-weight:650; }
      .public-toggles label span,.check-control span { line-height:1.35; }
      .security-toggles { display:grid; gap:8px; padding:12px; border:1px solid var(--line); border-radius:9px; background:var(--panel-2); }
      .security-toggles small,.settings-note small { color:var(--muted-text); }
      .settings-note { padding:13px; border:1px solid var(--line); border-radius:9px; background:var(--panel-2); }
      .settings-note p { margin:5px 0 8px; color:var(--muted-text); }
      .settings-section { display:grid; gap:8px; padding:13px; border:1px solid var(--line); border-radius:9px; background:var(--panel-2); }
      .settings-section.compact { grid-template-columns:repeat(2,minmax(0,1fr)); }
      .settings-section > span { margin:0; }
      .settings-section small { grid-column:1/-1; color:var(--muted-text); }
      .activity-modal { width:min(1000px,100%); }
      .activity-summary { display:flex; justify-content:space-between; gap:16px; padding:12px 18px; border-bottom:1px solid var(--line); color:var(--muted-text); }
      .activity-summary strong { color:var(--text); }
      .activity-table-wrap { min-height:220px; max-height:60vh; overflow:auto; }
      .activity-table { width:100%; border-collapse:collapse; font-size:12px; }
      .activity-table th,.activity-table td { padding:10px 12px; border-bottom:1px solid var(--line); text-align:left; vertical-align:top; }
      .activity-table th { position:sticky; z-index:1; top:0; background:var(--panel-2); color:var(--muted-text); font-size:10px; letter-spacing:.06em; text-transform:uppercase; }
      .activity-table td small { display:block; color:var(--muted-text); }
      .activity-table code { font-size:11px; }
      .activity-table .agent { max-width:260px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; color:var(--muted-text); }
      .shortcode-help { display:grid; gap:7px; padding:12px; border:1px solid var(--line); border-radius:9px; background:var(--panel-2); }
      .shortcode-help code { display:block; padding:8px 10px; border:1px solid var(--line); border-radius:6px; background:var(--panel); color:var(--accent-2); font:12px/1.4 ui-monospace,monospace; }
      .shortcode-help small { color:var(--muted-text); }
      @media (max-width:1100px) { .workspace { grid-template-columns:1fr; } .catalog { border-right:0; border-bottom:1px solid var(--line); } .file-list { max-height:420px; } }
      @media (max-width:900px) { .metrics { grid-template-columns:repeat(2,1fr); } .cabinet-layout { grid-template-columns:150px minmax(0,1fr); } }
      @media (max-width:640px) { .hero { align-items:flex-start; flex-direction:column; } .metrics { grid-template-columns:1fr 1fr; } .metrics .storage { grid-column:1/-1; } .cabinet-layout { grid-template-columns:1fr; } .drawers { display:flex; gap:6px; overflow:auto; border-right:0; border-bottom:1px solid var(--line); } .drawer,.drawer-row { min-width:132px; } .drawer-edit { display:none; } .form-grid,.modal-body,.public-toggles,.settings-section.compact { grid-template-columns:1fr; } .public-toggles > span { grid-column:auto; } .field.wide { grid-column:auto; } .editor-footer { align-items:stretch; flex-direction:column; } .editor-footer .primary { order:-1; } .activity-summary { flex-direction:column; } .activity-table { min-width:760px; } }
    `;
  }

  formatBytes(bytes) {
    if (!Number.isFinite(bytes) || bytes <= 0) return '0 B';
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    const index = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1);
    const value = bytes / Math.pow(1024, index);
    return `${value.toFixed(index === 0 ? 0 : (value >= 10 ? 1 : 2))} ${units[index]}`;
  }

  formatDateTime(value) {
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return value || '—';
    return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(date);
  }

  escape(value) {
    return String(value ?? '')
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;')
      .replaceAll("'", '&#039;');
  }
}

if (!customElements.get(TAG)) {
  customElements.define(TAG, FileVaultPage);
}
