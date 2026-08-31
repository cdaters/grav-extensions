import {baseKeymap, toggleMark} from 'prosemirror-commands';
import {history, redo, undo} from 'prosemirror-history';
import {keymap} from 'prosemirror-keymap';
import {defaultMarkdownSerializer} from 'prosemirror-markdown';
import {EditorState, TextSelection} from 'prosemirror-state';
import {liftListItem, sinkListItem, splitListItem} from 'prosemirror-schema-list';
import {EditorView} from 'prosemirror-view';
import {caxtonSchema} from './schema.js';
import {safeLinkUrl} from './security.js';

function textEntries(node, nodePosition) {
  const entries = [];
  node.descendants((child, relativePosition) => {
    if (!child.isText) return;
    for (let offset = 0; offset < child.text.length; offset += 1) {
      entries.push({character: child.text[offset], pm: nodePosition + 1 + relativePosition + offset});
    }
  });
  return entries;
}

function normalizedSerializedBlock(node) {
  const temporary = caxtonSchema.nodes.doc.create(null, [node]);
  return defaultMarkdownSerializer.serialize(temporary).replace(/\n$/, '');
}

function serializeCodeBlock(node, descriptor) {
  const metadata = descriptor.metadata;
  if (!metadata?.closed) return normalizedSerializedBlock(node);
  const body = node.textContent.replace(/\r\n|\r|\n/g, metadata.newline);
  return `${metadata.opening}${metadata.newline}${body}${metadata.newline}${metadata.closing}`;
}

export class VisualEditorAdapter {
  constructor(sourceDocument, options = {}) {
    this.sourceDocument = sourceDocument;
    this.readOnly = options.readOnly === true;
    this.view = null;
    this.state = EditorState.create({
      schema: caxtonSchema,
      doc: sourceDocument.visualDocument(),
      plugins: [
        history(),
        keymap({
          'Mod-z': undo,
          'Shift-Mod-z': redo,
          'Mod-y': redo,
          Enter: splitListItem(caxtonSchema.nodes.list_item),
          Tab: sinkListItem(caxtonSchema.nodes.list_item),
          'Shift-Tab': liftListItem(caxtonSchema.nodes.list_item),
        }),
        keymap(baseKeymap),
      ],
    });
    this.#rebuildMappings();
  }

  mount(container) {
    if (!(container instanceof Element)) throw new TypeError('Visual editor mount requires an Element.');
    this.destroy();
    this.view = new EditorView(container, {
      state: this.state,
      editable: () => !this.readOnly,
      attributes: {
        'data-caxton-visual-proof': 'true',
        'aria-label': 'Caxton visual editor proof',
        role: 'textbox',
        'aria-multiline': 'true',
      },
      dispatchTransaction: (transaction) => {
        if (this.readOnly && transaction.docChanged) return;
        this.state = this.state.apply(transaction);
        this.view.updateState(this.state);
        this.#rebuildMappings();
      },
    });
    return this.view;
  }

  focus() {
    this.view?.focus();
  }

  destroy() {
    this.view?.destroy();
    this.view = null;
  }

  setReadOnly(value) {
    this.readOnly = Boolean(value);
    if (this.view) this.view.setProps({editable: () => !this.readOnly});
  }

