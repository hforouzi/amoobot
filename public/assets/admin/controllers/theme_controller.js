// Light / dark toggle of the modern admin. It drives EasyAdmin's own colour scheme (the same localStorage key and body
// classes as EasyAdmin's page-color-scheme.js, so its settings menu stays in sync) and the modern tokens, which follow
// .ea-dark-scheme. The choice is kept per browser; until one is made, the system preference applies (EasyAdmin "auto").
import { Controller } from '../vendor/stimulus.js';

const STORAGE_KEY = 'ea/colorScheme';

export default class extends Controller {
    static targets = ['label'];
    static values = { lightLabel: String, darkLabel: String };

    connect() {
        this.render();
    }

    toggle() {
        const next = this.isDark() ? 'light' : 'dark';
        try {
            localStorage.setItem(STORAGE_KEY, next);
        } catch (e) {
            // Private mode: the choice lasts for this page only.
        }
        document.body.classList.remove('ea-light-scheme', 'ea-dark-scheme');
        document.body.classList.add(`ea-${next}-scheme`);
        document.body.style.colorScheme = next;
        document.body.setAttribute('data-bs-theme', next);
        this.render();
    }

    isDark() {
        return document.body.classList.contains('ea-dark-scheme');
    }

    render() {
        const label = this.isDark() ? this.lightLabelValue : this.darkLabelValue;
        this.element.setAttribute('aria-pressed', this.isDark() ? 'true' : 'false');
        if (label) {
            this.element.setAttribute('aria-label', label);
            this.element.setAttribute('title', label);
            if (this.hasLabelTarget) {
                this.labelTarget.textContent = label;
            }
        }
    }
}
