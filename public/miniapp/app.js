/*
 * Telegram Mini App (/app). Plain progressive-enhancement script, no framework.
 *
 * Sign-in: Telegram.WebApp.initData is POSTed to /app/auth (the server validates it; initDataUnsafe is never used).
 * The session token comes back in the body, is kept in sessionStorage (this WebView only) and sent as
 * "Authorization: Bearer"; state-changing requests also send X-CSRF-Token.
 *
 * Sections are server-rendered fragments (templates/miniapp/view/) loaded into #view. Inside them:
 *   a[data-nav]            opens another section (stack kept for Telegram's BackButton)
 *   a[data-open-link]      opens a page in Telegram's in-app browser (the subscription page)
 *   button[data-action]    POSTs to that URL, shows the answer's message, reloads the section if data-reload
 *   button[data-copy]      copies its value
 *   button[data-close-app] closes the Mini App (back to the chat)
 *   .view-root[data-main-button-text][data-main-button-href]  Telegram's MainButton for the section
 */
(function () {
    'use strict';

    var STORE_KEY = 'amoobot.miniapp.session';
    var tg = window.Telegram && window.Telegram.WebApp;
    var body = document.body;
    var view = document.getElementById('view');
    var nav = document.getElementById('nav');
    var lang = body.getAttribute('data-lang') === 'en' ? 'en' : 'fa';
    var session = null;
    var stack = [];
    var mainButtonHandler = null;

    /* ---------- Telegram integration ---------- */

    function supports(version) {
        return tg && typeof tg.isVersionAtLeast === 'function' && tg.isVersionAtLeast(version);
    }

    function haptic(kind) {
        if (!supports('6.1') || !tg.HapticFeedback) {
            return;
        }
        if (kind === 'select') {
            tg.HapticFeedback.selectionChanged();
        } else {
            tg.HapticFeedback.notificationOccurred(kind);
        }
    }

    /* Telegram's colors onto the page's variables (app.css); light/dark follow Telegram. */
    function applyTheme() {
        var p = tg.themeParams || {};
        var map = {
            '--bg': p.secondary_bg_color || p.bg_color,
            '--card': p.section_bg_color || p.bg_color,
            '--fg': p.text_color,
            '--muted': p.hint_color || p.subtitle_text_color,
            '--accent': p.button_color || p.link_color,
            '--accent-fg': p.button_text_color,
            '--border': p.section_separator_color,
            '--code': p.secondary_bg_color,
            '--bad': p.destructive_text_color
        };
        var root = document.documentElement;
        Object.keys(map).forEach(function (name) {
            if (map[name]) {
                root.style.setProperty(name, map[name]);
            }
        });
        root.setAttribute('data-theme', tg.colorScheme === 'dark' ? 'dark' : 'light');
        if (supports('6.1')) {
            tg.setHeaderColor('secondary_bg_color');
            tg.setBackgroundColor('secondary_bg_color');
        }
    }

    /* The on-screen keyboard: hide the bottom navigation while it is open (focus, or a much smaller viewport). */
    function watchKeyboard() {
        var isField = function (el) {
            return el && (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA' || el.isContentEditable);
        };
        document.addEventListener('focusin', function (e) {
            if (isField(e.target)) {
                body.classList.add('keyboard');
            }
        });
        document.addEventListener('focusout', function () {
            setTimeout(function () {
                if (!isField(document.activeElement)) {
                    body.classList.remove('keyboard');
                }
            }, 50);
        });
        tg.onEvent('viewportChanged', function () {
            if (tg.viewportStableHeight && tg.viewportHeight < tg.viewportStableHeight - 120) {
                body.classList.add('keyboard');
            } else if (!isField(document.activeElement)) {
                body.classList.remove('keyboard');
            }
        });
    }

    function updateBackButton() {
        if (!supports('6.1')) {
            return;
        }
        if (stack.length > 1) {
            tg.BackButton.show();
        } else {
            tg.BackButton.hide();
        }
    }

    function updateMainButton(root) {
        if (mainButtonHandler) {
            tg.MainButton.offClick(mainButtonHandler);
            mainButtonHandler = null;
        }
        var text = root && root.getAttribute('data-main-button-text');
        var href = root && root.getAttribute('data-main-button-href');
        if (!text || !href) {
            tg.MainButton.hide();
            return;
        }
        mainButtonHandler = function () {
            haptic('select');
            if (root.getAttribute('data-main-button-external') !== null) {
                openLink(href);
            } else {
                navigate(href, true);
            }
        };
        tg.MainButton.setText(text);
        tg.MainButton.onClick(mainButtonHandler);
        tg.MainButton.show();
    }

    function openLink(href) {
        var url = new URL(href, window.location.href).toString();
        if (supports('6.1')) {
            tg.openLink(url);
        } else {
            window.location.href = url;
        }
    }

    /* ---------- feedback ---------- */

    var toastTimer = null;
    function toast(message) {
        var el = document.getElementById('toast');
        el.textContent = message;
        el.classList.add('show');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () { el.classList.remove('show'); }, 2600);
    }

    function showState(name, messageKey) {
        document.querySelectorAll('[data-state]').forEach(function (el) {
            el.hidden = el.getAttribute('data-state') !== name;
        });
        if (messageKey) {
            var message = document.querySelector('[data-state-message]');
            message.textContent = message.getAttribute('data-' + messageKey) || message.getAttribute('data-error');
        }
    }

    /* ---------- session ---------- */

    function loadSession() {
        try {
            var stored = JSON.parse(window.sessionStorage.getItem(STORE_KEY) || 'null');
            if (stored && stored.token && stored.csrfToken && Date.parse(stored.expiresAt) > Date.now()) {
                return stored;
            }
        } catch (e) { /* storage blocked: sign in again */ }
        return null;
    }

    function saveSession(value) {
        session = value;
        try {
            if (value) {
                window.sessionStorage.setItem(STORE_KEY, JSON.stringify(value));
            } else {
                window.sessionStorage.removeItem(STORE_KEY);
            }
        } catch (e) { /* storage blocked: keep it in memory */ }
    }

    function signIn() {
        return fetch('/app/auth', {
            method: 'POST',
            headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
            body: JSON.stringify({initData: tg.initData}),
            credentials: 'omit'
        }).then(function (response) {
            return response.json().catch(function () { return {}; }).then(function (data) {
                if (!response.ok || !data.token) {
                    var error = new Error('auth');
                    error.code = response.status === 429 ? 'rate-limited' : (data.error === 'expired' ? 'expired' : 'invalid');
                    throw error;
                }
                saveSession({token: data.token, csrfToken: data.csrfToken, expiresAt: data.expiresAt});
            });
        });
    }

    /* fetch() with the session; on 401 signs in again once (initData stays valid for an hour). */
    function api(method, url, retried) {
        var headers = {'Accept': 'application/json, text/html', 'Authorization': 'Bearer ' + session.token};
        if (method !== 'GET') {
            headers['X-CSRF-Token'] = session.csrfToken;
        }
        return fetch(withLang(url), {method: method, headers: headers, credentials: 'omit'}).then(function (response) {
            if (response.status === 401 && !retried) {
                saveSession(null);
                return signIn().then(function () { return api(method, url, true); });
            }
            return response;
        });
    }

    function withLang(url) {
        return lang === 'en' ? url + (url.indexOf('?') === -1 ? '?' : '&') + 'lang=en' : url;
    }

    /* ---------- sections ---------- */

    function navigate(path, push) {
        return api('GET', path).then(function (response) {
            if (!response.ok) {
                throw new Error('view');
            }
            return response.text();
        }).then(function (html) {
            if (push) {
                stack.push(path);
            }
            showState('none');
            view.querySelectorAll('.view-root').forEach(function (el) { el.remove(); });
            view.insertAdjacentHTML('beforeend', html);
            var root = view.querySelector('.view-root');
            if (root && root.getAttribute('data-title')) {
                document.title = root.getAttribute('data-title');
            }
            markNav(root ? root.getAttribute('data-view') : '');
            updateBackButton();
            updateMainButton(root);
            window.scrollTo(0, 0);
        }).catch(function () {
            toast(body.getAttribute(navigator.onLine === false ? 'data-msg-offline' : 'data-msg-error'));
        });
    }

    function current() {
        return stack[stack.length - 1];
    }

    function back() {
        if (stack.length > 1) {
            stack.pop();
            haptic('select');
            navigate(current(), false);
        }
    }

    function markNav(key) {
        nav.querySelectorAll('[data-nav-key]').forEach(function (item) {
            var active = item.getAttribute('data-nav-key') === key;
            item.classList.toggle('active', active);
            if (active) {
                item.setAttribute('aria-current', 'page');
            } else {
                item.removeAttribute('aria-current');
            }
        });
    }

    function loadNav() {
        return api('GET', '/app/view/nav').then(function (response) {
            if (!response.ok) {
                throw new Error('nav');
            }
            return response.text();
        }).then(function (html) {
            nav.innerHTML = html;
            nav.hidden = nav.children.length === 0;
        });
    }

    /* ---------- actions ---------- */

    function copy(text) {
        var done = function () { haptic('success'); toast(body.getAttribute('data-msg-copied')); };
        var fallback = function () {
            var area = document.createElement('textarea');
            area.value = text;
            area.setAttribute('readonly', '');
            area.style.position = 'fixed';
            area.style.opacity = '0';
            document.body.appendChild(area);
            area.select();
            var ok = false;
            try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
            area.remove();
            if (ok) { done(); } else { toast(body.getAttribute('data-msg-copy-failed')); }
        };
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(done, fallback);
        } else {
            fallback();
        }
    }

    function runAction(button) {
        button.setAttribute('aria-busy', 'true');
        api('POST', button.getAttribute('data-action')).then(function (response) {
            return response.json().catch(function () { return {}; }).then(function (data) {
                haptic(response.ok ? 'success' : 'error');
                toast(data.message || body.getAttribute('data-msg-error'));
                if (response.ok && button.hasAttribute('data-reload')) {
                    return navigate(current(), false);
                }
            });
        }).catch(function () {
            haptic('error');
            toast(body.getAttribute('data-msg-error'));
        }).then(function () {
            button.removeAttribute('aria-busy');
        });
    }

    document.addEventListener('click', function (e) {
        var target = e.target.closest('a, button');
        if (!target) {
            return;
        }
        if (target.matches('a[data-nav]')) {
            e.preventDefault();
            haptic('select');
            if (nav.contains(target)) {
                stack = [];
            }
            navigate(target.getAttribute('href'), true);
        } else if (target.matches('a[data-open-link]')) {
            e.preventDefault();
            openLink(target.getAttribute('href'));
        } else if (target.matches('button[data-action]')) {
            runAction(target);
        } else if (target.matches('button[data-copy]')) {
            copy(target.getAttribute('data-copy'));
        } else if (target.matches('button[data-close-app]')) {
            tg.close();
        }
    });

    /* ---------- start ---------- */

    function start() {
        showState('loading');
        session = loadSession();
        (session ? Promise.resolve() : signIn()).then(loadNav).then(function () {
            stack = [];
            return navigate('/app/view/home', true);
        }).then(function () {
            if (!view.querySelector('.view-root')) {
                showState('failed', 'error');
            }
        }).catch(function (error) {
            showState('failed', error.code || 'error');
        });
    }

    if (!tg || !tg.initData) {
        showState('outside');
        return;
    }

    tg.ready();
    tg.expand();
    applyTheme();
    tg.onEvent('themeChanged', applyTheme);
    watchKeyboard();
    if (supports('6.1')) {
        tg.BackButton.onClick(back);
    }
    document.querySelector('[data-retry]').addEventListener('click', start);
    start();
})();
