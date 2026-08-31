import assert from 'node:assert/strict';
import fs from 'node:fs';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const { chromium } = require('playwright-core');

const baseUrl = (process.env.GRAV_JARVIS_BROWSER_BASE_URL || '').replace(/\/$/, '');
const pageRoute = `/${(process.env.GRAV_JARVIS_BROWSER_PAGE_ROUTE || 'archive').replace(/^\/+/, '')}`;
const assistantTag = 'grav-grav-jarvis--page';
const panelTag = 'grav-grav-jarvis--panel';
const username = process.env.GRAV_JARVIS_BROWSER_USERNAME || '';
const password = process.env.GRAV_JARVIS_BROWSER_PASSWORD || '';
const executableCandidates = [
  process.env.GRAV_JARVIS_BROWSER_EXECUTABLE,
  '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
  '/Applications/Chromium.app/Contents/MacOS/Chromium',
  '/usr/bin/google-chrome',
  '/usr/bin/chromium',
  '/usr/bin/chromium-browser',
].filter(Boolean);
const executablePath = executableCandidates.find(candidate => fs.existsSync(candidate));

assert.ok(baseUrl.startsWith('https://') || baseUrl.startsWith('http://'), 'GRAV_JARVIS_BROWSER_BASE_URL is required.');
assert.ok(username && password, 'Temporary browser-test credentials are required.');
assert.ok(executablePath, 'Set GRAV_JARVIS_BROWSER_EXECUTABLE to Chrome or Chromium.');

const browser = await chromium.launch({ executablePath, headless: true });
const context = await browser.newContext({ ignoreHTTPSErrors: true, viewport: { width: 1440, height: 1000 } });
const page = await context.newPage();
const consoleErrors = [];
const pageErrors = [];
const pageMutationRequests = [];
const proposalRequests = [];
const jarvisHttpFailures = [];

page.on('console', message => {
  if (message.type() === 'error') consoleErrors.push(message.text());
});
page.on('pageerror', error => pageErrors.push(error.message));
page.on('response', response => {
  const url = new URL(response.url());
  if (response.status() >= 400 && url.pathname.includes('grav-jarvis')) {
    jarvisHttpFailures.push({ status: response.status(), path: url.pathname });
  }
});
page.on('request', request => {
  const url = new URL(request.url());
  const method = request.method();
  if (url.pathname.includes('/api/v1/pages/') && ['POST', 'PUT', 'PATCH', 'DELETE'].includes(method)) {
    pageMutationRequests.push(`${method} ${url.pathname}`);
  }
  if (url.pathname.endsWith('/api/v1/grav-jarvis/proposals') && method === 'POST') {
    proposalRequests.push(JSON.parse(request.postData() || '{}'));
  }
});

async function editorSnapshot() {
  return page.evaluate(() => new Promise((resolve, reject) => {
    const timer = setTimeout(() => reject(new Error('editor snapshot timed out')), 2500);
    const receive = event => {
      clearTimeout(timer);
      window.removeEventListener('grav:editor:content-response', receive);
      resolve(event.detail || {});
    };
    window.addEventListener('grav:editor:content-response', receive);
    window.dispatchEvent(new CustomEvent('grav:editor:get-content'));
  }));
}

async function replaceBuffer(content) {
  await page.evaluate(value => {
    window.dispatchEvent(new CustomEvent('grav:editor:insert-content', { detail: { content: value, mode: 'replace' } }));
  }, content);
  await page.waitForTimeout(80);
  assert.equal((await editorSnapshot()).content, content, 'Public replace-buffer event did not update the editor.');
}

async function selectProvider(surface, providerId) {
  await surface.getByLabel('Provider', { exact: true }).selectOption(providerId);
  await surface.locator('main').waitFor({ state: 'visible' });
  await page.waitForTimeout(120);
}

