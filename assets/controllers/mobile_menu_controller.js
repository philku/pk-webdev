import { Controller } from '@hotwired/stimulus';

// Hamburger menu with slide animation and click-outside-to-close.
export default class extends Controller {
    static targets = ['menu', 'button'];

    connect() {
        this.open = false;
        const menu = this.menuTarget;

        menu.style.maxHeight = '0px';
        menu.style.opacity = '0';
        menu.style.overflow = 'hidden';
        menu.style.transition = 'max-height 200ms ease-out, opacity 150ms ease-out';
        menu.classList.remove('hidden');

        this._onClickOutside = this.onClickOutside.bind(this);
    }

    disconnect() {
        document.removeEventListener('click', this._onClickOutside);
    }

    toggle() {
        if (this.open) {
            this._close();
        } else {
            this._open();
        }
    }

    close() {
        if (this.open) {
            this._close();
        }
    }

    onClickOutside(event) {
        if (!this.element.contains(event.target)) {
            this._close();
        }
    }

    _open() {
        this.open = true;
        const menu = this.menuTarget;

        menu.style.maxHeight = menu.scrollHeight + 'px';
        menu.style.opacity = '1';

        this.buttonTarget.classList.add('open');

        // Delay listener — prevents the opening click from immediately closing.
        requestAnimationFrame(() => {
            document.addEventListener('click', this._onClickOutside);
        });
    }

    _close() {
        this.open = false;
        this.menuTarget.style.maxHeight = '0px';
        this.menuTarget.style.opacity = '0';
        this.buttonTarget.classList.remove('open');

        document.removeEventListener('click', this._onClickOutside);
    }
}
