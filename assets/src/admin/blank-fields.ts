// What the Pixonaut tab shows of a blank picked before the product is
// updated: its printshop, variant and views, drawn by the server as for a
// saved blank (`BlankFields`) and put in place of the last blank's.

/** The parts the route draws, as `ProductPanel::blank_fields()` names them. */
export type BlankParts = { blank_html: string; views_html: string };

/** Asks the route for the parts of `blank`; null when it cannot answer. */
export async function readBlankParts(
	root: HTMLElement,
	blank: string,
): Promise<BlankParts | null> {
	const route = root.dataset.blankFieldsUrl ?? '';
	const product = root.dataset.productId ?? '';
	if (route === '' || product === '') {
		return null;
	}
	const url = new URL(route, window.location.href);
	url.searchParams.set('product', product);
	url.searchParams.set('blank', blank);
	try {
		const response = await fetch(url, {
			headers: { 'X-WP-Nonce': root.dataset.restNonce ?? '' },
		});
		return response.ok ? ((await response.json()) as BlankParts) : null;
	} catch {
		return null;
	}
}

/** Shows the loading line while the blank is read, the dependent parts
 *  hidden meanwhile so nothing of the last blank is posted. */
export function showBlankLoading(root: HTMLElement, loading: boolean): void {
	const line = root.querySelector<HTMLElement>('.pixonaut-blank-loading');
	if (line) {
		line.hidden = !loading;
	}
	for (const part of root.querySelectorAll<HTMLElement>(
		'.pixonaut-blank-fields, .pixonaut-views-disclosure',
	)) {
		part.toggleAttribute('inert', loading);
		part.classList.toggle('pixonaut-is-loading', loading);
	}
}

/** Puts the parts read for a blank in place. */
export function showBlankParts(root: HTMLElement, parts: BlankParts): void {
	const fields = root.querySelector<HTMLElement>('.pixonaut-blank-fields');
	if (fields) {
		fields.innerHTML = parts.blank_html;
	}
	const views = root.querySelector<HTMLElement>('.pixonaut-views-disclosure');
	if (views) {
		views.innerHTML = parts.views_html;
	}
}
