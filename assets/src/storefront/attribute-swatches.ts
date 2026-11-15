/**
 * Variation attribute options drawn as swatches in the designer's look
 * (the store's `attribute_options` setting): a radio group put after the
 * theme's own field, which stays in the form, hidden, and makes every
 * pick. Choosing a swatch chooses the option in that field as the customer
 * would, so WooCommerce's own events follow it (on the classic form
 * `woocommerce_variation_select_change` and `found_variation`), and the
 * swatches redraw whenever the field changes: a choice, a reset, or the
 * options the other choices leave available. A click on the chosen swatch
 * clears the attribute, as the block form's own chips do, so a customer
 * whose choices leave no other option available can start again. A value
 * Pixonaut draws as a colour or a picture is drawn as it (`swatch-looks.ts`).
 */

import {
	type AttributeLooks,
	drawSwatchFace,
	lookOf,
	type SwatchLooks,
	showChosenName,
} from './swatch-looks';
import {
	type AttributeChoice,
	isChecked,
	type ThemeField,
	themeFields,
} from './theme-fields';

/** On the theme's field once swatches stand in for it; the stylesheet
 *  hides it. */
export const REPLACED_CLASS = 'pixonaut-swatches__replaced';

const GROUP_CLASS = 'pixonaut-swatches';

/** The swatches the page printed after a classic select
 *  (`PrintedSwatches`), which the live ones take the place of. */
export const PRINTED_CLASS = 'pixonaut-swatches--printed';
const SWATCH_CLASS = 'pixonaut-swatches__swatch';
const CHOSEN_CLASS = 'is-chosen';
const UNAVAILABLE_CLASS = 'is-unavailable';

/** The classic form's jQuery events after which its fields may hold
 *  another choice or offer other options. */
const CLASSIC_EVENTS =
	'woocommerce_variation_select_change woocommerce_update_variation_values reset_data';

type JQueryLike = (element: Element) => {
	on: (events: string, handler: () => void) => unknown;
	off: (events: string, handler: () => void) => unknown;
};

/** Keys that move the choice to the next and to the previous swatch, as in
 *  any radio group. */
const NEXT_KEYS = new Set(['ArrowRight', 'ArrowDown']);
const PREVIOUS_KEYS = new Set(['ArrowLeft', 'ArrowUp']);

/** Keys that choose the focused swatch. */
const CHOOSE_KEYS = new Set([' ', 'Enter']);

/**
 * Takes away the swatches the page printed in `root` that no live swatches
 * replaced, such as a form in steps whose designer draws its swatches only
 * once it loads, so the theme's field shows and works until then.
 */
export function removePrintedSwatches(root: HTMLElement): void {
	for (const printed of root.querySelectorAll(`.${PRINTED_CLASS}`)) {
		printed.remove();
	}
}

/**
 * Draws swatches for every attribute field in `root` (a part of the
 * add-to-cart form the designer draws in its form) that has none yet, each
 * value in its look from `looks`, by the field's posted name. Returns a
 * function that takes them away again and shows the fields as the theme
 * rendered them, for when the designer gives the part back.
 */
export function drawAttributeSwatches(
	root: HTMLElement,
	looks: SwatchLooks = {},
): () => void {
	const removers = themeFields(root)
		.filter((field) => !field.element.classList.contains(REPLACED_CLASS))
		.map((field) =>
			swatchesFor(field, field.name === null ? undefined : looks[field.name]),
		);
	return () => {
		for (const remove of removers) {
			remove();
		}
	};
}

/** Puts the swatches of `field` after it, each value in its look from
 *  `looks`, hides it, and keeps them drawn as it changes. Returns a
 *  function that takes them away again. */
