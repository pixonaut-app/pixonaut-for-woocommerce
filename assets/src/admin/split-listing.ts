// The "Show each value as its own product" control on a global attribute's
// row in the product's Attributes tab: a three-way checkbox whose click
// cycles Auto (the attribute's setting, read as mixed), On and Off. The
// choice goes in the row's hidden field, which WooCommerce posts with the
// product and with its Save attributes button.
//
// Under it, each value's card in the shop takes a title and an image of its
// own, shown while the choice lists the values as their own products: On, or
// Auto when the attribute does. Its image is chosen from the media library.

import './wp-media';

export const TOGGLE_SELECTOR = '.pixonaut-split-listing__toggle';

const CHOICE_SELECTOR = '.pixonaut-split-listing__choice';

const STATE_SELECTOR = '.pixonaut-split-listing__state';

const CARDS_SELECTOR = '.pixonaut-split-cards';

const CARD_SELECTOR = '.pixonaut-split-card';

export const CHOOSE_SELECTOR = '.pixonaut-split-card__choose';

export const REMOVE_SELECTOR = '.pixonaut-split-card__remove';

/** The image size a card's chosen image is previewed at, as the screen draws it. */
const PREVIEW_SIZE = 'thumbnail';

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
	showCards(row, choice);
	// WooCommerce enables its Save attributes button on a change in the rows.
	field.dispatchEvent(new Event('change', { bubbles: true }));
}

/** Shows the row's cards while its choice lists the values as their own products. */
function showCards(row: Element, choice: Choice): void {
	const cards = row.nextElementSibling;
	if (!(cards instanceof HTMLElement) || !cards.matches(CARDS_SELECTOR)) {
		return;
	}
	cards.hidden = !(
		choice === 'on' ||
		(choice === 'auto' && cards.dataset.attributeDefault === '1')
	);
}

/** Sets a card's image, or removes it with an id of 0. */
export function setCardImage(card: Element, id: number, url = ''): void {
	const field = card.querySelector<HTMLInputElement>(
		'.pixonaut-split-card__image-id',
	);
	const preview = card.querySelector<HTMLImageElement>(
		'.pixonaut-split-card__preview',
	);
	const remove = card.querySelector<HTMLElement>(REMOVE_SELECTOR);
	if (!field) {
		return;
	}
	field.value = id > 0 ? String(id) : '';
	if (preview) {
		preview.src = url;
		preview.hidden = id < 1;
	}
	if (remove) {
		remove.hidden = id < 1;
	}
	field.dispatchEvent(new Event('change', { bubbles: true }));
}

/** Opens the media library to choose a card's image. */
export function chooseCardImage(button: HTMLElement): void {
	const card = button.closest(CARD_SELECTOR);
	const media = window.wp?.media;
	if (!card || !media) {
		return;
	}
	const frame = media({
		title: button.dataset.frameTitle ?? '',
		button: { text: button.dataset.frameButton ?? '' },
		library: { type: 'image' },
		multiple: false,
	});
	frame.on('select', () => {
		const chosen = frame.state().get('selection').first().toJSON();
		setCardImage(
			card,
			chosen.id,
			chosen.sizes?.[PREVIEW_SIZE]?.url ?? chosen.url,
		);
	});
	frame.open();
}

/** Handles every row's control and cards, those WooCommerce adds or draws again included. */
export function initSplitListing(root: Document): void {
	root.addEventListener('click', (event) => {
		const toggle = (event.target as Element | null)?.closest<HTMLElement>(
			TOGGLE_SELECTOR,
		);
		if (toggle) {
			event.preventDefault();
			cycle(toggle);
			return;
		}
		const target = event.target as Element | null;
		const choose = target?.closest<HTMLElement>(CHOOSE_SELECTOR);
		if (choose) {
			event.preventDefault();
			chooseCardImage(choose);
			return;
		}
		const remove = target?.closest<HTMLElement>(REMOVE_SELECTOR);
		const card = remove?.closest(CARD_SELECTOR);
		if (card) {
			event.preventDefault();
			setCardImage(card, 0);
		}
	});
}
