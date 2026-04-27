document.addEventListener('DOMContentLoaded', function () {
  var button = document.querySelector('.nav-toggle');
  var nav = document.querySelector('.site-nav');
  var backdrop = document.querySelector('.nav-backdrop');
  var body = document.body;

  function setBackdropHidden(hidden) {
    if (backdrop) backdrop.hidden = hidden;
  }

  function closeMenu() {
    if (!button || !nav) return;
    button.setAttribute('aria-expanded', 'false');
    nav.classList.remove('is-open');
    body.classList.remove('nav-open');
    setBackdropHidden(true);
  }

  function openMenu() {
    if (!button || !nav) return;
    button.setAttribute('aria-expanded', 'true');
    nav.classList.add('is-open');
    body.classList.add('nav-open');
    setBackdropHidden(false);
    nav.scrollTop = 0;
  }

  if (button && nav) {
    button.addEventListener('click', function () {
      var expanded = button.getAttribute('aria-expanded') === 'true';
      if (expanded) {
        closeMenu();
      } else {
        openMenu();
      }
    });

    document.querySelectorAll('[data-nav-close]').forEach(function (node) {
      node.addEventListener('click', closeMenu);
    });

    nav.querySelectorAll('a').forEach(function (link) {
      link.addEventListener('click', closeMenu);
    });

    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') closeMenu();
    });

    document.addEventListener('click', function (event) {
      if (!nav.classList.contains('is-open')) return;
      if (nav.contains(event.target) || button.contains(event.target)) return;
      closeMenu();
    });
  }

  document.querySelectorAll('form[data-confirm]').forEach(function (form) {
    form.addEventListener('submit', function (event) {
      var text = form.getAttribute('data-confirm') || 'Подтвердить действие?';
      if (!window.confirm(text)) event.preventDefault();
    });
  });

  var auto = document.querySelector('[data-auto-refresh]');
  if (auto) {
    var seconds = parseInt(auto.getAttribute('data-auto-refresh') || '0', 10);
    if (seconds >= 5) {
      var badge = document.createElement('div');
      badge.className = 'notice compact';
      badge.textContent = 'Live-обновление включено: каждые ' + seconds + ' сек.';
      auto.parentNode.insertBefore(badge, auto.nextSibling);
      window.setTimeout(function () { window.location.reload(); }, seconds * 1000);
    }
  }

  initCommandPalette();
  initPwaRuntime();
});

function initCommandPalette() {
  var palette = document.getElementById('command-palette');
  var input = document.getElementById('command-search');
  var openButtons = document.querySelectorAll('[data-command-open]');
  var closeButtons = document.querySelectorAll('[data-command-close]');

  function open() {
    if (!palette) return;
    palette.hidden = false;
    window.setTimeout(function () { if (input) input.focus(); }, 30);
  }

  function close() {
    if (!palette) return;
    palette.hidden = true;
    if (input) input.value = '';
  }

  openButtons.forEach(function (btn) { btn.addEventListener('click', open); });
  closeButtons.forEach(function (btn) { btn.addEventListener('click', close); });

  document.addEventListener('keydown', function (event) {
    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
      event.preventDefault();
      open();
    }
    if (event.key === 'Escape') close();
  });

  if (input) {
    input.addEventListener('keydown', function (event) {
      if (event.key !== 'Enter') return;
      var q = input.value.trim();
      if (q.length > 0) {
        window.location.href = '/logs?q=' + encodeURIComponent(q);
      }
    });
  }
}

function setText(selector, text) {
  document.querySelectorAll(selector).forEach(function (node) { node.textContent = text; });
}

function initPwaRuntime() {
  var isStandalone =
    window.matchMedia('(display-mode: standalone)').matches ||
    window.matchMedia('(display-mode: fullscreen)').matches ||
    window.navigator.standalone === true ||
    document.referrer.indexOf('android-app://') === 0;

  document.documentElement.classList.toggle('is-standalone', isStandalone);
  setText('[data-pwa-display-mode]', isStandalone ? 'standalone' : 'browser');

  var deferredPrompt = null;
  var installButtons = document.querySelectorAll('[data-pwa-install]');

  if (isStandalone) {
    installButtons.forEach(function (btn) { btn.hidden = true; });
  }

  function setInstallState(text) {
    setText('[data-pwa-install-state]', text);
  }

  window.addEventListener('beforeinstallprompt', function (event) {
    if (isStandalone) return;
    event.preventDefault();
    deferredPrompt = event;
    installButtons.forEach(function (btn) { btn.hidden = false; });
    setInstallState('доступна установка');
  });

  installButtons.forEach(function (btn) {
    btn.addEventListener('click', function () {
      if (!deferredPrompt) return;
      deferredPrompt.prompt();
      deferredPrompt.userChoice.finally(function () {
        deferredPrompt = null;
        btn.hidden = true;
        setInstallState('запрос показан');
      });
    });
  });

  window.addEventListener('appinstalled', function () {
    installButtons.forEach(function (btn) { btn.hidden = true; });
    setInstallState('установлено');
  });

  window.setTimeout(function () {
    if (!deferredPrompt && !isStandalone) {
      setInstallState('prompt недоступен');
    }
  }, 1800);

  if (!('serviceWorker' in navigator)) {
    setText('[data-pwa-sw-state]', 'не поддерживается');
    return;
  }

  navigator.serviceWorker.register('/sw.js', { scope: '/' }).then(function (registration) {
    setText('[data-pwa-sw-state]', registration.active ? 'активен' : 'зарегистрирован');

    function showUpdateBanner(worker) {
      var banner = document.getElementById('pwa-update-banner');
      if (!banner) return;
      banner.hidden = false;
      var button = banner.querySelector('[data-pwa-reload]');
      if (button) {
        button.addEventListener('click', function () {
          if (worker) worker.postMessage({ type: 'SKIP_WAITING' });
          window.location.reload();
        }, { once: true });
      }
    }

    if (registration.waiting) showUpdateBanner(registration.waiting);

    registration.addEventListener('updatefound', function () {
      var worker = registration.installing;
      if (!worker) return;
      worker.addEventListener('statechange', function () {
        if (worker.state === 'installed' && navigator.serviceWorker.controller) {
          showUpdateBanner(worker);
        }
      });
    });
  }).catch(function () {
    setText('[data-pwa-sw-state]', 'ошибка регистрации');
  });

  navigator.serviceWorker.addEventListener('controllerchange', function () {
    setText('[data-pwa-sw-state]', 'обновлён');
  });
}
