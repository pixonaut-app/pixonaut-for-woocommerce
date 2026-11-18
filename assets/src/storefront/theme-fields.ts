/**
 * The add-to-cart form's own field of each variation attribute, classic
 * and block alike, read and set as the customer would: WooCommerce's
 * events follow every pick.
 */

/** One option of an attribute, as its field offers it now. */
export type AttributeChoice = {
	value: string;
	label: string;
	/** False for an option the field does not offer with the other
	 *  choices, or offers disabled: out of stock, or no such variation. */
	available: boolean;
	chosen: boolean;
};

/** The element holding the words of one option of a field. */
export type OptionText = { value: string; element: HTMLElement };

/** The theme's field of one attribute. */
export type ThemeField = {
	/** The element the swatches replace on screen. */
	element: HTMLElement;
	choices: () => AttributeChoice[];
	/** The element holding each option's words, by its value: a select's
	 *  option, a chip's text. */
	texts: () => OptionText[];
	pick: (value: string) => void;
	/** Leaves the attribute unchosen. */
	clear: () => void;
	/** The id of the attribute's name the field is labelled by; null for none. */
	labelId: () => string | null;
	/** The name the attribute is posted as, `attribute_pa_frame`; null when
	 *  the field has none. */
	name: string | null;
};

/** The block form's element of one attribute. */
export const BLOCK_ATTRIBUTE_SELECTOR =
	'.wp-block-woocommerce-add-to-cart-with-options-variation-selector-attribute';

/** The block form's chips of one attribute, and one chip in them. */
const BLOCK_CHIPS_SELECTOR = '[role="radiogroup"]';
const BLOCK_CHIP_SELECTOR = 'button[role="radio"]';

/** The words of one of the block form's chips, beside its swatch. */
const BLOCK_CHIP_TEXT_SELECTOR = '.wc-block-product-filter-chips__text';

/** The block form's name of one attribute, which labels its chips. */
const BLOCK_NAME_SELECTOR =
	'.wp-block-woocommerce-add-to-cart-with-options-variation-selector-attribute-name';

/** The classic form's field of one attribute. */
const CLASSIC_SELECT_SELECTOR = 'select[name^="attribute_"]';

/** The block form's posted field of one attribute, which its chips or
 *  dropdown set. */
const BLOCK_POSTED_SELECTOR = 'input[name^="attribute_"]';

/** Prefix of the id given a classic attribute's label that has none. */
const LABEL_ID_PREFIX = 'pixonaut-swatches-label-';

/** The attribute fields in `root`: each block attribute's chips or
 *  dropdown, and each classic select. */
export function themeFields(root: HTMLElement): ThemeField[] {
	const fields: ThemeField[] = [];
	const blocks = [
		...(root.matches(BLOCK_ATTRIBUTE_SELECTOR) ? [root] : []),
		...root.querySelectorAll<HTMLElement>(BLOCK_ATTRIBUTE_SELECTOR),
	];
	for (const block of blocks) {
		const chips = block.querySelector<HTMLElement>(BLOCK_CHIPS_SELECTOR);
		const select = block.querySelector('select');
		const nameId = () => block.querySelector(BLOCK_NAME_SELECTOR)?.id || null;
		const posted =
			block.querySelector<HTMLInputElement>(BLOCK_POSTED_SELECTOR)?.name ??
			null;
		if (chips !== null) {
			fields.push(chipsField(chips, nameId, posted));
		} else if (select !== null) {
			fields.push(selectField(select, nameId, posted));
		}
	}
	for (const select of root.querySelectorAll<HTMLSelectElement>(
		CLASSIC_SELECT_SELECTOR,
	)) {
		if (select.closest(BLOCK_ATTRIBUTE_SELECTOR) === null) {
			fields.push(
				selectField(select, () => classicLabelId(select), select.name),
			);
		}
	}
	return fields;
}

/**
 * A select, the classic form's or the block form's dropdown. The classic
 * form takes out the options the other choices leave no variation for:
 * every option it held is remembered, and one taken out is unavailable.
 */
function selectField(
	select: HTMLSelectElement,
	labelId: () => string | null,
	name: string | null,
): ThemeField {
	const labels = new Map<string, string>();
	const offered = () =>
		Array.from(select.options).filter((option) => option.value !== '');
	const choose = (value: string) => {
		if (select.value === value) {
			return;
		}
		select.value = value;
		for (const type of ['input', 'change']) {
			select.dispatchEvent(new Event(type, { bubbles: true }));
		}
	};
	return {
		element: select,
		choices: () => {
			const now = new Map(offered().map((option) => [option.value, option]));
			for (const option of now.values()) {
				labels.set(option.value, option.text.trim());
			}
			return [...labels].map(([value, label]) => {
				const option = now.get(value);
				return {
					value,
					label,
					available: option !== undefined && !option.disabled,
					chosen: select.value === value,
				};
			});
		},
		texts: () =>
			offered().map((option) => ({ value: option.value, element: option })),
		pick: (value) => choose(value),
		clear: () => choose(''),
		labelId,
		name,
	};
}

/** The block form's chips, which it disables or hides for an option the
 *  other choices leave no variation for. A click on a chosen chip clears
 *  it, so a pick clicks only another. */
function chipsField(
	chips: HTMLElement,
	labelId: () => string | null,
	name: string | null,
): ThemeField {
	const each = () =>
		Array.from(chips.querySelectorAll<HTMLButtonElement>(BLOCK_CHIP_SELECTOR));
	return {
		element: chips,
		choices: () =>
			each().map((chip) => ({
				value: chip.value,
				label:
					chip.textContent?.trim() ||
					chip.getAttribute('aria-label') ||
					chip.value,
				available: !chip.disabled && !chip.hidden,
				chosen: chip.getAttribute('aria-checked') === 'true',
			})),
		texts: () =>
			each().map((chip) => ({
				value: chip.value,
				element:
					chip.querySelector<HTMLElement>(BLOCK_CHIP_TEXT_SELECTOR) ?? chip,
			})),
		pick: (value) => {
			const chip = each().find((one) => one.value === value);
			if (chip !== undefined && !isChecked(chip)) {
				chip.click();
			}
		},
		clear: () => each().find(isChecked)?.click(),
		labelId,
		name,
	};
}

export function isChecked(element: Element): boolean {
	return element.getAttribute('aria-checked') === 'true';
}

/** The id of the classic row's label of `select`, given one when it has
 *  none; null without a label. */
function classicLabelId(select: HTMLSelectElement): string | null {
	const label =
		Array.from(select.labels ?? [])[0] ??
		select.closest('tr')?.querySelector('th label, .label label') ??
		null;
	if (label === null) {
		return null;
	}
	if (label.id === '') {
		label.id = LABEL_ID_PREFIX + select.name;
	}
	return label.id;
}
