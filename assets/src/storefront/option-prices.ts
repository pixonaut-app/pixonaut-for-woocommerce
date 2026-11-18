/**
 * The prices of an attribute's options written in the theme's own field,
 * where the store shows options there rather than as swatches: each option
 * of a classic or block select, and each of the block form's chips, reads
 * "50×70 · 599 kr" (`choice-prices.ts`). They are written again whenever
 * any of the form's attributes changes, and whenever WooCommerce draws the
 * options again: the classic form rebuilds its selects from the options it
 * first read, which the server printed with their prices
 * (`PrintedOptionPrices`), and the block form writes its options' names
 * back as its state changes.
 */

import {
	amountText,
	type ChoicePrices,
	choiceAmounts,
	formChoice,
	optionLabel,
} from './choice-prices';
import { themeFields } from './theme-fields';

/** On an option whose words carry a price: its own name, and the words
 *  written in it. `PrintedOptionPrices` prints the same attributes. */
const NAME_ATTRIBUTE = 'data-pixonaut-name';
const SHOWN_ATTRIBUTE = 'data-pixonaut-shown';

/** The classic form's jQuery event after which it has drawn its selects'
 *  options again. */
const CLASSIC_REDRAWN = 'woocommerce_update_variation_values';

type JQueryLike = (element: Element) => {
	on: (events: string, handler: () => void) => unknown;
	off: (events: string, handler: () => void) => unknown;
};

/** Writes the prices of the options of `form`'s attributes that show one
 *  in their fields, and keeps them written as the choices change. Returns
 *  a function that writes the options' own names back and stops. */
export function drawOptionPrices(
	form: HTMLFormElement,
	prices: ChoicePrices,
): () => void {
	const fields = themeFields(form).filter(
		(field) => field.name !== null && prices.roles[field.name] !== undefined,
	);
	const draw = () => {
		const amounts = choiceAmounts(prices, formChoice(form, prices.fields));
		for (const field of fields) {
			const shown = amounts[field.name ?? ''] ?? {};
			for (const { value, element } of field.texts()) {
				const amount = shown[value];
				writeOption(
					element,
					amount === undefined ? null : amountText(amount, prices),
					prices,
				);
			}
		}
	};
	draw();
	// A classic select's choice, and the block form's dropdown.
	form.addEventListener('change', draw);
	// The block form's chips, and the options its own script or the classic
	// form's writes again, tell no event the form hears.
	const observer = new MutationObserver(draw);
	for (const field of fields) {
		observer.observe(field.element, {
			subtree: true,
			childList: true,
			characterData: true,
			attributes: true,
			attributeFilter: ['aria-checked'],
		});
	}
	const jQuery = (form.ownerDocument.defaultView as { jQuery?: JQueryLike })
		.jQuery;
	jQuery?.(form).on(CLASSIC_REDRAWN, draw);
	return () => {
		observer.disconnect();
		form.removeEventListener('change', draw);
		jQuery?.(form).off(CLASSIC_REDRAWN, draw);
		for (const element of form.querySelectorAll<HTMLElement>(
			`[${NAME_ATTRIBUTE}]`,
		)) {
			writeOption(element, null, prices);
		}
	};
}

/** Writes an option's name followed by `price`, or its name alone for
 *  null. Words someone else wrote since, such as WooCommerce writing the
 *  name again, are the option's name now. */
function writeOption(
	element: HTMLElement,
	price: string | null,
	prices: Pick<ChoicePrices, 'label'>,
): void {
	const current = (element.textContent ?? '').trim();
	const name =
		element.getAttribute(SHOWN_ATTRIBUTE) === current
			? (element.getAttribute(NAME_ATTRIBUTE) ?? current)
			: current;
	const text = price === null ? name : optionLabel(name, price, prices);
	element.setAttribute(NAME_ATTRIBUTE, name);
	element.setAttribute(SHOWN_ATTRIBUTE, text);
	if (current !== text) {
		element.textContent = text;
	}
}
