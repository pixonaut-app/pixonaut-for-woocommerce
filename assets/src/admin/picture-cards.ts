// The Pixonaut tab's Product images (`admin/product-showcase-images.php`): a
// card per picture the product can show. Ticking a set says at once which is
// the product image and which go to the gallery, and choosing what a picture
// is drawn on changes its thumbnail, without updating the product.

/** Marks the list while no set is ticked, so the template's own card leads. */
const NONE_TICKED = 'pixonaut-picture-cards--none-ticked';

/** Marks a thumbnail drawn as the design alone, without a photo. */
const FLAT = 'is-flat';

/** Marks a choice of what a picture is drawn on that draws the design alone. */
const FLAT_CHOICE = 'pixonaut-showcase-kind--flat';

/** Shows a card's badge: `text`, or none for empty. */
function setBadge(card: Element, text: string): void {
	const badge = card.querySelector<HTMLElement>(
		'.pixonaut-picture-card__badge',
	);
	if (badge) {
		badge.textContent = text;
		badge.hidden = text === '';
	}
}

/**
 * Badges each card by what it is: the first ticked set is the product image,
 * the next ones up to the list's `data-max` go to the gallery, any after are
 * left out. With none ticked, the template's own picture is the product image.
 */
export function showPictureBadges(list: HTMLElement): void {
	const max = Number(list.dataset.max ?? 0);
	let place = 0;
	for (const card of list.querySelectorAll('.pixonaut-showcase-image')) {
		const ticked =
			card.querySelector<HTMLInputElement>('.pixonaut-picture-card__use')
				?.checked ?? false;
		let text = '';
		if (ticked) {
			text =
				place === 0
					? (list.dataset.first ?? '')
					: place < max
						? (list.dataset.gallery ?? '')
						: (list.dataset.leftOut ?? '');
			place += 1;
		}
		setBadge(card, text);
	}
	list.classList.toggle(NONE_TICKED, place === 0);
	const own = list.querySelector('.pixonaut-picture-card--own');
	if (own) {
		setBadge(own, place === 0 ? (list.dataset.first ?? '') : '');
	}
}

/**
 * Draws a card's thumbnail as its chosen tile says: the set's preview over
 * the tile's photo, or the preview alone for the design alone.
 */
export function showDrawnOn(card: Element): void {
	const chosen = card.querySelector<HTMLInputElement>(
		'.pixonaut-showcase-kind input:checked',
	);
	const tile = chosen?.closest('.pixonaut-showcase-kind');
	const thumb = card.querySelector('.pixonaut-picture-card__thumb');
	const photo = card.querySelector<HTMLImageElement>(
		'.pixonaut-picture-card__photo',
	);
	if (!tile || !thumb || !photo) {
		return;
	}
	const src =
		tile.querySelector<HTMLImageElement>(
			'.pixonaut-showcase-kind__thumbnail img',
		)?.src ?? '';
	const flat = tile.classList.contains(FLAT_CHOICE) || src === '';
	thumb.classList.toggle(FLAT, flat);
	photo.hidden = flat;
	if (!flat) {
		photo.src = src;
	}
}

/** Keeps the cards' badges and thumbnails in step with the choices. */
export function initPictureCards(root: HTMLElement): void {
	const list = root.querySelector<HTMLElement>('.pixonaut-picture-cards');
	if (!list) {
		return;
	}
	list.addEventListener('change', (event) => {
		if (!(event.target instanceof HTMLInputElement)) {
			return;
		}
		if (event.target.classList.contains('pixonaut-picture-card__use')) {
			showPictureBadges(list);
			return;
		}
		const card = event.target.closest('.pixonaut-picture-card');
		if (card) {
			showDrawnOn(card);
		}
	});
}
