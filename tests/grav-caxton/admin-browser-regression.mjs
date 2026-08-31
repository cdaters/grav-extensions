import assert from 'node:assert/strict';
import fs from 'node:fs';
import {createRequire} from 'node:module';

const require = createRequire(new URL('./editor/package.json', import.meta.url));
const {chromium} = require('playwright-core');
const baseUrl = (process.env.GRAV_CAXTON_BROWSER_BASE_URL || '').replace(/\/$/, '');
const route = (process.env.GRAV_CAXTON_BROWSER_PAGE_ROUTE || '').replace(/^\/+/, '');
const username = process.env.GRAV_CAXTON_BROWSER_USERNAME || '';
const password = process.env.GRAV_CAXTON_BROWSER_PASSWORD || '';
const screenshotPath = process.env.GRAV_CAXTON_BROWSER_SCREENSHOT || '';
const tag = 'grav-grav-caxton--caxton';
const baseline = '## Visible heading\n\nPlain **bold** and *italic*.\n\n{% protected %}\n';
const candidates = [
  process.env.GRAV_CAXTON_BROWSER_EXECUTABLE,
  '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
  '/Applications/Chromium.app/Contents/MacOS/Chromium',
  '/usr/bin/google-chrome',
  '/usr/bin/chromium',
].filter(Boolean);
const executablePath = candidates.find((candidate) => fs.existsSync(candidate));

assert.ok(baseUrl && route && username && password, 'Caxton browser fixture settings are required.');
assert.ok(executablePath, 'Chrome or Chromium is required.');

const browser = await chromium.launch({executablePath, headless: true});
const context = await browser.newContext({ignoreHTTPSErrors: true, viewport: {width: 1440, height: 1000}});
const page = await context.newPage();
const consoleErrors = [];
const pageErrors = [];
const pageMutations = [];
const httpFailures = [];
page.on('console', (message) => { if (message.type() === 'error') consoleErrors.push(message.text()); });
page.on('pageerror', (error) => pageErrors.push(error.message));
page.on('response', (response) => {
  if (response.status() >= 400) httpFailures.push(`${response.status()} ${new URL(response.url()).pathname}`);
});
page.on('request', (request) => {
  const url = new URL(request.url());
  if (url.pathname.includes('/api/v1/pages/') && ['POST', 'PUT', 'PATCH', 'DELETE'].includes(request.method())) {
    pageMutations.push(`${request.method()} ${url.pathname}`);
  }
});

async function snapshot() {
  return page.evaluate(() => new Promise((resolve, reject) => {
    const timeout = setTimeout(() => reject(new Error('Caxton content response timed out.')), 2500);
    const receive = (event) => {
      clearTimeout(timeout);
      window.removeEventListener('grav:editor:content-response', receive);
      resolve(event.detail || {});
    };
    window.addEventListener('grav:editor:content-response', receive);
    window.dispatchEvent(new CustomEvent('grav:editor:get-content'));
  }));
}

async function insertVisualText(text) {
  await page.evaluate(() => {
    const paragraph = [...document.querySelectorAll('grav-grav-caxton--caxton .ProseMirror p')]
      .find((node) => node.textContent.startsWith('Plain'));
    const range = document.createRange();
    range.setStart(paragraph.firstChild, 6);
    range.collapse(true);
    const selection = getSelection();
    selection.removeAllRanges();
    selection.addRange(range);
    paragraph.closest('.ProseMirror').focus();
  });
  await page.keyboard.type(text);
  await page.waitForTimeout(100);
}

async function selectVisualText(value, collapse = false) {
  await page.evaluate(({text, caret}) => {
    const paragraph = [...document.querySelectorAll('grav-grav-caxton--caxton .ProseMirror p')]
      .find((node) => node.textContent.includes(text));
    const textNode = [...paragraph.childNodes].find((node) => node.nodeType === Node.TEXT_NODE && node.textContent.includes(text));
    const offset = textNode.textContent.indexOf(text);
    const range = document.createRange();
    range.setStart(textNode, offset);
    range.setEnd(textNode, caret ? offset : offset + text.length);
    const selection = getSelection();
    selection.removeAllRanges();
    selection.addRange(range);
    paragraph.closest('.ProseMirror').focus();
  }, {text: value, caret: collapse});
}

const colorAverage = (value) => value.match(/\d+(?:\.\d+)?/g).slice(0, 3).map(Number)
  .reduce((total, channel) => total + channel, 0) / 3;

