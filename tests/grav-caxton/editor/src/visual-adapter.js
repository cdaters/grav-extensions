import {baseKeymap, setBlockType, toggleMark} from 'prosemirror-commands';
import {history, redo, undo} from 'prosemirror-history';
import {keymap} from 'prosemirror-keymap';
import {EditorState, TextSelection} from 'prosemirror-state';
import {liftListItem, sinkListItem, splitListItem} from 'prosemirror-schema-list';
import {EditorView} from 'prosemirror-view';
import {caxtonMarkdownSerializer, caxtonSchema} from './schema.js';
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
  return caxtonMarkdownSerializer.serialize(temporary).replace(/\u00a0/g, ' ').replace(/\n$/, '');
}

function normalizedSerializedBlocks(nodes) {
  if (nodes.length === 0) return '';
  const temporary = caxtonSchema.nodes.doc.create(null, nodes);
  return caxtonMarkdownSerializer.serialize(temporary).replace(/\u00a0/g, ' ').replace(/\n$/, '');
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
    this.onChange = typeof options.onChange === 'function' ? options.onChange : null;
    this.onReject = typeof options.onReject === 'function' ? options.onReject : null;
    this.onSelectionChange = typeof options.onSelectionChange === 'function' ? options.onSelectionChange : null;
    this.onRequestLink = typeof options.onRequestLink === 'function' ? options.onRequestLink : null;
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
          'Mod-k': () => {
            if (!this.onRequestLink) return false;
            this.onRequestLink();
            return true;
          },
          'Shift-Mod-b': () => this.toggleBlockquote(),
          'Shift-Mod-x': () => this.toggleSelectionMark('strikethrough'),
          'Shift-Mod-8': () => this.toggleList('bullet_list'),
          'Shift-Mod-7': () => this.toggleList('ordered_list'),
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
        if (transaction.docChanged && this.onChange) {
          this.#acceptInteractiveTransaction(transaction);
          return;
        }
        this.state = this.state.apply(transaction);
        this.view.updateState(this.state);
        this.#rebuildMappings();
        this.#notifySelection();
      },
    });
    this.#notifySelection();
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

  acceptSourceDocument(sourceDocument) {
    this.sourceDocument = sourceDocument;
    this.#rebuildMappings();
  }

  undo() {
    return this.#runCommand(undo);
  }

  redo() {
    return this.#runCommand(redo);
  }

  toggleSelectionMark(markName) {
    if (!['strong', 'em', 'code', 'strikethrough'].includes(markName)) return false;
    return this.#runCommand(toggleMark(caxtonSchema.marks[markName]));
  }

  removeSelectionFormatting() {
    if (this.readOnly || !this.view || this.state.selection.empty) return false;
    const {from, to} = this.state.selection;
    this.view.dispatch(this.state.tr.removeMark(from, to));
    return true;
  }

  linkState() {
    const {from, to, empty, $from} = this.state.selection;
    let mark = caxtonSchema.marks.link.isInSet($from.marks());
    if (!mark && !empty) {
      this.state.doc.nodesBetween(from, to, (node) => {
        mark ??= caxtonSchema.marks.link.isInSet(node.marks);
      });
    }
    return {selected: !empty, href: mark?.attrs.href ?? '', title: mark?.attrs.title ?? ''};
  }

  updateSelectionLink(href, title = null) {
    if (this.readOnly || !this.view || this.state.selection.empty) return false;
    const safe = safeLinkUrl(href);
    if (safe === null) return false;
    const {from, to} = this.state.selection;
    const mark = caxtonSchema.marks.link.create({href: safe, title: title || null});
    this.view.dispatch(this.state.tr
      .removeMark(from, to, caxtonSchema.marks.link)
      .addMark(from, to, mark));
    return true;
  }

  removeSelectionLink() {
    if (this.readOnly || !this.view || this.state.selection.empty) return false;
    const {from, to} = this.state.selection;
    this.view.dispatch(this.state.tr.removeMark(from, to, caxtonSchema.marks.link));
    return true;
  }

  toggleBlockquote() {
    const top = this.#selectedTopBlock();
    if (!top) return false;
    let replacement = null;
    if (top.node.type === caxtonSchema.nodes.blockquote) {
      if (top.node.childCount !== 1 || !['paragraph', 'heading'].includes(top.node.firstChild.type.name)) return false;
      replacement = top.node.firstChild.type.create(
        {...top.node.firstChild.attrs, ...this.#topSourceAttrs(top.node)},
        top.node.firstChild.content,
        top.node.firstChild.marks
      );
    } else if (['paragraph', 'heading'].includes(top.node.type.name)) {
      const nested = top.node.type.create(this.#nestedAttrs(top.node), top.node.content, top.node.marks);
      replacement = caxtonSchema.nodes.blockquote.create(this.#topSourceAttrs(top.node), nested);
    }
    return replacement ? this.#replaceTopBlock(top, replacement) : false;
  }

  toggleList(kind) {
    if (!['bullet_list', 'ordered_list'].includes(kind)) return false;
    const top = this.#selectedTopBlock();
    if (!top) return false;
    const target = caxtonSchema.nodes[kind];
    let replacement = null;
    if (top.node.type === target) {
      if (top.node.childCount !== 1 || top.node.firstChild.childCount !== 1
        || top.node.firstChild.firstChild.type !== caxtonSchema.nodes.paragraph) return false;
      const paragraph = top.node.firstChild.firstChild;
      replacement = caxtonSchema.nodes.paragraph.create(
        this.#topSourceAttrs(top.node),
        paragraph.content,
        paragraph.marks
      );
    } else if (['bullet_list', 'ordered_list'].includes(top.node.type.name)) {
      replacement = target.create(this.#listAttrs(kind, top.node), top.node.content);
    } else if (['paragraph', 'heading'].includes(top.node.type.name)) {
      const paragraph = caxtonSchema.nodes.paragraph.create(this.#nestedAttrs(top.node), top.node.content);
      const item = caxtonSchema.nodes.list_item.create(null, paragraph);
      replacement = target.create(this.#listAttrs(kind, top.node), item);
    }
    return replacement ? this.#replaceTopBlock(top, replacement) : false;
  }

  toggleCodeBlock() {
    const top = this.#selectedTopBlock();
    if (!top) return false;
    let replacement = null;
    if (top.node.type === caxtonSchema.nodes.code_block) {
      replacement = caxtonSchema.nodes.paragraph.create(
        this.#topSourceAttrs(top.node),
        top.node.textContent ? caxtonSchema.text(top.node.textContent) : null
      );
    } else if (['paragraph', 'heading'].includes(top.node.type.name)) {
      replacement = caxtonSchema.nodes.code_block.create(
        {...this.#topSourceAttrs(top.node), params: ''},
        top.node.textContent ? caxtonSchema.text(top.node.textContent) : null
      );
    }
    return replacement ? this.#replaceTopBlock(top, replacement) : false;
  }

  selectionState() {
    const {$from, empty} = this.state.selection;
    const marks = empty ? ($from.marks() ?? []) : null;
    const activeMark = (name) => marks
      ? Boolean(caxtonSchema.marks[name].isInSet(marks))
      : this.state.doc.rangeHasMark(this.state.selection.from, this.state.selection.to, caxtonSchema.marks[name]);
    const top = this.#selectedTopBlock();
    return {
      block: top?.node.type.name ?? null,
      heading: top?.node.type === caxtonSchema.nodes.heading ? top.node.attrs.level : null,
      strong: activeMark('strong'),
      em: activeMark('em'),
      code: activeMark('code'),
      strikethrough: activeMark('strikethrough'),
      link: activeMark('link'),
      canLink: !empty && Boolean(top),
      canUndo: undo(this.state),
      canRedo: redo(this.state),
    };
  }

  setTextStyle(style) {
    const parent = this.state.selection.$from.parent;
    if (!parent.isTextblock || !parent.attrs.blockId) return false;
    if (style === 'paragraph') {
      return this.#runCommand(setBlockType(caxtonSchema.nodes.paragraph, {...parent.attrs}));
    }
    const match = /^heading-([1-6])$/.exec(style);
    if (!match) return false;
    return this.#runCommand(setBlockType(caxtonSchema.nodes.heading, {
      ...parent.attrs,
      level: Number(match[1]),
    }));
  }

  #runCommand(command) {
    if (this.readOnly || !this.view) return false;
    return command(this.state, (transaction) => this.view.dispatch(transaction), this.view);
  }

  #acceptInteractiveTransaction(transaction) {
    const before = [];
    const after = [];
    this.state.doc.forEach((node) => before.push(node));
    transaction.doc.forEach((node) => after.push(node));
    let prefix = 0;
    while (prefix < before.length && prefix < after.length && before[prefix].eq(after[prefix])) prefix += 1;
    let beforeEnd = before.length - 1;
    let afterEnd = after.length - 1;
    while (beforeEnd >= prefix && afterEnd >= prefix && before[beforeEnd].eq(after[afterEnd])) {
      beforeEnd -= 1;
      afterEnd -= 1;
    }
    const changedBefore = before.slice(prefix, beforeEnd + 1);
    const changedAfter = after.slice(prefix, afterEnd + 1);
    const descriptors = changedBefore.map((node) => this.sourceDocument.block(node.attrs.blockId));
    const structural = changedBefore.length !== 1 || changedAfter.length !== 1;
    const structuralKinds = new Set(['paragraph', 'heading']);
    const rangeSafe = changedBefore.length > 0
      && descriptors.every((descriptor) => descriptor?.safe)
      && changedAfter.every((node) => node.type.name !== 'opaque_block');
    const structuralSafe = !structural || (
      descriptors.every((descriptor) => structuralKinds.has(descriptor.kind))
      && changedAfter.every((node) => structuralKinds.has(node.type.name))
    );
    if (!rangeSafe) {
      this.onReject?.('That edit crosses a protected or non-local source boundary.');
      return;
    }
    if (!structuralSafe) {
      this.onReject?.('Use Source mode to add, remove, or restructure top-level blocks.');
      return;
    }

    const previousState = this.state;
    try {
      this.state = this.state.apply(transaction);
      const replacement = changedAfter.length === 1 && changedBefore.length === 1
        && changedAfter[0].type.name === 'code_block'
        ? serializeCodeBlock(changedAfter[0], descriptors[0])
        : normalizedSerializedBlocks(changedAfter);
      const accepted = this.onChange({
        blockId: changedBefore[0].attrs.blockId,
        endBlockId: changedBefore.at(-1).attrs.blockId,
        replacement,
        transaction,
      });
      if (!accepted || typeof accepted.block !== 'function') throw new Error('INTERACTIVE_CHANGE_REJECTED');
      this.sourceDocument = accepted;
      const normalized = accepted.visualDocument();
      if (normalized.childCount !== this.state.doc.childCount) throw new Error('INTERACTIVE_STRUCTURE_MISMATCH');
      let metadata = this.state.tr;
      this.state.doc.forEach((node, position, index) => {
        const expected = normalized.child(index);
        if (node.type !== expected.type) throw new Error('INTERACTIVE_TYPE_MISMATCH');
        if (JSON.stringify(node.attrs) !== JSON.stringify(expected.attrs)) {
          metadata = metadata.setNodeMarkup(position, undefined, expected.attrs, node.marks);
        }
      });
      if (metadata.steps.length > 0) this.state = this.state.apply(metadata.setMeta('addToHistory', false));
      this.view.updateState(this.state);
      this.#rebuildMappings();
      this.#notifySelection();
    } catch (error) {
      this.state = previousState;
      this.view.updateState(this.state);
      this.#rebuildMappings();
      this.onReject?.('That edit could not be represented safely in Markdown source.');
    }
  }

  #dispatch(transaction, allowReadOnly = false) {
    if (this.readOnly && !allowReadOnly) throw new Error('READ_ONLY');
    this.state = this.state.apply(transaction);
    this.view?.updateState(this.state);
    this.#rebuildMappings();
    this.#notifySelection();
  }

  #notifySelection() {
    this.onSelectionChange?.(this.selectionState());
  }

  #selectedTopBlock() {
    const {from, to} = this.state.selection;
    let found = null;
    this.state.doc.forEach((node, position) => {
      const end = position + node.nodeSize;
      if (from >= position && to <= end && node.attrs.blockId && node.type.name !== 'opaque_block') {
        found = {node, position};
      }
    });
    return found;
  }

  #topSourceAttrs(node) {
    return {
      blockId: node.attrs.blockId,
      sourceStart: node.attrs.sourceStart,
      sourceEnd: node.attrs.sourceEnd,
      sourceKind: node.attrs.sourceKind,
    };
  }

  #nestedAttrs(node) {
    return {...node.attrs, blockId: null, sourceStart: null, sourceEnd: null, sourceKind: null};
  }

  #listAttrs(kind, node) {
    return kind === 'ordered_list'
      ? {...this.#topSourceAttrs(node), order: node.attrs.order ?? 1}
      : this.#topSourceAttrs(node);
  }

  #replaceTopBlock(top, replacement) {
    if (this.readOnly || !this.view) return false;
    this.view.dispatch(this.state.tr.replaceWith(top.position, top.position + top.node.nodeSize, replacement));
    return true;
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
    if (!['strong', 'em', 'code', 'strikethrough'].includes(markName)) throw new TypeError('Unsupported inline mark.');
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
