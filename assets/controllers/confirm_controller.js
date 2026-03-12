import { Controller } from '@hotwired/stimulus';

/*
 * Stimulus Controller für eine gestylte Lösch-Bestätigung.
 *
 * Statt dem Browser-confirm() zeigen wir ein eigenes Modal.
 * Das Modal wird bei Klick auf "Löschen" eingeblendet.
 * "Abbrechen" schließt es, "Löschen" sendet das Formular ab.
 */
export default class extends Controller {
    static targets = ['dialog'];

    show() {
        this.dialogTarget.showModal();
    }

    close() {
        this.dialogTarget.close();
    }
}
