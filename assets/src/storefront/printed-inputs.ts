/** On each input of the printed form the designer takes over, naming its
 *  field. */
const PRINTED_INPUT_ATTRIBUTE = 'data-pixonaut-field';
const PRINTED_INPUT_SELECTOR = `[${PRINTED_INPUT_ATTRIBUTE}]`;
const PRINTED_FORM_SELECTOR = '.pixonaut-form__placeholder';
const ASIDE_SELECTOR = '.pixonaut-placeholder__aside';
const FILL_SELECTOR = '.pixonaut-placeholder__fill';
const THUMB_SELECTOR = '.pixonaut-placeholder__thumb';
const SWATCH_SELECTOR = '.pixonaut-placeholder__swatch';

/** Half a step, as near as a slider's value and what the server printed for it
 *  can be and still be the same number. */
const NEAREST_STEP_SHARE = 0.51;

const FULL_TRACK_PERCENT = 100;

/** Printed forms already followed: the script printed inline and the storefront
 *  script both ask, whichever runs. */
const followed = new WeakSet<Element>();

/**
 * Makes each printed form's inputs act as the designer's own until it takes
 * them: a slider and a colour show what they hold, a text counts its
 * characters, and Enter in one does not send the add-to-cart form, which cannot
 * add a design yet. Listens on the printed form, which an input leaves when the
 * designer takes it.
 */
export function followPrintedInputs(page: Document): void {
	for (const printed of page.querySelectorAll(PRINTED_FORM_SELECTOR)) {
		if (followed.has(printed)) {
			continue;
		}
		followed.add(printed);
		for (const input of printed.querySelectorAll<HTMLInputElement>(
			`input[type="range"]${PRINTED_INPUT_SELECTOR}`,
		)) {
			standAtBrowsersValue(input);
		}
		printed.addEventListener('input', (event) => {
			show(event.target);
		});
		printed.addEventListener('keydown', (event) => {
			const { key, target } = event as KeyboardEvent;
			if (
				key === 'Enter' &&
				target instanceof HTMLInputElement &&
				target.matches(PRINTED_INPUT_SELECTOR)
			) {
				event.preventDefault();
			}
		});
	}
}

/**
 * Unmarks each printed input in `target` the customer has not changed, so the
 * SDK draws that field rather than take a default the form no longer stands
 * for as the customer's value. The element stays, so nothing moves until the
 * SDK replaces it. A changed input keeps its marker: the customer's words carry
 * over where the field's name still matches, and the SDK removes them where it
 * does not.
 */
export function dropUntouchedInputs(target: Element): void {
	for (const input of target.querySelectorAll(PRINTED_INPUT_SELECTOR)) {
		if (isUntouched(input)) {
			input.removeAttribute(PRINTED_INPUT_ATTRIBUTE);
		}
	}
}

/** Whether a printed input still holds what the page printed. */
function isUntouched(input: Element): boolean {
	if (input instanceof HTMLSelectElement) {
		return Array.from(input.options).every(
			(option) => option.selected === option.defaultSelected,
		);
	}
	return (
		(input instanceof HTMLInputElement ||
			input instanceof HTMLTextAreaElement) &&
		input.value === input.defaultValue
	);
}

// The inline script holds no less-than sign: a block theme's template runs
// through `wptexturize()`, which reads it as a tag's start and breaks the rest.

/**
 * Has a slider's printed value read as the one its browser holds, rounded to
 * its step: the designer treats any other as the customer's move. Leaves one
 * further from it than rounding goes, which the customer did move.
 */
function standAtBrowsersValue(input: HTMLInputElement): void {
	const step = Number(input.step);
	const held = Number(input.value);
	const printed = Number(input.defaultValue);
	if (
		input.value !== input.defaultValue &&
		Number.isFinite(step) &&
		step * NEAREST_STEP_SHARE >= Math.abs(held - printed)
	) {
		input.defaultValue = input.value;
	}
}

/** Shows what a printed input now holds, in what stands around it. */
function show(input: EventTarget | null): void {
	if (input instanceof HTMLInputElement && input.type === 'range') {
		const low = Number(input.min);
		const high = Number(input.max);
		const at = `${((Number(input.value) - low) / (high - low)) * FULL_TRACK_PERCENT}%`;
		const track = input.parentElement;
		track
			?.querySelector<HTMLElement>(FILL_SELECTOR)
			?.style.setProperty('width', at);
		track
			?.querySelector<HTMLElement>(THUMB_SELECTOR)
			?.style.setProperty('left', at);
		return;
	}
	if (input instanceof HTMLInputElement && input.type === 'color') {
		input
			.closest<HTMLElement>(SWATCH_SELECTOR)
			?.style.setProperty('background', input.value);
		const hex = aside(input);
		if (hex !== null) {
			hex.textContent = input.value.toUpperCase();
		}
		return;
	}
	if (
		(input instanceof HTMLInputElement ||
			input instanceof HTMLTextAreaElement) &&
		input.maxLength > 0
	) {
		const count = aside(input);
		if (count !== null) {
			count.textContent = `${input.value.length} / ${input.maxLength}`;
		}
	}
}

/** What the field writes beside its heading. */
function aside(input: Element): Element | null {
	return (
		input.closest('[data-pixonaut-type]')?.querySelector(ASIDE_SELECTOR) ?? null
	);
}
