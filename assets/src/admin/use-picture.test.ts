// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import { initUsePicture, NO_IMAGE } from './use-picture';

function link(picture: number, url = ''): string {
	return `<a href="#" class="pixonaut-use-picture" data-picture="${picture}" data-picture-url="${url}">Use the Pixonaut picture</a>`;
}

function variation(picture: number, own: boolean): HTMLElement {
	const element = document.createElement('div');
	element.className = 'woocommerce_variation';
	element.innerHTML = `
		<a href="#" class="upload_image_button${own ? ' remove' : ''}"><img src="own.jpg" /></a>
		<input type="hidden" class="upload_image_id" value="${own ? '12' : ''}" />
		<p class="pixonaut-picture">${link(picture, 'picture.png')}</p>`;
	document.body.append(element);
	return element;
}

function click(root: ParentNode): void {
	root.querySelector<HTMLElement>('.pixonaut-use-picture')?.click();
}

initUsePicture(document);

afterEach(() => {
	document.body.innerHTML = '';
	delete window.wp;
});

describe('the Product image box', () => {
	it('sets the drawn picture as the featured image', () => {
		const set = vi.fn();
		window.wp = { media: { featuredImage: { set } } };
		document.body.innerHTML = `<div id="postimagediv">${link(31)}</div>`;

		click(document);

		expect(set).toHaveBeenCalledWith(31);
	});

	it('removes the image when no picture is drawn, so one is', () => {
		const set = vi.fn();
		window.wp = { media: { featuredImage: { set } } };
		document.body.innerHTML = `<div id="postimagediv">${link(0)}</div>`;

		click(document);

		expect(set).toHaveBeenCalledWith(NO_IMAGE);
	});
});

describe("a variation's options", () => {
	it("sets the variation's image to the picture, as changed", () => {
		const element = variation(31, true);
		const field = element.querySelector<HTMLInputElement>('.upload_image_id');
		const changed = vi.fn();
		field?.addEventListener('change', changed);

		click(element);

		expect(field?.value).toBe('31');
		expect(element.querySelector('img')?.getAttribute('src')).toBe(
			'picture.png',
		);
		expect(changed).toHaveBeenCalled();
		expect(
			element.querySelector<HTMLElement>('.pixonaut-use-picture')?.hidden,
		).toBe(true);
	});

	it("removes the variation's image through WooCommerce when no picture is drawn", () => {
		const element = variation(0, true);
		const removed = vi.fn();
		element
			.querySelector('.upload_image_button')
			?.addEventListener('click', removed);

		click(element);

		expect(removed).toHaveBeenCalled();
	});
});
