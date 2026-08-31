import assert from 'node:assert/strict';
import fs from 'node:fs';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const { chromium } = require('../grav-jarvis/node_modules/playwright-core');

const baseUrl = (process.env.GRAV_COMMANDER_BROWSER_BASE_URL || '').replace(/\/$/, '');
const username = process.env.GRAV_COMMANDER_BROWSER_USERNAME || '';
const password = process.env.GRAV_COMMANDER_BROWSER_PASSWORD || '';
const mode = process.env.GRAV_COMMANDER_BROWSER_MODE || 'full';
const executableCandidates = [
  process.env.GRAV_COMMANDER_BROWSER_EXECUTABLE,
  '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
  '/Applications/Chromium.app/Contents/MacOS/Chromium',
  '/usr/bin/google-chrome',
  '/usr/bin/chromium',
].filter(Boolean);
const executablePath = executableCandidates.find(candidate => fs.existsSync(candidate));

assert.ok(baseUrl.startsWith('https://') || baseUrl.startsWith('http://'));
assert.ok(username && password, 'Temporary browser credentials are required.');
assert.ok(executablePath, 'Chrome or Chromium is required.');

const browser = await chromium.launch({ executablePath, headless: true });
const context = await browser.newContext({ ignoreHTTPSErrors: true, viewport: { width: 1440, height: 1000 } });
const page = await context.newPage();
const consoleErrors = [];
const pageErrors = [];
const writes = [];
const jarvisRequests = [];

page.on('console', message => { if (message.type() === 'error') consoleErrors.push(message.text()); });
page.on('pageerror', error => pageErrors.push(error.message));
page.on('request', request => {
  const url = new URL(request.url());
  if (url.pathname.endsWith('/api/v1/grav-commander/write')) writes.push(`${request.method()} ${url.pathname}`);
  if (url.pathname.includes('/api/v1/grav-commander/jarvis/')) jarvisRequests.push(`${request.method()} ${url.pathname}`);
});

