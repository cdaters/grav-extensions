(() => {
  const config = window.LanternSearchConfig || {};
  if (!config.endpoint || document.querySelector('[data-lantern-search]')) return;
  const escape = value => String(value ?? '').replace(/[&<>'"]/g, character => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' })[character]);
  const root = document.createElement('div');
  root.dataset.lanternSearch = '';
  root.innerHTML = `
    ${config.floating ? `<button class="lantern-trigger" type="button" aria-label="${escape(config.label || 'Search')}"><span aria-hidden="true">⌕</span><strong>${escape(config.label || 'Search')}</strong><kbd>${escape(config.shortcut || '/')}</kbd></button>` : ''}
    <div class="lantern-backdrop" hidden>
      <section class="lantern-dialog" role="dialog" aria-modal="true" aria-label="Site search">
        <div class="lantern-input-row"><span aria-hidden="true">⌕</span><input type="search" autocomplete="off" spellcheck="false" placeholder="${escape(config.placeholder || 'Search this site…')}" aria-label="Search this site"><button type="button" class="lantern-close" aria-label="Close search">×</button></div>
        <div class="lantern-status" role="status">Type to search public pages.</div>
        <ol class="lantern-results"></ol>
        <footer><span><kbd>↑</kbd><kbd>↓</kbd> choose</span><span><kbd>Enter</kbd> open</span><span><kbd>Esc</kbd> close</span></footer>
      </section>
    </div>`;
  document.body.appendChild(root);

  const backdrop = root.querySelector('.lantern-backdrop');
  const dialog = root.querySelector('.lantern-dialog');
  const input = root.querySelector('input');
  const list = root.querySelector('.lantern-results');
  const status = root.querySelector('.lantern-status');
  let results = [];
  let selected = -1;
  let timer;
  let controller;

  const renderSelection = () => list.querySelectorAll('a').forEach((link, index) => {
    link.classList.toggle('is-selected', index === selected);
    link.setAttribute('aria-selected', index === selected ? 'true' : 'false');
    if (index === selected) link.scrollIntoView({ block: 'nearest' });
  });
  const open = () => {
    backdrop.hidden = false;
    document.documentElement.classList.add('lantern-open');
    setTimeout(() => input.focus(), 0);
  };
  const close = () => {
    backdrop.hidden = true;
    document.documentElement.classList.remove('lantern-open');
    controller?.abort();
  };
  const search = async () => {
    const query = input.value.trim();
    if (query.length < 2) {
      results = []; selected = -1; list.innerHTML = ''; status.textContent = 'Type at least 2 characters to search.'; return;
    }
    controller?.abort(); controller = new AbortController();
    status.textContent = 'Searching…';
    try {
      const endpoint = new URL(config.endpoint, window.location.origin);
      endpoint.searchParams.set('q', query);
      const response = await fetch(endpoint, { signal: controller.signal, headers: { Accept: 'application/json' } });
      if (!response.ok) throw new Error(`HTTP ${response.status}`);
      const payload = await response.json();
      results = Array.isArray(payload.results) ? payload.results : [];
      selected = results.length ? 0 : -1;
      status.textContent = `${Number(payload.total || 0)} result${Number(payload.total || 0) === 1 ? '' : 's'} for “${query}”`;
      list.innerHTML = results.map((item, index) => `<li><a href="${escape(item.url || item.route)}" data-index="${index}" role="option" aria-selected="false"><span class="lantern-result-title">${escape(item.title || item.route)}</span><span class="lantern-result-route">${escape(item.route)}</span><span class="lantern-result-excerpt">${escape(item.excerpt || item.description || '')}</span></a></li>`).join('');
      renderSelection();
    } catch (error) {
      if (error?.name === 'AbortError') return;
      results = []; selected = -1; list.innerHTML = ''; status.textContent = 'Search is temporarily unavailable.';
    }
  };

  root.querySelector('.lantern-trigger')?.addEventListener('click', open);
  root.querySelector('.lantern-close').addEventListener('click', close);
  backdrop.addEventListener('click', event => { if (event.target === backdrop) close(); });
  dialog.addEventListener('click', event => event.stopPropagation());
  input.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(search, 180); });
  input.addEventListener('keydown', event => {
    if (event.key === 'ArrowDown' && results.length) { event.preventDefault(); selected = (selected + 1) % results.length; renderSelection(); }
    if (event.key === 'ArrowUp' && results.length) { event.preventDefault(); selected = (selected - 1 + results.length) % results.length; renderSelection(); }
    if (event.key === 'Enter' && selected >= 0) { event.preventDefault(); window.location.href = results[selected].url || results[selected].route; }
  });
  document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && !backdrop.hidden) { event.preventDefault(); close(); return; }
    const target = event.target;
    const typing = target && (target.matches?.('input, textarea, select, [contenteditable="true"]'));
    const shortcut = config.shortcut || '/';
    if (!typing && (event.key === shortcut || ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k'))) { event.preventDefault(); open(); }
  });
})();
