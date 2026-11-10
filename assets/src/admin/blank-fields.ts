// What the Pixonaut tab shows of a blank picked before the product is
// updated: its printshop, variant and views, drawn by the server as for a
// saved blank (`BlankFields`) and put in place of the last blank's.

/** The parts the route draws, as `ProductPanel::blank_fields()` names them. */
export type BlankParts = { blank_html: string; views_html: string };

/** The parts of the panel that depend on the blank. */
const PARTS = '.pixonaut-blank-fields, .pixonaut-views-disclosure';

/** The controls a form posts. */
const CONTROLS = 'input, select, textarea';

/** Marks a control disabled only while its blank is read. */
const DISABLED_WHILE_READING = 'pixonautReading';

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

/** Counts the reads of a blank, so only the answer to the latest one is
 *  shown: an earlier read answered late, or one for a blank cleared since,
 *  is dropped. */
export function latestOnly(): {
	next: () => number;
	isLatest: (read: number) => boolean;
} {
	let latest = 0;
	return {
		next: () => {
			latest += 1;
			return latest;
		},
		isLatest: (read) => read === latest,
	};
}

/** Shows the loading line while the blank is read, the dependent parts
 *  disabled meanwhile so nothing of the last blank is posted: `inert` keeps
 *  them from being used, `disabled` from being submitted. */
export function showBlankLoading(root: HTMLElement, loading: boolean): void {
	const line = root.querySelector<HTMLElement>('.pixonaut-blank-loading');
	if (line) {
		line.hidden = !loading;
		showFailure(line, false);
	}
	for (const part of root.querySelectorAll<HTMLElement>(PARTS)) {
		part.toggleAttribute('inert', loading);
		part.classList.toggle('pixonaut-is-loading', loading);
		for (const control of part.querySelectorAll<
			HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement
		>(CONTROLS)) {
			if (loading && !control.disabled) {
				control.disabled = true;
				control.dataset[DISABLED_WHILE_READING] = '';
			} else if (!loading && DISABLED_WHILE_READING in control.dataset) {
				control.disabled = false;
				delete control.dataset[DISABLED_WHILE_READING];
			}
		}
	}
}

/** Says the blank could not be read. The last blank's parts stay disabled,
 *  so Update saves the new blank without them. */
export function showBlankFailed(root: HTMLElement): void {
	const line = root.querySelector<HTMLElement>('.pixonaut-blank-loading');
	if (line) {
		line.hidden = false;
		showFailure(line, true);
	}
}

/** Shows the loading line's failure in place of its spinner, or back. */
function showFailure(line: HTMLElement, failed: boolean): void {
	const reading = line.querySelector<HTMLElement>('.pixonaut-blank-reading');
	if (reading) {
		reading.hidden = failed;
	}
	const failure = line.querySelector<HTMLElement>('.pixonaut-blank-failed');
	if (failure) {
		failure.hidden = !failed;
	}
}

/** The views' section in its part. */
const VIEWS_SECTION = 'details.pixonaut-disclosure';

/** Puts the parts read for a blank in place, the views' section left open
 *  when it was. */
export function showBlankParts(root: HTMLElement, parts: BlankParts): void {
	const fields = root.querySelector<HTMLElement>('.pixonaut-blank-fields');
	if (fields) {
		fields.innerHTML = parts.blank_html;
	}
	const views = root.querySelector<HTMLElement>('.pixonaut-views-disclosure');
	if (views) {
		const open =
			views.querySelector<HTMLDetailsElement>(VIEWS_SECTION)?.open ?? false;
		views.innerHTML = parts.views_html;
		const section = views.querySelector<HTMLDetailsElement>(VIEWS_SECTION);
		if (section && open) {
			section.open = true;
		}
	}
}
