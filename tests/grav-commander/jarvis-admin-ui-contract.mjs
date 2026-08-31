import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const plugin = process.env.GRAV_COMMANDER_PLUGIN_DIR || path.resolve(here, '../../plugins/grav-commander');
const source = fs.readFileSync(path.join(plugin, 'admin-next/pages/grav-commander.js'), 'utf8');

class ShadowStub {
  innerHTML = '';
  values = new Map();
  querySelector(selector) { return this.values.get(selector) || null; }
  querySelectorAll() { return []; }
}

class ElementStub {
  attachShadow() { this.shadowRoot = new ShadowStub(); return this.shadowRoot; }
}

const registry = new Map();
const windowStub = {
  __GRAV_PAGE_TAG: 'test-grav-commander-page',
  __GRAV_API_SERVER_URL: 'https://admin.test',
  __GRAV_API_PREFIX: '/api/v1',
  __GRAV_API_TOKEN: 'admin-session-token',
  location: { pathname: '/admin/plugin/grav-commander' },
};
const context = {
  window: windowStub,
  HTMLElement: ElementStub,
  customElements: { get: tag => registry.get(tag), define: (tag, value) => registry.set(tag, value) },
  localStorage: { getItem: () => null, setItem: () => {} },
  navigator: { clipboard: { writeText: async () => {} } },
  MutationObserver: class { observe() {} disconnect() {} },
  fetch: async () => { throw new Error('unexpected network'); },
  setTimeout, clearTimeout, console, URL, JSON, Promise, String, Number, Boolean, Array, Object, Math,
};
vm.runInNewContext(source, context, { filename: 'grav-commander.js' });
const CommanderPage = registry.get('test-grav-commander-page');
assert.ok(CommanderPage, 'Commander component was not registered');

