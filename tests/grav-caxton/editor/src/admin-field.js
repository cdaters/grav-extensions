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
  rule: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 12h16"/></svg>',
  media: '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="8" cy="10" r="1.5"/><path d="m5 17 4-4 3 3 3-3 4 4"/></svg>',
  sparkles: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m12 3 1.2 3.8L17 8l-3.8 1.2L12 13l-1.2-3.8L7 8l3.8-1.2zM18 14l.8 2.2L21 17l-2.2.8L18 20l-.8-2.2L15 17l2.2-.8zM5 13l.7 1.8 1.8.7-1.8.7L5 18l-.7-1.8-1.8-.7 1.8-.7z"/></svg>',
  source: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m8 8-4 4 4 4m8-8 4 4-4 4M14 5l-4 14"/></svg>',
};

const DEFAULT_TOOLBAR = Object.freeze([
  'undo', 'redo', 'separator', 'heading', 'separator', 'bold', 'italic', 'strikethrough', 'inline_code',
  'remove_format', 'separator', 'link', 'blockquote', 'bullet_list', 'ordered_list',
  'horizontal_rule', 'code_block', 'media', 'separator', 'jarvis', 'source',
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
  horizontalRule: 'horizontal_rule',
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
const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (character) => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[character]));
const normalizeReferenceId = (value) => String(value ?? '').trim().toLowerCase().replace(/[^a-z0-9._-]+/g, '-').replace(/^-|-$/g, '').slice(0, 80);

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
    this.allowJarvis = this._field?.caxton?.allow_jarvis === true;
    this.toolbarItems = normalizeToolbar(this._field?.caxton?.toolbar, this.allowSource)
      .filter((item) => item !== 'jarvis' || this.allowJarvis);
    this.sourceEnabled = this.allowSource && this.toolbarItems.includes('source');
    this.installStyles();
    this.renderShell();
    this.rebuildAdapters();
    this.mountMode();
    this.refreshStatus();
    this.updateCoordinateMap();
    if (this.allowJarvis) this.loadJarvisStatus();
    this.getContentHandler = () => this.publishContent();
    this.insertContentHandler = (event) => this.receiveContent(event);
    window.addEventListener('grav:editor:get-content', this.getContentHandler);
    window.addEventListener('grav:editor:insert-content', this.insertContentHandler);
  }

  disconnectedCallback() {
    this.visual?.destroy();
    this.source?.destroy();
    this.abortController?.abort();
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
      .cx-visual .ProseMirror > * { margin-block:0; }
      .cx-visual .ProseMirror > :first-child { margin-top:0 !important; }
      .cx-visual .ProseMirror > :last-child { margin-bottom:0 !important; }
      .cx-visual .ProseMirror p { margin-block:0 1rem; }
      .cx-visual .ProseMirror h1,.cx-visual .ProseMirror h2,.cx-visual .ProseMirror h3,.cx-visual .ProseMirror h4,.cx-visual .ProseMirror h5,.cx-visual .ProseMirror h6 { margin-block:1.65em .55em; line-height:1.2; letter-spacing:-.02em; }
      .cx-visual .ProseMirror h1 { font-size:2rem; font-weight:750; }
      .cx-visual .ProseMirror h2 { font-size:1.55rem; font-weight:720; }
      .cx-visual .ProseMirror h3 { font-size:1.25rem; font-weight:700; }
      .cx-visual .ProseMirror h4 { font-size:1.08rem; font-weight:700; }
      .cx-visual .ProseMirror h5 { font-size:1rem; font-weight:700; }
      .cx-visual .ProseMirror h6 { font-size:.92rem; font-weight:700; letter-spacing:.01em; }
      .cx-visual .ProseMirror a { color:var(--cx-accent); text-decoration:underline; }
      .cx-visual .ProseMirror blockquote { margin:1.1rem 0; padding:.2rem 1rem; border-inline-start:.24rem solid var(--cx-accent); color:color-mix(in srgb,var(--cx-text) 82%,var(--cx-muted)); }
      .cx-visual .ProseMirror ul,.cx-visual .ProseMirror ol { margin-block:0 1rem; padding-inline-start:1.7rem; }
      .cx-visual .ProseMirror ul { list-style:disc outside; }
      .cx-visual .ProseMirror ol { list-style:decimal outside; }
      .cx-visual .ProseMirror li { display:list-item; margin-block:.25rem; }
      .cx-visual .ProseMirror li > p { margin:0; }
      .cx-visual .ProseMirror li > ul,.cx-visual .ProseMirror li > ol { margin-block:.3rem 0; }
      .cx-visual .ProseMirror code { padding:.1em .3em; border-radius:.25rem; background:color-mix(in srgb,currentColor 8%,transparent); }
      .cx-visual .ProseMirror pre { margin-block:0 1.15rem; overflow:auto; padding:1rem; border-radius:.55rem; background:#15151d; color:#f2f0ff; }
      .cx-visual .ProseMirror hr { margin-block:1.65rem; border:0; border-top:1px solid var(--cx-border); }
      .cx-visual [data-caxton-media] { display:inline-flex; margin-block:.25rem 1rem; padding:.4rem .65rem; border:1px dashed var(--cx-border); border-radius:.4rem; color:var(--cx-muted); }
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
      .cx-dialog-backdrop { position:fixed; z-index:1000; inset:0; display:grid; place-items:center; padding:1rem; background:rgb(0 0 0 / .55); }
      .cx-dialog-backdrop[hidden] { display:none; }
      .cx-dialog { width:min(44rem,100%); max-height:min(82vh,52rem); overflow:auto; padding:1rem; border:1px solid var(--cx-border); border-radius:.75rem; color:var(--cx-text); background:var(--cx-raised); box-shadow:0 1.25rem 4rem rgb(0 0 0 / .35); }
      .cx-dialog h2 { margin:0 0 .35rem; font-size:1rem; }
      .cx-dialog p { margin:.25rem 0 .8rem; color:var(--cx-muted); font-size:.82rem; }
      .cx-dialog-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:.65rem; }
      .cx-dialog label { display:grid; gap:.25rem; color:var(--cx-muted); font-size:.75rem; font-weight:650; }
      .cx-dialog input,.cx-dialog select,.cx-dialog textarea { width:100%; min-height:2.35rem; padding:.45rem .55rem; border:1px solid var(--cx-border); border-radius:.4rem; color:var(--cx-text); background:var(--cx-bg); font:inherit; }
      .cx-dialog textarea { min-height:7rem; resize:vertical; }
      .cx-dialog-actions { display:flex; justify-content:flex-end; gap:.45rem; margin-top:.85rem; }
      .cx-media-list { display:grid; grid-template-columns:repeat(auto-fill,minmax(8rem,1fr)); gap:.55rem; margin:.75rem 0; }
      .cx-media-card { display:grid; gap:.35rem; min-width:0; padding:.45rem; border:1px solid var(--cx-border); border-radius:.5rem; color:var(--cx-text); background:var(--cx-panel); text-align:start; cursor:pointer; }
      .cx-media-card[aria-pressed="true"] { border-color:var(--cx-accent); box-shadow:0 0 0 2px color-mix(in srgb,var(--cx-accent) 20%,transparent); }
      .cx-media-card img { width:100%; aspect-ratio:4/3; object-fit:cover; border-radius:.3rem; background:var(--cx-bg); }
      .cx-media-card span { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; font-size:.72rem; }
      .cx-jarvis-result { margin-top:.8rem; padding:.75rem; border:1px solid var(--cx-border); border-radius:.5rem; background:var(--cx-panel); }
      .cx-jarvis-result pre { max-height:18rem; overflow:auto; margin:0; color:var(--cx-text); background:transparent; white-space:pre-wrap; font:inherit; }
      .cx-jarvis-meta { margin-top:.5rem; color:var(--cx-muted); font-size:.72rem; }
      .cx-footer { display:flex; align-items:center; gap:.7rem; min-height:2.25rem; padding:.35rem .7rem; border-top:1px solid var(--cx-border); color:var(--cx-muted); background:var(--cx-panel); font-size:.75rem; }
      .cx-footer [data-caxton-state] { margin-inline-start:auto; }
      .cx-error { color:#dc2626; }
      @media (max-width:640px) { .cx-toolbar{align-items:flex-start}.cx-spacer{display:none}.cx-switch{margin-inline-start:auto}.cx-visual .ProseMirror{padding:1.25rem 1rem}.cx-style{max-width:7.5rem}.cx-link-grid,.cx-dialog-grid{grid-template-columns:1fr} }
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
            <label>Reference ID (optional)<input type="text" data-caxton-link-reference maxlength="80" placeholder="source-name" autocomplete="off"></label>
            <label>Reference URL<input type="text" inputmode="url" data-caxton-link-reference-url placeholder="https://example.com" autocomplete="off"></label>
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
      <div class="cx-dialog-backdrop" data-caxton-media-dialog hidden><section class="cx-dialog" role="dialog" aria-modal="true" aria-label="Insert page media">
        <h2>Page media</h2><p>Choose media already attached to this page, then set its accessible text and optional title.</p>
        <div class="cx-media-list" data-caxton-media-list></div>
        <div class="cx-dialog-grid"><label>Alt or link text<input data-caxton-media-alt maxlength="500"></label><label>Optional title<input data-caxton-media-title maxlength="500"></label></div>
        <div class="cx-dialog-actions"><button class="cx-link-action" type="button" data-caxton-media-cancel>Cancel</button><button class="cx-link-action" type="button" data-caxton-media-insert data-primary>Insert media</button></div>
      </section></div>
      <div class="cx-dialog-backdrop" data-caxton-code-dialog hidden><section class="cx-dialog" role="dialog" aria-modal="true" aria-label="Code block language">
        <h2>Code block language</h2><p>Use a short language identifier such as html, css, javascript, php, yaml, or text.</p>
        <label>Language<input data-caxton-code-language maxlength="64" placeholder="text"></label>
        <div class="cx-dialog-actions"><button class="cx-link-action" type="button" data-caxton-code-cancel>Cancel</button><button class="cx-link-action" type="button" data-caxton-code-apply data-primary>Apply</button></div>
      </section></div>
      <div class="cx-dialog-backdrop" data-caxton-jarvis-dialog hidden><section class="cx-dialog" role="dialog" aria-modal="true" aria-label="Jarvis writing assistance">
        <h2>Jarvis</h2><p data-caxton-jarvis-context>Choose an action for the current selection or editable block. Nothing is saved automatically.</p>
        <div class="cx-dialog-grid"><label>Action<select data-caxton-jarvis-action></select></label><label>Provider<select data-caxton-jarvis-provider></select></label><label>Model<select data-caxton-jarvis-model><option value="">Provider default</option></select></label><label data-caxton-custom-label hidden>Custom instruction<input data-caxton-jarvis-custom maxlength="4000"></label></div>
        <div class="cx-dialog-actions"><button class="cx-link-action" type="button" data-caxton-jarvis-cancel>Close</button><button class="cx-link-action" type="button" data-caxton-jarvis-run data-primary>Generate proposal</button></div>
        <div class="cx-jarvis-result" data-caxton-jarvis-result hidden><strong>Original</strong><pre data-caxton-jarvis-original></pre><strong>Proposed</strong><pre data-caxton-jarvis-output></pre><div class="cx-jarvis-meta" data-caxton-jarvis-meta></div><div class="cx-dialog-actions"><button class="cx-link-action" type="button" data-caxton-jarvis-reject>Reject</button><button class="cx-link-action" type="button" data-caxton-jarvis-accept data-primary>Accept into unsaved buffer</button></div></div>
      </section></div>
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
    this.querySelector('[data-caxton-media-cancel]').addEventListener('click', () => this.closeDialog('media'));
    this.querySelector('[data-caxton-media-insert]').addEventListener('click', () => this.insertSelectedMedia());
    this.querySelector('[data-caxton-code-cancel]').addEventListener('click', () => this.closeDialog('code'));
    this.querySelector('[data-caxton-code-apply]').addEventListener('click', () => this.applyCodeLanguage());
    this.querySelector('[data-caxton-jarvis-cancel]').addEventListener('click', () => this.closeJarvis());
    this.querySelector('[data-caxton-jarvis-run]').addEventListener('click', () => this.runJarvis());
    this.querySelector('[data-caxton-jarvis-reject]').addEventListener('click', () => this.rejectJarvis());
    this.querySelector('[data-caxton-jarvis-accept]').addEventListener('click', () => this.acceptJarvis());
    this.querySelector('[data-caxton-jarvis-action]').addEventListener('change', () => this.refreshJarvisAction());
    this.querySelector('[data-caxton-jarvis-provider]').addEventListener('change', () => this.loadJarvisModels());
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
      horizontal_rule: ['Horizontal rule', ICONS.rule, ''],
      code_block: ['Code block', ICONS.codeBlock, ''],
      media: ['Page media', ICONS.media, ''],
      jarvis: ['Jarvis', ICONS.sparkles, ''],
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
      onRequestMedia: (blockId) => this.openMediaDialog(blockId),
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
    for (const control of this.querySelectorAll('[data-action]:not([data-action="style"])')) {
      const sourceCapable = control.dataset.action === 'jarvis'
        || (control.dataset.action === 'undo' && this.current === this.jarvisHistory?.after)
        || (control.dataset.action === 'redo' && this.current === this.jarvisHistory?.before);
      control.disabled = this.readOnly || (!sourceCapable && visualOnly);
    }
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
    const handled = action === 'undo' ? (this.undoJarvis() || this.visual?.undo())
      : action === 'redo' ? (this.redoJarvis() || this.visual?.redo())
        : action === 'bold' ? this.visual?.toggleSelectionMark('strong')
          : action === 'italic' ? this.visual?.toggleSelectionMark('em')
            : action === 'inline_code' ? this.visual?.toggleSelectionMark('code')
              : action === 'strikethrough' ? this.visual?.toggleSelectionMark('strikethrough')
              : action === 'remove_format' ? this.visual?.removeSelectionFormatting()
                : action === 'link' ? this.openLinkEditor()
                  : action === 'blockquote' ? this.visual?.toggleBlockquote()
                    : action === 'bullet_list' ? this.visual?.toggleList('bullet_list')
                      : action === 'ordered_list' ? this.visual?.toggleList('ordered_list')
                        : action === 'code_block' ? this.openCodeDialog()
                          : action === 'horizontal_rule' ? this.insertHorizontalRule()
                            : action === 'media' ? this.openMediaDialog()
                              : action === 'jarvis' ? this.openJarvis()
                          : false;
    if (!handled) this.showMessage('Select editable text to use that tool.', true);
  }

  selectionContext(maximumUnits = 65_536) {
    const selection = this.mode === 'visual' && this.visual
      ? this.visual.visualSelectionToSource()
      : this.source?.selection();
    if (!selection || !this.documentAdapter) return null;
    if (this.documentAdapter.overlapsOpaque(selection.from, selection.to)) return null;
    const block = this.documentAdapter.safeBlocks().find((item) => selection.from >= item.start && selection.to <= item.end);
    if (!block || !['paragraph', 'heading', 'blockquote', 'bullet_list', 'ordered_list', 'code_block', 'horizontal_rule'].includes(block.kind)) return null;
    const target = selection.from === selection.to ? {from: block.start, to: block.end} : selection;
    const from = Math.max(0, block.start - maximumUnits);
    const to = Math.min(this.current.length, block.end + maximumUnits);
    return {selection, target, block, context: {from, to}};
  }

  replaceSourceRange(from, to, replacement, message = 'Content updated') {
    if (this.readOnly || !Number.isInteger(from) || !Number.isInteger(to) || from < 0 || to < from || to > this.current.length
      || typeof replacement !== 'string' || replacement.includes('\u0000')) return false;
    const updated = this.current.slice(0, from) + replacement + this.current.slice(to);
    this.current = updated;
    this.rebuildAdapters();
    this.mountMode({from: from + replacement.length, to: from + replacement.length});
    this.emitChange();
    this.showMessage(message);
    return true;
  }

  undoJarvis() {
    if (!this.jarvisHistory || this.current !== this.jarvisHistory.after) return false;
    const history = this.jarvisHistory;
    this.current = history.before;
    this.rebuildAdapters();
    this.mountMode({from: history.selection.from, to: history.selection.to});
    this.emitChange();
    this.showMessage('Jarvis proposal undone');
    return true;
  }

  redoJarvis() {
    if (!this.jarvisHistory || this.current !== this.jarvisHistory.before) return false;
    const history = this.jarvisHistory;
    this.current = history.after;
    this.rebuildAdapters();
    this.mountMode({from: history.afterSelection.from, to: history.afterSelection.to});
    this.emitChange();
    this.showMessage('Jarvis proposal redone');
    return true;
  }

  insertHorizontalRule() {
    const context = this.selectionContext();
    if (!context || context.block.kind === 'horizontal_rule') return false;
    const before = this.current.slice(0, context.block.end);
    const after = this.current.slice(context.block.end);
    const leading = before.endsWith('\n\n') ? '' : before.endsWith('\n') ? '\n' : '\n\n';
    const trailing = after.startsWith('\n\n') ? '' : after.startsWith('\n') ? '\n' : '\n\n';
    return this.replaceSourceRange(context.block.end, context.block.end, `${leading}---${trailing}`, 'Horizontal rule inserted');
  }

  openCodeDialog() {
    if (this.mode !== 'visual' || !this.visual || this.readOnly) return false;
    const state = this.visual.selectionState();
    if (!['paragraph', 'heading', 'code_block'].includes(state.block)) return false;
    this.querySelector('[data-caxton-code-language]').value = state.codeLanguage || '';
    this.querySelector('[data-caxton-code-dialog]').hidden = false;
    this.querySelector('[data-caxton-code-language]').focus();
    return true;
  }

  applyCodeLanguage() {
    const input = this.querySelector('[data-caxton-code-language]');
    const language = input.value.trim().toLowerCase();
    if (language && !/^[a-z0-9][a-z0-9_+.-]{0,63}$/.test(language)) {
      this.showMessage('Use a short code-language identifier.', true);
      return;
    }
    const state = this.visual?.selectionState();
    const handled = state?.block === 'code_block'
      ? this.visual.setCodeLanguage(language)
      : this.visual?.toggleCodeBlock(language);
    if (!handled) { this.showMessage('Choose a paragraph, heading, or code block.', true); return; }
    this.closeDialog('code');
    this.showMessage(language ? `Code language set to ${language}` : 'Code block inserted');
  }

  closeDialog(kind) {
    this.querySelector(`[data-caxton-${kind}-dialog]`)?.setAttribute('hidden', '');
    this.visual?.focus();
  }

  openMediaDialog(blockId = null) {
    const clickedBlock = blockId ? this.documentAdapter?.block(blockId) : null;
    const context = clickedBlock?.safe
      ? {selection: {from: clickedBlock.start, to: clickedBlock.end}, target: {from: clickedBlock.start, to: clickedBlock.end}, block: clickedBlock, context: {from: clickedBlock.start, to: clickedBlock.end}}
      : this.selectionContext();
    if (!context || this.mode !== 'visual' || this.readOnly) return false;
    const provider = window.__GRAV_PAGE_MEDIA;
    const items = typeof provider === 'function' ? provider() : [];
    if (!Array.isArray(items) || items.length === 0) {
      this.showMessage('Add media to this page first, then choose Page media again.', true);
      return true;
    }
    this.mediaItems = items.filter((item) => item && typeof item.filename === 'string').slice(0, 500);
    this.selectedMedia = this.mediaItems[0] || null;
    this.mediaEditRange = null;
    this.mediaEditValues = null;
    const match = context.block.source.match(/^!\[([^\]]*)\]\((\S+?)(?:\s+["']([^"']*)["'])?\)\s*$/);
    if (match) {
      const decoded = (() => { try { return decodeURIComponent(match[2]); } catch { return match[2]; } })();
      const existing = this.mediaItems.find((item) => item.filename === decoded);
      if (existing) this.selectedMedia = existing;
      this.mediaEditRange = {from: context.block.start, to: context.block.end};
      this.mediaEditValues = {alt: match[1], title: match[3] || ''};
    }
    this.renderMediaList();
    this.querySelector('[data-caxton-media-dialog]').hidden = false;
    return true;
  }

  renderMediaList() {
    const list = this.querySelector('[data-caxton-media-list]');
    list.innerHTML = this.mediaItems.map((item, index) => `<button type="button" class="cx-media-card" data-media-index="${index}" aria-pressed="${item === this.selectedMedia}">${item.thumb ? `<img src="${escapeHtml(item.thumb)}" alt="">` : ''}<span title="${escapeHtml(item.filename)}">${escapeHtml(item.filename)}</span></button>`).join('');
    list.onclick = (event) => {
      const card = event.target.closest('[data-media-index]');
      if (!card) return;
      this.selectedMedia = this.mediaItems[Number(card.dataset.mediaIndex)] || null;
      this.querySelector('[data-caxton-media-alt]').value = this.selectedMedia?.alt || '';
      this.querySelector('[data-caxton-media-title]').value = this.selectedMedia?.title || '';
      for (const button of list.querySelectorAll('[data-media-index]')) button.setAttribute('aria-pressed', String(button === card));
    };
    this.querySelector('[data-caxton-media-alt]').value = this.mediaEditValues?.alt || this.selectedMedia?.alt || '';
    this.querySelector('[data-caxton-media-title]').value = this.mediaEditValues?.title || this.selectedMedia?.title || '';
  }

  insertSelectedMedia() {
    const context = this.selectionContext();
    const item = this.selectedMedia;
    if (!context || !item) { this.showMessage('Choose page media to insert.', true); return; }
    const alt = this.querySelector('[data-caxton-media-alt]').value.replace(/[\[\]\r\n]/g, ' ').trim();
    const title = this.querySelector('[data-caxton-media-title]').value.replace(/["\r\n]/g, ' ').trim();
    const filename = item.filename.replace(/[()\s]/g, (character) => encodeURIComponent(character));
    const isImage = String(item.type || '').startsWith('image') || /\.(?:avif|gif|jpe?g|png|svg|webp)$/i.test(item.filename);
    const reference = `${isImage ? '!' : ''}[${alt || item.filename}](${filename}${title ? ` "${title}"` : ''})`;
    const insertion = this.mediaEditRange
      ? {from: this.mediaEditRange.from, to: this.mediaEditRange.to, value: reference}
      : context.selection.from === context.selection.to
      ? {from: context.block.end, to: context.block.end, value: `${this.current.slice(0, context.block.end).endsWith('\n\n') ? '' : '\n\n'}${reference}\n`}
      : {from: context.selection.from, to: context.selection.to, value: reference};
    if (this.replaceSourceRange(insertion.from, insertion.to, insertion.value, 'Page media inserted')) this.closeDialog('media');
  }

  openLinkEditor() {
    const state = this.visual?.linkState();
    if (this.mode !== 'visual' || !state?.selected || this.readOnly) {
      this.showMessage('Select editable text before adding a link.', true);
      return false;
    }
    this.querySelector('[data-caxton-link-url]').value = state.href;
    this.querySelector('[data-caxton-link-title]').value = state.title;
    this.querySelector('[data-caxton-link-reference]').value = '';
    this.querySelector('[data-caxton-link-reference-url]').value = '';
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
    const referenceId = normalizeReferenceId(this.querySelector('[data-caxton-link-reference]').value);
    const referenceUrl = this.querySelector('[data-caxton-link-reference-url]').value.trim();
    if (referenceId) {
      const context = this.selectionContext();
      if (!context || context.selection.from === context.selection.to) {
        this.showMessage('Select editable text before adding a reference link.', true);
        return;
      }
      const selected = this.current.slice(context.selection.from, context.selection.to).replace(/[\[\]\r\n]/g, ' ');
      const definition = referenceUrl
        ? `\n\n[${referenceId}]: ${referenceUrl}${title ? ` "${title.replace(/"/g, '')}"` : ''}\n`
        : '';
      if (!referenceUrl && !new RegExp(`^\\[${referenceId.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}\\]:`, 'mi').test(this.current)) {
        this.showMessage('Enter a safe reference URL or use an existing reference ID.', true);
        return;
      }
      if (referenceUrl && !/^(?:https?:\/\/|mailto:|\/|#|\.\.?\/)/i.test(referenceUrl)) {
        this.showMessage('Enter a safe HTTP(S), mail, anchor, or site-relative reference URL.', true);
        return;
      }
      const replacement = `[${selected}][${referenceId}]`;
      const updated = this.current.slice(0, context.selection.from) + replacement + this.current.slice(context.selection.to) + definition;
      this.current = updated;
      this.rebuildAdapters();
      this.mountMode();
      this.emitChange();
      this.closeLinkEditor(true);
      this.showMessage('Reference link applied');
      return;
    }
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

  authHeaders(json = false) {
    let token = window.__GRAV_API_TOKEN || '';
    let environment = '';
    try {
      const auth = JSON.parse(localStorage.getItem('grav_admin_auth') || '{}');
      token ||= auth.accessToken || '';
      environment = auth.environment || '';
    } catch {}
    const headers = {Accept: 'application/json'};
    if (json) headers['Content-Type'] = 'application/json';
    if (token) headers['X-API-Token'] = token;
    if (environment) headers['X-Grav-Environment'] = environment;
    return headers;
  }

  async api(path, options = {}) {
    const server = window.__GRAV_API_SERVER_URL || '';
    const prefix = window.__GRAV_API_PREFIX || '/api/v1';
    const response = await fetch(`${server}${prefix}${path}`, {
      method: options.method || 'GET',
      headers: this.authHeaders(options.body !== undefined),
      body: options.body === undefined ? undefined : JSON.stringify(options.body),
      credentials: 'omit', cache: 'no-store', signal: options.signal,
    });
    const text = await response.text();
    let payload = {};
    try { payload = text ? JSON.parse(text) : {}; } catch {}
    if (!response.ok) {
      const error = payload.error || payload;
      throw new Error(error.detail || error.message || error.title || `Request failed (${response.status}).`);
    }
    return payload.data ?? payload;
  }

  async loadJarvisStatus() {
    const button = this.querySelector('[data-action="jarvis"]');
    if (!button) return;
    button.hidden = true;
    button.disabled = true;
    try {
      this.jarvisStatus = await this.api('/grav-caxton/jarvis/status');
      if (!this.jarvisStatus?.available || this.jarvisStatus.state !== 'ready') {
        button.hidden = true;
        return;
      }
      button.disabled = this.readOnly;
      button.hidden = false;
      button.title = 'Jarvis writing assistance';
      const action = this.querySelector('[data-caxton-jarvis-action]');
      action.innerHTML = this.jarvisStatus.actions.map((item) => `<option value="${escapeHtml(item.id)}">${escapeHtml(item.label)}</option>`).join('');
      const provider = this.querySelector('[data-caxton-jarvis-provider]');
      provider.innerHTML = this.jarvisStatus.providers.map((item) => `<option value="${escapeHtml(item.id)}">${escapeHtml(item.id)}</option>`).join('');
      await this.loadJarvisModels();
    } catch {
      button.hidden = true;
      this.jarvisStatus = null;
    }
  }

  async loadJarvisModels() {
    const provider = this.querySelector('[data-caxton-jarvis-provider]')?.value;
    const select = this.querySelector('[data-caxton-jarvis-model]');
    if (!provider || !select) return;
    select.innerHTML = '<option value="">Provider default</option>';
    try {
      const catalog = await this.api(`/grav-caxton/jarvis/providers/${encodeURIComponent(provider)}/models`);
      for (const model of catalog.models || []) {
        const id = model.id || model.model_id;
        if (!id) continue;
        select.insertAdjacentHTML('beforeend', `<option value="${escapeHtml(id)}">${escapeHtml(model.label || model.name || id)}</option>`);
      }
    } catch {}
  }

  openJarvis() {
    if (!this.jarvisStatus?.available || this.readOnly) return false;
    const context = this.selectionContext();
    if (!context || !['paragraph', 'heading', 'blockquote', 'bullet_list', 'ordered_list', 'code_block'].includes(context.block.kind)) {
      this.showMessage('Select text or place the cursor in a safe prose block before using Jarvis.', true);
      return true;
    }
    this.jarvisContext = context;
    this.jarvisProposal = null;
    this.querySelector('[data-caxton-jarvis-result]').hidden = true;
    this.querySelector('[data-caxton-jarvis-context]').textContent = context.selection.from === context.selection.to
      ? `Jarvis will use the current ${context.block.kind.replace('_', ' ')} block.`
      : 'Jarvis will use only the selected text; surrounding prose is context only.';
    this.querySelector('[data-caxton-jarvis-dialog]').hidden = false;
    this.refreshJarvisAction();
    if (context.block.kind === 'code_block') {
      for (const option of this.querySelector('[data-caxton-jarvis-action]').options) {
        option.disabled = !['explain', 'summarize', 'custom'].includes(option.value);
      }
      if (this.querySelector('[data-caxton-jarvis-action]').selectedOptions[0]?.disabled) {
        this.querySelector('[data-caxton-jarvis-action]').value = 'explain';
      }
      this.refreshJarvisAction();
    } else {
      for (const option of this.querySelector('[data-caxton-jarvis-action]').options) option.disabled = false;
    }
    return true;
  }

  refreshJarvisAction() {
    const custom = this.querySelector('[data-caxton-jarvis-action]')?.value === 'custom';
    this.querySelector('[data-caxton-custom-label]').hidden = !custom;
  }

  async runJarvis() {
    const context = this.jarvisContext;
    if (!context || this.coordinateMap?.source !== this.current) {
      this.showMessage('The editor selection changed. Reopen Jarvis and try again.', true);
      return;
    }
    const targetBytes = this.coordinateMap.selectionToBytes(context.target.from, context.target.to);
    const contextBytes = this.coordinateMap.selectionToBytes(context.context.from, context.context.to);
    if (!targetBytes || !contextBytes) { this.showMessage('This selection cannot be mapped safely.', true); return; }
    const run = this.querySelector('[data-caxton-jarvis-run]');
    run.disabled = true;
    run.textContent = 'Working…';
    try {
      const body = {
        route: this.editorRoute(), source: this.current, source_sha256: this.coordinateMap.identity,
        from: targetBytes.from, to: targetBytes.to, context_from: contextBytes.from, context_to: contextBytes.to,
        block_kind: context.block.kind, action: this.querySelector('[data-caxton-jarvis-action]').value,
        provider_id: this.querySelector('[data-caxton-jarvis-provider]').value,
        model: this.querySelector('[data-caxton-jarvis-model]').value || null,
        custom_instruction: this.querySelector('[data-caxton-jarvis-custom]').value || null,
      };
      this.jarvisProposal = await this.api('/grav-caxton/jarvis/proposals', {method: 'POST', body});
      const originalFrom = this.coordinateMap.byteToUtf16(this.jarvisProposal.target.from);
      const originalTo = this.coordinateMap.byteToUtf16(this.jarvisProposal.target.to);
      this.querySelector('[data-caxton-jarvis-original]').textContent = originalFrom === null || originalTo === null
        ? 'Original selection unavailable'
        : this.current.slice(originalFrom, originalTo);
      this.querySelector('[data-caxton-jarvis-output]').textContent = this.jarvisProposal.output;
      const usage = this.jarvisProposal.usage || {};
      const cost = this.jarvisProposal.cost || {};
      const units = usage.total_units ?? usage.total ?? 'unreported';
      const amount = cost.estimated_amount ?? cost.authoritative_amount ?? null;
      this.querySelector('[data-caxton-jarvis-meta]').textContent = `${this.jarvisProposal.provider_id}${this.jarvisProposal.model ? ` · ${this.jarvisProposal.model}` : ''} · usage ${units}${amount !== null ? ` · estimated cost ${amount} ${cost.currency || ''}` : ''}`;
      const accept = this.querySelector('[data-caxton-jarvis-accept]');
      accept.hidden = !this.jarvisProposal.accept_allowed;
      this.querySelector('[data-caxton-jarvis-result]').hidden = false;
    } catch (error) {
      this.showMessage(error.message || 'Jarvis could not create a proposal.', true);
    } finally {
      run.disabled = false;
      run.textContent = 'Generate proposal';
    }
  }

  async acceptJarvis() {
    const proposal = this.jarvisProposal;
    if (!proposal || this.coordinateMap?.source !== this.current) { this.showMessage('This proposal is stale. Generate it again.', true); return; }
    try {
      const result = await this.api(`/grav-caxton/jarvis/proposals/${proposal.proposal_id}/accept`, {method: 'POST', body: {
        route: this.editorRoute(), source: this.current, source_sha256: this.coordinateMap.identity,
        from: proposal.target.from, to: proposal.target.to, output: proposal.output,
      }});
      const from = this.coordinateMap.byteToUtf16(result.from);
      const to = this.coordinateMap.byteToUtf16(result.to);
      const before = this.current;
      const after = from === null || to === null ? null : before.slice(0, from) + result.replacement + before.slice(to);
      if (from === null || to === null || after === null) {
        throw new Error('The accepted range could not be mapped safely.');
      }
      this.jarvisHistory = {
        before, after, selection: {from, to},
        afterSelection: {from: from + result.replacement.length, to: from + result.replacement.length},
      };
      if (!this.replaceSourceRange(from, to, result.replacement, 'Jarvis proposal accepted into the unsaved buffer')) {
        throw new Error('The accepted range could not be mapped safely.');
      }
      this.jarvisProposal = null;
      this.closeDialog('jarvis');
    } catch (error) {
      this.showMessage(error.message || 'The Jarvis proposal is stale.', true);
    }
  }

  async rejectJarvis() {
    const proposal = this.jarvisProposal;
    this.jarvisProposal = null;
    this.querySelector('[data-caxton-jarvis-result]').hidden = true;
    if (!proposal?.proposal_id) return;
    try { await this.api(`/grav-caxton/jarvis/proposals/${proposal.proposal_id}/discard`, {method: 'POST', body: {route: this.editorRoute()}}); }
    catch {}
  }

  async closeJarvis() {
    await this.rejectJarvis();
    this.closeDialog('jarvis');
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
    if (undoButton) undoButton.disabled = this.readOnly || (!state.canUndo && this.current !== this.jarvisHistory?.after);
    if (redoButton) redoButton.disabled = this.readOnly || (!state.canRedo && this.current !== this.jarvisHistory?.before);
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
