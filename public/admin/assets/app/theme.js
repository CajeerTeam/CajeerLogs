import { button, select } from '../components/ui.js?v=0.4.10';

const key = 'cajeer-theme';
let preference = 'system';
const media = matchMedia('(prefers-color-scheme: dark)');
try { const saved = localStorage.getItem(key); if (['light', 'dark', 'system'].includes(saved)) preference = saved; } catch {}
function applyTheme() { document.documentElement.dataset.theme = preference === 'system' ? (media.matches ? 'dark' : 'light') : preference; }
function setTheme(value) { preference = value; try { localStorage.setItem(key, value); } catch {} applyTheme(); }
function themeButton() { return button(document.documentElement.dataset.theme === 'dark' ? 'Светлая тема' : 'Тёмная тема', () => { setTheme(document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark'); refreshThemeControls(); }, '', {'data-theme-toggle':true}); }
function themeSelector() { const el = select('theme', [['system','Системная'], ['light','Светлая'], ['dark','Тёмная']], preference, {'aria-label':'Цветовая тема', class:'theme-select'}); el.addEventListener('change', () => { setTheme(el.value); refreshThemeControls(); }); return el; }
function refreshThemeControls() { document.querySelectorAll('select[name=theme]').forEach(el => { el.value = preference; }); document.querySelectorAll('[data-theme-toggle]').forEach(el => { el.textContent = document.documentElement.dataset.theme === 'dark' ? 'Светлая тема' : 'Тёмная тема'; }); }
function initTheme() { applyTheme(); media.addEventListener('change', () => { if (preference === 'system') { applyTheme(); refreshThemeControls(); } }); window.addEventListener('storage', e => { if (e.key === key || e.key === null) { preference = ['light','dark','system'].includes(e.newValue) ? e.newValue : 'system'; applyTheme(); refreshThemeControls(); } }); }

export { themeButton, themeSelector, initTheme };
