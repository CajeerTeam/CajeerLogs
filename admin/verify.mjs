import { readFileSync, readdirSync } from 'node:fs';
import { resolve, dirname, relative, extname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawnSync } from 'node:child_process';
const root = fileURLToPath(new URL('../', import.meta.url));
const assets = resolve(root, 'public/admin/assets');
const version = JSON.parse(readFileSync(resolve(root, 'release.json'), 'utf8')).version;
const html = readFileSync(resolve(root, 'admin/index.html'), 'utf8');
const visited = new Set();
function visit(path) {
  if (!path.startsWith(assets + '/') || !['.js','.css'].includes(extname(path))) throw new Error('Invalid asset: '+path);
  if (visited.has(path)) return;
  visited.add(path);
  const data = readFileSync(path, 'utf8');
  if (!data.trim()) throw new Error('Empty asset: '+path);
  if (path.endsWith('.js')) {
    const check = spawnSync(process.execPath, ['--check', path], {stdio:'inherit'});
    if (check.status !== 0) throw new Error('Invalid JS: '+path);
  }
  const pattern = path.endsWith('.js') ? /^import\s+(?:[^;\n]*?\s+from\s+)?['"]([^'"]+)['"]/gm : /@import\s+url\(['"]([^'"]+)['"]\)/g;
  for (const [, spec] of data.matchAll(pattern)) {
    const [name, query] = spec.split('?');
    if (!name.startsWith('./') && !name.startsWith('../')) throw new Error('Non-local import: '+spec);
    if (query !== 'v='+version) throw new Error('Unversioned import: '+spec);
    visit(resolve(dirname(path), name));
  }
}
for (const name of ['panel.js','panel.css']) {
  if (!html.includes('/admin/assets/'+name+'?v={{VERSION}}')) throw new Error('Missing template asset: '+name);
  visit(resolve(assets,name));
}
function walk(dir) { for (const entry of readdirSync(dir,{withFileTypes:true})) { const path=resolve(dir,entry.name); if (entry.isDirectory()) walk(path); else if (['.js','.css'].includes(extname(path)) && !visited.has(path)) throw new Error('Unused production asset: '+relative(assets,path)); } }
walk(assets);
console.log(`Verified ${visited.size} versioned production JS/CSS assets (${version}).`);
