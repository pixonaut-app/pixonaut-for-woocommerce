// A side's print size per value of one of its product's attributes: only
// the values of the attribute chosen are shown, and only on a side
// customers fill in, as a fixed design prints at the size it starts on.
import { isFixedDesign } from './sides';

/** Shows the value sizes of the attribute chosen on `side`, if it has the field. */
export function showValueSizes(side: HTMLElement): void {
	const field = side.querySelector<HTMLElement>('.pixonaut-value-sizes');
	if (!field) {
		return;
	}
	field.hidden = isFixedDesign(side);
	const chosen =
		field.querySelector<HTMLSelectElement>('.pixonaut-value-attribute')
			?.value ?? '';
	for (const table of field.querySelectorAll<HTMLElement>(
		'.pixonaut-value-size-table',
	)) {
		table.hidden = table.dataset.attribute !== chosen;
	}
}

/** Keeps `side`'s value sizes in step with its attribute and mode. */
export function initValueSizes(side: HTMLElement): void {
	side
		.querySelector('.pixonaut-value-attribute')
		?.addEventListener('change', () => showValueSizes(side));
	side
		.querySelector('.pixonaut-side-mode')
		?.addEventListener('change', () => showValueSizes(side));
	showValueSizes(side);
}
