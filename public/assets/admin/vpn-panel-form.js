(function () {
    'use strict';

    function ready(callback) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', callback, { once: true });
            return;
        }

        callback();
    }

    function fieldValue(form, name) {
        var checked = form.querySelector('[name$="[' + name + ']"]:checked');
        if (checked) {
            return checked.value;
        }

        var field = form.querySelector('[name$="[' + name + ']"]');
        return field ? field.value : '';
    }

    function setVisible(elements, visible) {
        elements.forEach(function (element) {
            element.classList.toggle('vpn-panel-hidden', !visible);
            element.setAttribute('aria-hidden', visible ? 'false' : 'true');
        });
    }

    function markRequired(form, selector, required) {
        form.querySelectorAll(selector).forEach(function (field) {
            field.toggleAttribute('required', required);
        });
    }

    function refreshChoiceCards(form) {
        form.querySelectorAll('.vpn-panel-mode-choice .form-check, .vpn-panel-auth-mode-choice .form-check').forEach(function (choice) {
            var input = choice.querySelector('input');
            choice.classList.toggle('vpn-panel-choice-selected', Boolean(input && input.checked));
        });
    }

    function update(form) {
        var type = fieldValue(form, 'type');
        var mode = fieldValue(form, 'sanaeiIntegrationMode') || 'legacy';
        var authMode = fieldValue(form, 'authMode') || 'cookie';
        var isSanaei = type === 'sanaei_3xui';
        var isModern = isSanaei && mode === 'modern';
        var isLegacy = isSanaei && !isModern;
        var isLegacyBearer = isLegacy && authMode === 'bearer';
        var isLegacyCookie = isLegacy && !isLegacyBearer;

        setVisible(Array.from(form.querySelectorAll('.vpn-panel-mode-sanaei')), isSanaei);
        setVisible(Array.from(form.querySelectorAll('.vpn-panel-mode-modern')), isModern);
        setVisible(Array.from(form.querySelectorAll('.vpn-panel-mode-legacy')), isLegacy);
        setVisible(Array.from(form.querySelectorAll('.vpn-panel-auth-cookie')), isLegacyCookie);
        setVisible(Array.from(form.querySelectorAll('.vpn-panel-auth-bearer')), isLegacyBearer);
        setVisible(Array.from(form.querySelectorAll('.vpn-panel-token-section')), isModern || isLegacyBearer);

        markRequired(form, '[name$="[title]"]', true);
        markRequired(form, '[name$="[baseUrl]"]', true);
        markRequired(form, '[name$="[apiToken]"]', false);
        markRequired(form, '[name$="[username]"]', isLegacyCookie);
        markRequired(form, '[name$="[password]"]', false);

        refreshChoiceCards(form);
    }

    ready(function () {
        document.querySelectorAll('form').forEach(function (form) {
            if (!form.querySelector('[name$="[sanaeiIntegrationMode]"]')) {
                return;
            }

            form.classList.add('vpn-panel-admin-form');
            update(form);
            form.addEventListener('change', function (event) {
                if (!(event.target instanceof HTMLElement)) {
                    return;
                }

                if (event.target.matches('[name$="[type]"], [name$="[sanaeiIntegrationMode]"], [name$="[authMode]"]')) {
                    update(form);
                }
            });
        });
    });
})();
