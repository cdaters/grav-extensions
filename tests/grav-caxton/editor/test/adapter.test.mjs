import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {test} from 'node:test';
import {
  boundedOwnRecord,
  caxtonSchema,
  DualModeEditorSession,
  safeLinkUrl,
  safeMediaReference,
  SourceDocumentAdapter,
  SourceEditorAdapter,
  VisualEditorAdapter,
} from '../src/index.js';

const fixtures = new URL('../../fixtures/', import.meta.url);
const readFixture = (name) => readFile(new URL(name, fixtures), 'utf8');
const block = (session, kind, occurrence = 0) => session.document.blocks.filter((item) => item.kind === kind)[occurrence];

test('the private ProseMirror schema contains only the intended safe proof nodes and marks', () => {
  for (const name of [
    'doc', 'paragraph', 'heading', 'text', 'ordered_list', 'bullet_list', 'list_item',
    'blockquote', 'horizontal_rule', 'code_block', 'image', 'opaque_block',
  ]) assert.ok(caxtonSchema.nodes[name], `missing node ${name}`);
  for (const name of ['strong', 'em', 'code', 'link']) assert.ok(caxtonSchema.marks[name], `missing mark ${name}`);
  assert.equal(caxtonSchema.nodes.opaque_block.spec.draggable, false);
  assert.equal(caxtonSchema.nodes.opaque_block.spec.atom, true);
});

test('the expanded corpus becomes safe nodes plus exact opaque preservation cards', async () => {
  const source = await readFixture('adapter-corpus.md');
  const document = new SourceDocumentAdapter(source);
  assert.equal(document.blocks.map((item) => item.source).join(''), source);
  for (const kind of ['heading', 'paragraph', 'bullet_list', 'ordered_list', 'blockquote', 'horizontal_rule', 'code_block']) {
    assert.ok(document.blocks.some((item) => item.kind === kind && item.safe), `missing safe ${kind}`);
  }
  for (const kind of ['frontmatter', 'table', 'html', 'twig', 'shortcode', 'mixed', 'unknown']) {
    assert.ok(document.blocks.some((item) => item.kind === kind && !item.safe), `missing opaque ${kind}`);
  }
  for (const item of document.opaqueBlocks()) {
    assert.equal(item.node.type.name, 'opaque_block');
    assert.equal(item.node.attrs.sourceStart, item.start);
    assert.equal(item.node.attrs.sourceEnd, item.end);
    assert.ok(item.node.attrs.preview.length <= 241);
  }
});

test('source to visual to source remains byte-identical and clean across fixture variants', async () => {
  const corpus = await readFixture('adapter-corpus.md');
  const variants = [
    corpus,
    corpus.replaceAll('\n', '\r\n'),
    corpus.replace(/\n$/, ''),
    'Trailing spaces  \n\nUnicode 😀 é\n',
    '',
  ];
  for (const source of variants) {
    const session = await DualModeEditorSession.create(source);
    session.switchMode('visual');
    session.noteUiStateChange();
    session.switchMode('source');
    assert.equal(session.value(), source);
    assert.equal(session.isDirty(), false);
    assert.equal(session.dirtyReason, null);
  }
});

test('paragraph and heading edits are localized to their source spans', async () => {
  const source = '# Old heading\n\nBefore.\n\nEditable paragraph.\n\n{% opaque %}\n\nAfter.\n';
  let session = await DualModeEditorSession.create(source, {mode: 'visual'});
  const heading = block(session, 'heading');
  const headingPrefix = source.slice(0, heading.start);
  const headingSuffix = source.slice(heading.end);
  await session.replaceBlockText(heading.id, 'New heading');
  assert.equal(session.value(), `${headingPrefix}# New heading${headingSuffix}`);
  assert.equal(session.dirtyReason, 'visual-edit');

  session = await DualModeEditorSession.create(source, {mode: 'visual'});
  const paragraph = session.document.safeBlocks().find((item) => item.source === 'Editable paragraph.');
  const prefix = source.slice(0, paragraph.start);
  const suffix = source.slice(paragraph.end);
  await session.replaceBlockText(paragraph.id, 'Changed paragraph.');
  assert.equal(session.value(), `${prefix}Changed paragraph.${suffix}`);
  assert.ok(session.value().includes('{% opaque %}'));
});

