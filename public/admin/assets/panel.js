// CajeerLogs administration entry point. The browser loads the versioned ES module graph.
import './app/router.js?v=0.7.2';
import { bootstrap, clearSession } from './app/session.js?v=0.7.2';
import { installUnauthorizedHandler } from './api/client.js?v=0.7.2';
import { initTheme } from './app/theme.js?v=0.7.2';
import { initShell } from './app/shell.js?v=0.7.2';

installUnauthorizedHandler(() => clearSession('Сессия истекла. Войдите снова.'));
initTheme();
initShell();
void bootstrap();
