import {Schema} from 'prosemirror-model';
import {defaultMarkdownSerializer, MarkdownSerializer, schema as markdownSchema} from 'prosemirror-markdown';
import {safeLinkUrl, safeMediaReference} from './security.js';

const sourceAttrs = {
  blockId: {default: null},
  sourceStart: {default: null},
  sourceEnd: {default: null},
  sourceKind: {default: null},
};

let nodes = markdownSchema.spec.nodes;
for (const name of ['paragraph', 'blockquote', 'horizontal_rule', 'heading', 'code_block', 'ordered_list', 'bullet_list']) {
  const spec = nodes.get(name);
  nodes = nodes.update(name, {...spec, attrs: {...(spec.attrs ?? {}), ...sourceAttrs}});
}

const originalListItem = nodes.get('list_item');
nodes = nodes.update('list_item', {
  ...originalListItem,
  attrs: {...(originalListItem.attrs ?? {}), task: {default: false}, checked: {default: false}},
  toDOM(node) {
    const attrs = node.attrs.task ? {'data-caxton-task': node.attrs.checked ? 'checked' : 'unchecked'} : {};
    return ['li', attrs, 0];
  },
});

const originalImage = nodes.get('image');
nodes = nodes.update('image', {
  ...originalImage,
  toDOM(node) {
    const safe = safeMediaReference(node.attrs.src);
    if (safe === null) return ['span', {'data-caxton-media': 'blocked', role: 'img'}, 'Blocked media reference'];
    const label = node.attrs.alt ? `Media: ${node.attrs.alt}` : 'Media reference';
    return ['span', {'data-caxton-media': safe, role: 'img', 'aria-label': label}, label];
  },
});

nodes = nodes.append({
  opaque_block: {
    group: 'block',
    atom: true,
    isolating: true,
    selectable: true,
    draggable: false,
    attrs: {
      ...sourceAttrs,
      label: {default: 'Unsupported source'},
      preview: {default: ''},
    },
    toDOM(node) {
      return [
        'section',
        {
          'data-caxton-opaque': node.attrs.sourceKind ?? 'unknown',
          'data-source-start': String(node.attrs.sourceStart ?? ''),
          'data-source-end': String(node.attrs.sourceEnd ?? ''),
          contenteditable: 'false',
          tabindex: '0',
          role: 'note',
          'aria-label': node.attrs.label,
        },
        ['strong', {}, node.attrs.label],
        ['pre', {}, node.attrs.preview],
      ];
    },
  },
});

let marks = markdownSchema.spec.marks;
marks = marks.append({
  strikethrough: {
    parseDOM: [{tag: 's'}, {tag: 'del'}],
    toDOM() { return ['s', 0]; },
  },
});
const originalLink = marks.get('link');
marks = marks.update('link', {
  ...originalLink,
  inclusive: false,
  toDOM(node) {
    const safe = safeLinkUrl(node.attrs.href);
    if (safe === null) return ['span', {'data-caxton-link': 'blocked'}, 0];
    return ['a', {href: safe, title: node.attrs.title ?? null, rel: 'nofollow noopener noreferrer'}, 0];
  },
});

export const caxtonSchema = new Schema({nodes, marks});

export const caxtonMarkdownSerializer = new MarkdownSerializer(
  defaultMarkdownSerializer.nodes,
  {
    ...defaultMarkdownSerializer.marks,
    strikethrough: {open: '~~', close: '~~', mixable: true, expelEnclosingWhitespace: true},
  }
);

export const SAFE_BLOCK_TYPES = new Set([
  'paragraph',
  'heading',
  'blockquote',
  'horizontal_rule',
  'code_block',
  'ordered_list',
  'bullet_list',
]);

export const SAFE_MARK_TYPES = new Set(['strong', 'em', 'code', 'link', 'strikethrough']);
