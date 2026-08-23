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
  version: '0.3.11',
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
assert.doesNotMatch(source, /\b(?:confirm|prompt)\s*\(/, 'Admin actions must not depend on native browser dialogs');
page.state.status = structuredClone(healthyStatus);
page.render();
assert.match(page.shadowRoot.innerHTML, /readiness readiness-ready/);
assert.match(page.shadowRoot.innerHTML, /All checks passed/);
assert.match(page.shadowRoot.innerHTML, /id="readiness-panel" hidden/);
assert.match(page.shadowRoot.innerHTML, /id="packages-panel" >/);
assert.match(page.shadowRoot.innerHTML, /id="history-panel" hidden/);
assert.doesNotMatch(page.shadowRoot.innerHTML, /[⌃⌄]/, 'disclosures must not depend on font-rendered Unicode chevrons');
assert.match(page.shadowRoot.innerHTML, /<svg class="disclosure-icon"[^>]*>[\s\S]*?<path d="M3\.5 6 8 10\.5 12\.5 6"><\/path>[\s\S]*?<\/svg>/);
assert.match(source, /\.disclosure-toggle\[aria-expanded="true"\] \.disclosure-icon \{ transform:rotate\(180deg\); \}/);

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

const packageName = 'safeguard-example.zip';
const stageRequests = [];
page.api = async (requestPath, options = {}) => {
  stageRequests.push({ path: requestPath, method: options.method || 'GET' });
  return requestPath === '/site-safeguard/status' ? structuredClone(healthyStatus) : {};
};
await page.stagePackage(packageName);
assert.equal(page.state.armedAction, `create-stage:${packageName}`);
assert.match(page.state.message, /Click Confirm stage/);
assert.equal(stageRequests.length, 0, 'first create-stage click must not call the API');
page.state.inspections[packageName] = { valid: true };
const armedPackageCard = page.packageCard({
  name: packageName,
  manifest: { profile: { label: 'Portable site', deployable: true } },
  size: 1,
  modified: 1,
  sha256: 'a'.repeat(64),
}, '');
assert.match(armedPackageCard, /Confirm stage/);
assert.match(armedPackageCard, /cancel-action/);
await page.stagePackage(packageName);
assert.deepEqual(stageRequests[0], {
  path: `/site-safeguard/packages/${packageName}/stage`,
  method: 'POST',
});
assert.equal(page.state.message, 'Verified stage created. The running site was not modified.');

const restoreStageId = 'verified-stage';
const restorePhrase = 'RESTORE THIS SITE';
const restoreStatus = {
  ...structuredClone(healthyStatus),
  restore_confirmation: restorePhrase,
  restore_enabled: true,
  admin_restore_enabled: true,
  restore_launcher_available: true,
  stages: [{ id: restoreStageId, recognized: true, verified: true, modified: 1, record: { package: packageName } }],
};
const restoreRequests = [];
page.scheduleRestorePoll = () => {};
page.state.status = restoreStatus;
page.api = async (requestPath, options = {}) => {
  restoreRequests.push({ path: requestPath, method: options.method || 'GET', body: options.body || '' });
  if (requestPath.endsWith('/restore')) return { operation: { id: 'restore-operation' } };
  return requestPath === '/site-safeguard/status' ? structuredClone(restoreStatus) : {};
};
await page.restoreStage(restoreStageId);
assert.equal(page.state.armedAction, `restore:${restoreStageId}`);
assert.equal(restoreRequests.length, 0, 'opening restore confirmation must not call the API');
assert.match(page.shadowRoot.innerHTML, /Confirm full-site restore/);
assert.match(page.shadowRoot.innerHTML, /class="restore-phrase"/);
page.state.restorePhrase = 'WRONG';
await page.restoreStage(restoreStageId);
assert.equal(restoreRequests.length, 0, 'an incorrect restore phrase must not call the API');
assert.match(page.state.error, /type RESTORE THIS SITE exactly/);
page.state.restorePhrase = restorePhrase;
await page.restoreStage(restoreStageId);
assert.deepEqual(restoreRequests[0], {
  path: `/site-safeguard/stages/${restoreStageId}/restore`,
  method: 'POST',
  body: JSON.stringify({ confirmation: restorePhrase }),
});
assert.equal(page.state.restoreOperation, 'restore-operation');

page.state.status = structuredClone(healthyStatus);

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
page.cancelArmedAction();
assert.equal(page.state.armedAction, '');
assert.equal(page.state.message, 'Deletion cancelled. Nothing was removed.');

page.state.armedAction = `create-stage:${packageName}`;
page.cancelArmedAction();
assert.equal(page.state.message, 'Stage creation cancelled. The package and running site were unchanged.');

page.state.armedAction = `restore:${restoreStageId}`;
page.state.restorePhrase = restorePhrase;
page.cancelArmedAction();
assert.equal(page.state.restorePhrase, '');
assert.equal(page.state.message, 'Restore cancelled. The running site was unchanged.');

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
  stage_confirmation: 'two click',
  restore_confirmation: 'inline typed phrase',
  disclosures: ['readiness', 'packages', 'stages', 'history'],
  readiness_states: ['ready', 'warning', 'error'],
}));
