// The message under a required field the store manager left empty, shared by
// the settings tab and the product screen's Pixonaut fields.

/** The message's class; one per field, right after the element it is about. */
export const FIELD_ERROR_CLASS = 'pixonaut-field-error';

/**
 * Shows `message` right after `anchor`, the field or the box that holds it,
 * and marks `field` invalid; null takes the message away again. Without a
 * `field`, the caller marks the fields the message is about.
 */
export function setFieldError(
	field: HTMLElement | null,
	anchor: HTMLElement,
	message: string | null,
): void {
	const next = anchor.nextElementSibling;
	const shown = next?.classList.contains(FIELD_ERROR_CLASS) ? next : null;
	if (message === null) {
		shown?.remove();
		field?.removeAttribute('aria-invalid');
		return;
	}
	const error = shown ?? document.createElement('span');
	error.className = FIELD_ERROR_CLASS;
	error.setAttribute('role', 'alert');
	error.textContent = message;
	if (!shown) {
		anchor.after(error);
	}
	field?.setAttribute('aria-invalid', 'true');
}
