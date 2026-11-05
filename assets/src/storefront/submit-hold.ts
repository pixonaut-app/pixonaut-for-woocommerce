/** On the add-to-cart button while its click is being handled. */
export const BUSY_CLASS = 'pixonaut-busy';

/** The form's own add-to-cart button, when the form was sent without one. */
const SUBMIT_SELECTOR = '.single_add_to_cart_button, [type="submit"]';

/**
 * Holds the add-to-cart button busy from the click until the page moves on
 * or an error is shown: a spinner on it, and no second add to cart. The
 * button is disabled only once the form is sent, since a disabled button
 * drops its own name and value (WooCommerce's `add-to-cart`) from what the
 * form posts. A page the browser restores from its back-forward cache
 * starts released, its button as enabled as it was before the form was
 * sent.
 */
export class SubmitHold {
	private button: HTMLElement | null = null;

	private sent = false;

	/** Whether the hold disabled the button when the form was sent, so a
	 *  restored page enables it again. */
	private disabledOnSend = false;

	constructor(
		private readonly form: HTMLFormElement,
		/** Whether an element is one the designer drew, whose buttons are
		 *  not an add to cart. */
		private readonly drawnByDesigner: (element: Element) => boolean,
	) {
		form.ownerDocument.defaultView?.addEventListener('pageshow', (event) => {
			if (event.persisted) {
				this.sent = false;
				this.release();
			}
		});
	}

	/** Whether the form was sent on, and the page is on its way to the next. */
	get leaving(): boolean {
		return this.sent;
	}

	/** Marks the button the form was sent with, or its own, busy. */
	hold(submitter: HTMLElement | null): void {
		this.release();
		const button =
			submitter ??
			Array.from(this.form.querySelectorAll<HTMLElement>(SUBMIT_SELECTOR)).find(
				(element) => !this.drawnByDesigner(element),
			) ??
			null;
		if (button === null) {
			return;
		}
		button.classList.add(BUSY_CLASS);
		button.setAttribute('aria-busy', 'true');
		button.setAttribute('aria-disabled', 'true');
		this.button = button;
	}

	/** After the form was sent on: the button stays busy, and disabled. */
	left(): void {
		this.sent = true;
		if (
			this.button instanceof HTMLButtonElement ||
			this.button instanceof HTMLInputElement
		) {
			this.disabledOnSend = !this.button.disabled;
			this.button.disabled = true;
		}
	}

	/** Frees the button, unless the form was sent on. */
	release(): void {
		const button = this.button;
		if (this.sent || button === null) {
			return;
		}
		button.classList.remove(BUSY_CLASS);
		button.removeAttribute('aria-busy');
		button.removeAttribute('aria-disabled');
		if (
			this.disabledOnSend &&
			(button instanceof HTMLButtonElement ||
				button instanceof HTMLInputElement)
		) {
			button.disabled = false;
		}
		this.disabledOnSend = false;
		this.button = null;
	}
}
