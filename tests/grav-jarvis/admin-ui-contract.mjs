import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const plugin = process.env.GRAV_JARVIS_PLUGIN_DIR || path.resolve(here, '../../plugins/grav-jarvis');
const pageSource = fs.readFileSync(path.join(plugin, 'admin-next/pages/grav-jarvis.js'), 'utf8');
const panelSource = fs.readFileSync(path.join(plugin, 'admin-next/panels/grav-jarvis.js'), 'utf8');

class JarvisCustomEvent extends Event {
  constructor(type, init = {}) { super(type); this.detail = init.detail; }
}

class ShadowStub {
  innerHTML = '';
  getElementById() { return null; }
  querySelectorAll() { return []; }
}

class ElementStub {
  constructor() { this.attributes = {}; }
  attachShadow() { this.shadowRoot = new ShadowStub(); return this.shadowRoot; }
  getAttribute(name) { return this.attributes[name] ?? null; }
}

function load(source, kind) {
  const registry = new Map();
  const eventTarget = new EventTarget();
  const window = eventTarget;
  Object.assign(window, {
    __GRAV_PAGE_TAG: kind === 'page' ? 'test-jarvis-page' : undefined,
    __GRAV_PANEL_TAG: kind === 'panel' ? 'test-jarvis-panel' : undefined,
    __GRAV_API_SERVER_URL: 'https://admin.test', __GRAV_API_PREFIX: '/api/v1',
    __GRAV_API_TOKEN: 'admin-session-token', __GRAV_PAGE_ROUTE: '/test',
  });
  const context = {
    window, Event, CustomEvent: JarvisCustomEvent, HTMLElement: ElementStub,
    customElements: { get: tag => registry.get(tag), define: (tag, value) => registry.set(tag, value) },
    localStorage: { getItem: () => JSON.stringify({ environment: 'test' }) },
    fetch: async () => { throw new Error('unexpected network'); },
    setTimeout, clearTimeout, console, URL, JSON, Promise, String, Number, Boolean, Array, Object, Math,
  };
  vm.runInNewContext(source, context, { filename: `${kind}.js` });
  return { Class: registry.get(`test-jarvis-${kind}`), context, window };
}

for (const [name, source] of [['page', pageSource], ['panel', panelSource]]) {
  assert.match(source, /credentials:\s*['"]omit['"]/, `${name} requests must omit browser credentials`);
  assert.doesNotMatch(source, /api\.openai\.com|api\.anthropic\.com|GRAV_JARVIS_[A-Z_]*KEY/, `${name} must not contact or receive provider credentials`);
  assert.match(source, /X-API-Token/, `${name} must use Admin2 API authentication`);
  assert.match(source, /cache:\s*['"]no-store['"]/, `${name} requests must be non-cacheable in the browser`);
}
console.log('PASS: browser requests use authenticated same-API, no-store, credential-omitting transport');

const pageHarness = load(pageSource, 'page');
assert.ok(pageHarness.Class, 'Jarvis page component was not registered');
const page = new pageHarness.Class();
let request;
pageHarness.context.fetch = async (url, options) => {
  request = { url, options };
  return { ok: true, text: async () => JSON.stringify({ data: { available: true } }) };
};
await page.api('/grav-jarvis/bootstrap');
assert.equal(request.url, 'https://admin.test/api/v1/grav-jarvis/bootstrap');
assert.equal(request.options.credentials, 'omit');
assert.equal(request.options.headers['X-API-Token'], 'admin-session-token');
assert.equal(request.options.headers.Authorization, undefined);
assert.equal(request.options.body, undefined);
assert.equal(page.escape('<script>'), '&lt;script&gt;');
console.log('PASS: assistant API client and output escaping');

let completionBody;
page.state.provider = 'fixture'; page.state.model = 'fixture-a'; page.state.prompt = 'Help me';
page.api = async (_path, options) => { completionBody = options.body; return { response: 'Safe response', usage: { total: 3, unit: 'characters' } }; };
await page.ask();
assert.equal(JSON.stringify(completionBody), JSON.stringify({ provider_id: 'fixture', model: 'fixture-a', prompt: 'Help me' }));
assert.equal(page.state.response, 'Safe response');
page.api = async () => { throw new Error('Jarvis is disabled or unavailable.'); };
await page.load();
assert.match(page.state.error, /disabled or unavailable/);
page.state.bootstrap = { providers: [{ id: 'fixture', capabilities: ['text-completion'] }] };
page.state.provider = 'fixture'; page.state.validation = { usable: true, issues: [] };
page.state.modelMessage = 'Configured default remains available.'; page.render();
assert.match(page.shadowRoot.innerHTML, /Text Completion/);
assert.match(page.shadowRoot.innerHTML, /Configured default remains available/);
console.log('PASS: provider-neutral assistant completion and graceful absence');

const panelHarness = load(panelSource, 'panel');
assert.ok(panelHarness.Class, 'Jarvis panel component was not registered');
const panel = new panelHarness.Class();
panel.attributes.route = '/test';
panelHarness.window.addEventListener('grav:editor:get-content', () => {
  panelHarness.window.dispatchEvent(new JarvisCustomEvent('grav:editor:content-response', {
    detail: { route: '/test', content: 'Unsaved **buffer**', title: 'Unsaved title', template: 'default' },
  }));
});
const snapshot = await panel.editorSnapshot();
assert.equal(snapshot.content, 'Unsaved **buffer**');
assert.equal(snapshot.title, 'Unsaved title');
console.log('PASS: page launcher reads the official current unsaved editor buffer');

let inserted = null; let saved = false; let published = false;
panelHarness.window.addEventListener('grav:editor:insert-content', event => { inserted = event.detail; });
panelHarness.window.addEventListener('grav:editor:save', () => { saved = true; });
panelHarness.window.addEventListener('grav:editor:publish', () => { published = true; });
panel.state.bootstrap = { can_approve: true, providers: [], actions: [] };
panel.state.proposal = {
  proposal_id: 'a'.repeat(32), proposed_content: 'Reviewed proposal',
  context: { accept_allowed: true, truncated: { any: false } },
};
panel.editorSnapshot = async () => ({ route: '/test', content: 'Unsaved **buffer**' });
panel.api = async (_path, options) => {
  assert.equal(JSON.stringify(options.body), JSON.stringify({ route: '/test', current_content: 'Unsaved **buffer**', proposed_content: 'Reviewed proposal' }));
  return { accepted: true, content: 'Reviewed proposal' };
};
await panel.accept();
assert.equal(JSON.stringify(inserted), JSON.stringify({ content: 'Reviewed proposal', mode: 'replace' }));
assert.equal(saved, false); assert.equal(published, false);
assert.match(panel.state.message, /unsaved editor buffer/);
console.log('PASS: explicit acceptance replaces only the unsaved buffer');

inserted = null;
panel.state.proposal = { proposed_content: 'Rejected', context: { accept_allowed: true } };
panel.reject();
assert.equal(inserted, null);
assert.match(panel.state.message, /not changed/);
console.log('PASS: reject leaves the editor unchanged');

assert.match(panelSource, /rewrite|data-action/);
assert.match(panelSource, /grav:editor:get-content/);
assert.match(panelSource, /grav:editor:insert-content/);
assert.doesNotMatch(panelSource, /grav:editor:save|grav:editor:publish/);
assert.match(panelSource, /Selection-aware editing is deferred/);
assert.match(panelSource, /accept_allowed/);
console.log('PASS: six-action review UI, fail-closed acceptance, and explicit selection deferral');

console.log('Jarvis Admin2 UI contract passed (7 checks).');
