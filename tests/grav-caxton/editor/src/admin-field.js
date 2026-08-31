import {SourceCoordinateMap} from './coordinates.js';
import {SourceEditorAdapter} from './source-adapter.js';
import {SourceDocumentAdapter} from './source-document.js';
import {VisualEditorAdapter} from './visual-adapter.js';

const TAG = window.__GRAV_FIELD_TAG;
const STYLE_ID = 'grav-caxton-admin-field-styles';

const ICONS = {
  undo: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 7 4 12l5 5M5 12h8a6 6 0 0 1 6 6"/></svg>',
  redo: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m15 7 5 5-5 5m4-5h-8a6 6 0 0 0-6 6"/></svg>',
  bold: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 5h6a4 4 0 0 1 0 8H7zm0 8h7a4 4 0 0 1 0 8H7z"/></svg>',
  italic: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 5h8M6 19h8M14 5 10 19"/></svg>',
  strikethrough: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16.5 6.5A5 5 0 0 0 13 5h-2a3 3 0 0 0-3 3c0 1.7 1.3 2.6 4 3M5 12h14M8 17.5A5 5 0 0 0 11 19h2a3 3 0 0 0 3-3"/></svg>',
  code: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9 7-5 5 5 5m6-10 5 5-5 5"/></svg>',
  removeFormat: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m5 5 14 14M9 6h9M7 18h6M14 6l-2.3 7.4"/></svg>',
  link: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 13a5 5 0 0 0 7.1 0l2-2a5 5 0 0 0-7.1-7.1l-1.1 1.1M14 11a5 5 0 0 0-7.1 0l-2 2A5 5 0 0 0 12 20.1l1.1-1.1"/></svg>',
  quote: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 11H5a5 5 0 0 1 5-5v9a3 3 0 0 1-3 3M21 11h-5a5 5 0 0 1 5-5v9a3 3 0 0 1-3 3"/></svg>',
  bulletList: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 6h11M9 12h11M9 18h11"/><circle cx="4" cy="6" r="1"/><circle cx="4" cy="12" r="1"/><circle cx="4" cy="18" r="1"/></svg>',
  orderedList: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 6h10M10 12h10M10 18h10M4 5h2v3M4 12h2l-2 3h2M4 18h2v3H4"/></svg>',
  codeBlock: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 5h14v14H5zM9 9l-2 3 2 3M13 15h3"/></svg>',
  source: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m8 8-4 4 4 4m8-8 4 4-4 4M14 5l-4 14"/></svg>',
};

const DEFAULT_TOOLBAR = Object.freeze([
  'undo', 'redo', 'separator', 'heading', 'separator', 'bold', 'italic', 'strikethrough', 'inline_code',
  'remove_format', 'separator', 'link', 'blockquote', 'bullet_list', 'ordered_list',
  'code_block', 'separator', 'source',
]);
const TOOLBAR_ALIASES = Object.freeze({
  '|': 'separator',
  removeformat: 'remove_format',
  code: 'inline_code',
  strike: 'strikethrough',
  inlineCode: 'inline_code',
  bulletList: 'bullet_list',
  orderedList: 'ordered_list',
  codeBlock: 'code_block',
});
const TOOLBAR_ITEMS = new Set(DEFAULT_TOOLBAR);

function normalizeToolbar(value, allowSource = true) {
  const supplied = Array.isArray(value) ? value : (typeof value === 'string' ? value.split(',') : DEFAULT_TOOLBAR);
  const normalized = [];
  for (const entry of supplied.slice(0, 48)) {
    if (typeof entry !== 'string') continue;
    const raw = entry.trim();
    const item = TOOLBAR_ALIASES[raw] ?? raw;
    if (!TOOLBAR_ITEMS.has(item) || (!allowSource && item === 'source')) continue;
    if (item === 'separator' && (normalized.length === 0 || normalized.at(-1) === 'separator')) continue;
    normalized.push(item);
  }
  while (normalized.at(-1) === 'separator') normalized.pop();
  return normalized.length > 0 ? normalized : DEFAULT_TOOLBAR.filter((item) => allowSource || item !== 'source');
}

