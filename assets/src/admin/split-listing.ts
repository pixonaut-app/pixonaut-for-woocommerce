// The "Cards in the shop" menu on a global attribute's row in the
// product's Attributes tab: Auto (the attribute's setting), a card per value
// always, a card per value only while a filter chooses one, or one card for
// all values. WooCommerce posts it with the product and with its Save
// attributes button.
//
// Under it, each value's card in the shop takes a title and an image of its
// own, shown while the choice lists the values as their own cards: always
// or while filtered, or Auto when the attribute does. Its image is chosen
// from the media library.

import './wp-media';

export const CHOICE_SELECTOR = '.pixonaut-split-listing__choice';

const CARDS_SELECTOR = '.pixonaut-split-cards';

const CARD_SELECTOR = '.pixonaut-split-card';

export const CHOOSE_SELECTOR = '.pixonaut-split-card__choose';

export const REMOVE_SELECTOR = '.pixonaut-split-card__remove';

/** The image size a card's chosen image is previewed at, as the screen draws it. */
const PREVIEW_SIZE = 'thumbnail';

/** The choice that follows the attribute's own setting. */
const AUTO = 'auto';

/** The setting, and choice, listing the values in one card. */
const OFF = 'off';

/** Whether a choice lists the values as their own cards, given the attribute's setting. */
export function listsCards(choice: string, attributeMode: string): boolean {
	return choice === AUTO ? attributeMode !== OFF : choice !== OFF;
}

/** Shows a row's cards while its choice lists the values as their own cards. */
export function showCards(select: HTMLSelectElement): void {
	const cards = select.closest('.pixonaut-split-listing')?.nextElementSibling;
	if (!(cards instanceof HTMLElement) || !cards.matches(CARDS_SELECTOR)) {
		return;
	}
	cards.hidden = !listsCards(select.value, cards.dataset.attributeMode ?? OFF);
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
	root.addEventListener('change', (event) => {
		const target = event.target as Element | null;
		if (
			target instanceof HTMLSelectElement &&
			target.matches(CHOICE_SELECTOR)
		) {
			showCards(target);
		}
	});
	root.addEventListener('click', (event) => {
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
