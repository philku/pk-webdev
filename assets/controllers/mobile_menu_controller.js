import { Controller } from '@hotwired/stimulus';

/*
 * Stimulus Controller für das mobile Hamburger-Menü.
 *
 * Targets:
 *   - menu: Das <div> mit den mobilen Navigationslinks
 *
 * Actions:
 *   - toggle: Blendet das Menü ein/aus
 */
export default class extends Controller {
    static targets = ['menu'];

    toggle() {
        this.menuTarget.classList.toggle('hidden');
    }
}
