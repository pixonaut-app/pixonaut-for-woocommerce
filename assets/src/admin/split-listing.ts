// The "Show each value as its own product" control on a global attribute's
// row in the product's Attributes tab: a three-way checkbox whose click
// cycles Auto (the attribute's setting, read as mixed), On and Off. The
// choice goes in the row's hidden field, which WooCommerce posts with the
// product and with its Save attributes button.

export const TOGGLE_SELECTOR = '.pixonaut-split-listing__toggle';

const CHOICE_SELECTOR = '.pixonaut-split-listing__choice';

const STATE_SELECTOR = '.pixonaut-split-listing__state';

/** The choices in the order a click cycles through them. */
export const CHOICES = ['auto', 'on', 'off'] as const;

export type Choice = (typeof CHOICES)[number];

/** How a three-way checkbox reads each choice. */
const ARIA_CHECKED: Record<Choice, string> = {
	auto: 'mixed',
	on: 'true',
	off: 'false',
};

function isChoice(value: string): value is Choice {
	return (CHOICES as readonly string[]).includes(value);
}

/** The choice after `choice`, back to the first after the last. */
export function nextChoice(choice: string): Choice {
	const at = isChoice(choice) ? CHOICES.indexOf(choice) : -1;
	return CHOICES[(at + 1) % CHOICES.length];
}

/** Moves a row's control to its next choice. */
export function cycle(toggle: HTMLElement): void {
	const row = toggle.closest('.pixonaut-split-listing');
	const field = row?.querySelector<HTMLInputElement>(CHOICE_SELECTOR);
	if (!row || !field) {
		return;
	}
	const choice = nextChoice(field.value);
	field.value = choice;
	toggle.setAttribute('aria-checked', ARIA_CHECKED[choice]);
	const state = row.querySelector(STATE_SELECTOR);
	if (state) {
		state.textContent =
			toggle.dataset[`state${choice[0].toUpperCase()}${choice.slice(1)}`] ?? '';
	}
	// WooCommerce enables its Save attributes button on a change in the rows.
	field.dispatchEvent(new Event('change', { bubbles: true }));
}

/** Handles every row's control, those WooCommerce adds or draws again included. */
export function initSplitListing(root: Document): void {
	root.addEventListener('click', (event) => {
		const toggle = (event.target as Element | null)?.closest<HTMLElement>(
			TOGGLE_SELECTOR,
		);
		if (toggle) {
			event.preventDefault();
			cycle(toggle);
		}
	});
}
