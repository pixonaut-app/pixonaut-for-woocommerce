// The blank variant field lists the variants the blank sells. Those it no
// longer sells (`data-retired`) are taken out of the list until "Show
// retired variants" is ticked, but for the one chosen, which stays listed
// so a variation still set to a retired variant shows what it is set to.
//
// The options are taken out rather than hidden: Safari shows an <option>
// whatever its `hidden` attribute says.

/** Marks a field as set up; WooCommerce loads variations a page at a time. */
const READY = 'pixonautRetiredReady';

/** Sets up the blank variant fields in `container` not set up yet. */
export function initRetiredVariants(container: ParentNode): void {
	for (const field of container.querySelectorAll<HTMLElement>(
		'.pixonaut-variant',
	)) {
		if (field.dataset[READY] === undefined) {
			field.dataset[READY] = '';
			initField(field);
		}
	}
}

function initField(field: HTMLElement): void {
	const select = field.querySelector<HTMLSelectElement>(
		'select.pixonaut-variant-select',
	);
	const toggle = field.querySelector<HTMLInputElement>(
		'input.pixonaut-variant-show-retired',
	);
	if (!select || !toggle) {
		return;
	}
	const options = [...select.options];
	const show = () => {
		const chosen = select.value;
		select.replaceChildren(
			...options.filter(
				(option) =>
					toggle.checked ||
					option.dataset.retired === undefined ||
					option.value === chosen,
			),
		);
		select.value = chosen;
	};
	toggle.addEventListener('change', (event) => {
		// Only the list changes: WooCommerce would take a change bubbling up
		// from a variation's panel for a change to save.
		event.stopPropagation();
		show();
	});
	show();
}
