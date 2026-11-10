// A standing side's previews per option, on a blank whose print areas change
// with one (such as Size): one tile per option, its design drawn on the
// blank. The panel prints them for the designs the last update made; a
// change to the side that makes new designs replaces them with a line saying
// they appear on the next update. They show only while the side is standing.
// Each tile links to adjusting its layout by hand; Reset, on a tile adjusted,
// asks first.

import { isFixedDesign } from './sides';

/** The side's previews, or null when it has none to show. */
function optionsOf(side: HTMLElement): HTMLElement | null {
	return side.querySelector<HTMLElement>('.pixonaut-standing-options');
}

/** Shows the previews only while the side is a fixed design. */
export function showStandingOptions(side: HTMLElement): void {
	const options = optionsOf(side);
	if (options) {
		options.hidden = !isFixedDesign(side);
	}
}

/** Puts the line saying the previews appear on the next update in place of
 *  the tiles, which no longer show what the side will print. */
export function standingOptionsChanged(side: HTMLElement): void {
	const options = optionsOf(side);
	if (!options) {
		return;
	}
	const pending = options.querySelector<HTMLElement>(
		'.pixonaut-standing-options__pending',
	);
	if (pending) {
		pending.hidden = false;
	}
	for (const part of options.querySelectorAll<HTMLElement>(
		'.pixonaut-standing-options__tiles, .pixonaut-standing-options__more',
	)) {
		part.hidden = true;
	}
}

/** Wires Show all / Show fewer, which opens the tiles past the first rows,
 *  and the confirmation before Reset. */
export function initStandingOptions(side: HTMLElement): void {
	const options = optionsOf(side);
	for (const reset of options?.querySelectorAll<HTMLAnchorElement>(
		'.pixonaut-standing-option__reset',
	) ?? []) {
		reset.addEventListener('click', (event) => {
			if (!window.confirm(reset.dataset.confirm ?? '')) {
				event.preventDefault();
			}
		});
	}
	const more = options?.querySelector<HTMLButtonElement>(
		'.pixonaut-standing-options__more',
	);
	if (!options || !more) {
		return;
	}
	more.addEventListener('click', () => {
		const open = more.getAttribute('aria-expanded') !== 'true';
		for (const tile of options.querySelectorAll<HTMLElement>(
			'.pixonaut-standing-option[data-more]',
		)) {
			tile.hidden = !open;
		}
		more.setAttribute('aria-expanded', String(open));
		more.textContent = (open ? more.dataset.fewer : more.dataset.all) ?? '';
	});
}
