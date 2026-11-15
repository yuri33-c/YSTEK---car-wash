(function () {
    'use strict';

    var body = document.body;
    var sidebar = document.getElementById('appSidebar');
    var menuButton = document.querySelector('.mobile-menu-toggle');
    var scrim = document.querySelector('.sidebar-scrim');

    function setMenuOpen(open) {
        body.classList.toggle('sidebar-open', open);
        if (menuButton) {
            menuButton.setAttribute('aria-expanded', open ? 'true' : 'false');
            menuButton.setAttribute('aria-label', open ? 'Fechar navegação' : 'Abrir navegação');
        }
    }

    if (sidebar && menuButton) {
        menuButton.addEventListener('click', function () {
            setMenuOpen(!body.classList.contains('sidebar-open'));
        });
        if (scrim) scrim.addEventListener('click', function () { setMenuOpen(false); });
        sidebar.addEventListener('click', function (event) {
            if (event.target.closest('a')) setMenuOpen(false);
        });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') setMenuOpen(false);
        });
    }

    // Usa delegação porque o HTML da área principal é substituído por fragmentos.
    document.addEventListener('click', function (event) {
        if (event.target.closest('#modalService') && event.target.id === 'modalService') {
            if (typeof window.closeServiceModal === 'function') window.closeServiceModal();
        }
        if (event.target.closest('#modalTeam') && event.target.id === 'modalTeam') {
            if (typeof window.closeTeamModal === 'function') window.closeTeamModal();
        }
    });
    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') return;
        if (document.getElementById('modalService') && typeof window.closeServiceModal === 'function') window.closeServiceModal();
        if (document.getElementById('modalTeam') && typeof window.closeTeamModal === 'function') window.closeTeamModal();
    });

    // A área SUPER_ADMIN fica fora da sincronização da operação.
    var baseline = body.getAttribute('data-tenant-revision');
    if (!baseline || body.getAttribute('data-theme') !== 'tenant' || !window.fetch) return;

    var currentVersion = baseline;
    var interval = 5000;
    var failures = 0;
    var pending = false;
    var polling = false;
    var dirtyForms = new WeakSet();

    function isProtectedByEditing() {
        if (document.body.classList.contains('modal-open')
            || document.querySelector('.modal-backdrop.show, .agenda-detail-modal.is-open, [aria-modal="true"]')) return true;
        var active = document.activeElement;
        if (active && active.form && dirtyForms.has(active.form)) return true;
        return document.querySelector('form[data-live-dirty="1"]') !== null;
    }

    document.addEventListener('input', markFormDirty, true);
    document.addEventListener('change', markFormDirty, true);
    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (form && form.matches('form')) {
            dirtyForms.delete(form);
            form.removeAttribute('data-live-dirty');
        }
    }, true);
    document.addEventListener('reset', function (event) {
        var form = event.target;
        window.setTimeout(function () {
            if (form && form.matches('form')) {
                dirtyForms.delete(form);
                form.removeAttribute('data-live-dirty');
            }
        }, 0);
    }, true);

    function markFormDirty(event) {
        var control = event.target;
        var form = control && control.form;
        if (!form || form.closest('.modal-backdrop, .agenda-detail-modal')) return;
        dirtyForms.add(form);
        form.setAttribute('data-live-dirty', '1');
    }

    function requestFragment() {
        var url = new URL(window.location.href);
        url.searchParams.set('fragment', '1');
        return fetch(url.toString(), {
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { 'X-Requested-With': 'YSTEK-Realtime' }
        }).then(function (response) {
            if (!response.ok) throw new Error('Fragment indisponível');
            if (response.redirected && /class=LoginController/i.test(response.url)) {
                window.location.assign(response.url);
                throw new Error('Sessão encerrada');
            }
            return response.text();
        }).then(function (html) {
            var parsed = new DOMParser().parseFromString(html, 'text/html');
            var incoming = parsed.querySelector('.content-container');
            var current = document.querySelector('.content-container');
            if (!incoming || !current) throw new Error('Fragmento inválido');
            var tableScroll = Array.prototype.map.call(current.querySelectorAll('.table-responsive'), function (table) {
                return table.scrollLeft;
            });
            current.innerHTML = incoming.innerHTML;
            current.className = incoming.className;
            Array.prototype.forEach.call(current.querySelectorAll('.table-responsive'), function (table, index) {
                if (typeof tableScroll[index] === 'number') table.scrollLeft = tableScroll[index];
            });
            var incomingTopbar = parsed.querySelector('.topbar-right');
            var currentTopbar = document.querySelector('.topbar-right');
            if (incomingTopbar && currentTopbar) currentTopbar.innerHTML = incomingTopbar.innerHTML;
        });
    }

    function poll() {
        if (polling || document.hidden) return;
        polling = true;
        var endpoint = new URL('index.php', window.location.href);
        endpoint.searchParams.set('class', 'RealtimeController');

        fetch(endpoint.toString(), {
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { 'Accept': 'application/json' }
        }).then(function (response) {
            if (response.redirected && /class=LoginController/i.test(response.url)) {
                window.location.assign(response.url);
                throw new Error('Sessão encerrada');
            }
            if (!response.ok) throw new Error('Polling indisponível');
            return response.json();
        }).then(function (result) {
            if (!result || typeof result.version !== 'string') throw new Error('Resposta inválida');
            failures = 0;
            interval = 5000;
            if (result.version !== currentVersion) {
                pending = true;
                if (!isProtectedByEditing()) {
                    return requestFragment().then(function () {
                        currentVersion = result.version;
                        pending = false;
                    });
                }
            } else if (pending && !isProtectedByEditing()) {
                return requestFragment().then(function () {
                    currentVersion = result.version;
                    pending = false;
                });
            }
        }).catch(function () {
            failures += 1;
            interval = Math.min(30000, 5000 * Math.pow(2, Math.min(failures, 3)));
        }).finally(function () {
            polling = false;
            window.setTimeout(poll, interval);
        });
    }

    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) poll();
    });
    window.setTimeout(poll, interval);
})();