const button = (action, label, icon, shortcut = '') => `<button class="cx-button" type="button" data-action="${action}" title="${label}${shortcut ? ` (${shortcut})` : ''}" aria-label="${label}" aria-pressed="false">${icon}</button>`;

class CaxtonField extends HTMLElement {
  constructor() {
    super();
    this._field = null;
    this._value = '';
    this.current = '';
    this.baseline = '';
    this.mode = 'visual';
    this.initialized = false;
    this.syncing = false;
    this.documentAdapter = null;
    this.visual = null;
    this.source = null;
    this.coordinateMap = null;
    this.coordinateRevision = 0;
  }

  set field(value) { this._field = value; }
  get field() { return this._field; }
  set value(value) {
    const next = typeof value === 'string' ? value : String(value ?? '');
    this._value = next;
    if (this.initialized && !this.syncing && next !== this.current) this.acceptExternalValue(next);
  }
  get value() { return this.current || this._value; }

  connectedCallback() {
    if (this.initialized) return;
    this.initialized = true;
    this.current = typeof this._value === 'string' ? this._value : '';
    this.baseline = this.current;
    this.readOnly = Boolean(this._field?.readonly || this._field?.disabled);
    this.allowSource = this._field?.caxton?.allow_source !== false;
    this.toolbarItems = normalizeToolbar(this._field?.caxton?.toolbar, this.allowSource);
    this.sourceEnabled = this.allowSource && this.toolbarItems.includes('source');
    this.installStyles();
    this.renderShell();
    this.rebuildAdapters();
    this.mountMode();
    this.refreshStatus();
    this.updateCoordinateMap();
    this.getContentHandler = () => this.publishContent();
    this.insertContentHandler = (event) => this.receiveContent(event);
    window.addEventListener('grav:editor:get-content', this.getContentHandler);
    window.addEventListener('grav:editor:insert-content', this.insertContentHandler);
  }

  disconnectedCallback() {
    this.visual?.destroy();
    this.source?.destroy();
    window.removeEventListener('grav:editor:get-content', this.getContentHandler);
    window.removeEventListener('grav:editor:insert-content', this.insertContentHandler);
    this.initialized = false;
  }

