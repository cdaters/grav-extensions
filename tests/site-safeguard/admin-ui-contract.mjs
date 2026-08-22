import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';

const testDirectory = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.resolve(testDirectory, '../..');
const sourcePath = path.join(repoRoot, 'plugins/site-safeguard/admin-next/pages/site-safeguard.js');
const source = fs.readFileSync(sourcePath, 'utf8');

const storage = new Map();
const registry = new Map();

class ShadowRootStub {
  constructor() {
    this.innerHTML = '';
  }

  querySelector() {
    return null;
  }

  querySelectorAll() {
    return [];
  }
}

class HTMLElementStub {
  attachShadow() {
    this.shadowRoot = new ShadowRootStub();
    return this.shadowRoot;
  }
}

globalThis.HTMLElement = HTMLElementStub;
globalThis.localStorage = {
  getItem: key => storage.get(key) ?? null,
  setItem: (key, value) => storage.set(key, String(value)),
};
globalThis.window = {
  __GRAV_PAGE_TAG: 'site-safeguard-contract-page',
  location: { pathname: '/admin/plugin/site-safeguard', origin: 'https://example.test', protocol: 'https:', host: 'example.test' },
  matchMedia: () => ({ matches: true, addEventListener() {}, removeEventListener() {} }),
};
globalThis.document = {
  documentElement: { dataset: {}, classList: [] },
  body: { dataset: {}, classList: [] },
};
globalThis.customElements = {
  get: tag => registry.get(tag),
  define: (tag, constructor) => registry.set(tag, constructor),
};
globalThis.MutationObserver = class { observe() {} disconnect() {} };
globalThis.requestAnimationFrame = callback => callback();

vm.runInThisContext(source, { filename: sourcePath });
const Page = registry.get('site-safeguard-contract-page');
assert.ok(Page, 'Admin2 custom element should register');

const healthyStatus = {
  version: '0.3.9',
  profiles: [],
  packages: [],
  stages: [],
  restore_history: [{ id: 'done', state: 'completed' }],
  environment_requirements: [
    { key: 'php', label: 'PHP', group: 'required', available: true, detail: 'Available' },
    { key: 'zlib', label: 'Zlib', group: 'recommended', available: true, detail: 'Available' },
  ],
};

const page = new Page();
page.state.status = structuredClone(healthyStatus);
page.render();
assert.match(page.shadowRoot.innerHTML, /readiness readiness-ready/);
assert.match(page.shadowRoot.innerHTML, /All checks passed/);
assert.match(page.shadowRoot.innerHTML, /id="readiness-panel" hidden/);
assert.match(page.shadowRoot.innerHTML, /id="packages-panel" >/);
assert.match(page.shadowRoot.innerHTML, /id="history-panel" hidden/);

page.toggleDisclosure('readiness', false);
assert.equal(page.state.disclosures.readiness, true);
assert.equal(JSON.parse(storage.get('site_safeguard_disclosures')).readiness, true);
assert.doesNotMatch(page.shadowRoot.innerHTML, /id="readiness-panel" hidden/);

page.state.disclosures = {};
page.state.status.environment_requirements[1].available = false;
page.render();
assert.match(page.shadowRoot.innerHTML, /readiness readiness-warning/);
assert.match(page.shadowRoot.innerHTML, /capability is unavailable/);
assert.doesNotMatch(page.shadowRoot.innerHTML, /id="readiness-panel" hidden/);

page.state.status.environment_requirements[0].available = false;
page.render();
assert.match(page.shadowRoot.innerHTML, /readiness readiness-error/);
assert.match(page.shadowRoot.innerHTML, /required check needs attention/);

const requests = [];
page.api = async (requestPath, options = {}) => {
  requests.push({
    path: requestPath,
    method: options.method || 'GET',
    override: options.headers?.['X-HTTP-Method-Override'] || '',
  });
  if (requestPath.endsWith('/delete')) {
    throw new Error(`No route matches 'POST ${requestPath}'.`);
  }
  return requestPath === '/site-safeguard/status' ? structuredClone(healthyStatus) : {};
};

const packageName = 'safeguard-example.zip';
await page.deletePackage(packageName);
assert.equal(page.state.armedAction, `package:${packageName}`);
assert.match(page.state.message, /Click Confirm delete/);
assert.equal(requests.length, 0, 'first package-delete click must not call the API');
await page.deletePackage(packageName);
assert.deepEqual(requests[0], {
  path: `/site-safeguard/packages/${packageName}/delete`,
  method: 'POST',
  override: '',
});
assert.deepEqual(requests[1], {
  path: `/site-safeguard/packages/${packageName}`,
  method: 'POST',
  override: 'DELETE',
});
assert.equal(page.state.message, 'Package deleted.');

requests.length = 0;
const stageId = 'image-foundry-data';
await page.deleteStage(stageId, false);
assert.equal(page.state.armedAction, `stage:${stageId}`);
assert.match(page.state.message, /Click Confirm removal/);
assert.equal(requests.length, 0, 'first stage-delete click must not call the API');
assert.match(page.shadowRoot.innerHTML, /Confirm removal/);
await page.deleteStage(stageId, false);
assert.deepEqual(requests[0], {
  path: `/site-safeguard/stages/${stageId}/delete`,
  method: 'POST',
  override: '',
});
assert.deepEqual(requests[1], {
  path: `/site-safeguard/stages/${stageId}`,
  method: 'POST',
  override: 'DELETE',
});
assert.equal(page.state.message, 'Unrecognized staging directory removed.');

page.state.armedAction = 'stage:cancel-me';
page.cancelDestructiveAction();
assert.equal(page.state.armedAction, '');
assert.equal(page.state.message, 'Deletion cancelled. Nothing was removed.');

let deniedRequests = 0;
page.api = async () => {
  deniedRequests += 1;
  throw new Error('Missing required permission: site-safeguard.stage');
};
await assert.rejects(
  () => page.deleteResource('/new-action', '/compatible-action'),
  /Missing required permission/
);
assert.equal(deniedRequests, 1, 'authorization and unrelated failures must never be retried');

console.log(JSON.stringify({
  version: healthyStatus.version,
  delete_transport: 'POST action routes with stale-route fallback',
  delete_confirmation: 'two click',
  disclosures: ['readiness', 'packages', 'stages', 'history'],
  readiness_states: ['ready', 'warning', 'error'],
}));