try {
  await page.goto(`${baseUrl}/admin`, { waitUntil: 'networkidle' });
  await page.getByLabel('Username').fill(username);
  await page.getByLabel('Password').fill(password);
  await page.getByRole('button', { name: 'Sign In' }).click();
  const jarvisLink = page.getByRole('link', { name: 'Jarvis', exact: true });
  await jarvisLink.waitFor({ state: 'visible', timeout: 15000 });
  console.log('PASS: authenticated Admin2 login');

  await jarvisLink.click();
  await page.waitForURL(url => url.pathname.endsWith('/admin/plugin/grav-jarvis'), { timeout: 15000 });
  const assistant = page.locator(assistantTag);
  try {
    await assistant.waitFor({ state: 'visible' });
  } catch (error) {
    console.error(`Jarvis page diagnostic: ${page.url()} :: ${(await page.getByRole('main').first().innerText()).slice(0, 500)}`);
    throw error;
  }
  await assistant.locator('#provider option').filter({ hasText: 'Browser Fixture' }).waitFor({ state: 'attached' });
  await selectProvider(assistant, 'browser-fixture');
  await assistant.locator('.status.ready').waitFor();
  await assistant.getByLabel('Model', { exact: true }).selectOption('fixture-beta');
  assert.equal(await assistant.getByLabel('Model', { exact: true }).inputValue(), 'fixture-beta');
  console.log('PASS: Jarvis Admin page and provider/model selectors');

  const securityBoundary = await page.evaluate(async () => {
    const element = document.querySelector('grav-grav-jarvis--page');
    const anonymous = await fetch(element.apiUrl('/grav-jarvis/bootstrap'), {
      headers: { Accept: 'application/json' }, credentials: 'omit', cache: 'no-store',
    });
    let injected;
    try {
      await element.api('/grav-jarvis/completions', {
        method: 'POST',
        body: {
          provider_id: 'browser-fixture', prompt: 'test', endpoint_url: 'https://invalid.example',
          environment_variable: 'GRAV_JARVIS_INVALID_API_KEY',
        },
      });
      injected = { accepted: true };
    } catch (error) {
      injected = { accepted: false, status: error.status };
    }
    return { anonymous: anonymous.status, injected };
  });
  assert.equal(securityBoundary.anonymous, 401, 'A browser request without the Admin2 API token was not denied.');
  assert.equal(securityBoundary.injected.accepted, false, 'Provider authority injection was accepted.');
  assert.equal(securityBoundary.injected.status, 422, 'Provider authority injection did not fail validation.');
  console.log('PASS: API token and fixed provider-authority boundary');

  await selectProvider(assistant, 'browser-missing');
  await assistant.getByText('Needs configuration', { exact: true }).waitFor();
  assert.match(await assistant.locator('#provider-note').innerText(), /credential.*server environment/i);
  assert.equal(await assistant.getByRole('button', { name: 'Ask Jarvis' }).isDisabled(), true);
  await selectProvider(assistant, 'browser-unavailable');
  await assistant.getByText('Temporarily unavailable', { exact: true }).waitFor();
  assert.equal(await assistant.getByRole('button', { name: 'Ask Jarvis' }).isDisabled(), true);
  console.log('PASS: missing-credential and provider-unavailable states');

  await selectProvider(assistant, 'browser-flaky');
  await assistant.locator('.status.ready').waitFor();
  await assistant.getByRole('textbox', { name: 'What can Jarvis help with?' }).fill('Run the deterministic retry check.');
  await assistant.getByRole('button', { name: 'Ask Jarvis' }).click();
  await assistant.getByText(/JARVIS_BROWSER_FIXTURE_RESPONSE:/).waitFor();
  await assistant.getByText(/2 requests, 1 retry/i).waitFor();
  assert.equal(await assistant.getByRole('button', { name: 'Retry request' }).count(), 0);
  console.log('PASS: bounded automatic recovery from one typed rate-limit failure');

  await page.goto(`${baseUrl}/admin/pages/edit/${pageRoute.slice(1)}`, { waitUntil: 'networkidle' });
  const launcher = page.getByRole('button', { name: 'Jarvis', exact: true }).last();
  await launcher.waitFor({ state: 'visible', timeout: 15000 });
  await launcher.click();
  const panel = page.locator(panelTag);
  await panel.waitFor({ state: 'visible', timeout: 15000 });
  await panel.locator('#provider option').filter({ hasText: 'Browser Fixture' }).waitFor({ state: 'attached' });
  await selectProvider(panel, 'browser-fixture');
  await panel.locator('.status.ready').waitFor();
  await panel.getByLabel('Model', { exact: true }).selectOption('fixture-beta');
  const baseline = String((await editorSnapshot()).content ?? '');
  assert.ok(baseline.length > 0, 'The page-editor regression needs a non-empty fixture page.');
  console.log('PASS: page-editor Jarvis launcher and authenticated panel');

  const actions = [
    ['rewrite', 'Rewrite'], ['proofread', 'Proofread'], ['shorten', 'Shorten'],
    ['expand', 'Expand'], ['summarize', 'Summarize'], ['custom', 'Custom Prompt'],
  ];
  const proposalIds = new Set();
  for (const [action, label] of actions) {
    await replaceBuffer(baseline);
    const actionButton = panel.getByRole('button', { name: label, exact: true });
    await actionButton.focus();
    await actionButton.press('Space');
    assert.equal(await actionButton.getAttribute('aria-pressed'), 'true', `${label} was not keyboard-selectable.`);
    if (action === 'custom') await panel.getByLabel('Instruction').fill('Keep every original line and add only the fixture marker.');

    await panel.getByRole('button', { name: 'Create proposal' }).click();
    await panel.getByRole('heading', { name: `${label} proposal` }).waitFor();
    const first = await page.evaluate(() => document.querySelector('grav-grav-jarvis--panel')?.state?.proposal);
    assert.equal(first.action, action, `${label} sent the wrong action identifier.`);
    assert.equal(first.context.content_bytes, Buffer.byteLength(baseline), `${label} did not report bounded source bytes.`);
    assert.equal(first.context.truncated.content, false, `${label} unexpectedly truncated the fixture page.`);
    assert.ok(first.proposed_content.startsWith(`JARVIS_BROWSER_FIXTURE_ACTION:${action}\n`), `${label} returned the wrong fixture action.`);
    assert.equal(first.proposed_content.slice(first.proposed_content.indexOf('\n') + 1), baseline, `${label} mutated unrelated buffer content.`);
    proposalIds.add(first.proposal_id);
    await panel.getByRole('button', { name: 'Reject and close' }).click();
    await panel.getByText(/rejected and closed/i).waitFor();
    assert.equal((await editorSnapshot()).content, baseline, `${label} Reject changed the editor.`);

    await panel.getByRole('button', { name: 'Create proposal' }).click();
    await panel.getByRole('heading', { name: `${label} proposal` }).waitFor();
    const second = await page.evaluate(() => document.querySelector('grav-grav-jarvis--panel')?.state?.proposal);
    assert.notEqual(second.proposal_id, first.proposal_id, `${label} reused a rejected proposal.`);
    proposalIds.add(second.proposal_id);
    await panel.getByRole('button', { name: 'Accept into editor' }).click();
    await panel.getByText(/Accepted into the unsaved editor buffer/i).waitFor();
    assert.equal((await editorSnapshot()).content, second.proposed_content, `${label} did not update only the editor buffer.`);

    const duplicate = await page.evaluate(async ({ id, source, proposed, route }) => {
      const element = document.querySelector('grav-grav-jarvis--panel');
      try {
        await element.api(`/grav-jarvis/proposals/${id}/accept`, {
          method: 'POST', body: { route: route, current_content: source, proposed_content: proposed },
        });
        return { accepted: true };
      } catch (error) {
        return { accepted: false, status: error.status, code: error.code };
      }
    }, { id: second.proposal_id, source: baseline, proposed: second.proposed_content, route: pageRoute });
    assert.equal(duplicate.accepted, false, `${label} proposal was accepted twice.`);
    assert.equal(duplicate.status, 409, `${label} duplicate acceptance did not conflict.`);
  }
  assert.equal(proposalIds.size, actions.length * 2, 'Proposal identifiers were not unique.');
  assert.deepEqual(new Set(proposalRequests.map(item => item.action)), new Set(actions.map(([id]) => id)));
  console.log('PASS: all six whole-buffer actions support Reject and one-time Accept');

  await replaceBuffer(baseline);
  await panel.getByRole('button', { name: 'Rewrite', exact: true }).click();
  await panel.getByRole('button', { name: 'Create proposal' }).click();
  await panel.getByRole('heading', { name: 'Rewrite proposal' }).waitFor();
  const stale = await page.evaluate(() => document.querySelector('grav-grav-jarvis--panel')?.state?.proposal);
  const changed = `${baseline}\n\nMaterial editor change.`;
  await replaceBuffer(changed);
  await panel.getByRole('button', { name: 'Accept into editor' }).click();
  await panel.getByText(/stale, expired, or already accepted/i).waitFor();
  assert.equal((await editorSnapshot()).content, changed, 'Stale acceptance changed the editor.');
  assert.equal(await page.evaluate(() => document.querySelector('grav-grav-jarvis--panel')?.state?.proposal?.proposal_id), stale.proposal_id, 'Stale failure destroyed the proposal before regeneration.');
  await panel.getByRole('button', { name: 'Generate new proposal' }).click();
  await page.waitForFunction(previous => {
    const current = document.querySelector('grav-grav-jarvis--panel')?.state?.proposal?.proposal_id;
    return typeof current === 'string' && current !== previous;
  }, stale.proposal_id);
  const regenerated = await page.evaluate(() => document.querySelector('grav-grav-jarvis--panel')?.state?.proposal);
  assert.notEqual(regenerated.proposal_id, stale.proposal_id, 'Stale retry reused the old proposal.');
  assert.ok(regenerated.proposed_content.endsWith(changed), 'Stale retry did not use the current editor buffer.');
  await panel.getByRole('button', { name: 'Reject and close' }).click();
  console.log('PASS: stale proposal denial and fresh bounded regeneration');

  await replaceBuffer(baseline);
  await panel.getByRole('button', { name: 'Proofread', exact: true }).click();
  await panel.getByRole('button', { name: 'Create proposal' }).click();
  await panel.getByRole('heading', { name: 'Proofread proposal' }).waitFor();
  await panel.getByRole('button', { name: 'Accept into editor' }).click();
  await panel.getByText(/Accepted into the unsaved editor buffer/i).waitFor();
  assert.notEqual((await editorSnapshot()).content, baseline, 'Navigation test did not create an unsaved edit.');
  assert.deepEqual(pageMutationRequests, [], 'Jarvis triggered a page save/publish request.');
  await page.reload({ waitUntil: 'networkidle' });
  assert.equal((await editorSnapshot()).content, baseline, 'Reload found a persisted Jarvis edit.');
  console.log('PASS: Accept remains unsaved across navigation/reload');

  await launcher.waitFor({ state: 'visible' });
  await launcher.click();
  await panel.waitFor({ state: 'visible' });
  await page.setViewportSize({ width: 390, height: 844 });
  const narrowOverflow = await panel.evaluate(element => {
    const main = element.shadowRoot.querySelector('main');
    return main.scrollWidth - main.clientWidth;
  });
  assert.ok(narrowOverflow <= 1, `Narrow Jarvis panel overflows by ${narrowOverflow}px.`);
  const themeColors = await panel.evaluate(element => {
    const main = element.shadowRoot.querySelector('main');
    const light = getComputedStyle(main).backgroundColor;
    document.documentElement.classList.add('dark');
    const dark = getComputedStyle(main).backgroundColor;
    document.documentElement.classList.remove('dark');
    return { light, dark };
  });
  assert.notEqual(themeColors.light, 'rgba(0, 0, 0, 0)');
  assert.notEqual(themeColors.dark, 'rgba(0, 0, 0, 0)');
  assert.notEqual(themeColors.light, themeColors.dark, 'Jarvis did not inherit light/dark Admin2 colors.');
  console.log('PASS: keyboard, accessible labels, narrow layout, and light/dark inheritance');

  assert.deepEqual(pageMutationRequests, [], 'Jarvis issued an unexpected page mutation request.');
  assert.deepEqual(pageErrors, [], `Uncaught page errors: ${pageErrors.join(' | ')}`);
  const unexpectedHttp = jarvisHttpFailures.filter(item => ![401, 409, 422, 429].includes(item.status));
  assert.deepEqual(unexpectedHttp, [], `Unexpected Jarvis HTTP failures: ${JSON.stringify(unexpectedHttp)}`);
  const unexpectedConsole = consoleErrors.filter(message =>
    !message.startsWith('Failed to load resource:')
    && !message.includes('grav-security-probe.dat')
  );
  assert.deepEqual(unexpectedConsole, [], `Unexpected console errors: ${unexpectedConsole.join(' | ')}`);
  console.log('PASS: no page save/publish, uncaught error, or console error');
  console.log('Jarvis authenticated Admin2 browser regression passed (11 checks).');
} finally {
  await context.close();
  await browser.close();
}
