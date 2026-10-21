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
