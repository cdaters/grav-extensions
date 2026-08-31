import {GFM, parser as baseMarkdownParser} from '@lezer/markdown';
import {defaultMarkdownParser} from 'prosemirror-markdown';
import {caxtonSchema, SAFE_BLOCK_TYPES, SAFE_MARK_TYPES} from './schema.js';
import {hasUnsafeUrl, opaquePreview, safeLinkUrl, safeMediaReference} from './security.js';

const markdownParser = baseMarkdownParser.configure(GFM);
const MAX_SOURCE_UNITS = 2_097_152;
const MAX_BLOCKS = 20_000;
const MAX_NESTING = 128;
const SAFE_LEZER_BLOCKS = new Set([
  'Paragraph', 'ATXHeading1', 'ATXHeading2', 'ATXHeading3', 'ATXHeading4',
  'ATXHeading5', 'ATXHeading6', 'SetextHeading1', 'SetextHeading2',
  'BulletList', 'OrderedList', 'Blockquote', 'HorizontalRule', 'FencedCode',
]);

const OPAQUE_LABELS = {
  frontmatter: 'YAML frontmatter',
  html: 'Raw HTML',
  twig: 'Twig source',
  shortcode: 'Grav shortcode',
  table: 'Markdown table',
  malformed: 'Malformed source',
  unsafe_url: 'Unsafe URL source',
  unknown: 'Unsupported source',
  mixed: 'Mixed source',
};

function sourceId(index) {
  let hash = 2166136261;
  const value = `caxton:${index}`;
  for (let offset = 0; offset < value.length; offset += 1) {
    hash ^= value.charCodeAt(offset);
    hash = Math.imul(hash, 16777619);
  }
  return `block-${String(index).padStart(4, '0')}-${(hash >>> 0).toString(16).padStart(8, '0')}`;
}

function frontmatterRange(source) {
  if (!source.startsWith('---\n') && !source.startsWith('---\r\n') && !source.startsWith('---\r')) return null;
  const pattern = /(?:\r\n|\r|\n)(?:---|\.\.\.)(?=\r\n|\r|\n|$)/g;
  pattern.lastIndex = source.indexOf('\n') >= 0 ? source.indexOf('\n') : source.indexOf('\r');
  const match = pattern.exec(source);
  if (!match) return {start: 0, end: source.length, malformed: true};
  const closingStart = match.index + match[0].search(/(?:---|\.\.\.)/);
  const closingEnd = closingStart + 3;
  return {start: 0, end: closingEnd, malformed: false};
}

