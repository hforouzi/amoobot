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
 *   button[data-action]    POSTs to that URL (JSON body from data-body); the answer's redirect is opened, its
 *                          message shown; data-reload reloads the section; data-confirm asks first (Telegram's
 *                          confirm dialog)
 *   form[data-submit]      sends its fields as JSON to that URL, then follows the answer's redirect
 *   form[data-quote-url]   asks that URL for the server's price when [data-requote] fields change
 *   form[data-upload]      sends its file (multipart) to that URL
 *   .view-root[data-poll-url]  polled (backoff) until its state changes or is final, then the section reloads
 *
 * Every action and submit carries an Idempotency-Key kept on its element, so a double tap or a retry is the same
 * request for the server; after an error the key is dropped and the next try is a new one.
 * An admin money / destructive action is first answered 428 with {confirm: {token, message}}: the message is shown in
 * Telegram's confirm dialog and only on OK the call is repeated with the token in X-Admin-Confirm (same key).
 *   a[data-replace]        (filter chips, pager) opens in place of the current section (BackButton skips it)
 *   form[data-search]      opens that URL with the form's non-empty fields as the query, in place
 *   img[data-auth-src]     loaded with the session token (a receipt), shown as a data: URL
 *   .view-root[data-main-button-action]  Telegram's MainButton runs the [data-main-action] button
 *   form[data-check-url]   checked live by the server as it is edited (errors by field, warnings), nothing saved
 *   [data-error-for=name]  where a field's error goes (422 answers carry {errors: {field: [messages]}})
 *   form[data-dirty-guard] once edited, Telegram's BackButton asks (that text) before leaving it unsaved
 *   button[data-fill-from] fetches {code} from that URL into the form's field [name=data-fill-target]
 *   button[data-copy]      copies its value
 *   button[data-close-app] closes the Mini App (back to the chat)
 *   .view-root[data-main-button-text][data-main-button-href|data-main-button-form]  Telegram's MainButton
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
        var formId = root && root.getAttribute('data-main-button-form');
        var action = root && root.getAttribute('data-main-button-action');
        if (text && action) {
            mainButtonHandler = function () {
                var button = root.querySelector('[data-main-action="' + action + '"]');
                if (button) {
                    haptic('select');
                    runAction(button);
                }
            };
            tg.MainButton.setText(text);
            tg.MainButton.onClick(mainButtonHandler);
            tg.MainButton.show();
            return;
        }
        if (!text || (!href && !formId)) {
            tg.MainButton.hide();
            return;
        }
        mainButtonHandler = function () {
            haptic('select');
            var form = formId ? document.getElementById(formId) : null;
            if (form) {
                submitForm(form);
            } else if (root.getAttribute('data-main-button-external') !== null) {
                openLink(href);
            } else {
                navigate(href, true);
            }
        };
        tg.MainButton.setText(text);
        tg.MainButton.onClick(mainButtonHandler);
        tg.MainButton.show();
    }

    function confirmDialog(message) {
        return new Promise(function (resolve) {
            if (supports('6.2')) {
                tg.showConfirm(message, function (ok) { resolve(!!ok); });
            } else {
                resolve(window.confirm(message));
            }
        });
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

    /*
     * fetch() with the session; on 401 signs in again once (initData stays valid for an hour).
     * options: json (sent as JSON), form (FormData, multipart), key (Idempotency-Key).
     */
    function api(method, url, options, retried) {
        options = options || {};
        var headers = {'Accept': 'application/json, text/html', 'Authorization': 'Bearer ' + session.token};
        var payload;
        if (method !== 'GET') {
            headers['X-CSRF-Token'] = session.csrfToken;
        }
        if (options.key) {
            headers['Idempotency-Key'] = options.key;
        }
        if (options.confirm) {
            headers['X-Admin-Confirm'] = options.confirm;
        }
        if (options.json !== undefined) {
            headers['Content-Type'] = 'application/json';
            payload = JSON.stringify(options.json);
        } else if (options.form) {
            payload = options.form;
        }
        return fetch(withLang(url), {method: method, headers: headers, body: payload, credentials: 'omit'}).then(function (response) {
            if (response.status === 401 && !retried) {
                saveSession(null);
                return signIn().then(function () { return api(method, url, options, true); });
            }
            return response;
        });
    }

    /* The Idempotency-Key of an element: made once, kept until a call with it fails. */
    function keyOf(el) {
        if (!el.getAttribute('data-idempotency-key')) {
            var bytes = new Uint8Array(16);
            window.crypto.getRandomValues(bytes);
            el.setAttribute('data-idempotency-key', Array.prototype.map.call(bytes, function (b) { return ('0' + b.toString(16)).slice(-2); }).join(''));
        }
        return el.getAttribute('data-idempotency-key');
    }

    function withLang(url) {
        return lang === 'en' ? url + (url.indexOf('?') === -1 ? '?' : '&') + 'lang=en' : url;
    }

    /* ---------- sections ---------- */

    function navigate(path, push, keepScroll) {
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
            startPolling(root);
            loadAuthImages(root);
            if (!keepScroll) {
                window.scrollTo(0, 0);
            }
        }).catch(function () {
            toast(body.getAttribute(navigator.onLine === false ? 'data-msg-offline' : 'data-msg-error'));
        });
    }

    function current() {
        return stack[stack.length - 1];
    }

    function back() {
        var dirty = view.querySelector('form[data-dirty]');
        if (dirty) {
            confirmDialog(dirty.getAttribute('data-dirty-guard')).then(function (ok) {
                if (ok) {
                    dirty.removeAttribute('data-dirty');
                    back();
                }
            });
            return;
        }
        backNow();
    }

    function backNow() {
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

    function loadAuthImages(root) {
        if (!root) {
            return;
        }
        root.querySelectorAll('img[data-auth-src]').forEach(function (img) {
            var failed = function () {
                var note = document.createElement('p');
                note.className = 'muted';
                note.textContent = img.getAttribute('data-failed-text') || body.getAttribute('data-msg-error');
                img.replaceWith(note);
            };
            api('GET', img.getAttribute('data-auth-src')).then(function (response) {
                if (!response.ok) {
                    throw new Error('image');
                }
                return response.blob();
            }).then(function (blob) {
                var reader = new FileReader();
                reader.onload = function () { img.src = reader.result; };
                reader.onerror = failed;
                reader.readAsDataURL(blob);
            }).catch(failed);
        });
    }

    /* Opens a URL in place of the current section (filters, pages, searches): Back goes to where the list came from. */
    function replaceWith(url) {
        haptic('select');
        stack.pop();
        navigate(url, true);
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
        var json;
        try { json = JSON.parse(button.getAttribute('data-body') || '{}'); } catch (e) { json = {}; }
        var go = function () { send(button, 'POST', button.getAttribute('data-action'), {json: json, key: keyOf(button)}, null); };
        var question = button.getAttribute('data-confirm');
        if (question) {
            confirmDialog(question).then(function (ok) { if (ok) { go(); } });
        } else {
            go();
        }
    }

    /* One state-changing call from an element (button or form): busy while it runs, then the answer is applied. */
    function send(el, method, url, options, errorBox) {
        if (el.getAttribute('aria-busy') === 'true') {
            return;
        }
        el.setAttribute('aria-busy', 'true');
        if (errorBox) {
            errorBox.hidden = true;
        }
        var again = null;
        api(method, url, options).then(function (response) {
            return response.json().catch(function () { return {}; }).then(function (data) {
                if (response.status === 428 && data.confirm) {
                    // The server's confirmation step: same call, same key, with its token, only after "OK".
                    haptic('warning');
                    return confirmDialog(data.confirm.message).then(function (ok) {
                        if (ok) {
                            again = function () { send(el, method, url, Object.assign({}, options, {confirm: data.confirm.token}), errorBox); };
                        }
                    });
                }
                if (!response.ok) {
                    el.removeAttribute('data-idempotency-key');
                    haptic('error');
                    showError(data, errorBox);
                    return;
                }
                haptic('success');
                if (el.tagName === 'FORM') {
                    el.removeAttribute('data-dirty');
                    showFieldErrors(el, null);
                }
                if (data.message) {
                    toast(data.message);
                }
                if (data.redirect) {
                    return navigate(data.redirect, true);
                }
                if (el.hasAttribute('data-reload')) {
                    return navigate(current(), false, true);
                }
            });
        }).catch(function () {
            el.removeAttribute('data-idempotency-key');
            haptic('error');
            toast(body.getAttribute(navigator.onLine === false ? 'data-msg-offline' : 'data-msg-error'));
        }).then(function () {
            el.removeAttribute('aria-busy');
            if (again) {
                again();
            }
        });
    }

    /* A form's inline errors: each field's messages under it ([data-error-for]), the field marked invalid. */
    function showFieldErrors(form, errors) {
        if (!form) {
            return null;
        }
        var first = null;
        form.querySelectorAll('[data-error-for]').forEach(function (box) {
            var name = box.getAttribute('data-error-for');
            var messages = errors && errors[name];
            var input = form.querySelector('[name="' + name + '"]');
            box.textContent = messages ? messages.join(' ') : '';
            box.hidden = !messages;
            if (input) {
                if (messages) {
                    input.setAttribute('aria-invalid', 'true');
                } else {
                    input.removeAttribute('aria-invalid');
                }
            }
            if (messages && !first) {
                first = box;
                var section = box.closest('details');
                if (section) {
                    section.open = true;
                }
            }
        });
        return first;
    }

    /* An error answer: its message, and the channels to join when membership is required. */
    function showError(data, errorBox) {
        var fieldError = data.errors ? showFieldErrors(errorBox && errorBox.closest('form'), data.errors) : null;
        if (fieldError) {
            fieldError.scrollIntoView({block: 'center'});
        }
        var message = (data.message || body.getAttribute('data-msg-error')) + (data.detail ? '\n' + data.detail : '');
        if (!errorBox) {
            toast(message);
            return;
        }
        errorBox.textContent = message;
        (data.channels || []).forEach(function (channel) {
            if (!channel.url) {
                return;
            }
            var link = document.createElement('a');
            link.href = channel.url;
            link.setAttribute('data-open-link', '');
            link.className = 'btn secondary small';
            link.textContent = channel.title;
            errorBox.appendChild(link);
        });
        errorBox.hidden = false;
        errorBox.scrollIntoView({block: 'nearest'});
    }

    /* A form's fields as an object (empty ones left out). */
    function fieldsOf(form) {
        var data = {};
        new FormData(form).forEach(function (value, name) {
            if (typeof value !== 'string' || value.trim() === '') {
                return;
            }
            if (name.slice(-2) === '[]') {
                name = name.slice(0, -2);
                data[name] = (data[name] || []).concat([value.trim()]);
            } else {
                data[name] = value.trim();
            }
        });
        return data;
    }

    function submitForm(form) {
        var errorBox = form.querySelector('[data-form-error]');
        if (form.hasAttribute('data-upload')) {
            send(form, 'POST', form.getAttribute('data-upload'), {form: new FormData(form)}, errorBox);
        } else {
            send(form, 'POST', form.getAttribute('data-submit'), {json: fieldsOf(form), key: keyOf(form)}, errorBox);
        }
    }

    /* The server's price for the form's current choices (nothing is created). */
    var quoteTimer = null;
    function requote(form, delay) {
        clearTimeout(quoteTimer);
        quoteTimer = setTimeout(function () {
            var target = form.querySelector('[data-quote]');
            api('POST', form.getAttribute('data-quote-url'), {json: fieldsOf(form)}).then(function (response) {
                return response.json().then(function (data) {
                    if (response.ok && target && data.html) {
                        showFieldErrors(form, null);
                        target.innerHTML = data.html;
                    } else if (!response.ok) {
                        showError(data, form.querySelector('[data-form-error]'));
                    }
                });
            }).catch(function () { /* the price stays as shown; the submit checks again */ });
        }, delay);
    }

    /* The server's verdict on the form as it is now (the same rules as saving; nothing is saved). */
    var checkTimer = null;
    function check(form, delay) {
        clearTimeout(checkTimer);
        checkTimer = setTimeout(function () {
            api('POST', form.getAttribute('data-check-url'), {json: fieldsOf(form)}).then(function (response) {
                return response.ok ? response.json() : null;
            }).then(function (data) {
                if (!data) {
                    return;
                }
                showFieldErrors(form, data.errors);
                var box = form.querySelector('[data-warnings]');
                var list = box && box.querySelector('[data-warning-list]');
                if (list) {
                    list.innerHTML = '';
                    (data.warnings || []).forEach(function (text) {
                        var item = document.createElement('li');
                        item.textContent = text;
                        list.appendChild(item);
                    });
                    box.hidden = !(data.warnings || []).length;
                }
            }).catch(function () { /* the save checks again */ });
        }, delay);
    }

    function fillFrom(button) {
        var form = button.closest('form');
        var input = form && form.querySelector('[name="' + button.getAttribute('data-fill-target') + '"]');
        if (!input || button.getAttribute('aria-busy') === 'true') {
            return;
        }
        button.setAttribute('aria-busy', 'true');
        api('GET', button.getAttribute('data-fill-from')).then(function (response) {
            return response.ok ? response.json() : null;
        }).then(function (data) {
            if (data && data.code) {
                input.value = data.code;
                input.dispatchEvent(new Event('input', {bubbles: true}));
                haptic('select');
            }
        }).catch(function () { /* the admin types a code */ }).then(function () {
            button.removeAttribute('aria-busy');
        });
    }

    /* ---------- polling ---------- */

    var POLL_DELAYS = [2000, 3000, 5000, 8000, 13000, 20000, 30000];
    var pollRun = 0;

    /* Polls the section's status URL with a growing delay (capped at 30 s) while it stays on screen; a changed or
       final state reloads the section. Coming back to the app (Telegram's activated event) polls at once. */
    function startPolling(root) {
        var run = ++pollRun;
        var url = root && root.getAttribute('data-poll-url');
        if (!url) {
            return;
        }
        var shown = root.getAttribute('data-poll-state');
        var attempt = 0;
        var timer = null;
        var poll = function () {
            if (run !== pollRun) {
                return;
            }
            api('GET', url).then(function (response) {
                return response.ok ? response.json() : null;
            }).then(function (data) {
                if (run !== pollRun) {
                    return;
                }
                if (data && (data.final || data.state !== shown)) {
                    haptic(data.state === 'paid' ? 'success' : 'select');
                    navigate(current(), false, true);
                    return;
                }
                schedule();
            }).catch(schedule);
        };
        var schedule = function () {
            clearTimeout(timer);
            timer = setTimeout(poll, POLL_DELAYS[Math.min(attempt++, POLL_DELAYS.length - 1)]);
        };
        pollNow = function () {
            if (run === pollRun) {
                attempt = 0;
                clearTimeout(timer);
                poll();
            }
        };
        schedule();
    }
    var pollNow = function () {};

    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (form.matches('form[data-submit], form[data-upload]')) {
            e.preventDefault();
            submitForm(form);
        } else if (form.matches('form[data-search]')) {
            e.preventDefault();
            var query = new URLSearchParams();
            Object.keys(fieldsOf(form)).forEach(function (name) { query.set(name, fieldsOf(form)[name]); });
            var search = query.toString();
            replaceWith(form.getAttribute('data-search') + (search ? '?' + search : ''));
        }
    });

    var markEdited = function (e) {
        var form = e.target.closest && e.target.closest('form');
        if (!form) {
            return;
        }
        if (form.hasAttribute('data-dirty-guard')) {
            form.setAttribute('data-dirty', '1');
        }
        if (form.hasAttribute('data-check-url')) {
            check(form, 500);
        }
    };
    document.addEventListener('input', markEdited);
    document.addEventListener('change', function (e) {
        if (e.target.matches && e.target.matches('input[type="checkbox"], input[type="radio"], select')) {
            markEdited(e);
        }
    });

    document.addEventListener('input', function (e) {
        var form = e.target.closest('form[data-quote-url]');
        if (form && e.target.matches('[data-requote]')) {
            requote(form, 400);
        }
    });

    document.addEventListener('click', function (e) {
        var target = e.target.closest('a, button');
        if (!target) {
            return;
        }
        if (target.matches('a[data-nav][data-replace]')) {
            e.preventDefault();
            replaceWith(target.getAttribute('href'));
        } else if (target.matches('a[data-nav]')) {
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
        } else if (target.matches('button[data-fill-from]')) {
            fillFrom(target);
        } else if (target.matches('button[data-copy]')) {
            copy(target.getAttribute('data-copy'));
        } else if (target.matches('button[data-close-app]')) {
            tg.close();
        } else if (target.matches('button[data-requote-now]')) {
            requote(target.closest('form'), 0);
        }
    });

    /* ---------- start ---------- */

    function start() {
        showState('loading');
        session = loadSession();
        (session ? Promise.resolve() : signIn()).then(loadNav).then(function () {
            // ?service=ID (the bot's "View in the app" button): that service, with Home behind the BackButton.
            var service = new URLSearchParams(window.location.search).get('service');
            if (service && /^[0-9]{1,10}$/.test(service)) {
                stack = ['/app/view/home'];
                return navigate('/app/view/services/' + service, true).then(function () {
                    if (!view.querySelector('.view-root')) {
                        stack = [];
                        return navigate('/app/view/home', true);
                    }
                });
            }
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
    // Back from the gateway's page or the bot: check the payment now instead of at the next poll.
    tg.onEvent('activated', function () { pollNow(); });
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') {
            pollNow();
        }
    });
    document.querySelector('[data-retry]').addEventListener('click', start);
    start();
})();
