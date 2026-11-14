import { readFileSync } from 'node:fs';
const html = readFileSync(new URL('./index.html', import.meta.url), 'utf8');
for (const name of ['panel.js','panel.css']) {
  if (!html.includes('/admin/assets/'+name+'?v={{VERSION}}')) throw new Error('Missing asset reference: '+name);
  if (!readFileSync(new URL('../public/admin/assets/'+name,import.meta.url)).length) throw new Error('Empty asset: '+name);
}
console.log('Admin panel assets and HTML template verified; deploy public/admin/assets and admin/index.html with PHP application.');
