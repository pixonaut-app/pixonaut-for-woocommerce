import type { ProductPlacement } from '../types/sdk';
import type { ArrangedGroups } from './form-groups';

/** What a variation switches one side to: the server's mount options for
 *  it, with its print sizes as indexes into the side's mounted menu. A side
 *  on a blank also names the placements and views the product sells. */
export type VariationSide = {
	templateId: string;
	blankId?: string;
	placementId?: string;
	placements?: ProductPlacement[];
	views?: string[];
	/** The refs of the blank's variants the product sells, which the
	 *  side's designer offers. */
	variants?: string[];
	/** The form layout; absent when the template's own decides. */
	layout?: 'page' | 'steps';
	/** The side's arrangement of the form; absent for the template's own. */
	formGroups?: ArrangedGroups;
	/** Whether the canvas keeps the template's ratio at every print size
	 *  (`true`) or takes the chosen size's (`false`); absent when the
	 *  template's own setting decides. */
	drawAtTemplateRatio?: boolean;
	printSizes: number[];
	/** The size the side starts on, an index into the mounted menu like
	 *  `printSizes`; absent without a size menu. */
	selectedPrintSizeIndex?: number;
	/** The view the side starts on; absent for its first. */
	initialView?: string;
};

/** A variation as the server prints it: personalised, bought without a
 *  design (`plain`), or personalised but unable to mount now. `priceHtml`
 *  is its price as WooCommerce formats it; absent when it shows none. */
export type Variation = { priceHtml?: string } & (
	| {
			state: 'personalised';
			/** Its `attribute_*` values; empty for an "any" attribute. */
			attributes: Record<string, string>;
			approvalMode: string;
			/** The variant ref it is sold as; absent when it has none. */
			variant?: string;
			sides: Record<string, VariationSide>;
	  }
	| { state: 'plain' | 'unavailable'; attributes: Record<string, string> }
);

/** Variations by id. */
export type Variations = Record<string, Variation>;

/** The variation the page shows before the customer chooses one. */
export const NO_VARIATION = '';

/** How long the variation choice must settle before the designer follows
 *  it: the classic form announces one choice several times, and the block
 *  form updates its field after the event that changed it. */
export const VARIATION_SETTLE_MS = 100;

/** The add-to-cart form's field that holds the chosen variation's id, in the
 *  classic form and in the block one alike. */
const VARIATION_FIELD = 'variation_id';

/** The classic form's jQuery events that follow a change of its choices:
 *  one attribute's, the variation found or lost, and the reset. */
const CLASSIC_EVENTS =
	'woocommerce_variation_select_change found_variation reset_data hide_variation';

/** DOM events after which the block form may hold another variation. */
const BLOCK_EVENTS = ['change', 'click', 'input'] as const;

type JQueryLike = (element: Element) => {
	on: (events: string, handler: () => void) => unknown;
};

/** The form container's variations; null on a product without them. */
export function readVariations(container: HTMLElement): Variations | null {
	const raw = container.dataset.pixonautVariations;
	if (raw === undefined || raw === '') {
		return null;
	}
	try {
		const parsed: unknown = JSON.parse(raw);
		return typeof parsed === 'object' && parsed !== null
			? (parsed as Variations)
			: null;
	} catch {
		return null;
	}
}

/**
 * Calls `chosen` with the id of the variation the form holds, `NO_VARIATION`
 * while it holds none, each time that changes once the choice settled; and
 * once at the start for a variation the page preselected. Follows the
 * classic form's jQuery events and the block form's own DOM events alike,
 * reading the form's `variation_id` field after each. Returns a function
 * that reads the field at once, for an add to cart that cannot wait.
 */
export function watchVariation(
	form: HTMLFormElement,
	chosen: (id: string) => void,
): () => void {
	let last = NO_VARIATION;
	const read = () => {
		const id = variationId(form);
		if (id !== last) {
			last = id;
			chosen(id);
		}
	};
	return followChoices(form, read);
}

/**
 * Calls `settled` each time the form's choices may have changed, once they
 * settled, and once at the start: after the classic form's jQuery events
 * (a choice, its reset, the variation found or lost) and the block form's
 * own DOM events. Returns a function that calls it at once.
 */
export function followChoices(
	form: HTMLFormElement,
	settled: () => void,
): () => void {
	let timer: ReturnType<typeof setTimeout> | undefined;
	const now = () => {
		clearTimeout(timer);
		settled();
	};
	const settle = () => {
		clearTimeout(timer);
		timer = setTimeout(now, VARIATION_SETTLE_MS);
	};
	const jQuery = (form.ownerDocument.defaultView as { jQuery?: JQueryLike })
		?.jQuery;
	jQuery?.(form).on(CLASSIC_EVENTS, settle);
	for (const type of BLOCK_EVENTS) {
		form.addEventListener(type, settle);
	}
	settle();
	return now;
}

