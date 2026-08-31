import assert from 'node:assert/strict';
import {createServer} from 'node:http';
import {readFile, stat} from 'node:fs/promises';
import {extname, resolve} from 'node:path';
import {fileURLToPath} from 'node:url';
import {chromium} from 'playwright-core';

const editorRoot = resolve(fileURLToPath(new URL('..', import.meta.url)));
const repositoryRoot = resolve(editorRoot, '../../..');
const browserCandidates = [
  process.env.CAXTON_CHROME_PATH,
  '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
  '/usr/bin/google-chrome',
  '/usr/bin/google-chrome-stable',
  '/usr/bin/chromium',
  '/usr/bin/chromium-browser',
].filter(Boolean);
let chrome = null;
for (const candidate of browserCandidates) {
  try {
    await stat(candidate);
    chrome = candidate;
    break;
  } catch {
    // Try the next explicit system-browser path.
  }
}
if (chrome === null) throw new Error('System Chrome/Chromium not found; set CAXTON_CHROME_PATH.');

const mime = {'.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8'};
const server = createServer(async (request, response) => {
  try {
    const path = request.url === '/' ? resolve(editorRoot, 'test/proof.html') : resolve(repositoryRoot, `.${request.url}`);
    if (!path.startsWith(repositoryRoot)) throw new Error('outside root');
    const body = await readFile(path);
    response.writeHead(200, {'content-type': mime[extname(path)] ?? 'application/octet-stream'});
    response.end(body);
  } catch {
    response.writeHead(404);
    response.end('Not found');
  }
});
await new Promise((resolveReady) => server.listen(0, '127.0.0.1', resolveReady));
const address = server.address();

const browser = await chromium.launch({executablePath: chrome, headless: true});
const page = await browser.newPage();
const failures = [];
page.on('console', (message) => { if (message.type() === 'error') failures.push(message.text()); });
page.on('pageerror', (error) => failures.push(error.message));

