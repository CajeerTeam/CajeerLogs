// CajeerLogs administration entry point. The browser loads the versioned ES module graph.
import './app/router.js?v=0.6.7';
import { bootstrap, clearSession } from './app/session.js?v=0.6.7';
import { installUnauthorizedHandler } from './api/client.js?v=0.6.7';
import { initTheme } from './app/theme.js?v=0.6.7';
import { initShell } from './app/shell.js?v=0.6.7';

installUnauthorizedHandler(() => clearSession('Сессия истекла. Войдите снова.'));
initTheme();
initShell();
void bootstrap();
