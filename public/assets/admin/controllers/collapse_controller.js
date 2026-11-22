// A show / hide toggle: puts a class on <body> (data-collapse-class-value) and keeps aria-expanded in step; with
// data-collapse-key-value the state is kept per browser. Used for the compact (icons only) sidebar.
import { Controller } from '../vendor/stimulus.js';

export default class extends Controller {
    static values = { class: String, key: String };

    connect() {
        this.apply(this.stored() === '1');
    }

    toggle() {
        const collapsed = !document.body.classList.contains(this.classValue);
        this.apply(collapsed);
        if (this.keyValue) {
            try {
                localStorage.setItem(this.keyValue, collapsed ? '1' : '0');
            } catch (e) {
                // Private mode: not kept.
            }
        }
    }

    apply(collapsed) {
        if (this.classValue) {
            document.body.classList.toggle(this.classValue, collapsed);
        }
        this.element.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
    }

    stored() {
        try {
            return this.keyValue ? localStorage.getItem(this.keyValue) : null;
        } catch (e) {
            return null;
        }
    }
}
