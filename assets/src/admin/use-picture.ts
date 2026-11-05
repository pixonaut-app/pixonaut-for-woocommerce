// The "Use the Pixonaut picture" link beside a product's or variation's own
// image. In the Product image box it sets the drawn picture as the featured
// image through WordPress's own featured-image request, or removes the image
// when none is drawn, so the plugin sets the picture once it is. In a
// variation's options it does the same to the variation's image field, which
// WooCommerce saves with the variation.

/** WordPress's featured-image box, `wp.media.featuredImage`. */
type FeaturedImage = { set: (id: number) => void };

declare global {
	interface Window {
		wp?: { media?: { featuredImage?: FeaturedImage } };
	}
}

/** The id `wp.media.featuredImage.set()` takes to remove the image. */
export const NO_IMAGE = -1;

export const LINK_SELECTOR = 'a.pixonaut-use-picture';

/** Sets a variation's image field to the picture, or empties it. */
export function useOnVariation(link: HTMLElement, variation: Element): void {
	const picture = Number(link.dataset.picture ?? '0');
	const button = variation.querySelector<HTMLElement>('.upload_image_button');
	const field = variation.querySelector<HTMLInputElement>('.upload_image_id');
	if (!button || !field) {
		return;
	}
	if (picture > 0) {
		field.value = String(picture);
		const image = button.querySelector('img');
		if (image && link.dataset.pictureUrl) {
			image.src = link.dataset.pictureUrl;
		}
		button.classList.add('remove');
		// WooCommerce marks the variation as changed, to be saved.
		field.dispatchEvent(new Event('change', { bubbles: true }));
	} else if (button.classList.contains('remove')) {
		// WooCommerce's own remove: the placeholder, and the field emptied.
		button.click();
	}
	link.hidden = true;
}

/** Puts the picture back in place of the own image the link is beside. */
export function usePicture(link: HTMLElement): void {
	const variation = link.closest('.woocommerce_variation');
	if (variation) {
		useOnVariation(link, variation);
		return;
	}
	const picture = Number(link.dataset.picture ?? '0');
	window.wp?.media?.featuredImage?.set(picture > 0 ? picture : NO_IMAGE);
}

export function initUsePicture(root: Document): void {
	root.addEventListener('click', (event) => {
		const link =
			event.target instanceof Element
				? event.target.closest<HTMLElement>(LINK_SELECTOR)
				: null;
		if (link) {
			event.preventDefault();
			usePicture(link);
		}
	});
}
