import { Controller } from '@hotwired/stimulus';

/*
 * Stimulus Controller für das mobile Hamburger-Menü.
 *
 * Targets:
 *   - menu:   Das <div> mit den mobilen Navigationslinks
 *   - button: Der Hamburger-Button (für Icon-Animation)
 *
 * Features:
 *   - Slide-Animation (max-height + opacity Transition)
 *   - Hamburger→X Icon-Animation (CSS-Klasse 'open' auf dem Button)
 *   - Schließt bei Link-Klick und bei Klick außerhalb
 */
export default class extends Controller {
    static targets = ['menu', 'button'];

    connect() {
        this.open = false;
        const menu = this.menuTarget;

        // Anfangszustand: eingeklappt. overflow-hidden verhindert,
        // dass Inhalt während der Animation rausguckt.
        menu.style.maxHeight = '0px';
        menu.style.opacity = '0';
        menu.style.overflow = 'hidden';
        menu.style.transition = 'max-height 200ms ease-out, opacity 150ms ease-out';
        menu.classList.remove('hidden');

        // Bound reference speichern, damit wir den Listener sauber entfernen können.
        // Ohne bind() wäre 'this' im Callback nicht der Controller, sondern das Event-Target.
        this._onClickOutside = this.onClickOutside.bind(this);
    }

    // Stimulus ruft disconnect() auf wenn der Controller vom DOM entfernt wird.
    // Listener aufräumen, sonst bleiben sie als "Geister" aktiv.
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

    // Wird von den Links im Menü aufgerufen (data-action="click->mobile-menu#close")
    close() {
        if (this.open) {
            this._close();
        }
    }

    // Click-Event auf dem gesamten Document — schließt das Menü wenn der Klick
    // außerhalb des Headers (= Controller-Element) war.
    onClickOutside(event) {
        // this.element ist das <header>-Tag (das Element mit data-controller).
        // contains() prüft ob der Klick innerhalb des Headers war.
        if (!this.element.contains(event.target)) {
            this._close();
        }
    }

    _open() {
        this.open = true;
        const menu = this.menuTarget;

        menu.style.maxHeight = menu.scrollHeight + 'px';
        menu.style.opacity = '1';

        // 'open'-Klasse auf dem Button → CSS animiert die 3 Striche zum X.
        // Tailwind group-[.open]: Selektoren greifen darauf.
        this.buttonTarget.classList.add('open');

        // Listener für Klick-außerhalb registrieren.
        // requestAnimationFrame verhindert, dass der aktuelle Klick
        // (der das Menü gerade öffnet) sofort den Listener triggert.
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
