/**
 * Brings a refused add to cart's reason into view. The form's message sits
 * at its top while the button sits at its end, which on a phone puts the
 * message a screen or more above the place the customer tapped; without
 * this, the button looks as if it did nothing.
 */

/** The SDK's mark beside a field it refused, shown with its sentence while
 *  the field still owes a change; hidden and empty for a field that does
 *  not. Its parent holds the field: its label, its control and the mark. */
const FIELD_MARK_SELECTOR = '[role="alert"][data-testid^="violation-"]';

/** What a customer can move to in a field: its text box, select, or the
 *  button that opens an upload. A file input is drawn hidden behind its
 *  button and cannot take focus. */
const FIELD_CONTROL_SELECTOR = [
	'input:not([type="hidden"]):not([type="file"])',
	'textarea',
	'select',
	'button',
	'[tabindex]:not([tabindex="-1"])',
].join(',');

/** Whether `element` is drawn: not in a step or tab the form hides. */
function drawn(element: Element): boolean {
	return element.getClientRects().length > 0;
}

/** The first field the SDK marks as refused in `root`, in the order the
 *  form shows them; null when it marks none in view of the customer. */
export function firstMarkedField(root: ParentNode): HTMLElement | null {
	for (const mark of root.querySelectorAll<HTMLElement>(FIELD_MARK_SELECTOR)) {
		const field = mark.parentElement;
		if (
			!mark.hidden &&
			(mark.textContent ?? '').trim() !== '' &&
			field !== null &&
			drawn(field)
		) {
			return field;
		}
	}
	return null;
}

function controlOf(field: HTMLElement): HTMLElement | null {
	for (const control of field.querySelectorAll<HTMLElement>(
		FIELD_CONTROL_SELECTOR,
	)) {
		if (!control.hidden && drawn(control)) {
			return control;
		}
	}
	return null;
}

/** The room `element` keeps above itself when scrolled to: its
 *  stylesheet's `scroll-margin-top`, clear of a theme's sticky header. */
function scrollMarginTop(element: HTMLElement | null, view: Window): number {
	if (element === null) {
		return 0;
	}
	const margin = Number.parseFloat(
		view.getComputedStyle(element).scrollMarginTop,
	);
	return Number.isFinite(margin) ? margin : 0;
}

/** Whether `element` lies wholly inside the window. */
function inView(element: HTMLElement): boolean {
	const rect = element.getBoundingClientRect();
	const view = element.ownerDocument.defaultView;
	return view !== null && rect.top >= 0 && rect.bottom <= view.innerHeight;
}

/**
 * Shows the customer why the add to cart was refused: the first field the
 * SDK marks in `root`, focused, with `message` above it when both fit in the
 * window, else the field alone in the middle of it; with no field marked,
 * `message` alone, when it is out of view.
 */
export function bringRefusalIntoView(
	root: ParentNode,
	message: HTMLElement | null,
): void {
	const field = firstMarkedField(root);
	if (field === null) {
		if (message !== null && !message.hidden && !inView(message)) {
			message.scrollIntoView({ block: 'center' });
		}
		return;
	}
	const view = field.ownerDocument.defaultView;
	const fieldRect = field.getBoundingClientRect();
	const messageRect =
		message !== null && !message.hidden
			? message.getBoundingClientRect()
			: null;
	if (
		messageRect !== null &&
		view !== null &&
		messageRect.top <= fieldRect.top &&
		fieldRect.bottom - messageRect.top + scrollMarginTop(message, view) <=
			view.innerHeight
	) {
		message?.scrollIntoView({ block: 'start' });
	} else {
		field.scrollIntoView({ block: 'center' });
	}
	controlOf(field)?.focus({ preventScroll: true });
}
