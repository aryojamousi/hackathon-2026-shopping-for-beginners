import { Controller } from '@hotwired/stimulus';

/*
 * Why-this dialog — opens a modal explaining why a product was recommended.
 *
 * Wrap the trigger button and a native <dialog> in an element with
 * data-controller="why-dialog". The button calls #open, the close (×) button
 * calls #close. Clicking the backdrop also closes the dialog.
 */
export default class extends Controller {
    static targets = ['dialog'];

    open() {
        if (typeof this.dialogTarget.showModal === 'function') {
            this.dialogTarget.showModal();
        } else {
            this.dialogTarget.setAttribute('open', '');
        }
    }

    close() {
        this.dialogTarget.close();
    }

    // Close when the backdrop (the dialog element itself) is clicked.
    backdrop(event) {
        if (event.target === this.dialogTarget) {
            this.close();
        }
    }
}