test('inline strong, emphasis, and link edits normalize only the intentional paragraph', async () => {
  const source = 'Before exact.\n\nFormat this and [old link](https://old.example).\n\nAfter exact.\n';
  let session = await DualModeEditorSession.create(source, {mode: 'visual'});
  let paragraph = session.document.safeBlocks().find((item) => item.source.startsWith('Format this'));
  await session.toggleInlineMark(paragraph.id, 0, 6, 'strong');
  assert.equal(session.value(), 'Before exact.\n\n**Format** this and [old link](https://old.example).\n\nAfter exact.\n');

  session = await DualModeEditorSession.create(source, {mode: 'visual'});
  paragraph = session.document.safeBlocks().find((item) => item.source.startsWith('Format this'));
  await session.toggleInlineMark(paragraph.id, 7, 11, 'em');
  assert.equal(session.value(), 'Before exact.\n\nFormat *this* and [old link](https://old.example).\n\nAfter exact.\n');

  session = await DualModeEditorSession.create(source, {mode: 'visual'});
  paragraph = session.document.safeBlocks().find((item) => item.source.startsWith('Format this'));
  await session.updateLink(paragraph.id, 16, 24, 'https://new.example/path');
  assert.ok(session.value().includes('[old link](https://new.example/path)'));
  await assert.rejects(
    () => session.updateLink(block(session, 'paragraph', 1).id, 0, 1, 'javascript:alert(1)'),
    /Unsafe link URL/
  );
});

test('list edits use ProseMirror transactions and localize list normalization', async () => {
  const source = 'Before.\n\n3. first\n4. second\n\nAfter.\n';
  let session = await DualModeEditorSession.create(source, {mode: 'visual'});
  let list = block(session, 'ordered_list');
  await session.insertListItem(list.id, 'inserted', 1);
  assert.equal(session.value(), 'Before.\n\n3. first\n4. inserted\n5. second\n\nAfter.\n');

  list = block(session, 'ordered_list');
  await session.removeListItem(list.id, 0);
  assert.equal(session.value(), 'Before.\n\n3. inserted\n4. second\n\nAfter.\n');
});

test('fenced code edits retain the original fence, info string, and surrounding bytes', async () => {
  const source = 'Before.\n\n~~~~php strict\n{{ inert }}\n~~~~\n\n{% opaque %}\n\nAfter.\n';
  const session = await DualModeEditorSession.create(source, {mode: 'visual'});
  const code = block(session, 'code_block');
  await session.editCodeBlock(code.id, "echo '**still code**';");
  assert.equal(
    session.value(),
    "Before.\n\n~~~~php strict\necho '**still code**';\n~~~~\n\n{% opaque %}\n\nAfter.\n"
  );
});

test('CodeMirror state changes are exact and rebuild visual mappings deterministically', async () => {
  const source = 'First paragraph.\n\nSecond paragraph.\n';
  const adapter = new SourceEditorAdapter(source);
  const start = source.indexOf('Second');
  adapter.setSelection(start, start + 6);
  assert.deepEqual(adapter.selection(), {from: start, to: start + 6});
  adapter.applyChange(start, start + 6, 'Changed');
  assert.equal(adapter.value(), 'First paragraph.\n\nChanged paragraph.\n');

  const session = await DualModeEditorSession.create(source, {mode: 'source'});
  await session.sourceEdit(start, start + 6, 'Changed');
  assert.equal(session.value(), 'First paragraph.\n\nChanged paragraph.\n');
  assert.equal(session.dirtyReason, 'source-edit');
  assert.ok(session.document.safeBlocks().some((item) => item.source === 'Changed paragraph.'));
});

test('visual and source selections map exactly where representable and fail around opaque source', async () => {
  const source = '# Heading\n\nAlpha **bold** and *emphasis*.\n\nSecond [link](https://example.com) block.\n\n{% opaque %}\n\nAfter.\n';
  const session = await DualModeEditorSession.create(source, {mode: 'visual'});
  for (const value of ['Heading', 'Alpha', 'bold', 'bold** and *emphasis', 'Second', 'link']) {
    const from = source.indexOf(value);
    const to = from + value.length;
    const visual = session.sourceSelectionToVisual(from, to);
    assert.ok(visual, `source selection should map: ${value}`);
    assert.deepEqual(session.visualSelectionToSource(visual.from, visual.to), {from, to});
  }
  const multipleFrom = source.indexOf('Alpha');
  const multipleTo = source.indexOf(' block') + 6;
  const multiple = session.sourceSelectionToVisual(multipleFrom, multipleTo);
  assert.ok(multiple, 'safe multi-block selection should map');
  assert.deepEqual(session.visualSelectionToSource(multiple.from, multiple.to), {from: multipleFrom, to: multipleTo});
  const opaqueFrom = source.indexOf('{%');
  assert.equal(session.sourceSelectionToVisual(opaqueFrom, opaqueFrom + 2), null);
  assert.equal(session.sourceSelectionToVisual(source.indexOf('block.'), source.indexOf('After')), null);

  const context = session.selectionContext(source.indexOf('bold'), source.indexOf('bold') + 4, 24);
  assert.equal(context.selection.source, 'bold');
  assert.equal(context.block.kind, 'paragraph');
  assert.equal(context.sourceIdentity, session.currentIdentity);
  assert.ok(context.context.source.length <= context.block.to - context.block.from + 48);
});

