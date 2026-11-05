// What a variation's Pixonaut panel adds to the fields the product shares:
// the override that shows them, and telling WooCommerce the variation changed.

/** Marks a panel as set up; WooCommerce loads variations in pages, so a
 *  panel is found again with every page and must be set up only once. */
const READY = 'pixonautReady';

/** The variation panels in `container` not set up yet, marked as set up. */
export function newPanels(container: ParentNode): HTMLElement[] {
	const panels = [
		...container.querySelectorAll<HTMLElement>('.pixonaut-variation-panel'),
	].filter((panel) => panel.dataset[READY] === undefined);
	for (const panel of panels) {
		panel.dataset[READY] = '';
	}
	return panels;
}

/** Shows the variation's own fields only while it overrides the product's,
 *  and the line that says which configuration it uses. */
export function initOverride(panel: HTMLElement): void {
	const override = panel.querySelector<HTMLInputElement>('.pixonaut-override');
	const fields = panel.querySelector<HTMLElement>('.pixonaut-variation-fields');
	if (!override || !fields) {
		return;
	}
	const inherits = panel.querySelector<HTMLElement>(
		'.pixonaut-variation-inherits',
	);
	const overrides = panel.querySelector<HTMLElement>(
		'.pixonaut-variation-overrides',
	);
	const show = () => {
		fields.hidden = !override.checked;
		if (inherits) {
			inherits.hidden = override.checked;
		}
		if (overrides) {
			overrides.hidden = !override.checked;
		}
	};
	override.addEventListener('change', show);
	show();
}

/**
 * Tells WooCommerce the variation changed when a side or size row was added
 * or removed: it saves only variations one of whose fields fired `change`,
 * and adding or removing a row changes no field. Outside a variation panel
 * this does nothing.
 */
export function markChanged(root: HTMLElement): void {
	root
		.querySelector('.pixonaut-override')
		?.dispatchEvent(new Event('change', { bubbles: true }));
}
