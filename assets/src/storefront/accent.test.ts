// @vitest-environment jsdom
import { afterEach, describe, expect, it } from 'vitest';
import { hexColour, themeAccent } from './accent';

describe('hexColour', () => {
	it('writes a solid colour as #rrggbb', () => {
		expect(hexColour('rgb(30, 115, 190)')).toBe('#1e73be');
		expect(hexColour('rgba(0, 0, 0, 1)')).toBe('#000000');
	});

	it('gives none for a colour that is not solid', () => {
		expect(hexColour('rgba(0, 0, 0, 0)')).toBeUndefined();
		expect(hexColour('rgba(30, 115, 190, 0.5)')).toBeUndefined();
		expect(hexColour('transparent')).toBeUndefined();
		expect(hexColour('')).toBeUndefined();
	});
});

describe('themeAccent', () => {
	afterEach(() => {
		document.head.innerHTML = '';
		document.body.innerHTML = '';
	});

	it('reads the add-to-cart button’s background', () => {
		document.body.innerHTML = `
			<form><div class="pixonaut-form"></div>
			<button type="submit" style="background-color: rgb(150, 88, 138)">Add to cart</button></form>`;
		const form = document.querySelector('form');
		const container = document.querySelector<HTMLElement>('.pixonaut-form');
		if (form === null || container === null) {
			throw new Error('markup');
		}

		expect(themeAccent(container, form)).toBe('#96588a');
	});

	it('reads a button drawn with the theme’s button classes without a form, and leaves nothing behind', () => {
		document.head.innerHTML =
			'<style>.wp-element-button { background-color: rgb(17, 17, 17); }</style>';
		document.body.innerHTML = '<div class="pixonaut-form"></div>';
		const container = document.querySelector<HTMLElement>('.pixonaut-form');
		if (container === null) {
			throw new Error('markup');
		}

		expect(themeAccent(container, null)).toBe('#111111');
		expect(container.children).toHaveLength(0);
	});

	it('gives none for a theme whose buttons have no background', () => {
		document.body.innerHTML = '<div class="pixonaut-form"></div>';
		const container = document.querySelector<HTMLElement>('.pixonaut-form');
		if (container === null) {
			throw new Error('markup');
		}

		expect(themeAccent(container, null)).toBeUndefined();
	});
});