  #dispatch(transaction, allowReadOnly = false) {
    if (this.readOnly && !allowReadOnly) throw new Error('READ_ONLY');
    this.state = this.state.apply(transaction);
    this.view?.updateState(this.state);
    this.#rebuildMappings();
  }

  #topBlock(blockId) {
    let found = null;
    this.state.doc.forEach((node, offset) => {
      if (node.attrs.blockId === blockId) found = {node, position: offset};
    });
    if (!found) throw new Error('BLOCK_NOT_FOUND');
    return found;
  }

  #textRange(block, from, to) {
    const entries = textEntries(block.node, block.position);
    if (!Number.isInteger(from) || !Number.isInteger(to) || from < 0 || to < from || to > entries.length) {
      throw new RangeError('Invalid visual text range.');
    }
    const finalPosition = entries.length > 0 ? entries.at(-1).pm + 1 : block.position + 1;
    const start = entries[from]?.pm ?? finalPosition;
    const end = to === from ? start : (entries[to - 1]?.pm ?? start) + 1;
    return {start, end};
  }

  replaceBlockText(blockId, text) {
    const block = this.#topBlock(blockId);
    if (!['paragraph', 'heading'].includes(block.node.type.name)) throw new Error('BLOCK_NOT_TEXT_EDITABLE');
    if (typeof text !== 'string' || text === '' || /[\r\n\u0000]/.test(text)) throw new TypeError('Invalid block text.');
    const replacement = block.node.type.create(block.node.attrs, caxtonSchema.text(text), block.node.marks);
    this.#dispatch(this.state.tr.replaceWith(block.position, block.position + block.node.nodeSize, replacement));
    return this.serializeBlock(blockId);
  }

  toggleInlineMark(blockId, from, to, markName) {
    if (!['strong', 'em', 'code'].includes(markName)) throw new TypeError('Unsupported inline mark.');
    const block = this.#topBlock(blockId);
    const range = this.#textRange(block, from, to);
    if (range.start === range.end) throw new RangeError('Inline mark range cannot be empty.');
    let transaction = this.state.tr.setSelection(TextSelection.create(this.state.doc, range.start, range.end));
    const temporaryState = this.state.apply(transaction);
    let result = null;
    toggleMark(caxtonSchema.marks[markName])(temporaryState, (next) => { result = next; });
    if (!result) throw new Error('MARK_TRANSACTION_FAILED');
    this.#dispatch(result);
    return this.serializeBlock(blockId);
  }

  updateLink(blockId, from, to, href, title = null) {
    const safe = safeLinkUrl(href);
    if (safe === null) throw new TypeError('Unsafe link URL.');
    const block = this.#topBlock(blockId);
    const range = this.#textRange(block, from, to);
    const mark = caxtonSchema.marks.link.create({href: safe, title: title || null});
    const transaction = this.state.tr
      .removeMark(range.start, range.end, caxtonSchema.marks.link)
      .addMark(range.start, range.end, mark);
    this.#dispatch(transaction);
    return this.serializeBlock(blockId);
  }

  insertListItem(blockId, text, index = null) {
    const block = this.#topBlock(blockId);
    if (!['bullet_list', 'ordered_list'].includes(block.node.type.name)) throw new Error('BLOCK_NOT_LIST');
    if (typeof text !== 'string' || text.trim() === '' || /[\r\n\u0000]/.test(text)) throw new TypeError('Invalid list item.');
    const paragraph = caxtonSchema.nodes.paragraph.create(null, caxtonSchema.text(text.trim()));
    const item = caxtonSchema.nodes.list_item.create(null, paragraph);
    const children = [];
    block.node.forEach((child) => children.push(child));
    const insertion = index === null ? children.length : index;
    if (!Number.isInteger(insertion) || insertion < 0 || insertion > children.length) throw new RangeError('Invalid list index.');
    children.splice(insertion, 0, item);
    const replacement = block.node.type.create(block.node.attrs, children, block.node.marks);
    this.#dispatch(this.state.tr.replaceWith(block.position, block.position + block.node.nodeSize, replacement));
    return this.serializeBlock(blockId);
  }

  removeListItem(blockId, index) {
    const block = this.#topBlock(blockId);
    if (!['bullet_list', 'ordered_list'].includes(block.node.type.name)) throw new Error('BLOCK_NOT_LIST');
    const children = [];
    block.node.forEach((child) => children.push(child));
    if (!Number.isInteger(index) || index < 0 || index >= children.length || children.length <= 1) {
      throw new RangeError('Invalid list removal.');
    }
    children.splice(index, 1);
    const replacement = block.node.type.create(block.node.attrs, children, block.node.marks);
    this.#dispatch(this.state.tr.replaceWith(block.position, block.position + block.node.nodeSize, replacement));
    return this.serializeBlock(blockId);
  }

  editCodeBlock(blockId, text) {
    const block = this.#topBlock(blockId);
    if (block.node.type.name !== 'code_block') throw new Error('BLOCK_NOT_CODE');
    if (typeof text !== 'string' || text.includes('\u0000') || text.length > 262_144) throw new TypeError('Invalid code content.');
    const replacement = block.node.type.create(block.node.attrs, text === '' ? null : caxtonSchema.text(text));
    this.#dispatch(this.state.tr.replaceWith(block.position, block.position + block.node.nodeSize, replacement));
    return this.serializeBlock(blockId);
  }

  serializeBlock(blockId) {
    const block = this.#topBlock(blockId);
    const descriptor = this.sourceDocument.block(blockId);
    if (!descriptor?.safe) throw new Error('BLOCK_NOT_EDITABLE');
    return block.node.type.name === 'code_block'
      ? serializeCodeBlock(block.node, descriptor)
      : normalizedSerializedBlock(block.node);
  }

  setSelection(from, to = from) {
    const mapped = this.sourceSelectionToVisual(from, to);
    if (!mapped) return false;
    this.#dispatch(this.state.tr.setSelection(TextSelection.create(this.state.doc, mapped.from, mapped.to)), true);
    return true;
  }

  sourceSelectionToVisual(from, to = from) {
    if (!Number.isInteger(from) || !Number.isInteger(to) || from < 0 || to < from) return null;
    if (this.sourceDocument.overlapsOpaque(from, to)) return null;
    const start = this.sourceToPm.get(from);
    const end = this.sourceToPm.get(to);
    if (!Number.isInteger(start) || !Number.isInteger(end)) return null;
    return {from: start, to: end};
  }

  visualSelectionToSource(from = this.state.selection.from, to = this.state.selection.to) {
    if (!Number.isInteger(from) || !Number.isInteger(to) || from < 0 || to < from) return null;
    for (const opaque of this.opaquePositions) {
      if (from < opaque.to && to > opaque.from) return null;
    }
    const starts = this.pmToSource.get(from) ?? [];
    const ends = this.pmToSource.get(to) ?? [];
    if (starts.length === 0 || ends.length === 0) return null;
    if (from === to && new Set(starts).size > 1) return null;
    const start = Math.max(...starts);
    const end = Math.min(...ends);
    if (end < start) return null;
    return {from: start, to: end};
  }

  #rebuildMappings() {
    this.sourceToPm = new Map();
    this.pmToSource = new Map();
    this.opaquePositions = [];
    this.state.doc.forEach((node, position) => {
      const descriptor = this.sourceDocument.block(node.attrs.blockId);
      if (!descriptor) return;
      if (node.type.name === 'opaque_block') {
        this.opaquePositions.push({from: position, to: position + node.nodeSize, descriptor});
        return;
      }
      // Markdown escapes and character references do not have a one-to-one
      // source-unit representation. Refuse mapping for the entire block until
      // a token-aware mapper can prove exact boundaries.
      if (/\\[!"#$%&'()*+,\-./:;<=>?@[\\\]^_`{|}~]|&(?:#[0-9]+|#x[0-9a-f]+|[a-z][a-z0-9]+);/i.test(descriptor.source)) {
        return;
      }
      const entries = textEntries(node, position);
      let sourceCursor = 0;
      for (const entry of entries) {
        const relative = descriptor.source.indexOf(entry.character, sourceCursor);
        if (relative === -1) continue;
        const sourcePosition = descriptor.start + relative;
        this.sourceToPm.set(sourcePosition, entry.pm);
        this.sourceToPm.set(sourcePosition + 1, entry.pm + 1);
        this.#addReverseMapping(entry.pm, sourcePosition);
        this.#addReverseMapping(entry.pm + 1, sourcePosition + 1);
        sourceCursor = relative + 1;
      }
      const contentStart = entries[0]?.pm ?? position + 1;
      const contentEnd = entries.length > 0 ? entries.at(-1).pm + 1 : position + 1;
      this.sourceToPm.set(descriptor.start, contentStart);
      this.sourceToPm.set(descriptor.end, contentEnd);
      this.#addReverseMapping(contentStart, descriptor.start);
      this.#addReverseMapping(contentEnd, descriptor.end);
    });
  }

  #addReverseMapping(position, sourceOffset) {
    const values = this.pmToSource.get(position) ?? [];
    if (!values.includes(sourceOffset)) values.push(sourceOffset);
    this.pmToSource.set(position, values);
  }
}

export {caxtonSchema};
