// The products list: Regenerate now, as a bulk action and as a product row's
// action, runs in the page (`regenerate-now.ts`) instead of going to the
// server, which would queue the pictures as Regenerate in background does.
import { domReady } from '../shared/dom-ready';
import { ROOT_ID, regenerateNow } from './regenerate-now';

/** The bulk and row action's name, as `RegenerateNow::BULK_ACTION`. */
export const NOW_ACTION = 'pixonaut_regenerate_pictures_now';

/** Each Apply button, and the bulk action select it applies. */
const BULK_CONTROLS: ReadonlyArray<[string, string]> = [
	['#doaction', '#bulk-action-selector-top'],
	['#doaction2', '#bulk-action-selector-bottom'],
];

function reload(drawn: boolean): void {
	if (drawn) {
		window.location.reload();
	}
}

function selected(doc: Document): number[] {
	return Array.from(
		doc.querySelectorAll<HTMLInputElement>('input[name="post[]"]:checked'),
	)
		.map((input) => Number.parseInt(input.value, 10))
		.filter((id) => id > 0);
}

export function initProductsList(doc: Document): void {
	for (const [button, select] of BULK_CONTROLS) {
		doc.querySelector(button)?.addEventListener('click', (event) => {
			const ids = selected(doc);
			if (
				doc.querySelector<HTMLSelectElement>(select)?.value !== NOW_ACTION ||
				ids.length === 0 ||
				!doc.getElementById(ROOT_ID)
			) {
				return;
			}
			event.preventDefault();
			void regenerateNow(doc, ids, { onClose: reload });
		});
	}
	doc.addEventListener('click', (event) => {
		const link =
			event.target instanceof Element
				? event.target.closest<HTMLElement>(
						`.pixonaut-regenerate-row[data-action="${NOW_ACTION}"]`,
					)
				: null;
		const id = Number.parseInt(link?.dataset.productId ?? '', 10);
		if (!link || !(id > 0) || !doc.getElementById(ROOT_ID)) {
			return;
		}
		event.preventDefault();
		void regenerateNow(doc, [id], { onClose: reload });
	});
}

domReady(() => initProductsList(document));