function containsTwig(source) {
  return /(?:\{\{|\{%|\{#)/.test(source);
}

function containsShortcode(source) {
  if (/\[\/[A-Za-z][A-Za-z0-9_-]*\]/.test(source)) return true;
  if (/\[[A-Za-z][A-Za-z0-9_-]*\s*\/\]/.test(source)) return true;
  if (/\[[A-Za-z][A-Za-z0-9_-]*(?:\s+[A-Za-z_][A-Za-z0-9_-]*\s*=\s*(?:"[^"]*"|'[^']*'|[^\s\]]+))+\s*\]/.test(source)) return true;
  const paired = source.match(/\[([A-Za-z][A-Za-z0-9_-]*)\]/);
  return paired ? source.includes(`[/${paired[1]}]`) : false;
}

function containsInlineHtml(tree) {
  const cursor = tree.cursor();
  let found = false;
  function visit() {
    if (/^HTML(?:Block|Tag|Comment|ProcessingInstruction)$/.test(cursor.name)) found = true;
    if (!found && cursor.firstChild()) {
      do visit(); while (!found && cursor.nextSibling());
      cursor.parent();
    }
  }
  visit();
  return found;
}

function maximumTreeDepth(tree, limit = MAX_NESTING) {
  const cursor = tree.cursor();
  let maximum = 0;
  function visit(depth) {
    maximum = Math.max(maximum, depth);
    if (depth > limit) return;
    if (cursor.firstChild()) {
      do visit(depth + 1); while (cursor.nextSibling());
      cursor.parent();
    }
  }
  visit(0);
  return maximum;
}

function fenceMetadata(source) {
  const opening = source.match(/^([ \t]{0,3})(`{3,}|~{3,})([^\r\n]*)(\r\n|\r|\n)/);
  if (!opening) return {closed: false};
  const marker = opening[2][0];
  const count = opening[2].length;
  const closingPattern = new RegExp(`(?:^|\\r\\n|\\r|\\n)([ \\t]{0,3}${marker === '`' ? '`' : '~'}{${count},}[ \\t]*)$`);
  const closing = source.match(closingPattern);
  if (!closing) return {closed: false};
  const closingStart = closing.index + closing[0].length - closing[1].length;
  return {
    closed: true,
    opening: opening[0].slice(0, -opening[4].length),
    newline: opening[4],
    info: opening[3].trim(),
    bodyStart: opening[0].length,
    bodyEnd: closingStart - (source.slice(0, closingStart).match(/(?:\r\n|\r|\n)$/)?.[0].length ?? 0),
    closing: closing[1],
  };
}

function descriptorKind(lezerName, source, tree) {
  if (lezerName === 'FencedCode') {
    return fenceMetadata(source).closed
      ? {kind: 'code_block', safe: true}
      : {kind: 'malformed', safe: false};
  }
  if (containsTwig(source)) return {kind: 'twig', safe: false};
  if (containsShortcode(source)) return {kind: 'shortcode', safe: false};
  if (lezerName === 'Table') return {kind: 'table', safe: false};
  if (/^HTML/.test(lezerName)) return {kind: 'html', safe: false};
  if (containsInlineHtml(tree)) return {kind: 'mixed', safe: false};
  if (hasUnsafeUrl(source)) return {kind: 'unsafe_url', safe: false};
  if (/^(?:::|@@|!!!)/m.test(source)) return {kind: 'unknown', safe: false};
  if (!SAFE_LEZER_BLOCKS.has(lezerName)) return {kind: 'unknown', safe: false};
  const names = {
    Paragraph: 'paragraph',
    ATXHeading1: 'heading', ATXHeading2: 'heading', ATXHeading3: 'heading',
    ATXHeading4: 'heading', ATXHeading5: 'heading', ATXHeading6: 'heading',
    SetextHeading1: 'heading', SetextHeading2: 'heading',
    BulletList: 'bullet_list', OrderedList: 'ordered_list', Blockquote: 'blockquote',
    HorizontalRule: 'horizontal_rule', FencedCode: 'code_block',
  };
  return {kind: names[lezerName], safe: true};
}

function taskListJson(value) {
  if (Array.isArray(value)) return value.map(taskListJson);
  if (value === null || typeof value !== 'object') return value;
  const result = {...value};
  if (result.type === 'list_item') {
    const firstText = result.content?.[0]?.content?.[0];
    const match = firstText?.type === 'text' ? firstText.text.match(/^\[([ xX])\][ \t]+/) : null;
    if (match) result.attrs = {...result.attrs, task: true, checked: match[1].toLowerCase() === 'x'};
  }
  if (result.content) result.content = result.content.map(taskListJson);
  return result;
}

function safeNodeFor(descriptor) {
  let parsed;
  try {
    parsed = defaultMarkdownParser.parse(descriptor.source);
  } catch {
    return null;
  }
  if (parsed.childCount !== 1) return null;
  const json = taskListJson(parsed.firstChild.toJSON());
  json.attrs = {
    ...(json.attrs ?? {}),
    blockId: descriptor.id,
    sourceStart: descriptor.start,
    sourceEnd: descriptor.end,
    sourceKind: descriptor.kind,
  };
  let node;
  try {
    node = caxtonSchema.nodeFromJSON(json);
  } catch {
    return null;
  }
  let valid = SAFE_BLOCK_TYPES.has(node.type.name);
  let imageCount = 0;
  node.descendants((child) => {
    if (child.isBlock && child !== node && !SAFE_BLOCK_TYPES.has(child.type.name) && child.type.name !== 'list_item') valid = false;
    for (const mark of child.marks) {
      if (!SAFE_MARK_TYPES.has(mark.type.name)) valid = false;
      if (mark.type.name === 'link' && safeLinkUrl(mark.attrs.href) === null) valid = false;
    }
    if (child.type.name === 'image') {
      imageCount += 1;
      if (safeMediaReference(child.attrs.src) === null) valid = false;
    }
  });
  if (descriptor.source.includes('![') && imageCount === 0) valid = false;
  return valid ? node : null;
}

function opaqueNode(descriptor) {
  return caxtonSchema.nodes.opaque_block.create({
    blockId: descriptor.id,
    sourceStart: descriptor.start,
    sourceEnd: descriptor.end,
    sourceKind: descriptor.kind,
    label: OPAQUE_LABELS[descriptor.kind] ?? OPAQUE_LABELS.unknown,
    preview: opaquePreview(descriptor.source),
  });
}

export class SourceDocumentAdapter {
  constructor(source, options = {}) {
    if (typeof source !== 'string') throw new TypeError('Caxton source must be a string.');
    const limit = Number.isInteger(options.maxSourceUnits) ? options.maxSourceUnits : MAX_SOURCE_UNITS;
    if (source.length > limit) throw new RangeError('Caxton source exceeds the configured browser-unit limit.');
    if (source.includes('\u0000')) throw new TypeError('Caxton source cannot contain NUL.');
    this.source = source;
    this.blocks = [];
    this.diagnostics = [];
    this.#parse();
  }

  #parse() {
    const frontmatter = frontmatterRange(this.source);
    let bodyStart = 0;
    if (frontmatter) {
      this.#push(frontmatter.start, frontmatter.end, frontmatter.malformed ? 'malformed' : 'frontmatter', false);
      bodyStart = frontmatter.end;
    }
    const body = this.source.slice(bodyStart);
    const tree = markdownParser.parse(body);
    if (maximumTreeDepth(tree) > MAX_NESTING) {
      this.#push(bodyStart, this.source.length, 'malformed', false);
      this.diagnostics.push({code: 'nesting-limit', severity: 'warning', offset: bodyStart});
      return;
    }
    const cursor = tree.cursor();
    let covered = bodyStart;
    if (cursor.firstChild()) {
      do {
        const start = bodyStart + cursor.from;
        const end = bodyStart + cursor.to;
        if (start > covered) this.#push(covered, start, 'trivia', false);
        const source = this.source.slice(start, end);
        const subtree = markdownParser.parse(source);
        const classification = descriptorKind(cursor.name, source, subtree);
        this.#push(start, end, classification.kind, classification.safe, cursor.name);
        covered = end;
      } while (cursor.nextSibling());
      cursor.parent();
    }
    if (covered < this.source.length) this.#push(covered, this.source.length, 'trivia', false);
    const joined = this.blocks.map((block) => block.source).join('');
    if (joined !== this.source) throw new Error('Caxton browser source coverage failed.');
  }

  #push(start, end, kind, safe, parserKind = null) {
    if (end <= start) return;
    if (this.blocks.length >= MAX_BLOCKS) throw new RangeError('Caxton source exceeds the browser block limit.');
    const descriptor = {
      id: sourceId(this.blocks.length + 1),
      start,
      end,
      kind,
      safe,
      parserKind,
      source: this.source.slice(start, end),
      metadata: kind === 'code_block' ? fenceMetadata(this.source.slice(start, end)) : {},
    };
    if (safe) {
      descriptor.node = safeNodeFor(descriptor);
      if (descriptor.node === null) {
        descriptor.safe = false;
        descriptor.kind = 'mixed';
      }
    }
    if (!descriptor.safe && descriptor.kind !== 'trivia') descriptor.node = opaqueNode(descriptor);
    this.blocks.push(descriptor);
  }

  visualDocument() {
    return caxtonSchema.nodes.doc.create(null, this.blocks.filter((block) => block.node).map((block) => block.node));
  }

  block(id) {
    return this.blocks.find((block) => block.id === id) ?? null;
  }

  safeBlocks() {
    return this.blocks.filter((block) => block.safe);
  }

  opaqueBlocks() {
    return this.blocks.filter((block) => !block.safe && block.kind !== 'trivia');
  }

  overlapsOpaque(from, to) {
    return this.opaqueBlocks().some((block) => {
      if (from === to) return from > block.start && from < block.end;
      return from < block.end && to > block.start;
    });
  }

  applyPatch(blockId, replacement, expectedSource) {
    if (expectedSource !== this.source) throw new Error('STALE_SOURCE');
    const block = this.block(blockId);
    if (!block || !block.safe) throw new Error('BLOCK_NOT_EDITABLE');
    if (typeof replacement !== 'string' || replacement.includes('\u0000') || replacement.length > 262_144) {
      throw new TypeError('Invalid localized replacement.');
    }
    return this.source.slice(0, block.start) + replacement + this.source.slice(block.end);
  }
}

export {MAX_BLOCKS, MAX_NESTING, MAX_SOURCE_UNITS, markdownParser};