for (const forbidden of [
  /api\.openai\.com/i,
  /api\.anthropic\.com/i,
  /GRAV_JARVIS_[A-Z_]*KEY/,
  /endpoint_url/i,
  /authorization_header/i,
  /environment_variable/i,
]) {
  assert.doesNotMatch(source, forbidden, `Browser source must not expose provider authority: ${forbidden}`);
}
assert.match(source, /credentials:\s*['"]omit['"]/, 'Commander API calls must omit browser credentials and use its token');
assert.match(source, /cache:\s*['"]no-store['"]/, 'Commander Jarvis calls must not be browser cached');
assert.match(source, /X-API-Token/, 'Commander must use Admin2 API authentication');
assert.match(source, /grav-commander\/jarvis\/proposals/, 'Commander must use its guarded optional-consumer endpoint');
console.log('PASS: browser transport is authenticated, non-cacheable, and provider-authority-free');

const page = new CommanderPage();
page.setState = patch => { page.state = { ...page.state, ...patch }; };
page.state.root = 'pages';
page.state.file = {
  root: 'pages', path: 'guide.md', name: 'guide.md', extension: 'md',
  content: 'disk snapshot', editable: true, viewable: true,
};
page.state.jarvisProvider = 'fixture';
page.state.jarvisModel = 'fixture-a';
page.state.jarvisAction = 'improve';
page.shadowRoot.values.set('#gc-editor', { value: 'current unsaved buffer' });
page.shadowRoot.values.set('#gc-jarvis-custom', { value: '' });

const calls = [];
page.api = async (route, options = {}) => {
  calls.push({ route, options });
  if (route === '/grav-commander/jarvis/proposals') {
    return {
      proposal_id: 'a'.repeat(32), action: 'improve', mode: 'proposal',
      provider_id: 'fixture', model: 'fixture-a', output: 'proposed unsaved buffer',
      usage: { total: 42, unit: 'characters', request_count: 2, retry_count: 1, cache_hit: false },
      cost: { currency: 'USD', estimated_amount: '0.000100' },
      reliability: { attempts: 2, retry_count: 1, cache_hit: false },
      context: { root: 'pages', path: 'guide.md', accept_allowed: true, truncated: false, redacted: false, chunked: false },
    };
  }
  throw new Error(`unexpected route ${route}`);
};
await page.runJarvisAction();
assert.equal(calls.length, 1);
const request = JSON.parse(calls[0].options.body);
assert.equal(request.content, 'current unsaved buffer', 'Jarvis must receive the current unsaved buffer');
assert.deepEqual(Object.keys(request).sort(), ['action', 'content', 'custom_instruction', 'model', 'path', 'provider_id', 'root']);
assert.equal(page.state.file.content, 'current unsaved buffer', 'Unsaved buffer must survive the action render cycle');
assert.equal(page.state.jarvisProposal.output, 'proposed unsaved buffer');
assert.equal(calls.some(call => /\/write$/.test(call.route)), false, 'Generating a proposal must not save the file');
console.log('PASS: eligible action sends only bounded file identity and the current unsaved buffer');

page.shadowRoot.values.set('#gc-editor', { value: 'current unsaved buffer' });
page.api = async (route, options = {}) => {
  calls.push({ route, options });
  if (route.endsWith('/accept')) return { accepted: true, content: 'proposed unsaved buffer' };
  throw new Error(`unexpected route ${route}`);
};
await page.acceptJarvisProposal();
assert.equal(page.state.file.content, 'proposed unsaved buffer');
assert.equal(page.state.jarvisProposal, null);
assert.match(page.state.jarvisMessage, /unsaved editor buffer/i);
assert.equal(calls.some(call => /\/write$/.test(call.route)), false, 'Applying a proposal must not save the file');
console.log('PASS: Apply changes only the unsaved editor buffer and never calls the write endpoint');

page.state.jarvisProposal = {
  proposal_id: 'b'.repeat(32), mode: 'proposal', output: 'stale proposal',
  context: { accept_allowed: true },
};
page.state.file = { ...page.state.file, content: 'changed locally' };
page.shadowRoot.values.set('#gc-editor', { value: 'changed locally' });
page.api = async () => { throw new Error('409 Proposal Conflict'); };
await page.acceptJarvisProposal();
assert.equal(page.state.file.content, 'changed locally', 'A stale proposal must preserve the current buffer');
assert.match(page.state.jarvisError, /Proposal Conflict/);
console.log('PASS: stale application failure is visible and preserves the editor buffer');

const rendered = new CommanderPage();
rendered.state.roots = [{ key: 'pages', label: 'Pages', writable: true }];
rendered.state.root = 'pages';
rendered.state.file = {
  root: 'pages', path: 'guide.md', name: 'guide.md', extension: 'md',
  content: '<script>current</script>', editable: true, viewable: true,
};
rendered.state.jarvisStatus = {
  available: true, state: 'ready', providers: [{ id: 'fixture', capabilities: ['text-completion'] }],
  actions: [{ id: 'review', label: 'Review', mode: 'read-only' }],
};
rendered.state.jarvisProvider = 'fixture';
rendered.state.jarvisAction = 'review';
rendered.state.jarvisMessage = 'Result ready from explicitly truncated context.';
rendered.state.jarvisProposal = {
  mode: 'read-only', output: '<img src=x onerror=alert(1)>', provider_id: 'fixture', model: 'fixture-a',
  usage: {}, cost: {}, reliability: {}, context: { truncated: true, redacted: false, chunked: false, accept_allowed: false },
};
rendered.render();
assert.match(rendered.shadowRoot.innerHTML, /Jarvis file assistant/);
assert.match(rendered.shadowRoot.innerHTML, /&lt;img src=x onerror=alert\(1\)&gt;/);
assert.doesNotMatch(rendered.shadowRoot.innerHTML, /<img src=x/);
assert.match(rendered.shadowRoot.innerHTML, /truncated context/);
assert.match(rendered.shadowRoot.innerHTML, /role="status"|role="alert"/);
assert.match(source, /focus-visible/);
assert.match(source, /@media \(max-width: 1100px\)/);
console.log('PASS: result rendering is escaped, limitation-aware, accessible, themed, and responsive');

const absent = new CommanderPage();
absent.state.roots = [{ key: 'pages', label: 'Pages', writable: true }];
absent.state.file = { path: 'guide.md', extension: 'md', content: 'content', editable: true, viewable: true };
absent.state.jarvisStatus = { available: false, providers: [], actions: [] };
absent.render();
assert.doesNotMatch(absent.shadowRoot.innerHTML, /Jarvis file assistant/);
assert.match(absent.shadowRoot.innerHTML, /Save file/);
console.log('PASS: Jarvis absence hides only the optional panel and preserves Commander editing');

console.log('Grav Commander Jarvis Admin2 component contract passed (6 checks).');
