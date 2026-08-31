import {build} from 'esbuild';
import {mkdir, stat} from 'node:fs/promises';
import {dirname, resolve} from 'node:path';
import {fileURLToPath} from 'node:url';

const workspace = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const builds = [
  {
    input: resolve(workspace, 'src/index.js'),
    output: resolve(workspace, '../../../plugins/grav-caxton/admin-next/proof/caxton-editor.js'),
    banner: '/*! Caxton 0.1.1 editor-engine proof; third-party notices in THIRD-PARTY-NOTICES.md */',
  },
  {
    input: resolve(workspace, 'src/admin-field.js'),
    output: resolve(workspace, '../../../plugins/grav-caxton/admin-next/fields/caxton.js'),
    banner: '/*! Caxton 0.2.0 Admin2 field; third-party notices in THIRD-PARTY-NOTICES.md */',
  },
];

for (const item of builds) {
  await mkdir(dirname(item.output), {recursive: true});
  await build({
    entryPoints: [item.input],
    outfile: item.output,
    bundle: true,
    format: 'esm',
    platform: 'browser',
    target: ['es2022'],
    minify: true,
    sourcemap: false,
    legalComments: 'none',
    charset: 'utf8',
    banner: {js: item.banner},
  });
  const result = await stat(item.output);
  process.stdout.write(`Built ${item.output} (${result.size} bytes)\n`);
}
