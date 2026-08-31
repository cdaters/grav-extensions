import assert from 'node:assert/strict';
import {performance} from 'node:perf_hooks';
import {test} from 'node:test';
import {DualModeEditorSession, SourceDocumentAdapter} from '../src/index.js';

function measure(source) {
  const start = performance.now();
  const document = new SourceDocumentAdapter(source);
  return {document, milliseconds: performance.now() - start};
}

test('bounded ordinary, many-block, opaque, and large-fence fixtures avoid obvious pathological behavior', async () => {
  const ordinary = '# Heading\n\nParagraph with **formatting**.\n';
  const manySafe = Array.from({length: 2500}, (_, index) => `## Heading ${index}\n\nParagraph ${index}.\n\n`).join('');
  const manyOpaque = Array.from({length: 2000}, (_, index) => `{% opaque_${index} %}\n\n`).join('');
  const largeFence = `\`\`\`text\n${'safe code line\n'.repeat(20_000)}\`\`\`\n`;
  const longDocument = `${manySafe}${manyOpaque}${largeFence}`;
  const cases = {ordinary, manySafe, manyOpaque, largeFence, longDocument};
  const observations = {};
  for (const [name, source] of Object.entries(cases)) {
    const {document, milliseconds} = measure(source);
    observations[name] = {units: source.length, blocks: document.blocks.length, milliseconds};
    assert.equal(document.blocks.map((block) => block.source).join(''), source);
    assert.ok(milliseconds < 10_000, `${name} exceeded the pathological-test ceiling`);
  }
  const session = await DualModeEditorSession.create(longDocument);
  session.switchMode('visual');
  session.switchMode('source');
  assert.equal(session.value(), longDocument);
  assert.equal(session.isDirty(), false);
  process.stdout.write(`Caxton performance observations ${JSON.stringify(observations)}\n`);
});
