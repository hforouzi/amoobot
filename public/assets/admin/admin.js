// Admin UI helpers (no dependencies): the copy button of long technical values (AdminFormat::copyable()).
document.addEventListener('click', (event) => {
    const button = event.target instanceof Element ? event.target.closest('[data-copy]') : null;
    if (!button) {
        return;
    }
    event.preventDefault();
    event.stopPropagation();
    const value = button.getAttribute('data-copy') ?? '';
    const done = () => {
        button.classList.add('admin-copied');
        window.setTimeout(() => button.classList.remove('admin-copied'), 1200);
    };
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(value).then(done, () => {});
        return;
    }
    // Plain-HTTP admin (no Clipboard API): a temporary selection.
    const area = document.createElement('textarea');
    area.value = value;
    area.setAttribute('readonly', '');
    area.style.position = 'fixed';
    area.style.opacity = '0';
    document.body.appendChild(area);
    area.select();
    try {
        document.execCommand('copy');
        done();
    } finally {
        area.remove();
    }
});

// Row action menus inside a scrolling table: position them against the viewport, so the table's scroll container
// does not clip them (Bootstrap reads data-bs-popper-config when it creates the dropdown).
const fixRowMenus = () => {
    document.querySelectorAll('.admin-table-scroll [data-bs-toggle="dropdown"]').forEach((toggle) => {
        toggle.setAttribute('data-bs-popper-config', '{"strategy":"fixed"}');
    });
};
document.addEventListener('DOMContentLoaded', fixRowMenus);
document.addEventListener('turbo:load', fixRowMenus);
// Live preview of a Mini App guide text on its Bot Content edit page (templates/admin/bot_text/_guide_preview.html.twig):
// the body is sent (CSRF token) to the preview endpoint while typing; the answer is a whole document for the sandboxed
// iframe, plus the problems saving would meet. Nothing is saved, nothing from the answer is inserted as HTML here.
(() => {
    const panel = document.querySelector('[data-guide-preview]');
    const field = document.querySelector('textarea[data-guide-text]');
    if (!panel || !field) {
        return;
    }
    const frame = panel.querySelector('[data-guide-frame]');
    const fillList = (list, items) => {
        if (!list) {
            return;
        }
        list.replaceChildren(...items.map((text) => {
            const item = document.createElement('li');
            item.textContent = String(text);
            return item;
        }));
    };
    let timer = null;
    let sent = 0;
    const update = () => {
        const id = ++sent;
        const data = new FormData();
        data.append('_token', panel.dataset.token ?? '');
        data.append('key', panel.dataset.key ?? '');
        data.append('locale', panel.dataset.locale ?? '');
        data.append('text', field.value);
        fetch(panel.dataset.url ?? '', { method: 'POST', body: data, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then((response) => (response.ok ? response.json() : null))
            .then((result) => {
                if (!result || id !== sent) {
                    return;
                }
                frame.srcdoc = String(result.document ?? '');
                fillList(panel.querySelector('[data-guide-problems]'), Array.isArray(result.problems) ? result.problems : []);
                fillList(panel.querySelector('[data-guide-notes]'), Array.isArray(result.notes) ? result.notes : []);
            })
            .catch(() => {});
    };
    field.addEventListener('input', () => {
        window.clearTimeout(timer);
        timer = window.setTimeout(update, 400);
    });
})();
