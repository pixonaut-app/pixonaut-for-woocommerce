import type { MountOptions } from '../types/sdk';
import { hexColour, themeAccent } from './accent';

/**
 * The block theme presets the designer takes its look from, by what each is
 * for. A theme defines them on every page; one it does not define leaves the
 * plugin's own value. `storefront.css` reads the same properties.
 */
export const THEME_PROPERTIES = {
	accent: '--wp--preset--color--accent',
	border: '--wp--preset--color--border-strong',
	error: '--wp--preset--color--error',
	bodyFont: '--wp--preset--font-family--body',
	headingFont: '--wp--preset--font-family--heading',
	radius: '--wp--custom--radius--small',
} as const;

type ThemeProperty = keyof typeof THEME_PROPERTIES;

/** The presets the theme defines, as written in its stylesheet. */
export type ThemeLook = Partial<Record<ThemeProperty, string>>;

/** What the designer's mount options take from the theme: of the look, the
 *  SDK has an option for the accent alone. */
export type ThemeMountOptions = Pick<MountOptions, 'accent'>;

/** The presets in effect on `element`. */
export function readThemeLook(element: HTMLElement): ThemeLook {
	const style = getComputedStyle(element);
	const look: ThemeLook = {};
	for (const [key, property] of Object.entries(THEME_PROPERTIES) as [
		ThemeProperty,
		string,
	][]) {
		const value = style.getPropertyValue(property).trim();
		if (value !== '') {
			look[key] = value;
		}
	}
	return look;
}

/** Lists the presets the theme defines in the container's
 *  `data-pixonaut-look`, which the storefront stylesheet draws the tabs and
 *  the approval box by. */
export function markThemeLook(
	container: HTMLElement,
	look: ThemeLook = readThemeLook(container),
): void {
	container.dataset.pixonautLook = Object.keys(look).join(' ');
}

/**
 * The mount options the theme gives a designer whose store follows the
 * theme's accent: `accent` from the theme's accent preset, else from its
 * buttons (`themeAccent()`). Options the server sent win over these.
 */
export function themeMountOptions(
	container: HTMLElement,
	form: HTMLFormElement | null,
	look: ThemeLook = readThemeLook(container),
): ThemeMountOptions {
	const accent =
		solidColour(look.accent, container) ?? themeAccent(container, form);
	return accent === undefined ? {} : { accent };
}

/** A CSS colour as `#rrggbb`, resolved the way the page draws it inside
 *  `container`; undefined for none, an invalid one or one not solid. */
function solidColour(
	colour: string | undefined,
	container: HTMLElement,
): string | undefined {
	if (colour === undefined) {
		return undefined;
	}
	const probe = document.createElement('span');
	probe.hidden = true;
	probe.style.color = colour;
	// An invalid colour is not set, and the probe would inherit its parent's.
	if (probe.style.color === '') {
		return undefined;
	}
	container.append(probe);
	try {
		const computed = getComputedStyle(probe).color;
		return hexColour(computed) ?? paintedColour(computed);
	} finally {
		probe.remove();
	}
}

/** Opaque alpha in the bytes `getImageData()` gives. */
const OPAQUE = 255;

/** A colour `getComputedStyle()` keeps in its own space (`oklch()`,
 *  `color(display-p3 …)`) as the sRGB `#rrggbb` a canvas paints it in;
 *  undefined when it is not solid or there is no canvas to paint on. */
function paintedColour(colour: string): string | undefined {
	const context = document
		.createElement('canvas')
		.getContext('2d', { willReadFrequently: true });
	if (context === null) {
		return undefined;
	}
	context.fillStyle = colour;
	context.fillRect(0, 0, 1, 1);
	const [red, green, blue, alpha] = context.getImageData(0, 0, 1, 1).data;
	return alpha === OPAQUE
		? hexColour(`rgb(${red}, ${green}, ${blue})`)
		: undefined;
}
