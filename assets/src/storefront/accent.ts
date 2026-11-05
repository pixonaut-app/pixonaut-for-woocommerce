/** The classes a theme draws its primary buttons with: WooCommerce's own
 *  (`button alt`, as classic themes style them) and the block editor's
 *  (`wp-element-button`, as block themes do). */
const PROBE_CLASSES = ['button', 'alt', 'wp-element-button'];

/** `rgb(r, g, b)` or `rgba(r, g, b, a)`, as `getComputedStyle()` gives a
 *  colour. */
const RGB_PATTERN =
	/^rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*(?:,\s*([\d.]+)\s*)?\)$/;

const HEX_RADIX = 16;

/** Digits per channel in `#rrggbb`. */
const HEX_DIGITS = 2;

/**
 * The colour of the theme's buttons as `#rrggbb`, the form the SDK's
 * `accent` takes: the background of the add-to-cart form's own submit button
 * when there is one, else of a button drawn with the theme's button classes
 * for the moment it is read. Undefined when the theme draws its buttons
 * without a solid background.
 */
export function themeAccent(
	container: HTMLElement,
	form: HTMLFormElement | null,
): string | undefined {
	const button = form?.querySelector<HTMLElement>('[type="submit"]');
	if (button) {
		return hexColour(getComputedStyle(button).backgroundColor);
	}
	const probe = document.createElement('button');
	probe.type = 'button';
	probe.className = PROBE_CLASSES.join(' ');
	probe.hidden = true;
	probe.setAttribute('aria-hidden', 'true');
	container.append(probe);
	try {
		return hexColour(getComputedStyle(probe).backgroundColor);
	} finally {
		probe.remove();
	}
}

/** `rgb()` as `#rrggbb`; undefined for a colour that is not solid. */
export function hexColour(colour: string): string | undefined {
	const match = RGB_PATTERN.exec(colour.trim());
	if (match === null || (match[4] !== undefined && Number(match[4]) < 1)) {
		return undefined;
	}
	return `#${match
		.slice(1, 4)
		.map((channel) =>
			Number(channel).toString(HEX_RADIX).padStart(HEX_DIGITS, '0'),
		)
		.join('')}`;
}
