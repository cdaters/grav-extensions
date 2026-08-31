import {SourceDocumentAdapter} from './source-document.js';
import {SourceEditorAdapter} from './source-adapter.js';
import {VisualEditorAdapter} from './visual-adapter.js';

const MODES = new Set(['source', 'visual']);

export async function sha256Source(source) {
  if (!globalThis.crypto?.subtle) throw new Error('Web Crypto SHA-256 is required.');
  const digest = await globalThis.crypto.subtle.digest('SHA-256', new TextEncoder().encode(source));
  return Array.from(new Uint8Array(digest), (byte) => byte.toString(16).padStart(2, '0')).join('');
}

export class DualModeEditorSession {
  static async create(source, options = {}) {
    const identity = await sha256Source(source);
    return new DualModeEditorSession(source, identity, options);
  }

  constructor(source, identity, options = {}) {
    this.baselineSource = source;
    this.baselineIdentity = identity;
    this.currentSource = source;
    this.currentIdentity = identity;
    this.mode = options.mode ?? 'source';
    if (!MODES.has(this.mode)) throw new TypeError('Unsupported editor mode.');
    this.readOnly = options.readOnly === true;
    this.contentDirty = false;
    this.dirtyReason = null;
    this.stale = false;
    this.uiRevision = 0;
    this.#rebuildAdapters();
  }

  #rebuildAdapters() {
    this.document = new SourceDocumentAdapter(this.currentSource);
    this.visual = new VisualEditorAdapter(this.document, {readOnly: this.readOnly});
    this.source = new SourceEditorAdapter(this.currentSource, {readOnly: this.readOnly});
  }

  value() {
    return this.currentSource;
  }

  isDirty() {
    return this.contentDirty;
  }

  isStale() {
    return this.stale;
  }

  switchMode(mode) {
    if (!MODES.has(mode)) throw new TypeError('Unsupported editor mode.');
    this.mode = mode;
    return {mode: this.mode, dirty: this.contentDirty, identity: this.currentIdentity};
  }

  noteUiStateChange() {
    this.uiRevision += 1;
    return {uiRevision: this.uiRevision, dirty: this.contentDirty};
  }

  setReadOnly(value) {
    this.readOnly = Boolean(value);
    this.visual.setReadOnly(this.readOnly);
    this.source.setReadOnly(this.readOnly);
  }

  async sourceEdit(from, to, insert) {
    this.#assertWritable();
    const updated = this.source.applyChange(from, to, insert);
    await this.#acceptContent(updated, 'source-edit');
    return this.currentSource;
  }

  async replaceBlockText(blockId, text) {
    return this.#visualEdit(blockId, () => this.visual.replaceBlockText(blockId, text), 'visual-edit');
  }

  async toggleInlineMark(blockId, from, to, markName) {
    return this.#visualEdit(
      blockId,
      () => this.visual.toggleInlineMark(blockId, from, to, markName),
      'visual-edit'
    );
  }

  async updateLink(blockId, from, to, href, title = null) {
    return this.#visualEdit(
      blockId,
      () => this.visual.updateLink(blockId, from, to, href, title),
      'visual-edit'
    );
  }

  async insertListItem(blockId, text, index = null) {
    return this.#visualEdit(blockId, () => this.visual.insertListItem(blockId, text, index), 'visual-edit');
  }

  async removeListItem(blockId, index) {
    return this.#visualEdit(blockId, () => this.visual.removeListItem(blockId, index), 'visual-edit');
  }

  async editCodeBlock(blockId, text) {
    return this.#visualEdit(blockId, () => this.visual.editCodeBlock(blockId, text), 'visual-edit');
  }

  async #visualEdit(blockId, operation, reason) {
    this.#assertWritable();
    const expected = this.currentSource;
    const replacement = operation();
    const updated = this.document.applyPatch(blockId, replacement, expected);
    await this.#acceptContent(updated, reason);
    return this.currentSource;
  }

  async #acceptContent(source, reason) {
    this.currentSource = source;
    this.currentIdentity = await sha256Source(source);
    this.contentDirty = source !== this.baselineSource;
    this.dirtyReason = this.contentDirty ? reason : null;
    this.#rebuildAdapters();
  }

  async observeCanonicalSource(source, identity = null) {
    const observedIdentity = identity ?? await sha256Source(source);
    if (observedIdentity === this.baselineIdentity && source === this.baselineSource) {
      this.stale = false;
      return false;
    }
    if (this.contentDirty) {
      this.stale = true;
      return true;
    }
    this.baselineSource = source;
    this.baselineIdentity = observedIdentity;
    this.currentSource = source;
    this.currentIdentity = observedIdentity;
    this.stale = false;
    this.#rebuildAdapters();
    return false;
  }

  sourceSelectionToVisual(from, to = from) {
    return this.visual.sourceSelectionToVisual(from, to);
  }

  visualSelectionToSource(from, to = from) {
    return this.visual.visualSelectionToSource(from, to);
  }

  selectionContext(from, to = from, maximumContextUnits = 4096) {
    if (this.document.overlapsOpaque(from, to)) return null;
    const containing = this.document.safeBlocks().find((block) => from >= block.start && to <= block.end);
    if (!containing) return null;
    const contextStart = Math.max(0, containing.start - maximumContextUnits);
    const contextEnd = Math.min(this.currentSource.length, containing.end + maximumContextUnits);
    return {
      selection: {from, to, source: this.currentSource.slice(from, to)},
      block: {id: containing.id, kind: containing.kind, from: containing.start, to: containing.end},
      context: {from: contextStart, to: contextEnd, source: this.currentSource.slice(contextStart, contextEnd)},
      sourceIdentity: this.currentIdentity,
    };
  }

  adapterContract() {
    return Object.freeze({
      input: 'canonical-string',
      output: 'changed-canonical-string',
      change: 'intentional-content-only',
      dirty: this.contentDirty,
      mode: this.mode,
      focus: 'adapter-owned',
      readOnly: this.readOnly,
      selection: 'utf16-source-offsets-with-safe-failure',
      lifecycle: ['mount', 'focus', 'destroy'],
      theme: 'host-css-inheritance-no-shadow-root',
    });
  }

  #assertWritable() {
    if (this.readOnly) throw new Error('READ_ONLY');
    if (this.stale) throw new Error('STALE_SOURCE');
  }
}
