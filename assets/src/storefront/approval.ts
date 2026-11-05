/** The approval box's class, as the form template prints it. */
const BOX_SELECTOR = '.pixonaut-approval__box';

/** Each side's proof button, carrying its side in `data-pixonaut-side`. */
const PROOF_SELECTOR = '.pixonaut-approval__proof';

/** The approval box and the proof buttons together. */
const CONTROLS_SELECTOR = '.pixonaut-approval';

/** The message shown under the box when the form is sent without it. */
const ERROR_SELECTOR = '.pixonaut-approval__error';

/** Marks the controls while the form was sent without the box ticked. */
const INVALID_CLASS = 'is-invalid';

/**
 * The checkbox approval mode's controls in a designer's form container: the
 * "I approve the preview" box, which the form requires, and each side's
 * proof button, the word "preview" in the box's label (or an "Open proof"
 * button beside it, for a label that links no word). Adding to the cart with the box unticked shows the
 * error under the box, in place of the browser's own bubble, until the box is
 * ticked.
 */
export class CheckboxApproval {
	/** False while the chosen variation is approved by adding to the cart:
	 *  the controls are hidden and lock nothing. */
	private enabled = true;

	private constructor(
		private readonly container: HTMLElement,
		private readonly box: HTMLInputElement,
	) {}

	/** The container's approval controls; null when the product's approval
	 *  mode is not the checkbox. */
	static find(
		container: HTMLElement,
		form: HTMLFormElement | null,
	): CheckboxApproval | null {
		const box = container.querySelector<HTMLInputElement>(BOX_SELECTOR);
		return box === null || form === null
			? null
			: new CheckboxApproval(container, box);
	}

	/** Shows `message` under the box when the form is sent without it, until
	 *  it is ticked, and hands each proof button's click to `openProof` with
	 *  its side and the button. */
	start(
		openProof: (side: string, opener: HTMLElement) => void,
		message: string,
	): void {
		// The browser checks the required box before it sends the form, and
		// the designer before it approves: either way the box is invalid.
		this.box.addEventListener('invalid', (event) => {
			event.preventDefault();
			this.showInvalid(message);
		});
		this.box.addEventListener('change', () => {
			if (this.box.checked) {
				this.showInvalid(null);
			}
		});
		for (const button of this.container.querySelectorAll<HTMLElement>(
			PROOF_SELECTOR,
		)) {
			button.addEventListener('click', (event) => {
				// Inside the box's label, the click opens the proof alone.
				event.preventDefault();
				openProof(button.dataset.pixonautSide ?? '', button);
			});
		}
	}

	ticked(): boolean {
		return this.enabled && this.box.checked;
	}

	/**
	 * Shows the controls, or hides them for a variation approved another
	 * way. Hidden, the box is disabled, so the form neither requires nor
	 * sends it.
	 */
	setEnabled(enabled: boolean): void {
		this.enabled = enabled;
		this.box.disabled = !enabled;
		const controls = this.controls();
		if (controls !== null) {
			controls.hidden = !enabled;
		}
		if (!enabled) {
			this.showInvalid(null);
		}
	}

	/** After the side's design changed: the box is ticked again for the new
	 *  design. */
	designChanged(): void {
		this.box.checked = false;
	}

	/** The block theme's button class the template printed, for the proof
	 *  dialog's button; empty under a classic theme. */
	buttonClass(): string {
		return this.controls()?.dataset.pixonautButtonClass ?? '';
	}

	private controls(): HTMLElement | null {
		return this.box.closest<HTMLElement>(CONTROLS_SELECTOR);
	}

	/**
	 * Marks the box invalid and shows `message` under it, focusing the box so
	 * the customer finds it; null clears both.
	 */
	private showInvalid(message: string | null): void {
		const controls = this.controls();
		const error = controls?.querySelector<HTMLElement>(ERROR_SELECTOR) ?? null;
		controls?.classList.toggle(INVALID_CLASS, message !== null);
		if (message === null) {
			this.box.removeAttribute('aria-invalid');
		} else {
			this.box.setAttribute('aria-invalid', 'true');
			this.box.focus();
		}
		if (error !== null) {
			error.textContent = message ?? '';
			error.hidden = message === null;
		}
	}
}
