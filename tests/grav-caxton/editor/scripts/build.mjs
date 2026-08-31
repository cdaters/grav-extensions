import {build} from 'esbuild';
import {mkdir, stat} from 'node:fs/promises';
import {dirname, resolve} from 'node:path';
import {fileURLToPath} from 'node:url';

const workspace = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const output = resolve(workspace, '../../../plugins/grav-caxton/admin-next/proof/caxton-editor.js');

await mkdir(dirname(output), {recursive: true});
await build({
  entryPoints: [resolve(workspace, 'src/index.js')],
  outfile: output,
  bundle: true,
  format: 'esm',
  platform: 'browser',
  target: ['es2022'],
  minify: true,
  sourcemap: false,
  legalComments: 'none',
  charset: 'utf8',
  banner: {js: '/*! Caxton 0.1.1 editor-engine proof; third-party notices in THIRD-PARTY-NOTICES.md */'},
});

const result = await stat(output);
process.stdout.write(`Built ${output} (${result.size} bytes)\n`);
