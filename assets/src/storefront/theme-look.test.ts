// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import { markThemeLook, readThemeLook, themeMountOptions } from './theme-look';

/** A font preset is a whole `font-family` stack, not one family. */
const FONT_STACK =
	'"Inter",system-ui,-apple-system,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif';

/** The presets the Pixonaut Shop theme defines, with its default values, as
 *  a block theme prints them on `:root`. */
const THEME_STYLES = `<style>:root {
	--wp--preset--color--accent: #2f5bd3;
	--wp--preset--color--border-strong: #868c96;
	--wp--preset--color--error: #c8302c;
	--wp--preset--font-family--body: ${FONT_STACK};
	--wp--preset--font-family--heading: ${FONT_STACK};
	--wp--custom--radius--small: 6px;
}</style>`;

/** A classic theme's add-to-cart button, drawn in its primary colour. */
const BUTTON = `<button type="submit" style="background-color: rgb(150, 88, 138)">Add to cart</button>`;

function page(styles: string): {
	container: HTMLElement;
	form: HTMLFormElement;
} {
	document.head.innerHTML = styles;
	document.body.innerHTML = `<form><div class="pixonaut-form"></div>${BUTTON}</form>`;
	const form = document.querySelector('form');
	const container = document.querySelector<HTMLElement>('.pixonaut-form');
	if (form === null || container === null) {
		throw new Error('markup');
	}
	return { container, form };
}

afterEach(() => {
	vi.restoreAllMocks();
	document.head.innerHTML = '';
	document.body.innerHTML = '';
});

describe('readThemeLook', () => {
	it('reads the presets a theme defines', () => {
		const { container } = page(THEME_STYLES);

		expect(readThemeLook(container)).toEqual({
			accent: '#2f5bd3',
			border: '#868c96',
			error: '#c8302c',
			bodyFont: FONT_STACK,
			headingFont: FONT_STACK,
			radius: '6px',
		});
	});

	it('reads nothing from a theme without them', () => {
		const { container } = page('');

		expect(readThemeLook(container)).toEqual({});
	});
});

describe('markThemeLook', () => {
	it('lists the presets the theme defines on the container', () => {
		const { container } = page(THEME_STYLES);

		markThemeLook(container);

		expect(container.dataset.pixonautLook).toBe(
			'accent border error bodyFont headingFont radius',
		);
	});

	it('lists none for a theme without them', () => {
		const { container } = page('');

		markThemeLook(container);

		expect(container.dataset.pixonautLook).toBe('');
	});
});

describe('themeMountOptions', () => {
	it('takes the accent from the theme’s preset, before its buttons, and leaves nothing behind', () => {
		const { container, form } = page(THEME_STYLES);

		expect(themeMountOptions(container, form)).toEqual({ accent: '#2f5bd3' });
		expect(container.children).toHaveLength(0);
	});

	it('takes the accent from the buttons of a theme without the preset', () => {
		const { container, form } = page('');

		expect(themeMountOptions(container, form)).toEqual({ accent: '#96588a' });
	});

	it('takes the accent from the buttons when the preset is not a solid colour', () => {
		const { container, form } = page('');

		expect(
			themeMountOptions(container, form, { accent: 'not-a-colour' }),
		).toEqual({ accent: '#96588a' });
		expect(
			themeMountOptions(container, form, { accent: 'rgba(0, 0, 0, 0.5)' }),
		).toEqual({ accent: '#96588a' });
	});

	it('takes an accent in another colour space as a canvas paints it, unless it is not solid', () => {
		const { container, form } = page('');
		/** What the canvas paints a pixel in, as `getImageData()` bytes. */
		let painted = [47, 93, 80, 255];
		const context = {
			fillStyle: '',
			fillRect: vi.fn(),
			getImageData: () => ({ data: Uint8ClampedArray.from(painted) }),
		};
		vi.spyOn(window, 'getComputedStyle').mockImplementation(
			() =>
				({
					color: 'oklch(0.43 0.05 165)',
					backgroundColor: 'transparent',
				}) as CSSStyleDeclaration,
		);
		vi.spyOn(HTMLCanvasElement.prototype, 'getContext').mockReturnValue(
			context as unknown as CanvasRenderingContext2D,
		);

		expect(
			themeMountOptions(container, form, { accent: 'oklch(0.43 0.05 165)' }),
		).toEqual({ accent: '#2f5d50' });
		expect(context.fillStyle).toBe('oklch(0.43 0.05 165)');

		painted = [47, 93, 80, 128];
		expect(
			themeMountOptions(container, null, {
				accent: 'oklch(0.43 0.05 165 / 0.5)',
			}),
		).toEqual({});
	});

	it('gives none when neither the preset nor the buttons give a colour', () => {
		const { container } = page('');

		expect(themeMountOptions(container, null)).toEqual({});
	});
});