try {
  await page.goto(`http://127.0.0.1:${address.port}/`, {waitUntil: 'load'});
  const result = await page.evaluate(async () => {
    window.__caxtonExecuted = false;
    const proof = await import('/plugins/grav-caxton/admin-next/proof/caxton-editor.js');
    const source = '# Heading\n\nParagraph with **bold** and [link](https://example.com).\n\n<script>window.__caxtonExecuted = true;</script>\n\n{% dangerous() %}\n';
    const session = await proof.DualModeEditorSession.create(source, {mode: 'visual'});
    session.visual.mount(document.querySelector('#visual'));
    session.source.mount(document.querySelector('#source'));
    session.visual.focus();
    const visualFocused = document.activeElement?.classList.contains('ProseMirror') ?? false;
    session.source.focus();
    const sourceFocused = document.activeElement?.closest('.cm-editor') !== null;
    const boldStart = source.indexOf('bold');
    const mapped = session.sourceSelectionToVisual(boldStart, boldStart + 4);
    const reverse = mapped ? session.visualSelectionToSource(mapped.from, mapped.to) : null;
    const noEdit = session.switchMode('source').dirty === false && session.switchMode('visual').dirty === false;
    const opaque = [...document.querySelectorAll('[data-caxton-opaque]')].map((node) => ({
      text: node.textContent,
      html: node.innerHTML,
      role: node.getAttribute('role'),
      editable: node.getAttribute('contenteditable'),
      tabIndex: node.tabIndex,
    }));
    const firstOpaque = document.querySelector('[data-caxton-opaque]');
    firstOpaque?.focus();
    const opaqueFocused = document.activeElement === firstOpaque;
    const sourceBefore = session.source.value();
    session.source.applyChange(0, 1, '##');
    const sourceAfter = session.source.value();
    session.source.setReadOnly(true);
    const sourceReadOnly = session.source.state.readOnly;
    const visualRole = document.querySelector('.ProseMirror')?.getAttribute('role');
    const sourceLabel = document.querySelector('.cm-content')?.getAttribute('aria-label');
    session.visual.destroy();
    session.source.destroy();
    return {
      moduleKeys: Object.keys(proof).sort(),
      leakedGlobal: 'GravCaxtonProof' in window,
      executed: window.__caxtonExecuted,
      visualFocused,
      sourceFocused,
      mapped,
      reverse,
      noEdit,
      dirty: session.isDirty(),
      opaque,
      opaqueFocused,
      sourceBefore,
      sourceAfter,
      sourceReadOnly,
      visualRole,
      sourceLabel,
    };
  });
  assert.ok(result.moduleKeys.includes('DualModeEditorSession'));
  assert.equal(result.leakedGlobal, false);
  assert.equal(result.executed, false);
  assert.equal(result.visualFocused, true);
  assert.equal(result.sourceFocused, true);
  assert.ok(result.mapped);
  assert.deepEqual(result.reverse, {from: result.sourceBefore.indexOf('bold'), to: result.sourceBefore.indexOf('bold') + 4});
  assert.equal(result.noEdit, true);
  assert.equal(result.dirty, false);
  assert.ok(result.opaque.length >= 2);
  assert.ok(result.opaque.every((item) => item.role === 'note' && item.editable === 'false'));
  assert.ok(result.opaque.every((item) => item.tabIndex === 0));
  assert.equal(result.opaqueFocused, true);
  assert.ok(result.opaque.every((item) => !item.html.includes('<script>')));
  assert.notEqual(result.sourceAfter, result.sourceBefore);
  assert.equal(result.sourceReadOnly, true);
  assert.equal(result.visualRole, 'textbox');
  assert.equal(result.sourceLabel, 'Caxton source editor proof');

  const fieldResult = await page.evaluate(async () => {
    window.__GRAV_FIELD_TAG = 'grav-test--caxton';
    await import('/plugins/grav-caxton/admin-next/fields/caxton.js');
    const field = document.createElement('grav-test--caxton');
    const source = '## Visible heading\n\nPlain **bold** and *italic*.\n\n{% protected %}\n';
    const changes = [];
    field.field = {name: 'content', caxton: {allow_source: true}};
    field.value = source;
    field.addEventListener('change', (event) => changes.push(event.detail));
    document.body.replaceChildren(field);
    await new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve)));
    const visualText = field.querySelector('.ProseMirror')?.textContent || '';
    field.querySelector('[data-mode="source"]').click();
    const sourceAfterSwitch = field.source.value();
    field.querySelector('[data-mode="visual"]').click();
    window.__caxtonField = field;
    window.__caxtonFieldChanges = changes;
    return {
      source,
      visualText,
      sourceAfterSwitch,
      changesAfterSwitch: changes.length,
      mode: field.mode,
      opaqueText: field.querySelector('[data-caxton-opaque="twig"]')?.textContent || '',
      toolbarLabel: field.querySelector('[role="toolbar"]')?.getAttribute('aria-label'),
    };
  });
  assert.equal(fieldResult.visualText.includes('##'), false, 'Visual mode must hide heading Markdown punctuation.');
  assert.equal(fieldResult.visualText.includes('**'), false, 'Visual mode must hide inline Markdown punctuation.');
  assert.ok(fieldResult.visualText.includes('Visible heading'));
  assert.ok(fieldResult.visualText.includes('bold'));
  assert.equal(fieldResult.sourceAfterSwitch, fieldResult.source);
  assert.equal(fieldResult.changesAfterSwitch, 0, 'Mode changes must not emit content changes.');
  assert.equal(fieldResult.mode, 'visual');
  assert.ok(fieldResult.opaqueText.includes('{% protected %}'));
  assert.equal(fieldResult.toolbarLabel, 'Caxton editor tools');

  await page.evaluate(() => {
    const paragraph = [...document.querySelectorAll('.ProseMirror p')].find((node) => node.textContent.startsWith('Plain'));
    const range = document.createRange();
    range.setStart(paragraph.firstChild, 6);
    range.collapse(true);
    const selection = getSelection();
    selection.removeAllRanges();
    selection.addRange(range);
    paragraph.closest('.ProseMirror').focus();
  });
  await page.keyboard.type('changed ');
  const edited = await page.evaluate(async () => {
    await new Promise((resolve) => requestAnimationFrame(resolve));
    const field = window.__caxtonField;
    let snapshot = null;
    const receive = (event) => { snapshot = event.detail; };
    window.addEventListener('grav:editor:content-response', receive, {once: true});
    window.dispatchEvent(new CustomEvent('grav:editor:get-content'));
    await new Promise((resolve) => setTimeout(resolve, 20));
    return {
      value: field.value,
      changes: window.__caxtonFieldChanges.slice(),
      snapshot,
      state: field.querySelector('[data-caxton-state]').textContent,
    };
  });
  assert.ok(edited.value.includes('Plain changed **bold** and *italic*.'), edited.value);
  assert.ok(edited.value.includes('{% protected %}'));
  assert.equal(edited.changes.at(-1), edited.value);
  assert.equal(edited.snapshot.content, edited.value);
  assert.match(edited.snapshot.sourceIdentity, /^[a-f0-9]{64}$/);
  assert.equal(edited.state, 'Unsaved changes');

  const replaced = await page.evaluate(() => {
    const replacement = '# Replacement\n\nNo persistence request.\n';
    window.dispatchEvent(new CustomEvent('grav:editor:insert-content', {detail: {mode: 'replace', content: replacement}}));
    return {value: window.__caxtonField.value, change: window.__caxtonFieldChanges.at(-1)};
  });
  assert.equal(replaced.value, '# Replacement\n\nNo persistence request.\n');
  assert.equal(replaced.change, replaced.value);
  assert.deepEqual(failures, []);
  process.stdout.write('Caxton real-browser engine and Admin2-field component proof passed.\n');
} finally {
  await page.close();
  await browser.close();
  await new Promise((resolveClosed) => server.close(resolveClosed));
}
