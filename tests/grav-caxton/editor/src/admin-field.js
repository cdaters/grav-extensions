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
  code: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9 7-5 5 5 5m6-10 5 5-5 5"/></svg>',
  source: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m8 8-4 4 4 4m8-8 4 4-4 4M14 5l-4 14"/></svg>',
};

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
      ${TAG} { display:block; color:inherit; }
      .cx-shell { --cx-accent:var(--color-primary-500,#7c3aed); --cx-border:color-mix(in srgb,currentColor 17%,transparent); --cx-muted:color-mix(in srgb,currentColor 62%,transparent); --cx-panel:color-mix(in srgb,Canvas 96%,currentColor 4%); overflow:hidden; border:1px solid var(--cx-border); border-radius:.75rem; background:Canvas; box-shadow:0 1px 2px color-mix(in srgb,currentColor 7%,transparent); }
      .cx-toolbar { display:flex; align-items:center; gap:.25rem; min-height:3rem; padding:.4rem .55rem; border-bottom:1px solid var(--cx-border); background:var(--cx-panel); flex-wrap:wrap; }
      .cx-group { display:flex; align-items:center; gap:.15rem; }
      .cx-group + .cx-group { margin-inline-start:.25rem; padding-inline-start:.5rem; border-inline-start:1px solid var(--cx-border); }
      .cx-spacer { flex:1 1 1rem; }
      .cx-button,.cx-mode,.cx-style { min-height:2.1rem; border:1px solid transparent; border-radius:.4rem; color:inherit; background:transparent; font:inherit; }
      .cx-button { display:grid; place-items:center; width:2.1rem; padding:.38rem; cursor:pointer; }
      .cx-button svg { width:1.05rem; height:1.05rem; fill:none; stroke:currentColor; stroke-width:1.8; stroke-linecap:round; stroke-linejoin:round; }
      .cx-mode svg { width:.9rem; height:.9rem; margin-inline-end:.25rem; vertical-align:-.15rem; fill:none; stroke:currentColor; stroke-width:1.8; stroke-linecap:round; stroke-linejoin:round; }
      .cx-button:hover,.cx-button:focus-visible,.cx-mode:hover,.cx-mode:focus-visible,.cx-style:hover,.cx-style:focus-visible { border-color:var(--cx-border); background:color-mix(in srgb,var(--cx-accent) 10%,transparent); outline:none; }
      .cx-button:disabled,.cx-style:disabled { opacity:.4; cursor:not-allowed; }
      .cx-style { max-width:9.5rem; padding:.3rem 1.8rem .3rem .55rem; cursor:pointer; }
      .cx-switch { display:flex; padding:.16rem; border:1px solid var(--cx-border); border-radius:.55rem; background:color-mix(in srgb,currentColor 5%,transparent); }
      .cx-mode { padding:.25rem .6rem; cursor:pointer; font-size:.78rem; font-weight:650; }
      .cx-mode[aria-pressed="true"] { color:Canvas; background:var(--cx-accent); }
      .cx-surface { min-height:26rem; background:Canvas; }
      .cx-editor { min-height:26rem; }
      .cx-visual .ProseMirror { min-height:26rem; padding:2rem clamp(1rem,5vw,4.5rem); outline:none; line-height:1.72; font-size:1rem; }
      .cx-visual .ProseMirror > :first-child { margin-top:0; }
      .cx-visual .ProseMirror h1,.cx-visual .ProseMirror h2,.cx-visual .ProseMirror h3 { line-height:1.2; letter-spacing:-.02em; }
      .cx-visual .ProseMirror h1 { font-size:2rem; font-weight:750; }
      .cx-visual .ProseMirror h2 { font-size:1.55rem; font-weight:720; }
      .cx-visual .ProseMirror h3 { font-size:1.25rem; font-weight:700; }
      .cx-visual .ProseMirror h4 { font-size:1.08rem; font-weight:700; }
      .cx-visual .ProseMirror a { color:var(--cx-accent); text-decoration:underline; }
      .cx-visual .ProseMirror code { padding:.1em .3em; border-radius:.25rem; background:color-mix(in srgb,currentColor 8%,transparent); }
      .cx-visual .ProseMirror pre { overflow:auto; padding:1rem; border-radius:.55rem; background:#15151d; color:#f2f0ff; }
      .cx-visual [data-caxton-media] { display:inline-flex; padding:.4rem .65rem; border:1px dashed var(--cx-border); border-radius:.4rem; color:var(--cx-muted); }
      .cx-visual [data-caxton-opaque] { position:relative; margin:1.25rem 0; padding:1rem 1rem 1rem 1.2rem; overflow:hidden; border:1px solid var(--cx-border); border-inline-start:.28rem solid #64748b; border-radius:.55rem; background:color-mix(in srgb,#64748b 8%,Canvas); }
      .cx-visual [data-caxton-opaque="html"] { border-inline-start-color:#3b82f6; background:color-mix(in srgb,#3b82f6 8%,Canvas); }
      .cx-visual [data-caxton-opaque="twig"] { border-inline-start-color:#f59e0b; background:color-mix(in srgb,#f59e0b 9%,Canvas); }
      .cx-visual [data-caxton-opaque="shortcode"] { border-inline-start-color:#10b981; background:color-mix(in srgb,#10b981 8%,Canvas); }
      .cx-visual [data-caxton-opaque="table"] { border-inline-start-color:#8b5cf6; background:color-mix(in srgb,#8b5cf6 8%,Canvas); }
      .cx-visual [data-caxton-opaque] strong { display:block; margin-bottom:.45rem; font-size:.72rem; letter-spacing:.08em; text-transform:uppercase; }
      .cx-visual [data-caxton-opaque] pre { max-height:9rem; margin:0; padding:0; overflow:auto; color:inherit; background:transparent; font:500 .8rem/1.5 ui-monospace,SFMono-Regular,Menlo,monospace; white-space:pre-wrap; }
      .cx-source .cm-editor { min-height:26rem; background:Canvas; }
      .cx-source .cm-scroller { min-height:26rem; padding:1rem 0; font:500 .9rem/1.65 ui-monospace,SFMono-Regular,Menlo,monospace; }
      .cx-source .cm-content { padding-inline:1rem; }
      .cx-source .cm-gutters { border:0; background:var(--cx-panel); color:var(--cx-muted); }
      .cx-footer { display:flex; align-items:center; gap:.7rem; min-height:2.25rem; padding:.35rem .7rem; border-top:1px solid var(--cx-border); color:var(--cx-muted); background:var(--cx-panel); font-size:.75rem; }
      .cx-footer [data-caxton-state] { margin-inline-start:auto; }
      .cx-error { color:#dc2626; }
      @media (max-width:640px) { .cx-toolbar{align-items:flex-start}.cx-spacer{display:none}.cx-switch{margin-inline-start:auto}.cx-visual .ProseMirror{padding:1.25rem 1rem}.cx-style{max-width:7.5rem} }
    `;
    document.head.appendChild(style);
  }

  renderShell() {
    this.innerHTML = `<section class="cx-shell" data-caxton-field>
      <div class="cx-toolbar" role="toolbar" aria-label="Caxton editor tools">
        <div class="cx-group">
          <button class="cx-button" type="button" data-action="undo" title="Undo" aria-label="Undo">${ICONS.undo}</button>
          <button class="cx-button" type="button" data-action="redo" title="Redo" aria-label="Redo">${ICONS.redo}</button>
        </div>
        <div class="cx-group">
          <select class="cx-style" data-action="style" aria-label="Text style">
            <option value="paragraph">Paragraph</option><option value="heading-1">Heading 1</option><option value="heading-2">Heading 2</option><option value="heading-3">Heading 3</option><option value="heading-4">Heading 4</option>
          </select>
        </div>
        <div class="cx-group">
          <button class="cx-button" type="button" data-action="strong" title="Bold" aria-label="Bold">${ICONS.bold}</button>
          <button class="cx-button" type="button" data-action="em" title="Italic" aria-label="Italic">${ICONS.italic}</button>
          <button class="cx-button" type="button" data-action="code" title="Inline code" aria-label="Inline code">${ICONS.code}</button>
        </div>
        <div class="cx-spacer"></div>
        <div class="cx-switch" role="group" aria-label="Editing mode">
          <button class="cx-mode" type="button" data-mode="visual" aria-pressed="true">Visual</button>
          ${this.allowSource ? `<button class="cx-mode" type="button" data-mode="source" aria-pressed="false">${ICONS.source} Source</button>` : ''}
        </div>
      </div>
      <div class="cx-surface"><div class="cx-editor" data-editor></div></div>
      <footer class="cx-footer" aria-live="polite"><span data-caxton-summary>Source faithful</span><span data-caxton-protected></span><span data-caxton-state>Saved value unchanged</span></footer>
    </section>`;
    this.editorHost = this.querySelector('[data-editor]');
    this.querySelector('.cx-toolbar').addEventListener('click', (event) => this.handleToolbar(event));
    this.querySelector('[data-action="style"]').addEventListener('change', (event) => {
      if (!this.visual?.setTextStyle(event.target.value)) this.showMessage('Choose a text block before changing its style.', true);
    });
  }

  rebuildAdapters() {
    this.visual?.destroy();
    this.source?.destroy();
    try {
      this.documentAdapter = new SourceDocumentAdapter(this.current);
      this.visual = new VisualEditorAdapter(this.documentAdapter, {
        readOnly: this.readOnly,
        onChange: ({blockId, replacement}) => this.acceptVisualPatch(blockId, replacement),
        onReject: (message) => this.showMessage(message, true),
      });
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
    this.querySelector('[data-action="style"]').disabled = visualOnly;
  }

  handleToolbar(event) {
    const modeButton = event.target.closest('[data-mode]');
    if (modeButton) { this.switchMode(modeButton.dataset.mode); return; }
    const button = event.target.closest('[data-action]');
    if (!button || button.tagName === 'SELECT') return;
    const action = button.dataset.action;
    const handled = action === 'undo' ? this.visual?.undo()
      : action === 'redo' ? this.visual?.redo()
        : this.visual?.toggleSelectionMark(action);
    if (!handled) this.showMessage('Select editable text to use that tool.', true);
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

  acceptVisualPatch(blockId, replacement) {
    const updated = this.documentAdapter.applyPatch(blockId, replacement, this.current);
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
      this.visual = new VisualEditorAdapter(this.documentAdapter, {
        readOnly: this.readOnly,
        onChange: ({blockId, replacement}) => this.acceptVisualPatch(blockId, replacement),
        onReject: (message) => this.showMessage(message, true),
      });
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

export {CaxtonField};
