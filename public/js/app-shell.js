(function () {
    'use strict';

    var body = document.body;
    var sidebar = document.getElementById('appSidebar');
    var menuButton = document.querySelector('.mobile-menu-toggle');
    var scrim = document.querySelector('.sidebar-scrim');
    var mobileMoreButton = document.querySelector('[data-mobile-nav-more]');
    var mobileCardsQuery = window.matchMedia ? window.matchMedia('(max-width: 780px)') : null;

    function setMenuOpen(open) {
        body.classList.toggle('sidebar-open', open);
        if (menuButton) {
            menuButton.setAttribute('aria-expanded', open ? 'true' : 'false');
            menuButton.setAttribute('aria-label', open ? 'Fechar navegação' : 'Abrir navegação');
        }
        if (mobileMoreButton) {
            mobileMoreButton.setAttribute('aria-expanded', open ? 'true' : 'false');
        }
    }

    if (mobileMoreButton) {
        mobileMoreButton.addEventListener('click', function () {
            setMenuOpen(true);
        });
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

    // O clique recebe resposta visual imediata enquanto o servidor prepara a tela.
    // A navegação continua nativa para preservar POSTs, CSRF, histórico e scripts PHP.
    function showNavigationProgress() {
        body.classList.add('route-loading');
    }
    function isInternalPageLink(anchor) {
        if (!anchor || anchor.target === '_blank' || anchor.hasAttribute('download')) return false;
        var href = anchor.getAttribute('href') || '';
        if (!href || href.charAt(0) === '#' || href.indexOf('javascript:') === 0) return false;
        try {
            var url = new URL(href, window.location.href);
            return url.origin === window.location.origin
                && url.pathname === window.location.pathname
                && url.searchParams.has('class')
                && url.searchParams.get('action') !== 'logout';
        } catch (error) {
            return false;
        }
    }
    document.addEventListener('click', function (event) {
        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        var anchor = event.target.closest('a[href]');
        if (isInternalPageLink(anchor)) showNavigationProgress();
    }, true);
    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (form && form.tagName === 'FORM' && (form.method || 'get').toLowerCase() === 'post') {
            showNavigationProgress();
        }
    }, true);
    window.addEventListener('pageshow', function () {
        body.classList.remove('route-loading');
        setMenuOpen(false);
    });

    // Em telas pequenas, cada linha de tabela é apresentada como um cartão legível.
    // O nome da coluna é copiado para data-label, inclusive após atualizações realtime.
    function decorateMobileTables(root) {
        if (!mobileCardsQuery || !mobileCardsQuery.matches) return;
        var scope = root && root.querySelectorAll ? root : document;
        var tables = scope.querySelectorAll('table.table-system:not([data-no-mobile-cards]):not([data-mobile-cards="1"])');
        Array.prototype.forEach.call(tables, function (table) {
            var headerRow = table.querySelector('thead tr');
            if (!headerRow) return;
            var labels = Array.prototype.map.call(headerRow.querySelectorAll('th'), function (th) {
                return (th.textContent || '').replace(/\s+/g, ' ').trim();
            });
            if (!labels.length) return;

            Array.prototype.forEach.call(table.querySelectorAll('tbody tr'), function (row) {
                Array.prototype.forEach.call(row.children, function (cell, index) {
                    if (cell.tagName !== 'TD' || cell.hasAttribute('colspan')) return;
                    if (!cell.hasAttribute('data-label')) {
                        cell.setAttribute('data-label', labels[index] || ('Informação ' + (index + 1)));
                    }
                });
            });
            table.setAttribute('data-mobile-cards', '1');
        });
    }

    decorateMobileTables(document);
    if (mobileCardsQuery) {
        var refreshTables = function () {
            if (mobileCardsQuery.matches) {
                document.querySelectorAll('table.table-system[data-mobile-cards="1"]').forEach(function (table) {
                    table.removeAttribute('data-mobile-cards');
                    table.querySelectorAll('td[data-label]').forEach(function (cell) { cell.removeAttribute('data-label'); });
                });
                decorateMobileTables(document);
            } else {
                document.querySelectorAll('table.table-system[data-mobile-cards="1"]').forEach(function (table) {
                    table.removeAttribute('data-mobile-cards');
                    table.querySelectorAll('td[data-label]').forEach(function (cell) { cell.removeAttribute('data-label'); });
                });
            }
        };
        if (typeof mobileCardsQuery.addEventListener === 'function') mobileCardsQuery.addEventListener('change', refreshTables);
        else if (typeof mobileCardsQuery.addListener === 'function') mobileCardsQuery.addListener(refreshTables);
    }

    var content = document.querySelector('.content-container');
    if (content && window.MutationObserver) {
        var tableObserver = new MutationObserver(function (records) {
            var hasNewContent = records.some(function (record) {
                return record.addedNodes && record.addedNodes.length > 0;
            });
            if (hasNewContent) window.requestAnimationFrame(function () { decorateMobileTables(content); });
        });
        tableObserver.observe(content, { childList: true, subtree: true });
    }

    // A área SUPER_ADMIN fica fora da sincronização operacional.
    var baseline = body.getAttribute('data-tenant-revision');
    if (!baseline || body.getAttribute('data-theme') !== 'tenant' || !window.fetch) return;

    var currentVersion = baseline;
    var interval = 12000;
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
            decorateMobileTables(current);
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
            interval = 12000;
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
            interval = Math.min(60000, 12000 * Math.pow(2, Math.min(failures, 3)));
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