try {
  await page.goto(`${baseUrl}/admin`, {waitUntil: 'networkidle'});
  await page.getByLabel('Username').fill(username);
  await page.getByLabel('Password').fill(password);
  await page.getByRole('button', {name: 'Sign In'}).click();
  await page.getByRole('link', {name: 'Dashboard', exact: true}).waitFor({state: 'visible', timeout: 15000});

  await page.goto(`${baseUrl}/admin/pages/edit/${route}`, {waitUntil: 'networkidle'});
  const field = page.locator(tag);
  await field.waitFor({state: 'visible', timeout: 15000});
  try {
    await field.locator('.ProseMirror').waitFor({state: 'visible'});
  } catch (error) {
    if (screenshotPath) await page.screenshot({path: screenshotPath, fullPage: true});
    console.error('Caxton mount diagnostics', JSON.stringify({pageErrors, consoleErrors, httpFailures}));
    throw error;
  }
  await page.waitForFunction((selector) => /^[a-f0-9]{64}$/.test(document.querySelector(selector)?.coordinateMap?.identity || ''), tag);
  const opened = await snapshot();
  assert.equal(opened.content, baseline);
  assert.match(await field.evaluate((element) => element.coordinateMap.identity), /^[a-f0-9]{64}$/);
  const visual = await field.locator('.ProseMirror').innerText();
  assert.doesNotMatch(visual, /##|\*\*/);
  assert.match(visual, /Visible heading/);
  assert.match(await field.locator('[data-caxton-opaque="twig"]').innerText(), /\{% protected %\}/);
  const initialRhythm = await field.evaluate((element) => {
    const heading = element.querySelector('.ProseMirror h2');
    const paragraph = element.querySelector('.ProseMirror p');
    return {
      headingBottom: parseFloat(getComputedStyle(heading).marginBottom),
      paragraphBottom: parseFloat(getComputedStyle(paragraph).marginBottom),
    };
  });
  assert.ok(initialRhythm.headingBottom >= 6, JSON.stringify(initialRhythm));
  assert.ok(initialRhythm.paragraphBottom >= 12, JSON.stringify(initialRhythm));
  assert.deepEqual(pageMutations, []);
  if (screenshotPath) await page.screenshot({path: screenshotPath, fullPage: true});
  console.log('PASS: authenticated Caxton field hides Markdown punctuation and protects Twig');

  await field.getByRole('button', {name: 'Source'}).click();
  assert.equal((await snapshot()).content, baseline);
  assert.equal(await field.evaluate((element) => element.source.value()), baseline);
  await field.getByRole('button', {name: 'Visual'}).click();
  assert.deepEqual(pageMutations, []);
  console.log('PASS: exact no-edit source/visual switching emits no persistence request');

  await insertVisualText('changed ');
  const unsaved = (await snapshot()).content;
  assert.equal(unsaved, '## Visible heading\n\nPlain changed **bold** and *italic*.\n\n{% protected %}\n');
  assert.match(await field.locator('[data-caxton-state]').innerText(), /Unsaved changes/);
  assert.deepEqual(pageMutations, []);
  await page.reload({waitUntil: 'networkidle'});
  await page.locator(tag).waitFor({state: 'visible'});
  assert.equal((await snapshot()).content, baseline);
  console.log('PASS: visual edit is localized and reload-before-Save is non-persistent');

  await insertVisualText('saved ');
  const savedValue = '## Visible heading\n\nPlain saved **bold** and *italic*.\n\n{% protected %}\n';
  assert.equal((await snapshot()).content, savedValue);
  await page.getByRole('button', {name: 'Save', exact: true}).click();
  await page.waitForFunction(() => document.body.innerText.includes('Page saved') || document.body.innerText.includes('Saved'));
  assert.ok(pageMutations.length >= 1, 'Ordinary Save did not use the page API.');
  await page.reload({waitUntil: 'networkidle'});
  await page.locator(tag).waitFor({state: 'visible'});
  assert.equal((await snapshot()).content, savedValue);
  console.log('PASS: only the ordinary Admin2 Save persists Caxton content');

  const rhythmMutationCount = pageMutations.length;
  const rhythmSource = 'First paragraph.\n\nSecond paragraph.\n\n# Heading\n\nHeading paragraph.\n\n- First item\n- Second item\n\nAfter list.\n\n> Quoted text.\n\nAfter quote.\n\n```text\ncode\n```\n\nAfter code.\n\n{% protected %}\n\nAfter protected.\n';
  await page.evaluate((content) => window.dispatchEvent(new CustomEvent('grav:editor:insert-content', {detail: {mode: 'replace', content}})), rhythmSource);
  await page.waitForTimeout(100);
  const rhythm = await field.evaluate(async (element) => {
    let styleChanges = 0;
    element.addEventListener('change', () => { styleChanges += 1; });
    const inspect = () => {
      const nodes = [...element.querySelector('.ProseMirror').children];
      return {
        gaps: nodes.slice(1).map((node, index) => Math.round((node.getBoundingClientRect().top - nodes[index].getBoundingClientRect().bottom) * 10) / 10),
        tags: nodes.map((node) => node.dataset.caxtonOpaque ? `opaque:${node.dataset.caxtonOpaque}` : node.tagName.toLowerCase()),
        listStyle: getComputedStyle(element.querySelector('ul')).listStyleType,
        blockquoteMargin: parseFloat(getComputedStyle(element.querySelector('blockquote')).marginTop),
        codeMargin: parseFloat(getComputedStyle(element.querySelector('pre')).marginBottom),
        protectedMargin: parseFloat(getComputedStyle(element.querySelector('[data-caxton-opaque]')).marginTop),
      };
    };
    document.documentElement.classList.remove('dark');
    const light = inspect();
    document.documentElement.classList.add('dark');
    const dark = inspect();
    await new Promise((resolve) => requestAnimationFrame(resolve));
    return {light, dark, styleChanges, content: element.value, state: element.querySelector('[data-caxton-state]').textContent};
  });
  for (const theme of [rhythm.light, rhythm.dark]) {
    assert.ok(theme.gaps.every((gap) => gap >= 8), JSON.stringify(theme));
    assert.notEqual(theme.listStyle, 'none', JSON.stringify(theme));
    assert.ok(theme.blockquoteMargin >= 12 && theme.codeMargin >= 12 && theme.protectedMargin >= 12, JSON.stringify(theme));
  }
  assert.equal(rhythm.styleChanges, 0);
  assert.equal(rhythm.content, rhythmSource);
  assert.equal(rhythm.state, 'Unsaved changes');
  assert.equal(pageMutations.length, rhythmMutationCount);
  await page.evaluate((content) => window.dispatchEvent(new CustomEvent('grav:editor:insert-content', {detail: {mode: 'replace', content}})), savedValue);
  console.log('PASS: signed-in light/dark computed rhythm survives Admin2 reset without content or dirty-state side effects');

  const mutationCount = pageMutations.length;
  await selectVisualText('saved');
  await field.getByRole('button', {name: 'Link', exact: true}).click();
  await field.locator('[data-caxton-link-url]').fill('/linked-page');
  await field.locator('[data-caxton-link-title]').fill('Internal page');
  await field.getByRole('button', {name: 'Apply link'}).click();
  assert.match((await snapshot()).content, /\[saved\]\(\/linked-page "Internal page"\)/);

  await page.evaluate(() => window.dispatchEvent(new CustomEvent('grav:editor:insert-content', {
    detail: {mode: 'replace', content: 'Strike this.\n'},
  })));
  await selectVisualText('Strike');
  await field.getByRole('button', {name: 'Strikethrough'}).click();
  assert.equal((await snapshot()).content, '~~Strike~~ this.\n');

  await page.evaluate(() => window.dispatchEvent(new CustomEvent('grav:editor:insert-content', {
    detail: {mode: 'replace', content: 'Alpha beta.\n\nGamma.\n'},
  })));
  await field.evaluate((element) => { element.visual.setSelection(6, 6); element.visual.focus(); });
  await page.keyboard.press('Enter');
  assert.equal((await snapshot()).content, 'Alpha \n\nbeta.\n\nGamma.\n');
  await field.getByRole('button', {name: 'Undo'}).click();
  assert.equal((await snapshot()).content, 'Alpha beta.\n\nGamma.\n');
  await field.evaluate((element) => { element.visual.setSelection(13, 13); element.visual.focus(); });
  await page.keyboard.press('Backspace');
  assert.equal((await snapshot()).content, 'Alpha beta.Gamma.\n');

  await page.evaluate(() => window.dispatchEvent(new CustomEvent('grav:editor:insert-content', {
    detail: {mode: 'replace', content: 'Toolbar paragraph.\n'},
  })));
  await selectVisualText('Toolbar', true);
  await field.getByRole('button', {name: 'Blockquote'}).click();
  assert.equal((await snapshot()).content, '> Toolbar paragraph.\n');
  await page.evaluate(() => window.dispatchEvent(new CustomEvent('grav:editor:insert-content', {
    detail: {mode: 'replace', content: 'Toolbar paragraph.\n'},
  })));
  await selectVisualText('Toolbar', true);
  await field.getByRole('button', {name: 'Bullet list'}).click();
  assert.equal((await snapshot()).content, '* Toolbar paragraph.\n');
  await field.getByRole('button', {name: 'Numbered list'}).click();
  assert.equal((await snapshot()).content, '1. Toolbar paragraph.\n');

  await page.evaluate(() => window.dispatchEvent(new CustomEvent('grav:editor:insert-content', {
    detail: {mode: 'replace', content: 'Media anchor.\n'},
  })));
  await selectVisualText('Media', true);
  await field.getByRole('button', {name: 'Page media'}).click();
  await field.locator('[data-caxton-media-dialog]').waitFor({state: 'visible'});
  assert.match(await field.locator('[data-caxton-media-list]').innerText(), /fixture\.png/);
  await field.locator('[data-caxton-media-alt]').fill('Fixture image');
  await field.locator('[data-caxton-media-insert]').click();
  assert.match((await snapshot()).content, /!\[Fixture image\]\(fixture\.png\)/);
  assert.equal(pageMutations.length, mutationCount, 'Toolbar actions must remain unsaved-buffer changes.');
  console.log('PASS: links, strikethrough, paragraph structure, quotes, lists, and Admin2 page media remain source-faithful and unsaved');

  await page.evaluate(() => window.dispatchEvent(new CustomEvent('grav:editor:insert-content', {
    detail: {mode: 'replace', content: 'Editable sentence.\n\nUnchanged context.\n'},
  })));
  await selectVisualText('Editable sentence.');
  await field.getByRole('button', {name: 'Jarvis'}).click();
  const jarvisDialog = field.locator('[data-caxton-jarvis-dialog]');
  await jarvisDialog.waitFor({state: 'visible'});
  await field.locator('[data-caxton-jarvis-provider]').selectOption('browser-fixture');
  await field.locator('[data-caxton-jarvis-action]').selectOption('rewrite');
  await field.getByRole('button', {name: 'Generate proposal'}).click();
  await field.locator('[data-caxton-jarvis-result]').waitFor({state: 'visible'});
  assert.equal(await field.locator('[data-caxton-jarvis-original]').innerText(), 'Editable sentence.');
  assert.match(await field.locator('[data-caxton-jarvis-output]').innerText(), /^JARVIS_BROWSER_FIXTURE_RESPONSE:[a-f0-9]{16}$/);
  assert.match(await field.locator('[data-caxton-jarvis-meta]').innerText(), /browser-fixture.*fixture-alpha.*usage/s);
  const beforeProposal = (await snapshot()).content;
  await field.getByRole('button', {name: 'Reject'}).click();
  assert.equal((await snapshot()).content, beforeProposal);

  await field.getByRole('button', {name: 'Generate proposal'}).click();
  await field.locator('[data-caxton-jarvis-result]').waitFor({state: 'visible'});
  const proposed = await field.locator('[data-caxton-jarvis-output]').innerText();
  await field.getByRole('button', {name: 'Accept into unsaved buffer'}).click();
  await page.waitForFunction(({selector, replacement}) => document.querySelector(selector)?.value.includes(replacement), {
    selector: tag,
    replacement: proposed,
  });
  const accepted = (await snapshot()).content;
  assert.equal(accepted, `${proposed}\n\nUnchanged context.\n`);
  assert.equal(pageMutations.length, mutationCount, 'Jarvis Accept must not persist the page.');
  await field.getByRole('button', {name: 'Undo'}).click();
  assert.equal((await snapshot()).content, beforeProposal);
  await field.getByRole('button', {name: 'Redo'}).click();
  assert.equal((await snapshot()).content, accepted);
  await field.getByRole('button', {name: 'Undo'}).click();
  await field.getByRole('button', {name: 'Source'}).click();
  await field.evaluate((element) => {
    const from = element.value.indexOf('Editable sentence.');
    element.source.setSelection(from, from + 'Editable sentence.'.length);
    element.source.focus();
  });
  await field.getByRole('button', {name: 'Jarvis'}).click();
  await jarvisDialog.waitFor({state: 'visible'});
  await field.locator('[data-caxton-jarvis-provider]').selectOption('browser-fixture');
  await field.locator('[data-caxton-jarvis-action]').selectOption('proofread');
  await field.getByRole('button', {name: 'Generate proposal'}).click();
  await field.locator('[data-caxton-jarvis-result]').waitFor({state: 'visible'});
  const sourceProposed = await field.locator('[data-caxton-jarvis-output]').innerText();
  await field.getByRole('button', {name: 'Accept into unsaved buffer'}).click();
  await page.waitForFunction(({selector, replacement}) => document.querySelector(selector)?.value.includes(replacement), {
    selector: tag,
    replacement: sourceProposed,
  });
  assert.equal((await snapshot()).content, `${sourceProposed}\n\nUnchanged context.\n`);
  await field.getByRole('button', {name: 'Undo'}).click();
  assert.equal((await snapshot()).content, beforeProposal);
  await field.getByRole('button', {name: 'Visual'}).click();
  console.log('PASS: deterministic Jarvis proposal preview, Reject, Accept, and one-step undo/redo stay in the unsaved buffer');

  const responsive = await field.evaluate((element) => {
    document.documentElement.classList.add('dark');
    const shell = element.querySelector('.cx-shell');
    const toolbar = element.querySelector('.cx-toolbar');
    return {
      background: getComputedStyle(shell).backgroundColor,
      foreground: getComputedStyle(shell).color,
      toolbar: getComputedStyle(toolbar).backgroundColor,
    };
  });
  assert.ok(colorAverage(responsive.background) < 50, JSON.stringify(responsive));
  assert.ok(colorAverage(responsive.foreground) > 220, JSON.stringify(responsive));
  assert.ok(colorAverage(responsive.toolbar) < 60, JSON.stringify(responsive));
  await field.getByRole('button', {name: 'Source'}).click();
  const sourceTheme = await field.locator('.cm-editor').evaluate((element) => ({
    background: getComputedStyle(element).backgroundColor,
    foreground: getComputedStyle(element).color,
  }));
  assert.ok(colorAverage(sourceTheme.background) < 50, JSON.stringify(sourceTheme));
  assert.ok(colorAverage(sourceTheme.foreground) > 220, JSON.stringify(sourceTheme));
  await field.getByRole('button', {name: 'Visual'}).click();
  await page.setViewportSize({width: 390, height: 844});
  const overflow = await field.evaluate((element) => element.scrollWidth - element.clientWidth);
  assert.ok(overflow <= 1, `Caxton overflows its narrow container by ${overflow}px.`);

  await page.setViewportSize({width: 1440, height: 1000});
  await page.goto(`${baseUrl}/admin/plugins/grav-caxton`, {waitUntil: 'networkidle'});
  await page.getByText('Toolbar items', {exact: true}).waitFor({state: 'visible', timeout: 15000});
  const settingsText = await page.locator('main').innerText().catch(() => page.locator('body').innerText());
  const settingsValues = await page.locator('input').evaluateAll((nodes) => nodes.map((node) => node.value));
  assert.match(settingsText, /Toolbar items/);
  assert.ok(settingsValues.includes('undo'), JSON.stringify(settingsValues));
  assert.ok(settingsValues.includes('blockquote'), JSON.stringify(settingsValues));
  assert.ok(settingsValues.includes('strikethrough'), JSON.stringify(settingsValues));
  assert.ok(settingsValues.includes('horizontal_rule'), JSON.stringify(settingsValues));
  assert.ok(settingsValues.includes('media'), JSON.stringify(settingsValues));
  assert.ok(settingsValues.includes('jarvis'), JSON.stringify(settingsValues));
  assert.ok(settingsValues.includes('source'), JSON.stringify(settingsValues));
  console.log('PASS: Admin2 plugin settings expose the ordered safe toolbar configuration');
  const relevantConsoleErrors = consoleErrors.filter((message) => !message.startsWith('Failed to load resource:'));
  assert.deepEqual(relevantConsoleErrors, []);
  assert.deepEqual(httpFailures.filter((failure) => /caxton|\/gpm\/plugins\/grav-caxton/.test(failure)), []);
  assert.deepEqual(pageErrors, []);
  console.log('PASS: real dark contrast, source palette, narrow layout, and clean browser console');
} finally {
  await context.close();
  await browser.close();
}
