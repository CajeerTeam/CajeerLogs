// CajeerLogs administration entry point. The browser loads the versioned ES module graph.
import './app/router.js?v=1.0.4';
import { bootstrap, clearSession } from './app/session.js?v=1.0.4';
import { installUnauthorizedHandler } from './api/client.js?v=1.0.4';
import { initTheme } from './app/theme.js?v=1.0.4';
import { initShell } from './app/shell.js?v=1.0.4';

installUnauthorizedHandler(() => clearSession('Сессия истекла. Войдите снова.'));
initTheme();
initShell();
void bootstrap();