  installStyles() {
    if (document.getElementById(STYLE_ID)) return;
    const style = document.createElement('style');
    style.id = STYLE_ID;
    style.textContent = `
      ${TAG} { --cx-accent:var(--color-primary-500,#7c3aed); --cx-bg:#ffffff; --cx-panel:#f7f7f8; --cx-raised:#ffffff; --cx-text:#18181b; --cx-muted:#71717a; --cx-border:#d4d4d8; --cx-on-accent:#ffffff; display:block; color:var(--cx-text); color-scheme:light; }
      :where(.dark,[data-theme="dark"],[data-color-scheme="dark"]) ${TAG},${TAG}.dark,${TAG}[data-theme="dark"] { --cx-bg:#18181b; --cx-panel:#222226; --cx-raised:#29292e; --cx-text:#f4f4f5; --cx-muted:#a1a1aa; --cx-border:#3f3f46; --cx-on-accent:#ffffff; color-scheme:dark; }
      .cx-shell { position:relative; overflow:hidden; border:1px solid var(--cx-border); border-radius:.75rem; color:var(--cx-text); background:var(--cx-bg); box-shadow:0 1px 2px rgb(0 0 0 / .08); }
      .cx-toolbar { position:relative; display:flex; align-items:center; gap:.25rem; min-height:3rem; padding:.4rem .55rem; border-bottom:1px solid var(--cx-border); color:var(--cx-text); background:var(--cx-panel); flex-wrap:wrap; }
      .cx-tools { display:flex; align-items:center; gap:.15rem; flex-wrap:wrap; }
      .cx-separator { align-self:stretch; width:1px; min-height:1.5rem; margin:.25rem .35rem; background:var(--cx-border); }
      .cx-spacer { flex:1 1 1rem; }
      .cx-button,.cx-mode,.cx-style { min-height:2.1rem; border:1px solid transparent; border-radius:.4rem; color:var(--cx-text); background:transparent; font:inherit; }
      .cx-button { display:grid; place-items:center; width:2.1rem; padding:.38rem; cursor:pointer; }
      .cx-button svg { width:1.05rem; height:1.05rem; fill:none; stroke:currentColor; stroke-width:1.8; stroke-linecap:round; stroke-linejoin:round; }
      .cx-mode svg { width:.9rem; height:.9rem; margin-inline-end:.25rem; vertical-align:-.15rem; fill:none; stroke:currentColor; stroke-width:1.8; stroke-linecap:round; stroke-linejoin:round; }
      .cx-button:hover,.cx-button:focus-visible,.cx-mode:hover,.cx-mode:focus-visible,.cx-style:hover,.cx-style:focus-visible { border-color:var(--cx-border); background:color-mix(in srgb,var(--cx-accent) 10%,transparent); outline:none; }
      .cx-button[aria-pressed="true"] { color:var(--cx-accent); border-color:color-mix(in srgb,var(--cx-accent) 35%,var(--cx-border)); background:color-mix(in srgb,var(--cx-accent) 12%,transparent); }
      .cx-button:disabled,.cx-style:disabled { opacity:.4; cursor:not-allowed; }
      .cx-style { max-width:9.5rem; padding:.3rem 1.8rem .3rem .55rem; cursor:pointer; }
      .cx-switch { display:flex; padding:.16rem; border:1px solid var(--cx-border); border-radius:.55rem; background:color-mix(in srgb,currentColor 5%,transparent); }
      .cx-mode { padding:.25rem .6rem; cursor:pointer; font-size:.78rem; font-weight:650; }
      .cx-mode[aria-pressed="true"] { color:var(--cx-on-accent); background:var(--cx-accent); }
      .cx-surface { min-height:26rem; color:var(--cx-text); background:var(--cx-bg); }
      .cx-editor { min-height:26rem; }
      .cx-visual .ProseMirror { min-height:26rem; padding:2rem clamp(1rem,5vw,4.5rem); outline:none; line-height:1.72; font-size:1rem; }
      .cx-visual .ProseMirror > :first-child { margin-top:0; }
      .cx-visual .ProseMirror h1,.cx-visual .ProseMirror h2,.cx-visual .ProseMirror h3 { line-height:1.2; letter-spacing:-.02em; }
      .cx-visual .ProseMirror h1 { font-size:2rem; font-weight:750; }
      .cx-visual .ProseMirror h2 { font-size:1.55rem; font-weight:720; }
      .cx-visual .ProseMirror h3 { font-size:1.25rem; font-weight:700; }
      .cx-visual .ProseMirror h4 { font-size:1.08rem; font-weight:700; }
      .cx-visual .ProseMirror a { color:var(--cx-accent); text-decoration:underline; }
      .cx-visual .ProseMirror blockquote { margin:1.1rem 0; padding:.2rem 1rem; border-inline-start:.24rem solid var(--cx-accent); color:color-mix(in srgb,var(--cx-text) 82%,var(--cx-muted)); }
      .cx-visual .ProseMirror ul,.cx-visual .ProseMirror ol { padding-inline-start:1.7rem; }
      .cx-visual .ProseMirror code { padding:.1em .3em; border-radius:.25rem; background:color-mix(in srgb,currentColor 8%,transparent); }
      .cx-visual .ProseMirror pre { overflow:auto; padding:1rem; border-radius:.55rem; background:#15151d; color:#f2f0ff; }
      .cx-visual [data-caxton-media] { display:inline-flex; padding:.4rem .65rem; border:1px dashed var(--cx-border); border-radius:.4rem; color:var(--cx-muted); }
      .cx-visual [data-caxton-opaque] { position:relative; margin:1.25rem 0; padding:1rem 1rem 1rem 1.2rem; overflow:hidden; border:1px solid var(--cx-border); border-inline-start:.28rem solid #64748b; border-radius:.55rem; background:color-mix(in srgb,#64748b 10%,var(--cx-bg)); }
      .cx-visual [data-caxton-opaque="html"] { border-inline-start-color:#3b82f6; background:color-mix(in srgb,#3b82f6 10%,var(--cx-bg)); }
      .cx-visual [data-caxton-opaque="twig"] { border-inline-start-color:#f59e0b; background:color-mix(in srgb,#f59e0b 11%,var(--cx-bg)); }
      .cx-visual [data-caxton-opaque="shortcode"] { border-inline-start-color:#10b981; background:color-mix(in srgb,#10b981 10%,var(--cx-bg)); }
      .cx-visual [data-caxton-opaque="table"] { border-inline-start-color:#8b5cf6; background:color-mix(in srgb,#8b5cf6 10%,var(--cx-bg)); }
      .cx-visual [data-caxton-opaque] strong { display:block; margin-bottom:.45rem; font-size:.72rem; letter-spacing:.08em; text-transform:uppercase; }
      .cx-visual [data-caxton-opaque] pre { max-height:9rem; margin:0; padding:0; overflow:auto; color:inherit; background:transparent; font:500 .8rem/1.5 ui-monospace,SFMono-Regular,Menlo,monospace; white-space:pre-wrap; }
      .cx-source .cm-editor { min-height:26rem; color:var(--cx-text); background:var(--cx-bg); }
      .cx-source .cm-scroller { min-height:26rem; padding:1rem 0; font:500 .9rem/1.65 ui-monospace,SFMono-Regular,Menlo,monospace; }
      .cx-source .cm-content { padding-inline:1rem; caret-color:var(--cx-text); }
      .cx-source .cm-gutters { border:0; background:var(--cx-panel); color:var(--cx-muted); }
      .cx-source .cm-activeLine,.cx-source .cm-activeLineGutter { background:color-mix(in srgb,var(--cx-accent) 8%,transparent); }
      .cx-source .cm-selectionBackground,.cx-source .cm-content ::selection { background:color-mix(in srgb,var(--cx-accent) 34%,transparent) !important; }
      .cx-source .cm-cursor { border-left-color:var(--cx-text); }
      :where(.dark,[data-theme="dark"],[data-color-scheme="dark"]) ${TAG} .cx-source :where(.tok-heading,.tok-strong) { color:#c4b5fd; }
      :where(.dark,[data-theme="dark"],[data-color-scheme="dark"]) ${TAG} .cx-source :where(.tok-link,.tok-url) { color:#93c5fd; }
      :where(.dark,[data-theme="dark"],[data-color-scheme="dark"]) ${TAG} .cx-source :where(.tok-meta,.tok-comment) { color:#a1a1aa; }
      .cx-link-panel { position:absolute; z-index:30; inset-inline-end:.55rem; top:calc(100% + .35rem); width:min(28rem,calc(100% - 1.1rem)); padding:.8rem; border:1px solid var(--cx-border); border-radius:.6rem; color:var(--cx-text); background:var(--cx-raised); box-shadow:0 .75rem 2rem rgb(0 0 0 / .22); }
      .cx-link-panel[hidden] { display:none; }
      .cx-link-grid { display:grid; grid-template-columns:1fr 1fr; gap:.55rem; }
      .cx-link-panel label { display:grid; gap:.25rem; color:var(--cx-muted); font-size:.72rem; font-weight:650; }
      .cx-link-panel input { min-height:2.25rem; width:100%; padding:.4rem .55rem; border:1px solid var(--cx-border); border-radius:.4rem; color:var(--cx-text); background:var(--cx-bg); font:inherit; }
      .cx-link-panel input:focus-visible { border-color:var(--cx-accent); outline:2px solid color-mix(in srgb,var(--cx-accent) 25%,transparent); outline-offset:1px; }
      .cx-link-actions { display:flex; justify-content:flex-end; gap:.4rem; margin-top:.65rem; }
      .cx-link-action { min-height:2rem; padding:.3rem .65rem; border:1px solid var(--cx-border); border-radius:.4rem; color:var(--cx-text); background:var(--cx-panel); font:inherit; font-size:.78rem; cursor:pointer; }
      .cx-link-action[data-primary] { color:var(--cx-on-accent); border-color:var(--cx-accent); background:var(--cx-accent); }
      .cx-footer { display:flex; align-items:center; gap:.7rem; min-height:2.25rem; padding:.35rem .7rem; border-top:1px solid var(--cx-border); color:var(--cx-muted); background:var(--cx-panel); font-size:.75rem; }
      .cx-footer [data-caxton-state] { margin-inline-start:auto; }
      .cx-error { color:#dc2626; }
      @media (max-width:640px) { .cx-toolbar{align-items:flex-start}.cx-spacer{display:none}.cx-switch{margin-inline-start:auto}.cx-visual .ProseMirror{padding:1.25rem 1rem}.cx-style{max-width:7.5rem}.cx-link-grid{grid-template-columns:1fr} }
    `;
    document.head.appendChild(style);
  }

