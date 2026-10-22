/*
 * Telegram Mini App (/app). Plain progressive-enhancement script, no framework.
 *
 * Sign-in: Telegram.WebApp.initData is POSTed to /app/auth (the server validates it; initDataUnsafe is never used).
 * The session token comes back in the body, is kept in sessionStorage (this WebView only) and sent as
 * "Authorization: Bearer"; state-changing requests also send X-CSRF-Token.
 */
(function () {
    'use strict';

    var STORE_KEY = 'amoobot.miniapp.session';
    var tg = window.Telegram && window.Telegram.WebApp;
    var body = document.body;
    var session = null;

    function showState(name, messageKey) {
        document.querySelectorAll('[data-state]').forEach(function (el) {
            el.hidden = el.getAttribute('data-state') !== name;
        });
        if (messageKey) {
            var message = document.querySelector('[data-state-message]');
            message.textContent = message.getAttribute('data-' + messageKey) || message.getAttribute('data-error');
        }
    }

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

    /* fetch() with the session; on 401 signs in again once (initData is still valid for an hour). */
    function api(method, url, retried) {
        var headers = {'Accept': 'application/json, text/html', 'Authorization': 'Bearer ' + session.token};
        if (method !== 'GET') {
            headers['X-CSRF-Token'] = session.csrfToken;
        }
        return fetch(url, {method: method, headers: headers, credentials: 'omit'}).then(function (response) {
            if (response.status === 401 && !retried) {
                saveSession(null);
                return signIn().then(function () { return api(method, url, true); });
            }
            return response;
        });
    }

    function start() {
        showState('loading');
        session = loadSession();
        (session ? Promise.resolve() : signIn()).then(function () {
            return api('GET', '/app/api/me');
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('me');
            }
            showState('none');
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
    document.querySelector('[data-retry]').addEventListener('click', start);
    window.MiniApp = {api: api, body: body};
    start();
})();