/**
 * The personalised variations sold as variant `ref` that `usable` says may
 * be chosen, those that keep most of the form's other choices first, in
 * their own order on a tie.
 */
export function variationsSoldAs(
	variations: Variations,
	ref: string,
	form: HTMLFormElement,
	usable: (id: string) => boolean,
): string[] {
	const kept = new Map<string, number>();
	for (const [id, variation] of Object.entries(variations)) {
		if (
			variation.state === 'personalised' &&
			variation.variant === ref &&
			usable(id)
		) {
			// An "any" attribute keeps whatever the form holds.
			kept.set(
				id,
				Object.entries(variation.attributes).filter(
					([name, value]) => value === '' || chosenValue(form, name) === value,
				).length,
			);
		}
	}
	// Sorting is stable, so a tie keeps the variations' order.
	return [...kept.keys()].sort(
		(first, second) => (kept.get(second) ?? 0) - (kept.get(first) ?? 0),
	);
}

/**
 * Puts the form's attribute fields on `attributes`, one after the other,
 * leaving one the variation takes "any" of, and announces each change as
 * the shopper's own would be, so WooCommerce finds the variation. The
 * classic form offers only the options the other choices allow: when one
 * is missing, the choices are cleared, so it offers every option again,
 * and set anew. False, changing nothing, when an attribute has no field;
 * false when a field still cannot hold its value.
 */
export function selectAttributes(
	form: HTMLFormElement,
	attributes: Record<string, string>,
): boolean {
	const wanted = Object.entries(attributes).filter(([, value]) => value !== '');
	if (wanted.some(([name]) => namedFields(form, name).length === 0)) {
		return false;
	}
	const apply = () =>
		wanted.every(([name, value]) => chooseValue(form, name, value));
	if (apply()) {
		return true;
	}
	for (const [name] of wanted) {
		chooseValue(form, name, '');
	}
	return apply();
}

/**
 * Puts the attribute field `name` on `value` and announces the change; ''
 * clears a select. False when the field has no such option.
 */
function chooseValue(
	form: HTMLFormElement,
	name: string,
	value: string,
): boolean {
	const fields = namedFields(form, name);
	const select = fields.find((field) => field instanceof HTMLSelectElement);
	let changed: HTMLElement | null = null;
	if (select !== undefined) {
		if (
			value !== '' &&
			!Array.from(select.options).some((option) => option.value === value)
		) {
			return false;
		}
		if (select.value !== value) {
			select.value = value;
			changed = select;
		}
	} else if (value !== '') {
		const radio = fields.find(
			(field) =>
				field instanceof HTMLInputElement &&
				field.type === 'radio' &&
				field.value === value,
		) as HTMLInputElement | undefined;
		if (radio === undefined) {
			return false;
		}
		if (!radio.checked) {
			radio.checked = true;
			changed = radio;
		}
	}
	for (const type of ['input', 'change']) {
		changed?.dispatchEvent(new Event(type, { bubbles: true }));
	}
	return true;
}

/** The value the form's attribute field `name` holds; '' for none. The
 *  block form's chips and dropdowns keep it in a hidden field. */
export function chosenValue(form: HTMLFormElement, name: string): string {
	const fields = namedFields(form, name);
	const field =
		fields.find((one) => one instanceof HTMLSelectElement) ??
		fields.find((one) => (one as HTMLInputElement).checked) ??
		fields.find((one) => one.type === 'hidden');
	return field?.value ?? '';
}

/**
 * Whether the form's attribute fields hold every value of `choice`, by field
 * name; false for an empty choice.
 */
export function holdsChoice(
	form: HTMLFormElement,
	choice: Record<string, string>,
): boolean {
	const wanted = Object.entries(choice);
	return (
		wanted.length > 0 &&
		wanted.every(([name, value]) => chosenValue(form, name) === value)
	);
}

function variationId(form: HTMLFormElement): string {
	const field = namedFields(form, VARIATION_FIELD)[0];
	const id = field?.value ?? '';
	return id === '0' ? NO_VARIATION : id;
}

/** The form's fields named `name`; names are compared as text, so an
 *  attribute name needs no CSS escaping. */
function namedFields(
	form: HTMLFormElement,
	name: string,
): Array<HTMLInputElement | HTMLSelectElement> {
	return Array.from(
		form.querySelectorAll<HTMLInputElement | HTMLSelectElement>(
			'input, select',
		),
	).filter((field) => field.name === name);
}