function swatchesFor(field: ThemeField, looks?: AttributeLooks): () => void {
	const document = field.element.ownerDocument;
	const group = document.createElement('div');
	group.className = GROUP_CLASS;
	group.setAttribute('role', 'radiogroup');
	const labelId = field.labelId();
	if (labelId !== null) {
		group.setAttribute('aria-labelledby', labelId);
	}
	// With the names left out, the chosen one shows beside the attribute's.
	const nameBeside = looks !== undefined && !looks.names;
	const label = () =>
		labelId === null ? null : document.getElementById(labelId);
	const draw = () => {
		const choices = field.choices();
		drawSwatches(group, choices, looks);
		if (nameBeside) {
			showChosenName(
				label(),
				choices.find((choice) => choice.chosen)?.label ?? null,
			);
		}
	};
	group.addEventListener('click', (event) => {
		const swatch = (event.target as Element).closest<HTMLElement>(
			`.${SWATCH_CLASS}`,
		);
		if (swatch === null || swatch.getAttribute('aria-disabled') === 'true') {
			return;
		}
		if (isChecked(swatch)) {
			field.clear();
		} else {
			field.pick(swatch.dataset.value ?? '');
		}
		draw();
	});
	group.addEventListener('keydown', (event) => {
		const target = (event.target as Element).closest<HTMLElement>(
			`.${SWATCH_CLASS}`,
		);
		if (target === null) {
			return;
		}
		if (CHOOSE_KEYS.has(event.key)) {
			event.preventDefault();
			target.click();
			return;
		}
		const step = NEXT_KEYS.has(event.key)
			? 1
			: PREVIOUS_KEYS.has(event.key)
				? -1
				: 0;
		const next = step === 0 ? null : nextAvailable(group, target, step);
		if (next === null) {
			return;
		}
		event.preventDefault();
		const value = next.dataset.value ?? '';
		field.pick(value);
		draw();
		swatchOf(group, value)?.focus();
	});
	field.element.classList.add(REPLACED_CLASS);
	const printed = field.element.nextElementSibling;
	if (printed?.classList.contains(PRINTED_CLASS)) {
		printed.remove();
	}
	field.element.after(group);
	draw();
	// A choice made in the field, its options changed by the other choices,
	// and the classic form's own events: it sets the select through jQuery,
	// whose `change` reaches no listener but jQuery's.
	const parent = field.element.parentElement;
	parent?.addEventListener('change', draw);
	const observer = new MutationObserver(draw);
	observer.observe(field.element, {
		subtree: true,
		childList: true,
		attributes: true,
		attributeFilter: ['disabled', 'hidden', 'aria-checked'],
	});
	const form = field.element.closest('form');
	const jQuery = (document.defaultView as { jQuery?: JQueryLike } | null)
		?.jQuery;
	if (form !== null) {
		jQuery?.(form).on(CLASSIC_EVENTS, draw);
	}
	return () => {
		observer.disconnect();
		parent?.removeEventListener('change', draw);
		if (form !== null) {
			jQuery?.(form).off(CLASSIC_EVENTS, draw);
		}
		group.remove();
		field.element.classList.remove(REPLACED_CLASS);
		if (nameBeside) {
			showChosenName(label(), null);
		}
	};
}

/** Draws `choices` in `group`, in their looks, reusing the swatches it
 *  holds. One swatch takes the focus from Tab: the chosen one, else the
 *  first available. */
function drawSwatches(
	group: HTMLElement,
	choices: AttributeChoice[],
	looks?: AttributeLooks,
): void {
	const document = group.ownerDocument;
	const kept = new Map(
		Array.from(group.querySelectorAll<HTMLElement>(`.${SWATCH_CLASS}`)).map(
			(swatch) => [swatch.dataset.value ?? '', swatch],
		),
	);
	const tabbable =
		choices.find((choice) => choice.chosen && choice.available) ??
		choices.find((choice) => choice.available);
	const swatches = choices.map((choice) => {
		const swatch = kept.get(choice.value) ?? document.createElement('span');
		kept.delete(choice.value);
		swatch.className = SWATCH_CLASS;
		swatch.classList.toggle(CHOSEN_CLASS, choice.chosen);
		swatch.classList.toggle(UNAVAILABLE_CLASS, !choice.available);
		swatch.setAttribute('role', 'radio');
		swatch.setAttribute('aria-checked', String(choice.chosen));
		swatch.dataset.value = choice.value;
		drawSwatchFace(
			swatch,
			choice.label,
			lookOf(looks, choice.value),
			looks?.names ?? true,
		);
		if (choice.available) {
			swatch.removeAttribute('aria-disabled');
		} else {
			swatch.setAttribute('aria-disabled', 'true');
		}
		swatch.tabIndex = choice === tabbable ? 0 : -1;
		return swatch;
	});
	for (const gone of kept.values()) {
		gone.remove();
	}
	// Only a swatch out of place is moved: moving one takes its focus.
	swatches.forEach((swatch, index) => {
		const there = group.children[index] ?? null;
		if (there !== swatch) {
			group.insertBefore(swatch, there);
		}
	});
}

/** The available swatch `step` places after `from`, going round; null
 *  when there is none. */
function nextAvailable(
	group: HTMLElement,
	from: HTMLElement,
	step: number,
): HTMLElement | null {
	const all = Array.from(
		group.querySelectorAll<HTMLElement>(`.${SWATCH_CLASS}`),
	);
	const start = all.indexOf(from);
	for (let moved = 1; moved <= all.length; moved++) {
		const index =
			(((start + step * moved) % all.length) + all.length) % all.length;
		const swatch = all[index];
		if (swatch?.getAttribute('aria-disabled') !== 'true') {
			return swatch ?? null;
		}
	}
	return null;
}

function swatchOf(group: HTMLElement, value: string): HTMLElement | undefined {
	return Array.from(
		group.querySelectorAll<HTMLElement>(`.${SWATCH_CLASS}`),
	).find((swatch) => swatch.dataset.value === value);
}
