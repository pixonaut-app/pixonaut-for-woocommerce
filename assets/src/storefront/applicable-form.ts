import {
	type ApplicableAttributes,
	type AttributeValues,
	hiddenAttributes,
} from './applicable-attributes';
import {
	BLOCK_ATTRIBUTE_SELECTOR,
	type ThemeField,
	themeFields,
} from './theme-fields';
import { followChoices } from './variations';

/** On the row of an attribute that does not apply to the choice, while it
 *  is hidden. */
export const NOT_APPLICABLE_ATTRIBUTE = 'data-pixonaut-not-applicable';

/** The classic form's link that clears every choice, which WooCommerce
 *  prints in the last attribute's row. */
const RESET_SELECTOR = '.reset_variations';

/** DOM events by which the customer makes a choice in either form. */
const CHOICE_EVENTS = ['change', 'click', 'input'] as const;

/** How long to wait before looking again at a field that did not take its
 *  value: the block form answers a click only once it is interactive, and
 *  shows a choice a moment after it was made. */
export const RETRY_MS = 100;

/** How many times in a row a field that did not take its value is looked
 *  at again, about five seconds in all, before the next choice is waited
 *  for. */
export const MAX_RETRIES = 50;

/**
 * Hides, in an add-to-cart form, each attribute that does not apply to the
 * choices made in it (`hiddenAttributes()`), and shows it again once a
 * choice makes it apply. WooCommerce finds a variation only with a value in
 * every field, so a hidden attribute's field is given its first value; the
 * server keeps it off the cart line. A field shown again is cleared, for the
 * customer to choose. Returns a function that looks at the form at once.
 */
export function hideInapplicableAttributes(
	form: HTMLFormElement,
	rules: ApplicableAttributes,
): () => void {
	let retries = 0;
	let timer: ReturnType<typeof setTimeout> | undefined;
	// A field given or cleared of a value announces it as a choice is; only
	// the customer's own choice starts the count of tries again.
	let applying = false;
	let customerChose = true;
	for (const type of CHOICE_EVENTS) {
		form.addEventListener(
			type,
			() => {
				customerChose ||= !applying;
			},
			true,
		);
	}
	const apply = (): void => {
		clearTimeout(timer);
		applying = true;
		const settled = applyOnce(form, rules);
		applying = false;
		if (settled) {
			retries = 0;
		} else if (retries < MAX_RETRIES) {
			retries += 1;
			timer = setTimeout(apply, RETRY_MS);
		}
	};
	const now = followChoices(form, () => {
		if (customerChose) {
			customerChose = false;
			retries = 0;
		}
		// A field that never takes its value announces every try.
		if (retries < MAX_RETRIES) {
			apply();
		}
	});
	now();
	return now;
}

/** Hides and shows the form's attributes for its choices now. False when a
 *  field does not hold what it should yet. */
function applyOnce(
	form: HTMLFormElement,
	rules: ApplicableAttributes,
): boolean {
	const fields = themeFields(form).filter(
		(field) => field.name !== null && rules.fields.includes(field.name),
	);
	const chosen: AttributeValues = {};
	for (const field of fields) {
		chosen[field.name as string] = chosenValue(field);
	}
	const hidden = hiddenAttributes(rules, chosen);
	let settled = true;
	for (const field of fields) {
		const row = rowOf(field);
		const wasHidden = row.hasAttribute(NOT_APPLICABLE_ATTRIBUTE);
		if (hidden.includes(field.name as string)) {
			hide(row);
			settled = fill(field) && settled;
		} else if (wasHidden) {
			// What the field holds is the value it was filled with, or a
			// choice made before the attribute stopped applying.
			show(row);
			if (chosenValue(field) !== '') {
				field.clear();
				settled = chosenValue(field) === '' && settled;
			}
		}
	}
	keepResetLinkShown(form, fields);
	return settled;
}

function chosenValue(field: ThemeField): string {
	return field.choices().find((choice) => choice.chosen)?.value ?? '';
}

/** Gives a hidden field its first value the form offers; true once it
 *  holds one. */
function fill(field: ThemeField): boolean {
	const choices = field.choices();
	if (choices.some((choice) => choice.chosen)) {
		return true;
	}
	const first = choices.find((choice) => choice.available);
	if (first !== undefined) {
		field.pick(first.value);
	}
	return chosenValue(field) !== '';
}

/** The element holding the attribute's name and field: the classic
 *  table's row, the block form's attribute. */
function rowOf(field: ThemeField): HTMLElement {
	return (
		field.element.closest<HTMLElement>(`tr, ${BLOCK_ATTRIBUTE_SELECTOR}`) ??
		field.element
	);
}

function hide(row: HTMLElement): void {
	row.setAttribute(NOT_APPLICABLE_ATTRIBUTE, '');
	// Themes lay their rows out with rules of their own, some with
	// `!important`.
	row.style.setProperty('display', 'none', 'important');
}

function show(row: HTMLElement): void {
	row.removeAttribute(NOT_APPLICABLE_ATTRIBUTE);
	row.style.removeProperty('display');
}

/** Keeps the classic form's link that clears every choice in the last row
 *  shown, since the row WooCommerce printed it in may be hidden. */
function keepResetLinkShown(form: HTMLFormElement, fields: ThemeField[]): void {
	const link = form.querySelector<HTMLElement>(RESET_SELECTOR);
	const home = link?.closest('tr') ?? null;
	if (link === null || home === null) {
		return;
	}
	const rows = fields
		.map(rowOf)
		.filter(
			(row) => row.matches('tr') && row.parentElement === home.parentElement,
		);
	const last = rows
		.filter((row) => !row.hasAttribute(NOT_APPLICABLE_ATTRIBUTE))
		.pop();
	if (last === undefined || last === home || !rows.includes(home)) {
		return;
	}
	(last.querySelector('td:last-of-type') ?? last).append(link);
}
