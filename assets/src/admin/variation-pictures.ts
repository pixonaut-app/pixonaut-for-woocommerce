// "Generate a picture for every variation", on the settings page (a
// checkbox) and in a variable product's Pixonaut tab (a select): when the
// store manager changes it, the cost route says what the change does to the
// shop's storage, shown under the control before anything is saved. Past
// the warning, a box to tick appears: the server saves it on only with that
// box ticked, whatever the script shows.

/** Where the answer is shown, as the server renders it in the product tab;
 *  made beside the control on the settings page. */
export const COST_SELECTOR = '.pixonaut-variation-pictures__cost';

const CONTROL_SELECTOR = '[data-pixonaut-cost-url]';

/** What the cost route answers. */
export type Cost = {
	on: boolean;
	pictures: number;
	bytes: number;
	free: number | null;
	percent: number | null;
	warn: boolean;
	summary: string;
	warning: string | null;
};

/** The value the control stands for: `on`, `off` or `default`. */
function chosen(control: HTMLInputElement | HTMLSelectElement): string {
	if (control instanceof HTMLInputElement) {
		return control.checked ? 'on' : 'off';
	}
	return control.value;
}

/** The product pictures checkbox the settings page's control names, when
 *  it is being turned on: that draws the variation pictures of every
 *  product that has them on. */
function picturesTurningOn(
	control: HTMLInputElement | HTMLSelectElement,
): boolean {
	const id = control.dataset.pixonautCostPictures;
	const pictures = id ? document.getElementById(id) : null;
	return (
		pictures instanceof HTMLInputElement &&
		pictures.checked &&
		control.dataset.pixonautCostPicturesSaved === 'off'
	);
}

/** Each control's latest request, so an answer to an earlier one is dropped. */
const latest = new WeakMap<HTMLElement, number>();

/** The route's address for the control's value. */
export function costUrl(control: HTMLInputElement | HTMLSelectElement): string {
	const url = new URL(
		control.dataset.pixonautCostUrl ?? '',
		window.location.href,
	);
	const product = control.dataset.pixonautCostProduct;
	if (product) {
		url.searchParams.set('product', product);
		url.searchParams.set('choice', chosen(control));
	} else {
		url.searchParams.set('on', chosen(control) === 'on' ? '1' : '0');
		if (picturesTurningOn(control)) {
			url.searchParams.set('pictures', '1');
		}
	}
	return url.toString();
}

/** The box the answer is shown in: the server's, else one made after the control's row. */
function costBox(control: HTMLInputElement | HTMLSelectElement): HTMLElement {
	const group = control.closest('.pixonaut-variation-pictures');
	const rendered = group?.querySelector<HTMLElement>(COST_SELECTOR);
	if (rendered) {
		return rendered;
	}
	const cell = control.closest('td') ?? control.parentElement;
	const existing = cell?.querySelector<HTMLElement>(COST_SELECTOR);
	if (existing) {
		return existing;
	}
	const box = document.createElement('div');
	box.className = COST_SELECTOR.slice(1);
	box.setAttribute('role', 'status');
	box.setAttribute('aria-live', 'polite');
	box.hidden = true;
	cell?.append(box);
	return box;
}

/** Shows the answer: its summary, its warning, and the box to tick past it. */
export function showCost(
	box: HTMLElement,
	cost: Cost,
	confirm: { name: string; label: string },
): void {
	box.replaceChildren();
	const summary = document.createElement('p');
	summary.className = 'pixonaut-variation-pictures__summary';
	summary.textContent = cost.summary;
	box.append(summary);
	if (cost.warning !== null) {
		const warning = document.createElement('p');
		warning.className = 'pixonaut-variation-pictures__warning';
		warning.textContent = cost.warning;
		const label = document.createElement('label');
		label.className = 'pixonaut-variation-pictures__confirm';
		const input = document.createElement('input');
		input.type = 'checkbox';
		input.name = confirm.name;
		input.value = '1';
		input.required = true;
		label.append(input, ` ${confirm.label}`);
		box.append(warning, label);
	}
	box.classList.toggle('is-warning', cost.warning !== null);
	box.hidden = false;
}

/** Shows what the control's value costs, or hides the box at the saved value. */
export async function updateCost(
	control: HTMLInputElement | HTMLSelectElement,
	fetcher: typeof fetch = fetch,
): Promise<void> {
	const box = costBox(control);
	const request = (latest.get(control) ?? 0) + 1;
	latest.set(control, request);
	if (
		chosen(control) === control.dataset.pixonautCostSaved &&
		!picturesTurningOn(control)
	) {
		box.replaceChildren();
		box.hidden = true;
		return;
	}
	const confirm = {
		name: control.dataset.pixonautConfirmName ?? '',
		label: control.dataset.pixonautConfirmLabel ?? '',
	};
	let answer: Cost | null = null;
	try {
		const response = await fetcher(costUrl(control), {
			headers: { 'X-WP-Nonce': control.dataset.pixonautCostNonce ?? '' },
		});
		if (response.ok) {
			answer = (await response.json()) as Cost;
		}
	} catch {
		answer = null;
	}
	if (latest.get(control) !== request) {
		// The control changed meanwhile: a later request shows its own answer.
		return;
	}
	if (answer !== null) {
		showCost(box, answer, confirm);
		return;
	}
	box.replaceChildren();
	const failed = document.createElement('p');
	failed.textContent = control.dataset.pixonautCostError ?? '';
	box.append(failed);
	box.hidden = false;
}

/** Shows the cost under each such control whenever it changes. */
export function initVariationPictures(root: ParentNode): void {
	for (const control of root.querySelectorAll<
		HTMLInputElement | HTMLSelectElement
	>(CONTROL_SELECTOR)) {
		control.addEventListener('change', () => {
			void updateCost(control);
		});
		const pictures = control.dataset.pixonautCostPictures;
		document.getElementById(pictures ?? '')?.addEventListener('change', () => {
			void updateCost(control);
		});
	}
}
