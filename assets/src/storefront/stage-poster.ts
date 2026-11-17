/** The picture a side's preview shows until its designer has drawn
 *  (`Storefront::posters()`), naming the template and variant it shows. */
const POSTER_SELECTOR = '.pixonaut-preview__poster';

/** What a side shows: its template, and the blank variant it is shown in. */
export type Shown = { templateId: string; variant?: string };

/**
 * Takes down the poster in `preview` when it shows another template or
 * variant than `shown`: the customer picked a variation before the designer
 * drew, and its designer draws over the spinner rather than over a picture
 * of another product.
 */
export function dropPosterUnlike(
	preview: ParentNode | null,
	shown: Shown,
): void {
	const poster = preview?.querySelector<HTMLImageElement>(POSTER_SELECTOR);
	if (
		poster &&
		(poster.dataset.pixonautTemplate !== shown.templateId ||
			poster.dataset.pixonautVariant !== (shown.variant ?? ''))
	) {
		poster.remove();
	}
}

/**
 * Takes down each poster that failed to load, now or later, so the spinner
 * shows over its preview again in place of a broken image.
 */
export function dropBrokenPosters(doc: Document): void {
	for (const poster of doc.querySelectorAll<HTMLImageElement>(
		POSTER_SELECTOR,
	)) {
		if (poster.complete && poster.naturalWidth === 0) {
			poster.remove();
			continue;
		}
		poster.addEventListener('error', () => poster.remove(), { once: true });
	}
}