test('dirty state distinguishes selection/UI/mode changes, content edits, and stale source', async () => {
  const source = '# Heading\n\nParagraph.\n';
  const session = await DualModeEditorSession.create(source);
  session.switchMode('visual');
  session.noteUiStateChange();
  const selection = session.sourceSelectionToVisual(source.indexOf('Paragraph'), source.indexOf('Paragraph') + 4);
  assert.ok(selection);
  assert.equal(session.isDirty(), false);
  const paragraph = block(session, 'paragraph');
  await session.replaceBlockText(paragraph.id, 'Changed.');
  assert.equal(session.isDirty(), true);
  assert.equal(await session.observeCanonicalSource('# External\n'), true);
  assert.equal(session.isStale(), true);
  await assert.rejects(() => session.replaceBlockText(block(session, 'paragraph').id, 'Again.'), /STALE_SOURCE/);
});

test('malformed and hostile source remains bounded, inert, and exact', async () => {
  for (const name of ['security.md', 'malformed-complex.md']) {
    const source = await readFixture(name);
    const session = await DualModeEditorSession.create(source);
    session.switchMode('visual');
    session.switchMode('source');
    assert.equal(session.value(), source);
    assert.equal(session.isDirty(), false);
    assert.ok(session.document.opaqueBlocks().length > 0);
  }
  assert.equal(safeLinkUrl('javascript:alert(1)'), null);
  assert.equal(safeLinkUrl('bad\u0001value'), null);
  assert.equal(safeLinkUrl('also\u0002bad'), null);
  assert.equal(safeMediaReference('data:text/html,bad'), null);
  assert.equal(safeLinkUrl('https://example.com'), 'https://example.com');
  assert.throws(() => boundedOwnRecord(JSON.parse('{"__proto__":{"polluted":true}}'), []), /Unsupported record key/);
  assert.throws(() => boundedOwnRecord(Object.create({polluted: true}), []), /plain record/);
  assert.equal({}.polluted, undefined);
  assert.throws(() => new SourceDocumentAdapter('x'.repeat(2_097_153)), /exceeds/);
  assert.throws(() => new SourceDocumentAdapter('bad\u0000source'), /NUL/);
  assert.throws(
    () => new SourceDocumentAdapter(Array.from({length: 10_001}, (_, index) => `p${index}\n\n`).join('')),
    /block limit/
  );
  const deeplyNested = new SourceDocumentAdapter(`${'> '.repeat(140)}nested\n`);
  assert.equal(deeplyNested.safeBlocks().length, 0);
  assert.equal(deeplyNested.diagnostics[0]?.code, 'nesting-limit');
});

test('selection mapping fails safely for non-literal Markdown source units', async () => {
  const source = 'Escaped \\*asterisk\\* and entity &amp;.\n';
  const session = await DualModeEditorSession.create(source, {mode: 'visual'});
  assert.equal(session.sourceSelectionToVisual(source.indexOf('asterisk'), source.indexOf('asterisk') + 8), null);
  assert.equal(session.visualSelectionToSource(1, 2), null);
});

test('encoded unsafe link targets become opaque rather than safe editor marks', () => {
  const source = '[unsafe](javascript&#58;alert(1))\n';
  const document = new SourceDocumentAdapter(source);
  assert.equal(document.safeBlocks().length, 0);
  assert.equal(document.opaqueBlocks()[0]?.kind, 'unsafe_url');
});

test('task lists and safe media use inert semantic representations', async () => {
  const source = '- [x] done\n- [ ] next\n\n![Alt](images/local.jpg "Title")\n';
  const document = new SourceDocumentAdapter(source);
  const list = document.safeBlocks().find((item) => item.kind === 'bullet_list').node;
  assert.equal(list.child(0).attrs.task, true);
  assert.equal(list.child(0).attrs.checked, true);
  assert.equal(list.child(1).attrs.checked, false);
  const imageParagraph = document.safeBlocks().find((item) => item.kind === 'paragraph').node;
  let image = null;
  imageParagraph.descendants((node) => { if (node.type.name === 'image') image = node; });
  assert.equal(image.attrs.src, 'images/local.jpg');
});

test('adapter initialization stays private and exposes the future Admin2-shaped lifecycle only', async () => {
  const source = '# Proof\n';
  const session = await DualModeEditorSession.create(source, {readOnly: true});
  assert.ok(new VisualEditorAdapter(session.document, {readOnly: true}).state.doc);
  assert.deepEqual(session.adapterContract(), {
    input: 'canonical-string',
    output: 'changed-canonical-string',
    change: 'intentional-content-only',
    dirty: false,
    mode: 'source',
    focus: 'adapter-owned',
    readOnly: true,
    selection: 'utf16-source-offsets-with-safe-failure',
    lifecycle: ['mount', 'focus', 'destroy'],
    theme: 'host-css-inheritance-no-shadow-root',
  });
  const headingStart = source.indexOf('Proof');
  assert.equal(session.visual.setSelection(headingStart, headingStart + 5), true);
  await assert.rejects(() => session.sourceEdit(0, 0, 'x'), /READ_ONLY/);
});