try {
  await page.goto(`${baseUrl}/admin`, { waitUntil: 'networkidle' });
  await page.getByLabel('Username').fill(username);
  await page.getByLabel('Password').fill(password);
  await page.getByRole('button', { name: 'Sign In' }).click();
  const commanderLink = page.getByRole('link', { name: 'Grav Commander', exact: true });
  await commanderLink.waitFor({ state: 'visible', timeout: 15000 });
  await commanderLink.click();
  await page.waitForURL(url => url.pathname.endsWith('/admin/plugin/grav-commander'), { timeout: 15000 });
  const commander = page.locator('grav-grav-commander--page');
  await commander.waitFor({ state: 'visible', timeout: 15000 });
  await commander.locator('#gc-root').selectOption('pages');
  await page.waitForTimeout(500);
  await commander.locator('#gc-path').fill('jarvis-commander-fixture');
  await commander.locator('#gc-go').click();
  await page.waitForTimeout(500);
  const navigationState = await commander.evaluate(element => ({
    path: element.state?.path,
    items: (element.state?.items || []).map(item => item.name),
    error: element.state?.error,
  }));
  assert.ok(navigationState.items.includes('fixture.md'), `Fixture navigation failed: ${JSON.stringify(navigationState)}`);
  await commander.locator('tr').filter({ hasText: 'fixture.md' }).dblclick();
  await commander.locator('#gc-editor').waitFor({ state: 'visible' });
  const baseline = await commander.locator('#gc-editor').textContent();
  assert.match(baseline || '', /Commander Jarvis Fixture/);
  assert.equal(await commander.getByRole('button', { name: 'Save file' }).isEnabled(), true);
  console.log('PASS: signed-in Commander eligible text editor remains usable');

  if (mode === 'absence') {
    assert.equal(await commander.locator('[aria-label="Jarvis file assistant"]').count(), 0);
    assert.equal(jarvisRequests.length, 1, 'Only graceful status discovery should run when Jarvis is absent.');
    console.log('PASS: Commander remains fully usable with Jarvis absent');
    process.exitCode = 0;
  } else {
    const assistant = commander.locator('[aria-label="Jarvis file assistant"]');
    await assistant.waitFor({ state: 'visible' });
    await assistant.getByLabel('Jarvis provider').selectOption('browser-fixture');
    await assistant.getByRole('button', { name: 'Load models' }).click();
    await assistant.getByText(/2 models loaded/i).waitFor();
    await assistant.getByLabel('Jarvis model').selectOption('fixture-beta');
    await assistant.getByRole('button', { name: 'Check provider' }).click();
    await assistant.getByText('Provider configuration is usable.', { exact: true }).waitFor();
    console.log('PASS: public provider validation and neutral model discovery render in Commander');

    await assistant.getByLabel('Jarvis action').selectOption('review');
    await assistant.getByRole('button', { name: 'Run Jarvis action' }).click();
    await assistant.getByText(/Jarvis result ready/i).waitFor();
    await assistant.locator('.gc-jarvis-result pre').first().waitFor({ state: 'visible' });
    assert.match(await assistant.locator('.gc-jarvis-meta').last().innerText(), /characters.*cost unknown.*1 request/i);
    assert.equal(writes.length, 0, 'Read-only Jarvis action wrote the file.');
    await assistant.getByRole('button', { name: 'Reject / dismiss' }).click();
    assert.equal(await commander.locator('#gc-editor').textContent(), baseline);
    console.log('PASS: deterministic review renders usage/cost and Reject preserves the buffer');

    await assistant.getByLabel('Jarvis action').selectOption('improve');
    await assistant.getByRole('button', { name: 'Run Jarvis action' }).click();
    await assistant.getByRole('button', { name: 'Apply to unsaved editor' }).waitFor({ state: 'visible' });
    const proposed = await assistant.locator('.gc-jarvis-diff section').nth(1).locator('pre').innerText();
    await assistant.getByRole('button', { name: 'Apply to unsaved editor' }).click();
    await assistant.getByText(/Applied to the unsaved editor buffer/i).waitFor();
    assert.equal(await commander.locator('#gc-editor').textContent(), proposed);
    assert.equal(writes.length, 0, 'Apply called Commander write.');
    await page.reload({ waitUntil: 'networkidle' });
    const reloaded = page.locator('grav-grav-commander--page');
    await reloaded.locator('#gc-root').waitFor({ state: 'visible' });
    await reloaded.locator('#gc-root').selectOption('pages');
    await page.waitForTimeout(500);
    await reloaded.locator('#gc-path').fill('jarvis-commander-fixture');
    await reloaded.locator('#gc-go').click();
    await reloaded.locator('tr').filter({ hasText: 'fixture.md' }).dblclick();
    await reloaded.locator('#gc-editor').waitFor({ state: 'visible' });
    assert.equal(await reloaded.locator('#gc-editor').textContent(), baseline, 'Unsaved Jarvis proposal persisted after reload.');
    console.log('PASS: one-time Apply changes only the unsaved buffer and reload restores disk content');

    const reloadedAssistant = reloaded.locator('[aria-label="Jarvis file assistant"]');
    await reloadedAssistant.getByLabel('Jarvis provider').selectOption('browser-unavailable');
    await reloadedAssistant.getByLabel('Jarvis action').selectOption('review');
    await reloadedAssistant.getByRole('button', { name: 'Run Jarvis action' }).click();
    await reloadedAssistant.locator('[role="alert"]').waitFor({ state: 'visible' });
    assert.match(await reloadedAssistant.locator('[role="alert"]').innerText(), /provider.*validated|unavailable/i);
    assert.equal(await reloaded.getByRole('button', { name: 'Save file' }).isEnabled(), true);
    assert.equal(writes.length, 0);
    console.log('PASS: provider failure is visible while Commander remains usable');
  }

  const unexpectedConsoleErrors = consoleErrors.filter(message =>
    !message.includes('spitfirebbs.com/user/data/grav-security-probe.dat')
    && !message.includes('net::ERR_FAILED')
    && !message.includes('status of 503')
  );
  assert.deepEqual(unexpectedConsoleErrors, [], `Unexpected browser console errors: ${unexpectedConsoleErrors.join(' | ')}`);
  assert.deepEqual(pageErrors, [], `Browser page errors: ${pageErrors.join(' | ')}`);
  console.log('PASS: no unexpected Commander/Jarvis console or page errors');
} finally {
  await context.close();
  await browser.close();
}
