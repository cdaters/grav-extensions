(() => {
  const initialize = root => {
    if (root.dataset.fvReady === 'true') return;
    root.dataset.fvReady = 'true';

    const files = root.querySelector('[data-fv-files]');
    const search = root.querySelector('[data-fv-search]');
    const category = root.querySelector('[data-fv-category]');
    const sort = root.querySelector('[data-fv-sort]');
    const count = root.querySelector('[data-fv-count]');
    const countLabel = root.querySelector('[data-fv-count-label]');
    const empty = root.querySelector('[data-fv-empty]');
    const items = Array.from(root.querySelectorAll('[data-fv-item]'));
    if (!files) return;

    const update = () => {
      const needle = search?.value.trim().toLowerCase() || '';
      const selectedCategory = category?.value || '';
      let visible = 0;
      items.forEach(item => {
        const match = (!needle || item.dataset.name.includes(needle)) && (!selectedCategory || item.dataset.category === selectedCategory);
        item.hidden = !match;
        if (match) visible += 1;
      });

      const mode = sort?.value || 'order';
      const sorted = [...items].sort((a, b) => {
        if (mode === 'name') return a.dataset.name.localeCompare(b.dataset.name);
        if (mode === 'date') return (b.dataset.date || '').localeCompare(a.dataset.date || '');
        if (mode === 'size') return Number(b.dataset.size) - Number(a.dataset.size);
        if (mode === 'downloads') return Number(b.dataset.downloads) - Number(a.dataset.downloads);
        return Number(a.dataset.order) - Number(b.dataset.order);
      });
      sorted.forEach(item => files.appendChild(item));
      if (count) count.textContent = String(visible);
      if (countLabel) countLabel.textContent = visible === 1 ? 'archive file in this view' : 'archive files in this view';
      if (empty) empty.hidden = visible !== 0 || items.length === 0;
    };

    search?.addEventListener('input', update);
    category?.addEventListener('change', update);
    sort?.addEventListener('change', update);

    root.querySelectorAll('[data-fv-view]').forEach(button => {
      button.addEventListener('click', () => {
        const view = button.dataset.fvView;
        files.classList.toggle('fv-grid', view === 'grid');
        files.classList.toggle('fv-list', view === 'list');
        root.querySelectorAll('[data-fv-view]').forEach(peer => {
          const active = peer === button;
          peer.classList.toggle('active', active);
          peer.setAttribute('aria-pressed', String(active));
        });
      });
    });

    root.querySelectorAll('[data-checksum]').forEach(button => {
      button.addEventListener('click', async () => {
        try {
          await navigator.clipboard.writeText(button.dataset.checksum);
          const before = button.textContent;
          button.textContent = 'Copied';
          setTimeout(() => { button.textContent = before; }, 1600);
        } catch (_) {
          window.prompt('SHA-256 checksum', button.dataset.checksum);
        }
      });
    });

    const defaultView = root.dataset.defaultView === 'grid' ? 'grid' : 'list';
    root.querySelector(`[data-fv-view="${defaultView}"]`)?.click();
    update();
  };

  document.querySelectorAll('[data-file-vault]').forEach(initialize);
})();