  renderShell() {
    const tools = this.toolbarItems.filter((item) => item !== 'source').map((item) => this.toolbarItem(item)).join('');
    this.innerHTML = `<section class="cx-shell" data-caxton-field>
      <div class="cx-toolbar" role="toolbar" aria-label="Caxton editor tools">
        <div class="cx-tools">${tools}</div>
        <div class="cx-spacer"></div>
        ${this.sourceEnabled ? `<div class="cx-switch" role="group" aria-label="Editing mode">
          <button class="cx-mode" type="button" data-mode="visual" aria-pressed="true">Visual</button>
          <button class="cx-mode" type="button" data-mode="source" aria-pressed="false">${ICONS.source} Source</button>
        </div>` : ''}
        <div class="cx-link-panel" data-caxton-link-panel role="dialog" aria-label="Edit link" hidden>
          <div class="cx-link-grid">
            <label>Link URL<input type="text" inputmode="url" data-caxton-link-url placeholder="https://example.com or /page" autocomplete="off" required></label>
            <label>Optional title<input type="text" data-caxton-link-title maxlength="240" autocomplete="off"></label>
          </div>
          <div class="cx-link-actions">
            <button class="cx-link-action" type="button" data-caxton-link-remove>Remove link</button>
            <button class="cx-link-action" type="button" data-caxton-link-cancel>Cancel</button>
            <button class="cx-link-action" type="button" data-caxton-link-apply data-primary>Apply link</button>
          </div>
        </div>
      </div>
      <div class="cx-surface"><div class="cx-editor" data-editor></div></div>
      <footer class="cx-footer" aria-live="polite"><span data-caxton-summary>Source faithful</span><span data-caxton-protected></span><span data-caxton-state>Saved value unchanged</span></footer>
    </section>`;
    this.editorHost = this.querySelector('[data-editor]');
    this.querySelector('.cx-toolbar').addEventListener('click', (event) => this.handleToolbar(event));
    this.querySelector('[data-action="style"]')?.addEventListener('change', (event) => {
      if (!this.visual?.setTextStyle(event.target.value)) this.showMessage('Choose a text block before changing its style.', true);
    });
    this.linkPanel = this.querySelector('[data-caxton-link-panel]');
    this.querySelector('[data-caxton-link-apply]').addEventListener('click', (event) => this.applyLink(event));
    this.querySelector('[data-caxton-link-cancel]').addEventListener('click', () => this.closeLinkEditor(true));
    this.querySelector('[data-caxton-link-remove]').addEventListener('click', () => this.removeLink());
    this.linkPanel.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') { event.preventDefault(); this.closeLinkEditor(true); }
    });
  }

  toolbarItem(item) {
    if (item === 'separator') return '<span class="cx-separator" role="separator" aria-orientation="vertical"></span>';
    if (item === 'heading') return `<select class="cx-style" data-action="style" aria-label="Text style">
      <option value="paragraph">Paragraph</option><option value="heading-1">Heading 1</option><option value="heading-2">Heading 2</option><option value="heading-3">Heading 3</option><option value="heading-4">Heading 4</option><option value="heading-5">Heading 5</option><option value="heading-6">Heading 6</option>
    </select>`;
    const items = {
      undo: ['Undo', ICONS.undo, 'Ctrl/⌘ Z'],
      redo: ['Redo', ICONS.redo, 'Ctrl/⌘ Shift Z'],
      bold: ['Bold', ICONS.bold, 'Ctrl/⌘ B'],
      italic: ['Italic', ICONS.italic, 'Ctrl/⌘ I'],
      strikethrough: ['Strikethrough', ICONS.strikethrough, 'Ctrl/⌘ Shift X'],
      inline_code: ['Inline code', ICONS.code, ''],
      remove_format: ['Clear formatting', ICONS.removeFormat, ''],
      link: ['Link', ICONS.link, 'Ctrl/⌘ K'],
      blockquote: ['Blockquote', ICONS.quote, 'Ctrl/⌘ Shift B'],
      bullet_list: ['Bullet list', ICONS.bulletList, 'Ctrl/⌘ Shift 8'],
      ordered_list: ['Numbered list', ICONS.orderedList, 'Ctrl/⌘ Shift 7'],
      code_block: ['Code block', ICONS.codeBlock, ''],
    };
    const definition = items[item];
    return definition ? button(item, ...definition) : '';
  }

  rebuildAdapters() {
    this.visual?.destroy();
    this.source?.destroy();
    try {
      this.documentAdapter = new SourceDocumentAdapter(this.current);
      this.visual = this.createVisualAdapter(this.documentAdapter);
      this.source = new SourceEditorAdapter(this.current, {
        readOnly: this.readOnly,
        onChange: (value) => { if (!this.syncing) this.acceptSourceEdit(value); },
      });
      this.showMessage('Source faithful');
    } catch (error) {
      this.documentAdapter = null;
      this.visual = null;
      this.showMessage('Visual mode is unavailable for this source. Source text remains unchanged.', true);
    }
  }

  createVisualAdapter(documentAdapter) {
    return new VisualEditorAdapter(documentAdapter, {
      readOnly: this.readOnly,
      onChange: ({blockId, endBlockId, replacement}) => this.acceptVisualPatch(blockId, replacement, endBlockId),
      onReject: (message) => this.showMessage(message, true),
      onSelectionChange: (state) => this.updateToolbarState(state),
      onRequestLink: () => this.openLinkEditor(),
    });
  }

  mountMode(selection = null) {
    this.visual?.destroy();
    this.source?.destroy();
    this.editorHost.replaceChildren();
    this.editorHost.className = `cx-editor cx-${this.mode}`;
    if (this.mode === 'visual' && this.visual) {
      this.visual.mount(this.editorHost);
      if (selection) this.visual.setSelection(selection.from, selection.to);
    } else if (this.source) {
      this.mode = 'source';
      this.source.mount(this.editorHost);
      if (selection) this.source.setSelection(selection.from, selection.to);
    }
    for (const button of this.querySelectorAll('[data-mode]')) button.setAttribute('aria-pressed', String(button.dataset.mode === this.mode));
    const visualOnly = this.mode !== 'visual' || !this.visual || this.readOnly;
    for (const control of this.querySelectorAll('[data-action]:not([data-action="style"])')) control.disabled = visualOnly;
    const style = this.querySelector('[data-action="style"]');
    if (style) style.disabled = visualOnly;
    if (!visualOnly) this.updateToolbarState(this.visual.selectionState());
  }

  handleToolbar(event) {
    const modeButton = event.target.closest('[data-mode]');
    if (modeButton) { this.switchMode(modeButton.dataset.mode); return; }
    const button = event.target.closest('[data-action]');
    if (!button || button.tagName === 'SELECT') return;
    const action = button.dataset.action;
    const handled = action === 'undo' ? this.visual?.undo()
      : action === 'redo' ? this.visual?.redo()
        : action === 'bold' ? this.visual?.toggleSelectionMark('strong')
          : action === 'italic' ? this.visual?.toggleSelectionMark('em')
            : action === 'inline_code' ? this.visual?.toggleSelectionMark('code')
              : action === 'strikethrough' ? this.visual?.toggleSelectionMark('strikethrough')
              : action === 'remove_format' ? this.visual?.removeSelectionFormatting()
                : action === 'link' ? this.openLinkEditor()
                  : action === 'blockquote' ? this.visual?.toggleBlockquote()
                    : action === 'bullet_list' ? this.visual?.toggleList('bullet_list')
                      : action === 'ordered_list' ? this.visual?.toggleList('ordered_list')
                        : action === 'code_block' ? this.visual?.toggleCodeBlock()
                          : false;
    if (!handled) this.showMessage('Select editable text to use that tool.', true);
  }

  openLinkEditor() {
    const state = this.visual?.linkState();
    if (this.mode !== 'visual' || !state?.selected || this.readOnly) {
      this.showMessage('Select editable text before adding a link.', true);
      return false;
    }
    this.querySelector('[data-caxton-link-url]').value = state.href;
    this.querySelector('[data-caxton-link-title]').value = state.title;
    this.querySelector('[data-caxton-link-remove]').hidden = !state.href;
    this.linkPanel.hidden = false;
    this.querySelector('[data-caxton-link-url]').focus();
    return true;
  }

  closeLinkEditor(returnFocus = false) {
    this.linkPanel.hidden = true;
    if (returnFocus) this.visual?.focus();
  }

  applyLink(event) {
    event?.preventDefault();
    const href = this.querySelector('[data-caxton-link-url]').value.trim();
    const title = this.querySelector('[data-caxton-link-title]').value.trim();
    if (!this.visual?.updateSelectionLink(href, title || null)) {
      this.showMessage('Enter a safe HTTP(S), mail, anchor, or site-relative link.', true);
      return;
    }
    this.closeLinkEditor(true);
    this.showMessage('Link applied');
  }

  removeLink() {
    if (!this.visual?.removeSelectionLink()) {
      this.showMessage('Select linked text before removing a link.', true);
      return;
    }
    this.closeLinkEditor(true);
    this.showMessage('Link removed');
  }

  updateToolbarState(state = {}) {
    const active = {
      bold: state.strong,
      italic: state.em,
      inline_code: state.code,
      strikethrough: state.strikethrough,
      link: state.link,
      blockquote: state.block === 'blockquote',
      bullet_list: state.block === 'bullet_list',
      ordered_list: state.block === 'ordered_list',
      code_block: state.block === 'code_block',
    };
    for (const [action, pressed] of Object.entries(active)) {
      this.querySelector(`[data-action="${action}"]`)?.setAttribute('aria-pressed', String(Boolean(pressed)));
    }
    const style = this.querySelector('[data-action="style"]');
    if (style && (state.block === 'paragraph' || state.heading)) {
      style.value = state.heading ? `heading-${state.heading}` : 'paragraph';
    }
    const undoButton = this.querySelector('[data-action="undo"]');
    const redoButton = this.querySelector('[data-action="redo"]');
    const linkButton = this.querySelector('[data-action="link"]');
    if (undoButton) undoButton.disabled = this.readOnly || !state.canUndo;
    if (redoButton) redoButton.disabled = this.readOnly || !state.canRedo;
    if (linkButton) linkButton.disabled = this.readOnly || !state.canLink;
  }

  switchMode(next) {
    if (next === this.mode || (next === 'source' && !this.allowSource)) return;
    let selection = null;
    if (this.mode === 'visual' && this.visual) selection = this.visual.visualSelectionToSource();
    if (this.mode === 'source' && this.source) selection = this.source.selection();
    this.mode = next === 'visual' && this.visual ? 'visual' : 'source';
    this.mountMode(selection);
    this.refreshStatus();
  }

  acceptVisualPatch(blockId, replacement, endBlockId = blockId) {
    const updated = endBlockId === blockId
      ? this.documentAdapter.applyPatch(blockId, replacement, this.current)
      : this.documentAdapter.applyRangePatch(blockId, endBlockId, replacement, this.current);
    const nextDocument = new SourceDocumentAdapter(updated);
    this.current = updated;
    this.documentAdapter = nextDocument;
    this.syncing = true;
    this.source.replaceValue(updated);
    this.syncing = false;
    this.emitChange();
    return nextDocument;
  }

  acceptSourceEdit(value) {
    this.current = value;
    try {
      this.documentAdapter = new SourceDocumentAdapter(value);
      this.visual?.destroy();
      this.visual = this.createVisualAdapter(this.documentAdapter);
      this.showMessage('Source faithful');
    } catch {
      this.documentAdapter = null;
      this.visual = null;
      this.showMessage('Visual mode is unavailable for this source. Source text remains editable.', true);
    }
    this.emitChange();
  }

  acceptExternalValue(value) {
    this.current = value;
    this.baseline = value;
    this.rebuildAdapters();
    this.mountMode();
    this.refreshStatus();
    this.updateCoordinateMap();
  }

  replaceContent(value) {
    if (this.readOnly || typeof value !== 'string' || value.includes('\u0000')) return false;
    this.current = value;
    this.rebuildAdapters();
    this.mountMode();
    this.emitChange();
    return true;
  }

  emitChange() {
    this._value = this.current;
    this.updateCoordinateMap();
    this.refreshStatus();
    this.dispatchEvent(new CustomEvent('change', {detail: this.current, bubbles: true, composed: true}));
  }

  updateCoordinateMap() {
    const revision = ++this.coordinateRevision;
    SourceCoordinateMap.create(this.current).then((map) => {
      if (revision === this.coordinateRevision) this.coordinateMap = map;
    }).catch(() => { if (revision === this.coordinateRevision) this.coordinateMap = null; });
  }

  publishContent() {
    const visualSelection = this.mode === 'visual' && this.visual ? this.visual.visualSelectionToSource() : null;
    const sourceSelection = this.mode === 'source' && this.source ? this.source.selection() : visualSelection;
    const selectionBytes = sourceSelection && this.coordinateMap?.source === this.current
      ? this.coordinateMap.selectionToBytes(sourceSelection.from, sourceSelection.to)
      : null;
    window.dispatchEvent(new CustomEvent('grav:editor:content-response', {detail: {
      content: this.current,
      route: this.editorRoute(),
      sourceIdentity: this.coordinateMap?.source === this.current ? this.coordinateMap.identity : null,
      selection: sourceSelection,
      selectionBytes,
    }}));
  }

  receiveContent(event) {
    const detail = event.detail || {};
    if (detail.mode !== 'replace' || typeof detail.content !== 'string') return;
    this.replaceContent(detail.content);
  }

  editorRoute() {
    const marker = '/pages/edit/';
    const index = location.pathname.indexOf(marker);
    if (index < 0) return '';
    try { return '/' + location.pathname.slice(index + marker.length).split('/').map(decodeURIComponent).join('/'); }
    catch { return '/' + location.pathname.slice(index + marker.length); }
  }

  showMessage(message, error = false) {
    const target = this.querySelector('[data-caxton-summary]');
    if (!target) return;
    target.textContent = message;
    target.classList.toggle('cx-error', error);
  }

  refreshStatus() {
    const protectedCount = this.documentAdapter?.opaqueBlocks().length ?? 0;
    const protectedTarget = this.querySelector('[data-caxton-protected]');
    const stateTarget = this.querySelector('[data-caxton-state]');
    if (protectedTarget) protectedTarget.textContent = protectedCount > 0 ? `${protectedCount} protected source block${protectedCount === 1 ? '' : 's'}` : 'All blocks visually safe';
    if (stateTarget) stateTarget.textContent = this.current === this.baseline ? 'Saved value unchanged' : 'Unsaved changes';
  }
}

if (!customElements.get(TAG)) customElements.define(TAG, CaxtonField);

export {CaxtonField, DEFAULT_TOOLBAR, normalizeToolbar};
